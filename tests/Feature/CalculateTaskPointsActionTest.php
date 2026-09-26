<?php

use App\Actions\CalculateTaskPointsAction;
use App\Enums\TaskUserWeightEnum;
use App\Models\Task;
use App\Models\TaskInstance;
use App\Models\TaskUserWeight;

/**
 * @param  array<int, TaskUserWeightEnum>  $weights  keyed by user id
 */
function makeTaskWithUserWeights(array $weights, int $base_points = 100): Task
{
    return (new Task(['base_points' => $base_points]))->setRelation('userWeights', collect($weights)->map(
        fn (TaskUserWeightEnum $weight, int $user_id) => new TaskUserWeight(['user_id' => $user_id, 'weight' => $weight])
    )->values());
}

beforeEach(function () {
    $this->freezeTime();
});

it('returns the base points for a neutral weight without a due date', function () {
    expect(CalculateTaskPointsAction::make()->calculate(100))->toBe(100);
});

it('applies the weight multiplier', function () {
    expect(CalculateTaskPointsAction::make()->calculate(100, 1.15))->toBe(115);
});

it('does not add a bounty before the due date', function () {
    expect(CalculateTaskPointsAction::make()->calculate(100, due_at: now()->addDays(2)))->toBe(100);
});

it('adds a bounty for every overdue day', function () {
    expect(CalculateTaskPointsAction::make()->calculate(100, due_at: now()->subDays(3)))->toBe(115);
});

it('caps the overdue bounty', function () {
    expect(CalculateTaskPointsAction::make()->calculate(100, due_at: now()->subDays(20)))->toBe(130);
});

it('averages the weights of the members, a missing weight counts as neutral', function (array $weights, array $member_ids, int $expected_points) {
    expect(CalculateTaskPointsAction::run(makeTaskWithUserWeights($weights), collect($member_ids)))->toBe($expected_points);
})->with([
    'everyone hates it' => [[1 => TaskUserWeightEnum::HATE, 2 => TaskUserWeightEnum::HATE], [1, 2], 125],
    'hate and love' => [[1 => TaskUserWeightEnum::HATE, 2 => TaskUserWeightEnum::LOVE], [1, 2], 100],
    'one member hates it' => [[1 => TaskUserWeightEnum::HATE], [1, 2, 3], 108],
    'former member ignored' => [[1 => TaskUserWeightEnum::HATE, 9 => TaskUserWeightEnum::LOVE], [1], 125],
]);

it('calculates the points from a task instance including the overdue bounty', function () {
    $task_instance = (new TaskInstance(['due_at' => now()->subDays(3)]))
        ->setRelation('task', makeTaskWithUserWeights([1 => TaskUserWeightEnum::NEUTRAL]));

    expect(CalculateTaskPointsAction::run($task_instance, collect([1])))->toBe(115)
        ->and(CalculateTaskPointsAction::run($task_instance, collect([1]), false))->toBe(100);
});
