<?php

namespace App\Http\Requests;

use App\Enums\ResetPeriodEnum;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateHouseholdSettingsRequest extends FormRequest
{
    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'reset_period' => ['required', Rule::enum(ResetPeriodEnum::class)],
            'reset_day_of_week' => ['required_if:reset_period,'.ResetPeriodEnum::WEEKLY->value, 'nullable', 'integer', 'between:1,7'],
            'reset_day_of_month' => ['required_if:reset_period,'.ResetPeriodEnum::MONTHLY->value, 'nullable', 'integer', 'between:1,28'],
        ];
    }
}
