<?php

namespace App\Actions\HouseholdStats;

use App\Enums\PointTransactionType;
use App\Models\Household;
use App\Models\PointTransaction;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Lorisleiva\Actions\Concerns\AsAction;

class ListHouseholdActivityAction
{
    use AsAction;

    private const int DEFAULT_LIMIT = 50;

    private const int MAX_LIMIT = 100;

    /**
     * Task completions, newest first, paged with a keyset cursor (the id of the last returned row),
     * so deep pages stay cheap regardless of how many completions the household has.
     *
     * @return array{
     *     data: list<array{id: int, user_id: int, user_name: string, is_me: bool, task_name: string, category_icon: ?string, points: int, completed_at: string}>,
     *     next_cursor: ?int,
     * }
     *
     * @throws AuthorizationException
     */
    public function handle(User $user, Household $household, int $limit = self::DEFAULT_LIMIT, ?int $cursor = null): array
    {
        if (! $user->households()->whereKey($household->id)->exists()) {
            throw new AuthorizationException(__('app.no_permission'));
        }

        $limit = max(1, min($limit, self::MAX_LIMIT));

        $transactions = PointTransaction::query()
            ->where('household_id', $household->id)
            ->where('type', PointTransactionType::TASK_COMPLETION)
            ->when($cursor, fn ($query) => $query->where('id', '<', $cursor))
            ->with(['user', 'taskInstance.task.category'])
            ->orderByDesc('id')
            ->limit($limit + 1)
            ->get();

        $has_more = $transactions->count() > $limit;
        $page = $transactions->take($limit);

        return [
            'data' => $page
                ->map(fn (PointTransaction $point_transaction) => [
                    'id' => $point_transaction->id,
                    'user_id' => $point_transaction->user_id,
                    'user_name' => $point_transaction->user->name,
                    'is_me' => $point_transaction->user_id === $user->id,
                    'task_name' => $point_transaction->taskInstance?->task->name ?? '',
                    'category_icon' => $point_transaction->taskInstance?->task->category?->icon,
                    'points' => $point_transaction->amount,
                    'completed_at' => $point_transaction->created_at->toIso8601String(),
                ])
                ->values()
                ->all(),
            'next_cursor' => $has_more ? $page->last()->id : null,
        ];
    }

    public function asController(Request $request, Household $household): JsonResponse
    {
        $validated = $request->validate([
            'cursor' => ['nullable', 'integer', 'min:1'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:'.self::MAX_LIMIT],
        ]);

        try {
            return response()->json($this->handle(
                $request->user(),
                $household,
                (int) ($validated['limit'] ?? self::DEFAULT_LIMIT),
                isset($validated['cursor']) ? (int) $validated['cursor'] : null,
            ));
        } catch (AuthorizationException $e) {
            return response()->json(['message' => $e->getMessage()], 403);
        } catch (\Throwable $e) {
            report($e);

            return response()->json(['message' => __('app.failed_action')], 500);
        }
    }
}
