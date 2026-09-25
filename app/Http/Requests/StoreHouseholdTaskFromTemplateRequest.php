<?php

namespace App\Http\Requests;

use App\Concerns\ValidatesTaskAssignment;
use App\Enums\RecurrenceUnitEnum;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Enum;
use Illuminate\Validation\Validator;

class StoreHouseholdTaskFromTemplateRequest extends FormRequest
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
            'is_recurring' => ['nullable', 'boolean'],
            'recurrence_interval' => ['nullable', 'required_if_accepted:is_recurring', 'int', 'min:1'],
            'recurrence_unit' => ['nullable', 'required_if_accepted:is_recurring', new Enum(RecurrenceUnitEnum::class)],
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
                $task_template = $this->route('task_template');
                $already_exists = $this->route('household')->tasks()
                    ->where(function ($query) use ($task_template) {
                        $query->where('task_template_id', $task_template->id);
                        foreach ($task_template->getTranslations('name') as $name) {
                            $query->orWhere(fn ($query) => $query->named($name));
                        }
                    })
                    ->exists();

                if ($already_exists) {
                    $validator->errors()->add('task_template', __('app.task_already_exists'));
                }
            },
        ];
    }
}
