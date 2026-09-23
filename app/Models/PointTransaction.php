<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['household_id', 'user_id', 'amount', 'balance_after', 'type', 'task_instance_id', 'task_offer_id', 'reward_redemption_id'])]
class PointTransaction extends Model
{

}
