<?php

use App\Actions\AuthenticateWorkOsUserAction;
use App\Actions\ClaimTaskInstanceAction;
use App\Actions\CompleteTaskInstanceAction;
use App\Actions\GetHouseholdUserAction;
use App\Actions\Household\DeleteHouseholdAction;
use App\Actions\Household\GenerateHouseholdQrCodeAction;
use App\Actions\Household\JoinHouseholdAction;
use App\Actions\Household\LeaveHouseholdAction;
use App\Actions\Household\ListHouseholdsAction;
use App\Actions\Household\RenameHouseholdAction;
use App\Actions\Household\StoreHouseholdAction;
use App\Actions\Household\UpdateHouseholdSettingsAction;
use App\Actions\HouseholdMemberDeparture\ListHouseholdMemberDeparturesAction;
use App\Actions\HouseholdMemberDeparture\ResolveHouseholdMemberDepartureAction;
use App\Actions\HouseholdReward\CalculateRewardDifficultyAction;
use App\Actions\HouseholdReward\DeleteHouseholdRewardAction;
use App\Actions\HouseholdReward\FulfillRewardRedemptionAction;
use App\Actions\HouseholdReward\ListHouseholdRewardRedemptionsAction;
use App\Actions\HouseholdReward\ListHouseholdRewardsAction;
use App\Actions\HouseholdReward\RedeemHouseholdRewardAction;
use App\Actions\HouseholdReward\StartRewardEditingAction;
use App\Actions\HouseholdReward\StopRewardEditingAction;
use App\Actions\HouseholdReward\StoreHouseholdRewardAction;
use App\Actions\HouseholdReward\UpdateHouseholdRewardAction;
use App\Actions\HouseholdStats\GetHouseholdStatsAction;
use App\Actions\HouseholdStats\ListHouseholdActivityAction;
use App\Actions\HouseholdTask\DeleteHouseholdTaskAction;
use App\Actions\HouseholdTask\ListHouseholdTasksAction;
use App\Actions\HouseholdTask\ListOneOffHouseholdTasksAction;
use App\Actions\HouseholdTask\LogHouseholdTaskCompletionAction;
use App\Actions\HouseholdTask\OpenHouseholdTaskInstanceAction;
use App\Actions\HouseholdTask\StoreCompletedHouseholdTaskAction;
use App\Actions\HouseholdTask\StoreCompletedHouseholdTaskFromTemplateAction;
use App\Actions\HouseholdTask\StoreHouseholdTaskAction;
use App\Actions\HouseholdTask\StoreHouseholdTaskFromTemplateAction;
use App\Actions\HouseholdTask\UpdateHouseholdTaskAction;
use App\Actions\HouseholdUser\DeleteHouseholdUserAction;
use App\Actions\HouseholdUser\ListHouseholdMembersAction;
use App\Actions\HouseholdUser\ListHouseholdUsersAction;
use App\Actions\HouseholdUser\UpdateHouseholdUserAction;
use App\Actions\ListTaskInstancesAction;
use App\Actions\PushToken\DeletePushTokenAction;
use App\Actions\PushToken\StorePushTokenAction;
use App\Actions\RequestTaskInstanceGraceDayAction;
use App\Actions\StoreTaskUserWeightAction;
use App\Actions\TaskOffer\AcceptTaskOfferAction;
use App\Actions\TaskOffer\CancelTaskOfferAction;
use App\Actions\TaskOffer\StoreTaskOfferAction;
use App\Actions\TaskTemplate\ListTaskTemplatesAction;
use App\Http\Controllers\Api\UserController;
use App\Http\Middleware\SetAppLocaleMiddleware;
use Illuminate\Support\Facades\Route;

Route::post('/auth/workos', AuthenticateWorkOsUserAction::class);

