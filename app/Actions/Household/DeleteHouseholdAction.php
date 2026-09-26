<?php

namespace App\Actions\Household;

use App\Models\Household;
use Illuminate\Routing\Attributes\Controllers\Authorize;
use Illuminate\Support\Facades\DB;
use Lorisleiva\Actions\Concerns\AsAction;

#[Authorize('delete', 'household')]
class DeleteHouseholdAction
{
    use AsAction;

    public function handle(Household $household): bool
    {
        return DB::transaction(fn (): bool => (bool) $household->delete());
    }

    /**
     * @return array{message: string}
     */
    public function asController(Household $household): array
    {
        $this->handle($household);

        return ['message' => __('app.success_action')];
    }
}
