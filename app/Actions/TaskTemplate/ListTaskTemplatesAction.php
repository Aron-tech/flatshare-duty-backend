<?php

namespace App\Actions\TaskTemplate;

use App\Models\TaskTemplate;
use Illuminate\Support\Collection;
use Lorisleiva\Actions\Concerns\AsAction;

class ListTaskTemplatesAction
{
    use AsAction;

    /**
     * The task templates in the current locale, grouped by their category name. They come from the cache, see TaskTemplate::cachedInLocale().
     *
     * @return Collection<string, Collection<int, array<string, mixed>>>
     */
    public function handle(): Collection
    {
        return collect(TaskTemplate::cachedInLocale())
            ->groupBy(fn (array $task_template): string => $task_template['category']['name'] ?? __('app.other'));
    }

    /**
     * @return array{task_templates: Collection<string, Collection<int, array<string, mixed>>>}
     */
    public function asController(): array
    {
        return ['task_templates' => $this->handle()];
    }
}
