<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['task_instance_id', 'user_id', 'completed_at'])]
class TaskInstanceUser extends Model
{
    //
}
