<?php

namespace App\Actions\Household;

use App\Actions\WeeklyPointGoal\RecalculateWeeklyPointGoalsAction;
use App\Enums\RoleEnum;
use App\Http\Requests\JoinHouseholdRequest;
use App\Models\Household;
use App\Models\User;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Lorisleiva\Actions\Concerns\AsAction;

class JoinHouseholdAction
{
    use AsAction;

    /**
     * @throws ModelNotFoundException<Household> when no household has the join code
     */
    public function handle(User $user, string $join_code): Household
    {
        $household = Household::query()->where('join_code', $join_code)->firstOrFail();

        DB::transaction(
            fn (): array => $user->households()->syncWithoutDetaching([$household->id => ['role' => RoleEnum::USER]])
        );
        RecalculateWeeklyPointGoalsAction::run($household);

        return $household;
    }

    /**
     * @return array{message: string}
     */
    public function asController(JoinHouseholdRequest $request, #[CurrentUser] User $user): array
    {
        $this->handle($user, $request->validated('code'));

        return ['message' => __('app.success_action')];
    }
}
