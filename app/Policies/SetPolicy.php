<?php

namespace App\Policies;

use App\Models\Set;
use App\Models\User;

/**
 * 老師只能管理自己的題組；管理員可以管理全部（docs/SPEC.md 第 2 節）。
 */
class SetPolicy
{
    public function manage(User $user, Set $set): bool
    {
        return $set->owner_id === $user->id || $user->hasRole('admin');
    }
}
