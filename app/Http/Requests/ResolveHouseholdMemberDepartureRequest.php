<?php

namespace App\Http\Requests;

use App\Models\HouseholdMemberDeparture;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ResolveHouseholdMemberDepartureRequest extends FormRequest
{
    /**
     * The tasks to delete, an empty list keeps all of them. Only the tasks created by the former member can be chosen.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        /** @var HouseholdMemberDeparture $departure */
        $departure = $this->route('member_departure');

        return [
            'task_ids' => ['present', 'array'],
            'task_ids.*' => [
                'integer',
                'distinct',
                Rule::exists('tasks', 'id')
                    ->where('household_id', $departure->household_id)
                    ->where('created_by', $departure->user_id)
                    ->withoutTrashed(),
            ],
        ];
    }
}
