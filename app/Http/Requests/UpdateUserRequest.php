<?php

namespace App\Http\Requests;

use App\Enums\LanguageEnum;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Enum;

class UpdateUserRequest extends FormRequest
{
    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'nickname' => ['nullable', 'string', 'min:3', 'max:255'],
            'language' => ['sometimes', new Enum(LanguageEnum::class)],
        ];
    }
}
