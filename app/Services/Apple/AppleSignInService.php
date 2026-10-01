<?php

namespace App\Services\Apple;

use Firebase\JWT\JWK;
use Firebase\JWT\JWT;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use UnexpectedValueException;

/**
 * Native Sign in with Apple: verifies the identity token the app receives from the Apple sheet,
 * exchanges the authorization code for a refresh token and revokes it when the account is deleted.
 *
 * @see https://developer.apple.com/documentation/sign_in_with_apple/sign_in_with_apple_rest_api
 */
class AppleSignInService
{
    private const string ISSUER = 'https://appleid.apple.com';

    private const string KEYS_CACHE_KEY = 'apple-sign-in-keys';

    /**
     * Verifies the signature, the issuer, the audience and the expiry of the identity token.
     *
     * @return array{sub: string, email: ?string}
     *
     * @throws UnexpectedValueException when the token is invalid
     */
    public function verifyIdentityToken(string $identity_token): array
    {
        try {
            $claims = JWT::decode($identity_token, JWK::parseKeySet($this->publicKeys(), 'RS256'));
        } catch (UnexpectedValueException $e) {
            // Apple rotates its keys, the token may be signed by a key newer than the cached set.
            if (! str_contains($e->getMessage(), '"kid"')) {
                throw $e;
            }
            Cache::forget(self::KEYS_CACHE_KEY);
            $claims = JWT::decode($identity_token, JWK::parseKeySet($this->publicKeys(), 'RS256'));
        }

        if (($claims->iss ?? null) !== self::ISSUER || ($claims->aud ?? null) !== config('services.apple.client_id') || empty($claims->sub)) {
            throw new UnexpectedValueException('The Apple identity token was not issued for this app.');
        }

        return [
            'sub' => (string) $claims->sub,
            'email' => isset($claims->email) ? (string) $claims->email : null,
        ];
    }

    /**
     * Exchanges the single-use authorization code for a refresh token, only the refresh token can be revoked later.
     *
     * @throws RequestException|ConnectionException
     */
    public function exchangeAuthorizationCode(string $authorization_code): string
    {
        return Http::asForm()->acceptJson()->timeout(15)
            ->post(self::ISSUER.'/auth/token', [
                'client_id' => config('services.apple.client_id'),
                'client_secret' => $this->clientSecret(),
                'code' => $authorization_code,
                'grant_type' => 'authorization_code',
            ])
            ->throw()
            ->json('refresh_token');
    }

    /**
     * Revokes the user's Sign in with Apple authorization, as the App Store requires on account deletion.
     *
     * @throws RequestException|ConnectionException
     */
    public function revokeRefreshToken(string $refresh_token): void
    {
        Http::asForm()->timeout(15)
            ->post(self::ISSUER.'/auth/revoke', [
                'client_id' => config('services.apple.client_id'),
                'client_secret' => $this->clientSecret(),
                'token' => $refresh_token,
                'token_type_hint' => 'refresh_token',
            ])
            ->throw();
    }

    /**
     * @return array{keys: array<int, array<string, string>>}
     */
    private function publicKeys(): array
    {
        return Cache::remember(
            self::KEYS_CACHE_KEY,
            now()->addHours(12),
            fn (): array => Http::acceptJson()->timeout(15)->get(self::ISSUER.'/auth/keys')->throw()->json(),
        );
    }

    /**
     * The client secret is a short-lived JWT signed with the "Sign in with Apple" key of the developer account.
     */
    private function clientSecret(): string
    {
        return JWT::encode(
            [
                'iss' => config('services.apple.team_id'),
                'iat' => now()->timestamp,
                'exp' => now()->addMinutes(5)->timestamp,
                'aud' => self::ISSUER,
                'sub' => config('services.apple.client_id'),
            ],
            // The key can be stored in a single-line env variable with escaped line breaks.
            str_replace('\n', "\n", (string) config('services.apple.private_key')),
            'ES256',
            config('services.apple.key_id'),
        );
    }
}
