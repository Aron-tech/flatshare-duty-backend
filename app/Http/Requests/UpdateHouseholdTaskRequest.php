<?php

namespace App\Http\Requests;

use App\Concerns\ValidatesTaskAssignment;
use App\Enums\RecurrenceUnitEnum;
use App\Enums\TaskDifficultyEnum;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class UpdateHouseholdTaskRequest extends FormRequest
{
    use ValidatesTaskAssignment;

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'string', 'min:3', 'max:125'],
            'description' => ['sometimes', 'nullable', 'string'],
            'category_id' => ['sometimes', 'nullable', 'int', 'exists:categories,id'],
            'icon' => ['sometimes', 'nullable', 'string', 'max:255'],
            'duration_minutes' => ['sometimes', 'int', 'min:1'],
            'difficulty' => ['sometimes', Rule::enum(TaskDifficultyEnum::class)],
            'is_recurring' => ['sometimes', 'boolean'],
            'recurrence_interval' => ['nullable', 'required_if_accepted:is_recurring', 'int', 'min:1'],
            'recurrence_unit' => ['nullable', 'required_if_accepted:is_recurring', Rule::enum(RecurrenceUnitEnum::class)],
            'max_user' => ['sometimes', 'int', 'min:1'],
            ...$this->assignmentRules(),
        ];
    }

    /**
     * @return array<int, callable(Validator): void>
     */
    public function after(): array
    {
        return [
            fn (Validator $validator) => $this->validateAssignment($validator, $this->has('is_recurring') ? $this->boolean('is_recurring') : (bool) $this->route('task')->is_recurring),
            function (Validator $validator) {
                if (! $this->has('name') || $validator->errors()->has('name')) {
                    return;
                }

                $already_exists = $this->route('household')->tasks()
                    ->whereKeyNot($this->route('task')->id)
                    ->named($this->string('name'))
                    ->exists();

                if ($already_exists) {
                    $validator->errors()->add('name', __('app.task_already_exists'));
                }
            },
        ];
    }
}