// A nyelv a hitelesített user `language` mezőjéből jön, ezért az auth után fut.
Route::middleware(['auth:sanctum', SetAppLocaleMiddleware::class])->group(function (): void {
    Route::get('user/me', [UserController::class, 'me']);
    Route::put('user/me', [UserController::class, 'update']);

    Route::post('push-tokens', StorePushTokenAction::class);
    Route::delete('push-tokens', DeletePushTokenAction::class);

    Route::get('task-templates', ListTaskTemplatesAction::class);

    Route::put('household-users/{household_user}', UpdateHouseholdUserAction::class);
    Route::delete('household-users/{household_user}', DeleteHouseholdUserAction::class);

    Route::get('households', ListHouseholdsAction::class);
    Route::post('households', StoreHouseholdAction::class);
    Route::post('households/join', JoinHouseholdAction::class);

    // The rewards, tasks and task instances are resolved through the household, so the ones of another household are not found (404).
    Route::prefix('households/{household}')->scopeBindings()->group(function (): void {
        Route::put('/', RenameHouseholdAction::class);
        Route::delete('/', DeleteHouseholdAction::class);
        Route::put('/settings', UpdateHouseholdSettingsAction::class);
        Route::get('/qrcode', GenerateHouseholdQrCodeAction::class);
        Route::delete('/leave', LeaveHouseholdAction::class);

        Route::get('/users', ListHouseholdUsersAction::class);
        Route::get('/members', ListHouseholdMembersAction::class);
        Route::get('/me', GetHouseholdUserAction::class);
        Route::get('/stats', GetHouseholdStatsAction::class);
        Route::get('/activity', ListHouseholdActivityAction::class);

        Route::get('/member-departures', ListHouseholdMemberDeparturesAction::class);
        Route::post('/member-departures/{member_departure}/resolve', ResolveHouseholdMemberDepartureAction::class);

        Route::prefix('/rewards')->group(function (): void {
            Route::get('/', ListHouseholdRewardsAction::class);
            Route::post('/', StoreHouseholdRewardAction::class);
            Route::get('/difficulty', CalculateRewardDifficultyAction::class);
            Route::put('/{reward}', UpdateHouseholdRewardAction::class);
            Route::delete('/{reward}', DeleteHouseholdRewardAction::class);
            Route::post('/{reward}/editing', StartRewardEditingAction::class);
            Route::delete('/{reward}/editing', StopRewardEditingAction::class);
            Route::post('/{reward}/redeem', RedeemHouseholdRewardAction::class);
        });

        Route::get('/reward-redemptions', ListHouseholdRewardRedemptionsAction::class);
        Route::post('/reward-redemptions/{reward_redemption}/fulfill', FulfillRewardRedemptionAction::class);

        Route::prefix('/task-instances')->group(function (): void {
            Route::get('/', ListTaskInstancesAction::class);
            Route::post('/{task_instance}/claim', ClaimTaskInstanceAction::class);
            Route::post('/{task_instance}/complete', CompleteTaskInstanceAction::class);
            Route::post('/{task_instance}/grace-day', RequestTaskInstanceGraceDayAction::class);
            Route::post('/{task_instance}/offers', StoreTaskOfferAction::class);
        });

        Route::prefix('/task-offers')->group(function (): void {
            Route::post('/{task_offer}/accept', AcceptTaskOfferAction::class);
            Route::delete('/{task_offer}', CancelTaskOfferAction::class);
        });

        Route::prefix('/tasks')->group(function (): void {
            Route::get('/', ListHouseholdTasksAction::class);
            Route::post('/', StoreHouseholdTaskAction::class);
            Route::get('/one-off', ListOneOffHouseholdTasksAction::class);
            Route::post('/log', StoreCompletedHouseholdTaskAction::class);
            // The task templates are shared by every household.
            Route::post('/templates/{task_template}/log', StoreCompletedHouseholdTaskFromTemplateAction::class)->withoutScopedBindings();
            Route::post('/{task}/log', LogHouseholdTaskCompletionAction::class);
            Route::post('/{task}/open', OpenHouseholdTaskInstanceAction::class);
            Route::post('/{task}/user-weight', StoreTaskUserWeightAction::class);
            Route::post('/{task_template}', StoreHouseholdTaskFromTemplateAction::class)->withoutScopedBindings();
            Route::put('/{task}', UpdateHouseholdTaskAction::class);
            Route::delete('/{task}', DeleteHouseholdTaskAction::class);
        });
    });
});
