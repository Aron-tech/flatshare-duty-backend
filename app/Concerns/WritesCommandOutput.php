<?php

namespace App\Concerns;

use Illuminate\Console\Command;

trait WritesCommandOutput
{
    /**
     * Commands are silent by default (scheduler runs); the --output option enables console output.
     */
    protected function writeOutput(Command $command, string $message): void
    {
        if ($command->option('output')) {
            $command->info($message);
        }
    }
}
