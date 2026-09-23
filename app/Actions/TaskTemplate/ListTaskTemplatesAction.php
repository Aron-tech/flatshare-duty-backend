<?php

namespace App\Actions\TaskTemplate;

use App\Models\TaskTemplate;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Lorisleiva\Actions\Concerns\AsAction;

class ListTaskTemplatesAction
{
    use AsAction;

    public function handle(): Collection
    {
        return TaskTemplate::query()->with('category')->get()->groupBy(fn ($task) => $task->category?->name ?? __('app.other'));
    }

    public function asController(Request $request): JsonResponse
    {
        try {
            $task_templates = $this->handle();

            return response()->json(['task_templates' => $task_templates]);
        } catch (AuthorizationException $e) {
            return response()->json(['message' => $e->getMessage()], 403);
        } catch (\Throwable $e) {
            report($e);

            return response()->json(['message' => __('app.failed_action')], 500);
        }
    }
}
