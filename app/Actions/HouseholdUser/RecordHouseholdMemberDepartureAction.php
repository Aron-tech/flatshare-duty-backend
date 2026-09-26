<?php

namespace App\Actions\HouseholdUser;

use App\Actions\PushToken\SendPushNotificationAction;
use App\Enums\RoleEnum;
use App\Models\Household;
use App\Models\HouseholdMemberDeparture;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Lorisleiva\Actions\Concerns\AsAction;

class RecordHouseholdMemberDepartureAction
{
    use AsAction;

    /**
     * Records the departure of a member who created tasks in the household, so the admins can decide which of them to delete,
     * see ResolveHouseholdMemberDepartureAction. The admins are notified in either case, except the one who removed the member.
     */
    public function handle(int $household_id, int $user_id, ?int $removed_by = null): ?HouseholdMemberDeparture
    {
        $departure = new HouseholdMemberDeparture([
            'household_id' => $household_id,
            'user_id' => $user_id,
            'removed_by' => $removed_by,
        ]);
        $task_count = $departure->createdTasks()->count();

        if ($task_count > 0) {
            $departure->save();
        }

        DB::afterCommit(fn () => $this->notify($departure, $task_count));

        return $departure->exists ? $departure : null;
    }

    private function notify(HouseholdMemberDeparture $departure, int $task_count): void
    {
        $household = Household::find($departure->household_id);
        $former_member = User::find($departure->user_id);
        if (! $household || ! $former_member) {
            return;
        }

        $admins = User::query()
            ->whereIn('id', $household->householdUsers()->where('role', RoleEnum::ADMIN)->select('user_id'))
            ->when($departure->removed_by, fn ($query, int $removed_by) => $query->whereKeyNot($removed_by))
            ->get();

        $admins->groupBy(fn (User $admin) => $admin->language?->value)->each(function ($users, $locale) use ($household, $former_member, $task_count) {
            $locale = $locale ?: null;
            SendPushNotificationAction::run(
                $users->pluck('id'),
                __('app.member_departure_title', ['household' => $household->name], $locale),
                $task_count > 0
                    ? __('app.member_departure_tasks_body', ['name' => $former_member->name, 'count' => $task_count], $locale)
                    : __('app.member_departure_body', ['name' => $former_member->name], $locale),
                ['route' => $task_count > 0 ? "/member-departures?household_id={$household->id}" : '/', 'household_id' => $household->id],
            );
        });
    }
}
