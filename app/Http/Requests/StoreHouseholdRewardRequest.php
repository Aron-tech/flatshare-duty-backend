<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class StoreHouseholdRewardRequest extends FormRequest
{
    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'min:3', 'max:125'],
            'description' => ['nullable', 'string', 'max:1000'],
            'points_cost' => ['required', 'int', 'min:1', 'max:1000000'],
            'stock_quantity' => ['nullable', 'int', 'min:0'],
            'is_active' => ['nullable', 'boolean'],
        ];
    }
}
