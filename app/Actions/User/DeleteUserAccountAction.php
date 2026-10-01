<?php

namespace App\Actions\User;

use App\Actions\Household\DeleteHouseholdAction;
use App\Actions\HouseholdUser\DeleteHouseholdUserAction;
use App\Models\Household;
use App\Models\HouseholdUser;
use App\Models\HouseholdUserRequest;
use App\Models\TaskSticker;
use App\Models\TaskUserRotation;
use App\Models\TaskUserWeight;
use App\Models\TaskWeightNotification;
use App\Models\User;
use App\Services\Apple\AppleSignInService;
use App\Services\WorkOS\WorkOSService;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Support\Facades\DB;
use Lorisleiva\Actions\Concerns\AsAction;
use Throwable;

/**
 * Deletes the user's account, as the App Store and Google Play require it inside the app.
 *
 * The user row itself stays, because the households' shared history (completions, point transactions, offers)
 * references it, but every personal data is removed from it irreversibly (anonymization):
 * - the households created by the user are deleted for every member,
 * - the user leaves every other household (claims released, rewards deleted, admins asked about the tasks, see HouseholdUserObserver),
 * - the personal settings (weights, rotations, stickers, notifications, join requests), the API and push tokens are deleted,
 * - the user is deleted from WorkOS too, so the identity provider does not keep the account,
 * - the Sign in with Apple authorization is revoked, as the App Store requires (5.1.1(v)).
 */
class DeleteUserAccountAction
{
    use AsAction;

    public function __construct(
        private readonly WorkOSService $work_os_service,
        private readonly AppleSignInService $apple_sign_in_service,
    ) {}

    public function handle(User $user): void
    {
        $work_os_id = $user->workos_id;
        $apple_refresh_token = $user->apple_refresh_token;

        DB::transaction(function () use ($user): void {
            Household::query()
                ->where('created_by', $user->id)
                ->each(fn (Household $household) => DeleteHouseholdAction::run($household));

            $user->householdUsers()
                ->with('household')
                ->each(fn (HouseholdUser $household_user) => DeleteHouseholdUserAction::run($household_user));

            TaskUserWeight::query()->where('user_id', $user->id)->delete();
            TaskUserRotation::query()->where('user_id', $user->id)->delete();
            TaskSticker::query()->where('user_id', $user->id)->delete();
            TaskWeightNotification::query()->where('user_id', $user->id)->delete();
            HouseholdUserRequest::query()->where('user_id', $user->id)->delete();

            $user->pushTokens()->delete();
            $user->tokens()->delete();

            $user->forceFill([
                'workos_id' => "deleted-{$user->id}",
                'apple_id' => null,
                'apple_refresh_token' => null,
                'first_name' => '',
                'last_name' => '',
                'nickname' => null,
                'email' => "deleted-{$user->id}@deleted.invalid",
                'avatar' => '',
                'anonymized_at' => now(),
            ])->save();
        });

        if ($work_os_id !== null) {
            try {
                $this->work_os_service->deleteUser($work_os_id);
            } catch (Throwable $e) {
                // The local account is already gone, the WorkOS user can be deleted by hand from the report.
                report($e);
            }
        }

        if ($apple_refresh_token !== null) {
            try {
                $this->apple_sign_in_service->revokeRefreshToken($apple_refresh_token);
            } catch (Throwable $e) {
                // The local account is already gone, the user can still revoke the app in the Apple ID settings.
                report($e);
            }
        }
    }

    /**
     * @return array{message: string}
     */
    public function asController(#[CurrentUser] User $user): array
    {
        $this->handle($user);

        return ['message' => __('app.account_deleted')];
    }
}
