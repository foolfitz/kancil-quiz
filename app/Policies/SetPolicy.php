<?php

namespace App\Policies;

use App\Models\Set;
use App\Models\User;

/**
 * 題組的權限（docs/SPEC.md 第 2 節、3.2）。
 *
 * - manage：擁有者與管理員。建立活動、刪除、分享連結、申請公開。
 * - edit：manage，加上負責該語言的審核者修正已公開的題組（C-03）。
 * - view、copy：manage、已公開的題組（所有老師）、負責該語言的審核者。
 *   未公開（unlisted）的題組只能透過分享連結檢視與複製，由 SharedSetController 以 token 判斷（T-17）。
 * - review：負責該語言的審核者；審核者不能審核自己的題組，管理員可以。
 */
class SetPolicy
{
    public function manage(User $user, Set $set): bool
    {
        return $set->owner_id === $user->id || $user->hasRole('admin');
    }

    public function edit(User $user, Set $set): bool
    {
        return $this->manage($user, $set)
            || ($set->isPublic() && $user->canReview($set->language_code));
    }

    public function view(User $user, Set $set): bool
    {
        return $this->manage($user, $set)
            || $set->isPublic()
            || $user->canReview($set->language_code);
    }

    public function copy(User $user, Set $set): bool
    {
        return $this->view($user, $set);
    }

    public function review(User $user, Set $set): bool
    {
        if ($user->hasRole('admin')) {
            return true;
        }

        return $set->owner_id !== $user->id && $user->canReview($set->language_code);
    }
}
