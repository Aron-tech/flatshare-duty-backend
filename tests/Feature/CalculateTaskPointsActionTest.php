<?php

use App\Actions\CalculateTaskPointsAction;
use App\Enums\TaskUserWeightEnum;
use App\Models\Task;
use App\Models\TaskInstance;
use App\Models\TaskUserWeight;
use App\Models\User;

function makeTaskWithUserWeight(User $user, TaskUserWeightEnum $weight = TaskUserWeightEnum::NEUTRAL, int $base_points = 100): Task
{
    return (new Task(['base_points' => $base_points]))
        ->setRelation('userWeights', collect([new TaskUserWeight(['user_id' => $user->id, 'weight' => $weight])]));
}

beforeEach(function () {
    $this->freezeTime();
    $this->user = (new User)->forceFill(['id' => 1]);
});

it('returns null when the user has no weight for the task', function () {
    expect(CalculateTaskPointsAction::make()->calculate(100, null))->toBeNull();
});

it('returns the base points for a neutral weight without a due date', function () {
    expect(CalculateTaskPointsAction::make()->calculate(100, TaskUserWeightEnum::NEUTRAL))->toBe(100);
});

it('applies the weight multiplier', function (TaskUserWeightEnum $weight, int $expected_points) {
    expect(CalculateTaskPointsAction::make()->calculate(100, $weight))->toBe($expected_points);
})->with([
    'hate' => [TaskUserWeightEnum::HATE, 115],
    'love' => [TaskUserWeightEnum::LOVE, 85],
]);

it('applies the frequency multiplier', function (int $frequency, int $expected_points) {
    expect(CalculateTaskPointsAction::make()->calculate(100, TaskUserWeightEnum::NEUTRAL, $frequency))->toBe($expected_points);
})->with([
    'once' => [1, 90],
    'twice' => [2, 80],
    'many times' => [5, 70],
]);

it('does not add a bounty before the due date', function () {
    expect(CalculateTaskPointsAction::make()->calculate(100, TaskUserWeightEnum::NEUTRAL, due_at: now()->addDays(2)))->toBe(100);
});

it('adds a bounty for every overdue day', function () {
    expect(CalculateTaskPointsAction::make()->calculate(100, TaskUserWeightEnum::NEUTRAL, due_at: now()->subDays(3)))->toBe(115);
});

it('caps the overdue bounty', function () {
    expect(CalculateTaskPointsAction::make()->calculate(100, TaskUserWeightEnum::NEUTRAL, due_at: now()->subDays(20)))->toBe(130);
});

it('multiplies the points for solo completion', function () {
    expect(CalculateTaskPointsAction::make()->calculate(100, TaskUserWeightEnum::NEUTRAL, is_solo: true))->toBe(150);
});

it('calculates the points from a task', function () {
    $task = makeTaskWithUserWeight($this->user, TaskUserWeightEnum::HATE)->setData('frequency', 2);

    expect(CalculateTaskPointsAction::run($task, $this->user))->toBe(92);
});

it('calculates the points from a task instance including the overdue bounty', function () {
    $task_instance = (new TaskInstance(['due_at' => now()->subDays(3)]))
        ->setRelation('task', makeTaskWithUserWeight($this->user));

    expect(CalculateTaskPointsAction::run($task_instance, $this->user))->toBe(115);
});

it('returns null when the user has no weight for the given task', function () {
    $other_user = (new User)->forceFill(['id' => 2]);

    expect(CalculateTaskPointsAction::run(makeTaskWithUserWeight($other_user), $this->user))->toBeNull();
});
