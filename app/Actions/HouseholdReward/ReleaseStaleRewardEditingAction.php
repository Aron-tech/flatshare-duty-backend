<?php

namespace App\Actions\HouseholdReward;

use App\Concerns\WritesCommandOutput;
use App\Models\Reward;
use Illuminate\Console\Command;
use Lorisleiva\Actions\Concerns\AsAction;

class ReleaseStaleRewardEditingAction
{
    use AsAction;
    use WritesCommandOutput;

    public string $commandSignature = 'rewards:release-stale-editing {--output : Eredmény kiírása a konzolra}';

    public string $commandDescription = 'Visszaállítja az is_editing-et false-ra a túl régóta szerkesztés alatt álló jutalmaknál.';

    /**
     * Releases the editings left open longer than the timeout, e.g. when the app was closed during editing.
     *
     * @return int the number of released rewards
     */
    public function handle(): int
    {
        return Reward::query()
            ->where('is_editing', true)
            ->where(fn ($query) => $query
                ->whereNull('editing_started_at')
                ->orWhere('editing_started_at', '<=', now()->subMinutes(Reward::EDITING_TIMEOUT_MINUTES)))
            ->update(['is_editing' => false, 'editing_started_at' => null]);
    }

    public function asCommand(Command $command): void
    {
        $this->writeOutput($command, "Felszabadított jutalmak: {$this->handle()}");
    }
}
