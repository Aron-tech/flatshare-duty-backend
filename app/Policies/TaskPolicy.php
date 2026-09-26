<?php

namespace App\Policies;

use App\Concerns\GrantsPermission;
use App\Enums\RoleEnum;
use App\Models\Task;
use App\Models\User;
use Illuminate\Auth\Access\Response;

class TaskPolicy
{
    use GrantsPermission;

    /**
     * Any member of the household can edit its tasks, except a child.
     */
    public function update(User $user, Task $task): Response
    {
        $role = $user->membershipOf($task->household_id)?->role;

        return $this->allowIf($role !== null && $role !== RoleEnum::CHILD);
    }

    /**
     * Like editing, a child member cannot delete tasks.
     */
    public function delete(User $user, Task $task): Response
    {
        return $this->update($user, $task);
    }
}
