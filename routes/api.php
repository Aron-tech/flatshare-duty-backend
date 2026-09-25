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
use App\Actions\HouseholdReward\CalculateRewardDifficultyAction;
use App\Actions\HouseholdReward\DeleteHouseholdRewardAction;
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
use App\Actions\StoreTaskUserWeightAction;
use App\Actions\TaskTemplate\ListTaskTemplatesAction;
use App\Http\Controllers\Api\UserController;
use App\Http\Middleware\SetAppLocaleMiddleware;
use Illuminate\Support\Facades\Route;

Route::post('/auth/workos', AuthenticateWorkOsUserAction::class);

// A nyelv a hitelesített user `language` mezőjéből jön, ezért az auth után fut.
Route::middleware(['auth:sanctum', SetAppLocaleMiddleware::class])->group(function () {
    Route::get('user/me', [UserController::class, 'me']);
    Route::put('user/me', [UserController::class, 'update']);

    Route::prefix('push-tokens')->group(function () {
        Route::post('/', StorePushTokenAction::class);
        Route::delete('/', DeletePushTokenAction::class);
    });

    Route::prefix('households')->group(function () {
        Route::get('/', ListHouseholdsAction::class);
        Route::post('/', StoreHouseholdAction::class);
        Route::put('/{household}', RenameHouseholdAction::class);
        Route::get('/{household}/qrcode', GenerateHouseholdQrCodeAction::class);
        Route::post('/join', JoinHouseholdAction::class);
        Route::delete('/{household}/leave', LeaveHouseholdAction::class);
        Route::delete('/{household}', DeleteHouseholdAction::class);

        Route::get('/{household}/users', ListHouseholdUsersAction::class);
        Route::get('/{household}/members', ListHouseholdMembersAction::class);
        Route::get('/{household}/me', GetHouseholdUserAction::class);
        Route::get('/{household}/stats', GetHouseholdStatsAction::class);
        Route::get('/{household}/activity', ListHouseholdActivityAction::class);

        Route::prefix('/{household}/rewards')->group(function () {
            Route::get('/', ListHouseholdRewardsAction::class);
            Route::post('/', StoreHouseholdRewardAction::class);
            Route::get('/difficulty', CalculateRewardDifficultyAction::class);
            Route::put('/{reward}', UpdateHouseholdRewardAction::class);
            Route::delete('/{reward}', DeleteHouseholdRewardAction::class);
            Route::post('/{reward}/editing', StartRewardEditingAction::class);
            Route::delete('/{reward}/editing', StopRewardEditingAction::class);
            Route::post('/{reward}/redeem', RedeemHouseholdRewardAction::class);
        });

        Route::prefix('/{household}/task-instances')->group(function () {
            Route::get('/', ListTaskInstancesAction::class);
            Route::post('/{task_instance}/claim', ClaimTaskInstanceAction::class);
            Route::post('/{task_instance}/complete', CompleteTaskInstanceAction::class);
        });

        Route::prefix('/{household}/tasks')->group(function () {
            Route::get('/', ListHouseholdTasksAction::class);
            Route::post('/', StoreHouseholdTaskAction::class);
            Route::get('/one-off', ListOneOffHouseholdTasksAction::class);
            Route::post('/log', StoreCompletedHouseholdTaskAction::class);
            Route::post('/templates/{task_template}/log', StoreCompletedHouseholdTaskFromTemplateAction::class);
            Route::post('/{task}/log', LogHouseholdTaskCompletionAction::class);
            Route::post('/{task}/open', OpenHouseholdTaskInstanceAction::class);
            Route::post('/{task_template}', StoreHouseholdTaskFromTemplateAction::class);
            Route::put('/{task}', UpdateHouseholdTaskAction::class);
            Route::delete('/{task}', DeleteHouseholdTaskAction::class);

            Route::prefix('/{task}/user-weight')->group(function () {
                Route::post('/', StoreTaskUserWeightAction::class);
            });
        });
    });

    Route::prefix('task-templates')->group(function () {
        Route::get('/', ListTaskTemplatesAction::class);
    });

    Route::prefix('household-users')->group(function () {
        Route::put('/{household_user}', UpdateHouseholdUserAction::class);
        Route::delete('/{household_user}', DeleteHouseholdUserAction::class);
    });
});
