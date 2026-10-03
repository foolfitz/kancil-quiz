<?php

namespace App\Policies;

use App\Models\Activity;
use App\Models\User;

class ActivityPolicy
{
    public function manage(User $user, Activity $activity): bool
    {
        return $activity->owner_id === $user->id || $user->hasRole('admin');
    }
}
