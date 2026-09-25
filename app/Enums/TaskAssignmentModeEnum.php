<?php

namespace App\Enums;

enum TaskAssignmentModeEnum: string
{
    case NONE = 'none';
    case ROTATING = 'rotating';
    case FIXED = 'fixed';
}
