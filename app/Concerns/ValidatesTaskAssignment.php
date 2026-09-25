<?php

namespace App\Concerns;

use App\Enums\TaskAssignmentModeEnum;
use Illuminate\Validation\Rules\Enum;
use Illuminate\Validation\Validator;

trait ValidatesTaskAssignment
{
    /**
     * @return array<string, array<mixed>>
     */
    protected function assignmentRules(): array
    {
        return [
            'assignment_mode' => ['nullable', new Enum(TaskAssignmentModeEnum::class)],
            'fixed_user_id' => ['nullable', 'required_if:assignment_mode,fixed', 'int', 'exists:users,id'],
            'rotation_user_ids' => ['nullable', 'array'],
            'rotation_user_ids.*' => ['int', 'distinct', 'exists:users,id'],
        ];
    }

    /**
     * Only members of the household can be assigned, and only recurring tasks can be assigned at all.
     */
    protected function validateAssignment(Validator $validator, bool $is_recurring): void
    {
        $mode = $this->input('assignment_mode');
        if ($mode && $mode !== TaskAssignmentModeEnum::NONE->value && ! $is_recurring) {
            $validator->errors()->add('assignment_mode', __('app.task_assignment_requires_recurring'));
        }

        $user_ids = array_filter([$this->input('fixed_user_id'), ...(array) $this->input('rotation_user_ids', [])]);
        if (! $user_ids || $validator->errors()->hasAny(['fixed_user_id', 'rotation_user_ids', 'rotation_user_ids.*'])) {
            return;
        }

        $member_count = $this->route('household')->householdUsers()->whereIn('user_id', $user_ids)->distinct()->count('user_id');
        if ($member_count !== count(array_unique($user_ids))) {
            $validator->errors()->add('assignment_mode', __('app.task_assignee_not_member'));
        }
    }
}
