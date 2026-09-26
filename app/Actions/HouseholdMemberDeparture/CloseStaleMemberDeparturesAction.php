<?php

namespace App\Actions\HouseholdMemberDeparture;

use App\Concerns\WritesCommandOutput;
use App\Models\HouseholdMemberDeparture;
use Illuminate\Console\Command;
use Lorisleiva\Actions\Concerns\AsAction;

class CloseStaleMemberDeparturesAction
{
    use AsAction;
    use WritesCommandOutput;

    public string $commandSignature = 'households:close-stale-departures {--output : Eredmény kiírása a konzolra}';

    public string $commandDescription = 'Lezárja a régóta eldöntetlen tag-távozásokat, a távozott tag feladatai megmaradnak.';

    /**
     * Closes the departures no admin decided about in time, as if they kept every task, so the question does not stay open forever.
     *
     * @return int the number of closed departures
     */
    public function handle(): int
    {
        return HouseholdMemberDeparture::query()
            ->unresolved()
            ->where('created_at', '<=', now()->subDays(HouseholdMemberDeparture::AUTO_CLOSE_DAYS))
            ->update(['resolved_at' => now()]);
    }

    public function asCommand(Command $command): void
    {
        $this->writeOutput($command, "Lezárt távozások: {$this->handle()}");
    }
}
