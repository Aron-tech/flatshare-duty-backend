<?php

namespace App\Http\Requests;

use App\Enums\RoleEnum;
use App\Models\HouseholdUser;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Enum;

class UpdateHouseholdUserRequest extends FormRequest
{
    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'role' => [new Enum(RoleEnum::class)],
        ];
    }
}
