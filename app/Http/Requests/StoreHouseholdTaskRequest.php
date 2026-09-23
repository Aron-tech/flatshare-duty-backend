<?php

namespace App\Http\Requests;

use App\Enums\RecurrenceUnitEnum;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Enum;

class StoreHouseholdTaskRequest extends FormRequest
{
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
            'base_points' => ['required', 'int', 'min:1'],
            'is_recurring' => ['nullable', 'boolean'],
            'recurrence_interval' => ['nullable', 'int', 'min:1'],
            'recurrence_unit' => ['nullable', new Enum(RecurrenceUnitEnum::class)]
        ];
    }
}
