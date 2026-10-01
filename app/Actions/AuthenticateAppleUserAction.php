<?php

namespace App\Actions;

use App\Enums\LanguageEnum;
use App\Http\Requests\AppleLoginRequest;
use App\Models\User;
use App\Services\Apple\AppleSignInService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Str;
use Lorisleiva\Actions\Concerns\AsAction;
use Throwable;
use UnexpectedValueException;

/**
 * Native Sign in with Apple on iOS (the App Store expects the system sheet instead of a web login).
 * The user is found by the Apple id, then by the e-mail, so a user who signed in with Apple through WorkOS before keeps the account.
 */
class AuthenticateAppleUserAction
{
    use AsAction;

    public function __construct(
        private readonly AppleSignInService $apple_sign_in_service,
    ) {}

    /**
     * @return array{user: User, token: string}
     */
    public function handle(
        string $identity_token,
        string $authorization_code,
        ?string $first_name = null,
        ?string $last_name = null,
        ?string $language = null,
    ): array {
        $claims = $this->apple_sign_in_service->verifyIdentityToken($identity_token);
        $refresh_token = $this->apple_sign_in_service->exchangeAuthorizationCode($authorization_code);

        $user = User::query()->where('apple_id', $claims['sub'])->first()
            ?? ($claims['email'] ? User::query()->where('email', $claims['email'])->first() : null);

        if ($user === null) {
            if ($claims['email'] === null) {
                throw new UnexpectedValueException('The Apple identity token has no e-mail for a new user.');
            }

            $user = new User([
                'email' => $claims['email'],
                'first_name' => $first_name ?? Str::before($claims['email'], '@'),
                'last_name' => $last_name ?? '',
                'avatar' => '',
                'language' => (LanguageEnum::tryFrom((string) $language) ?? LanguageEnum::HUNGARIAN)->value,
            ]);
        }

        $user->fill(array_filter([
            'apple_id' => $claims['sub'],
            'apple_refresh_token' => $refresh_token,
            'first_name' => $first_name,
            'last_name' => $last_name,
        ], fn (?string $value): bool => $value !== null && $value !== ''))->save();

        return [
            'user' => $user,
            'token' => $user->createToken('mobile-app')->plainTextToken,
        ];
    }

    public function asController(AppleLoginRequest $request): JsonResponse
    {
        try {
            $result = $this->handle(
                identity_token: $request->validated('identity_token'),
                authorization_code: $request->validated('authorization_code'),
                first_name: $request->validated('first_name'),
                last_name: $request->validated('last_name'),
                language: $request->validated('language'),
            );

            return response()->json([
                'token' => $result['token'],
                'user' => $result['user']->fresh(),
            ]);
        } catch (Throwable $e) {
            // The exception message may contain internal details, it is only logged.
            report($e);

            return response()->json(['message' => __('app.failed_action')], 401);
        }
    }
}
