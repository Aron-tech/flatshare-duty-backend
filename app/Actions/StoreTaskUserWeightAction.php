<?php

namespace App\Actions;

use App\Http\Requests\StoreTaskUserWeightRequest;
use App\Models\Household;
use App\Models\Task;
use App\Models\TaskUserWeight;
use App\Models\User;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Routing\Attributes\Controllers\Authorize;
use Illuminate\Support\Facades\DB;
use Lorisleiva\Actions\Concerns\AsAction;

#[Authorize('view', 'household')]
class StoreTaskUserWeightAction
{
    use AsAction;

    /**
     * @param  array{weight: string}  $data
     */
    public function handle(User $user, Task $task, array $data): TaskUserWeight
    {
        return DB::transaction(fn (): TaskUserWeight => TaskUserWeight::updateOrCreate(
            [
                'household_id' => $task->household_id,
                'user_id' => $user->id,
                'task_id' => $task->id,
            ],
            [
                'weight' => $data['weight'],
            ]
        ));
    }

    /**
     * @return array{household: Household, message: string}
     */
    public function asController(StoreTaskUserWeightRequest $request, #[CurrentUser] User $user, Household $household, Task $task): array
    {
        $this->handle($user, $task, $request->validated());

        return ['household' => $household, 'message' => __('app.success_action')];
    }
}
