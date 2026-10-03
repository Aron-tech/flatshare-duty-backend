<?php

namespace App\Http\Requests;

use App\Enums\TaskUserWeightEnum;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreTaskUserWeightRequest extends FormRequest
{
    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'weight' => ['required', Rule::enum(TaskUserWeightEnum::class)],
        ];
    }
}
