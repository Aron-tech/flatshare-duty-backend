<?php

namespace App\Models;

use App\Concerns\DataTrait;
use App\Enums\RecurrenceUnitEnum;
use App\Enums\TaskDifficultyEnum;
use App\Observers\TaskObserver;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['task_template_id', 'household_id', 'created_by', 'name', 'description', 'category_id', 'icon', 'duration_minutes', 'difficulty', 'base_points', 'is_recurring', 'recurrence_interval', 'recurrence_unit', 'max_user', 'data'])]
#[ObservedBy([TaskObserver::class])]
class Task extends Model
{
    use DataTrait;

    protected function casts(): array
    {
        return [
            'duration_minutes' => 'int',
            'difficulty' => TaskDifficultyEnum::class,
            'base_points' => 'int',
            'recurrence_interval' => 'int',
            'is_recurring' => 'boolean',
            'recurrence_unit' => RecurrenceUnitEnum::class,
            'max_user' => 'int',
            'data' => 'array',
        ];
    }

    public function calculateBasePoints(): self
    {
        $this->base_points = round($this->difficulty * $this->duration_minutes);

        return $this;
    }

    public static function loadFromTemplate(TaskTemplate $task_template, array $data = [], ?string $locale = null): self
    {
        $locale = $locale ?? app()->getLocale();
        $template_attributes = $task_template->only(array_diff($task_template->getFillable(), ['name', 'description']));

        $translations = [
            'name' => $task_template->getTranslation('name', $locale),
            'description' => $task_template->getTranslation('description', $locale),
        ];

        return (new self)->forceFill(array_merge($template_attributes, $translations, $data));
    }

    public function taskTemplate(): BelongsTo
    {
        return $this->belongsTo(TaskTemplate::class);
    }

    public function household(): BelongsTo
    {
        return $this->belongsTo(Household::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    public function userWeights(): HasMany
    {
        return $this->hasMany(TaskUserWeight::class);
    }
}
