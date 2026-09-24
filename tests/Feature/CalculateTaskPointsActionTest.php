<?php

use App\Actions\CalculateTaskPointsAction;
use App\Enums\TaskUserWeightEnum;
use App\Models\TaskUserWeight;

function makeTaskUserWeight(TaskUserWeightEnum $weight = TaskUserWeightEnum::NEUTRAL, int $frequency = 0): TaskUserWeight
{
    return new TaskUserWeight(['weight' => $weight, 'frequency' => $frequency]);
}

beforeEach(function () {
    $this->freezeTime();
});

it('returns null when the user has no weight for the task', function () {
    expect(CalculateTaskPointsAction::make()->calculate(100, null))->toBeNull();
});

it('returns the base points for a neutral weight without a due date', function () {
    expect(CalculateTaskPointsAction::make()->calculate(100, makeTaskUserWeight()))->toBe(100);
});

it('applies the weight multiplier', function (TaskUserWeightEnum $weight, int $expected_points) {
    expect(CalculateTaskPointsAction::make()->calculate(100, makeTaskUserWeight($weight)))->toBe($expected_points);
})->with([
    'hate' => [TaskUserWeightEnum::HATE, 115],
    'love' => [TaskUserWeightEnum::LOVE, 85],
]);

it('applies the frequency multiplier', function (int $frequency, int $expected_points) {
    expect(CalculateTaskPointsAction::make()->calculate(100, makeTaskUserWeight(frequency: $frequency)))->toBe($expected_points);
})->with([
    'once' => [1, 90],
    'twice' => [2, 80],
    'many times' => [5, 70],
]);

it('does not add a bounty before the due date', function () {
    expect(CalculateTaskPointsAction::make()->calculate(100, makeTaskUserWeight(), now()->addDays(2)))->toBe(100);
});

it('adds a bounty for every overdue day', function () {
    expect(CalculateTaskPointsAction::make()->calculate(100, makeTaskUserWeight(), now()->subDays(3)))->toBe(115);
});

it('caps the overdue bounty', function () {
    expect(CalculateTaskPointsAction::make()->calculate(100, makeTaskUserWeight(), now()->subDays(20)))->toBe(130);
});

it('multiplies the points for solo completion', function () {
    expect(CalculateTaskPointsAction::make()->calculate(100, makeTaskUserWeight(), is_solo: true))->toBe(150);
});
