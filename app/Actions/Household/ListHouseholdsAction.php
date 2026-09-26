<?php

namespace App\Actions\Household;

use App\Models\Household;
use App\Models\User;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Database\Eloquent\Collection;
use Lorisleiva\Actions\Concerns\AsAction;

class ListHouseholdsAction
{
    use AsAction;

    /**
     * @return Collection<int, Household>
     */
    public function handle(User $user): Collection
    {
        return $user->households;
    }

    /**
     * @return array{households: Collection<int, Household>}
     */
    public function asController(#[CurrentUser] User $user): array
    {
        return ['households' => $this->handle($user)];
    }
}
