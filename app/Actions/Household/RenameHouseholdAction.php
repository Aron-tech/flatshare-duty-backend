<?php

namespace App\Actions\Household;

use App\Http\Requests\RenameHouseholdRequest;
use App\Models\Household;
use Illuminate\Routing\Attributes\Controllers\Authorize;
use Illuminate\Support\Facades\DB;
use Lorisleiva\Actions\Concerns\AsAction;

#[Authorize('update', 'household')]
class RenameHouseholdAction
{
    use AsAction;

    /**
     * @param  array{name: string}  $data
     */
    public function handle(Household $household, array $data): Household
    {
        DB::transaction(fn (): bool => $household->update($data));

        return $household;
    }

    /**
     * @return array{household: Household, message: string}
     */
    public function asController(RenameHouseholdRequest $request, Household $household): array
    {
        return ['household' => $this->handle($household, $request->validated()), 'message' => __('app.success_action')];
    }
}
