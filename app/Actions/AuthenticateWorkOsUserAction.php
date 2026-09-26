<?php

namespace App\Actions;

use App\Enums\LanguageEnum;
use App\Http\Requests\WorkOsLoginRequest;
use App\Models\User;
use App\Services\WorkOS\WorkOSService;
use Illuminate\Http\JsonResponse;
use Lorisleiva\Actions\Concerns\AsAction;
use Throwable;

class AuthenticateWorkOsUserAction
{
    use AsAction;

    public function __construct(
        private readonly WorkOSService $work_os_service,
    ) {}

    /**
     * Signs in the WorkOS user, creating or updating the local user by e-mail, and issues an API token.
     *
     * @return array{user: User, token: string}
     */
    public function handle(string $code, ?string $fallback_locale = null): array
    {
        $response = $this->work_os_service->authenticateWithCode($code);
        $work_os_user = $response->user;

        $detected_locale = $work_os_user->locale ?? $fallback_locale;
        $language = $detected_locale ? LanguageEnum::tryFrom($detected_locale) ?? LanguageEnum::fromLocale($detected_locale) : null;

        $user = User::updateOrCreate(
            ['email' => $work_os_user->email],
            array_filter([
                'workos_id' => $work_os_user->id,
                'first_name' => $work_os_user->firstName,
                'last_name' => $work_os_user->lastName,
                'avatar' => $work_os_user->profilePictureUrl ?: null,
                'language' => $language?->value ?? LanguageEnum::HUNGARIAN->value,
            ], fn (mixed $value): bool => $value !== null)
        );

        return [
            'user' => $user,
            'token' => $user->createToken('mobile-app')->plainTextToken,
        ];
    }

    public function asController(WorkOsLoginRequest $request): JsonResponse
    {
        try {
            $result = $this->handle(
                code: $request->validated('code'),
                fallback_locale: $request->validated('language')
            );

            return response()->json([
                'token' => $result['token'],
                'user' => $result['user'],
            ]);
        } catch (Throwable $e) {
            // The exception message may contain internal details (e.g. SQL), it is only logged.
            report($e);

            return response()->json(['message' => __('app.failed_action')], 401);
        }
    }
}
