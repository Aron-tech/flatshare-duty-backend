<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class UpdateHouseholdRewardRequest extends FormRequest
{
    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'string', 'min:3', 'max:125'],
            'description' => ['sometimes', 'nullable', 'string', 'max:1000'],
            'points_cost' => ['sometimes', 'int', 'min:1', 'max:1000000'],
            'stock_quantity' => ['sometimes', 'nullable', 'int', 'min:0'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }
}
