<?php

namespace App\Models;

use App\Concerns\DataTrait;
use App\Concerns\LogsModelActivity;
use App\Enums\RecurrenceUnitEnum;
use App\Enums\TaskAssignmentModeEnum;
use App\Enums\TaskDifficultyEnum;
use App\Observers\TaskObserver;
use App\Observers\WeeklyPointGoalObserver;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

#[Fillable(['task_template_id', 'household_id', 'created_by', 'name', 'description', 'category_id', 'icon', 'duration_minutes', 'difficulty', 'base_points', 'is_recurring', 'recurrence_interval', 'recurrence_unit', 'assignment_mode', 'fixed_user_id', 'last_assigned_user_id', 'max_user', 'data'])]
#[ObservedBy([TaskObserver::class, WeeklyPointGoalObserver::class])]
class Task extends Model
{
    use DataTrait;
    use LogsModelActivity;
    use SoftDeletes;

    protected function casts(): array
    {
        return [
            'duration_minutes' => 'int',
            'difficulty' => TaskDifficultyEnum::class,
            'base_points' => 'int',
            'recurrence_interval' => 'int',
            'is_recurring' => 'boolean',
            'recurrence_unit' => RecurrenceUnitEnum::class,
            'assignment_mode' => TaskAssignmentModeEnum::class,
            'max_user' => 'int',
            'data' => 'array',
        ];
    }

    public function calculateBasePoints(): self
    {
        $this->base_points = (int) round($this->difficulty->multiplier() * $this->duration_minutes);

        return $this;
    }

    /**
     * Case-insensitive match on the task name, used to prevent duplicate tasks in a household.
     */
    #[Scope]
    protected function named(Builder $query, string $name): void
    {
        $query->whereRaw('lower(name) = ?', [mb_strtolower(trim($name))]);
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

    public function taskInstances(): HasMany
    {
        return $this->hasMany(TaskInstance::class);
    }

    public function fixedUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'fixed_user_id');
    }

    public function rotations(): HasMany
    {
        return $this->hasMany(TaskUserRotation::class)->orderBy('rotation_order');
    }

    public function userWeights(): HasMany
    {
        return $this->hasMany(TaskUserWeight::class);
    }
}
