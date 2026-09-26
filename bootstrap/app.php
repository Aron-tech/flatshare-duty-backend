<?php

use App\Actions\HouseholdMemberDeparture\CloseStaleMemberDeparturesAction;
use App\Actions\HouseholdReward\ReleaseStaleRewardEditingAction;
use App\Actions\RecurringTask\GenerateRecurringTaskInstancesAction;
use App\Actions\RecurringTask\ReleaseOverdueTaskClaimsAction;
use App\Actions\TaskOffer\ExpireTaskOffersAction;
use App\Actions\TaskWeightNotification\NotifyUnweightedTasksAction;
use App\Actions\WeeklyPointGoal\CloseWeeklyPointGoalsAction;
use App\Actions\WeeklyPointGoal\RemindWeeklyPointGoalsAction;
use App\Models\Household;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withCommands([
        NotifyUnweightedTasksAction::class,
        ReleaseStaleRewardEditingAction::class,
        CloseWeeklyPointGoalsAction::class,
        RemindWeeklyPointGoalsAction::class,
        GenerateRecurringTaskInstancesAction::class,
        ReleaseOverdueTaskClaimsAction::class,
        ExpireTaskOffersAction::class,
        CloseStaleMemberDeparturesAction::class,
    ])
    ->withMiddleware(function (Middleware $middleware): void {
        //
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request): bool => $request->is('api/*') || $request->expectsJson(),
        );

        // A missing household (a deleted one or a wrong join code) has its own message.
        $exceptions->render(function (NotFoundHttpException $e): mixed {
            $previous = $e->getPrevious();

            return $previous instanceof ModelNotFoundException && $previous->getModel() === Household::class
                ? response()->json(['message' => __('app.not_found_household')], Response::HTTP_NOT_FOUND)
                : null;
        });

        // Unexpected errors are reported, the client only gets a general message, since the exception may contain internal details (e.g. SQL).
        $exceptions->respond(
            fn (Response $response, Throwable $e, Request $request): Response => $response->getStatusCode() === Response::HTTP_INTERNAL_SERVER_ERROR && $request->is('api/*') && ! config('app.debug')
                ? response()->json(['message' => __('app.failed_action')], Response::HTTP_INTERNAL_SERVER_ERROR)
                : $response,
        );
    })->create();
