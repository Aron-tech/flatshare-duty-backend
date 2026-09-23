<?php

use App\Actions\AuthenticateWorkOsUserAction;
use App\Actions\Household\DeleteHouseholdAction;
use App\Actions\Household\GenerateHouseholdQrCodeAction;
use App\Actions\Household\JoinHouseholdAction;
use App\Actions\Household\LeaveHouseholdAction;
use App\Actions\Household\ListHouseholdsAction;
use App\Actions\Household\RenameHouseholdAction;
use App\Actions\Household\StoreHouseholdAction;
use App\Actions\HouseholdTask\DeleteHouseholdTaskAction;
use App\Actions\HouseholdTask\ListHouseholdTasksAction;
use App\Actions\HouseholdTask\StoreHouseholdTaskAction;
use App\Actions\HouseholdUser\DeleteHouseholdUserAction;
use App\Actions\HouseholdUser\ListHouseholdUsersAction;
use App\Actions\HouseholdUser\UpdateHouseholdUserAction;
use App\Actions\TaskTemplate\ListTaskTemplatesAction;
use App\Http\Controllers\Api\UserController;
use Illuminate\Support\Facades\Route;

Route::post('/auth/workos', AuthenticateWorkOsUserAction::class);

Route::middleware('auth:sanctum')->group(function () {
    Route::get('user/me', [UserController::class, 'me']);

    Route::prefix('households')->group(function () {
        Route::get('/', ListHouseholdsAction::class);
        Route::post('/', StoreHouseholdAction::class);
        Route::put('/{household}', RenameHouseholdAction::class);
        Route::get('/{household}/qrcode', GenerateHouseholdQrCodeAction::class);
        Route::post('/join', JoinHouseholdAction::class);
        Route::delete('/{household}/leave', LeaveHouseholdAction::class);
        Route::delete('/{household}', DeleteHouseholdAction::class);

        Route::get('/{household}/users', ListHouseholdUsersAction::class);

        Route::get('{household}/tasks', ListHouseholdTasksAction::class);
        Route::post('{household}/tasks', StoreHouseholdTaskAction::class);
        Route::post('{household}/tasks/{task_template}', StoreHouseholdTaskAction::class);
        Route::delete('{household}/tasks', DeleteHouseholdTaskAction::class);
    });

    Route::prefix('task-templates')->group(function () {
        Route::get('/', ListTaskTemplatesAction::class);
    });

    Route::prefix('household-users')->group(function () {
        Route::put('/{household_user}', UpdateHouseholdUserAction::class);
        Route::delete('/{household_user}', DeleteHouseholdUserAction::class);
    });
});
