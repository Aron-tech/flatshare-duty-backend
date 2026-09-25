<?php

namespace App\Http\Requests;

use App\Concerns\ValidatesTaskAssignment;
use App\Enums\RecurrenceUnitEnum;
use App\Enums\TaskDifficultyEnum;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Enum;
use Illuminate\Validation\Validator;

class StoreHouseholdTaskRequest extends FormRequest
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
            'task_template_id' => ['nullable', 'int', 'exists:task_templates,id'],
            'name' => ['required', 'string', 'min:3', 'max:125'],
            'description' => [],
            'category_id' => ['nullable', 'int', 'exists:categories,id'],
            'icon' => ['nullable', 'string', 'max:255'],
            'duration_minutes' => ['required', 'int', 'min:1'],
            'difficulty' => ['required', new Enum(TaskDifficultyEnum::class)],
            'is_recurring' => ['nullable', 'boolean'],
            'recurrence_interval' => ['nullable', 'int', 'min:1'],
            'recurrence_unit' => ['nullable', new Enum(RecurrenceUnitEnum::class)],
            'max_user' => ['nullable', 'int', 'min:1'],
            ...$this->assignmentRules(),
        ];
    }

    /**
     * @return array<int, callable(Validator): void>
     */
    public function after(): array
    {
        return [
            fn (Validator $validator) => $this->validateAssignment($validator, $this->boolean('is_recurring')),
            function (Validator $validator) {
                if ($validator->errors()->has('name')) {
                    return;
                }

                if ($this->route('household')->tasks()->named($this->string('name'))->exists()) {
                    $validator->errors()->add('name', __('app.task_already_exists'));
                }
            },
        ];
    }
}
