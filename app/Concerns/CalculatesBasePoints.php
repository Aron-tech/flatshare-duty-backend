<?php

namespace App\Concerns;

use App\Enums\TaskDifficultyEnum;

/**
 * The base points of a task or a task template come from its duration and difficulty.
 *
 * @property TaskDifficultyEnum $difficulty
 * @property int $duration_minutes
 * @property int $base_points
 */
trait CalculatesBasePoints
{
    public function calculateBasePoints(): static
    {
        $this->base_points = $this->difficulty->basePoints($this->duration_minutes);

        return $this;
    }
}
