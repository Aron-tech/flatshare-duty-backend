<?php

namespace App\Http\Requests;

use App\Enums\TaskUserWeightEnum;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Enum;

class StoreTaskUserWeightRequest extends FormRequest
{
    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'weight' => ['required', new Enum(TaskUserWeightEnum::class)],
        ];
    }
}
