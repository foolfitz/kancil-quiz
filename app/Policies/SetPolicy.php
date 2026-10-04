<?php

namespace App\Policies;

use App\Models\Set;
use App\Models\User;

/**
 * 題組的權限（docs/SPEC.md 第 2 節、3.2）。
 *
 * - manage：擁有者與管理員。建立活動、刪除、分享連結、申請公開。
 * - edit：manage，加上負責該語言的審核者修正已公開的題組（C-03）。
 * - view：manage、已公開的題組（所有老師）、負責該語言的審核者檢視待審的題組（3.2）。
 * - copy：manage、已公開的題組。審核者為了審核才看得到待審的題組，不能複製。
 *   未公開（unlisted）的題組只能透過分享連結檢視與複製，由 SetShareController、SetCopyController 以 token 判斷（T-17）。
 * - export：manage、已公開的題組（T-15）。公開題組另外有不需登入的開放資料網址；
 *   未公開（unlisted）的題組由 SetExportController 以分享連結的 token 判斷。
 * - review：負責該語言的審核者；審核者不能審核自己的題組，管理員可以。
 * - createActivity：manage，加上所有老師都能用教材題組建立活動（3.6）。
 *
 * 教材題組由匯入指令維護：沒有人能 manage（分享、申請公開、刪除）或 review，
 * 負責該語言的審核者仍可以修正內容（edit）。
 */
class SetPolicy
{
    public function manage(User $user, Set $set): bool
    {
        if ($set->isTextbook()) {
            return false;
        }

        return $set->owner_id === $user->id || $user->hasRole('admin');
    }

    public function createActivity(User $user, Set $set): bool
    {
        return $this->manage($user, $set) || $set->isTextbook();
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
            || ($set->review_status === 'pending' && $user->canReview($set->language_code));
    }

    public function copy(User $user, Set $set): bool
    {
        return $this->manage($user, $set) || $set->isPublic();
    }

    public function export(User $user, Set $set): bool
    {
        return $this->manage($user, $set) || $set->isPublic();
    }

    public function review(User $user, Set $set): bool
    {
        if ($set->isTextbook()) {
            return false;
        }

        if ($user->hasRole('admin')) {
            return true;
        }

        return $set->owner_id !== $user->id && $user->canReview($set->language_code);
    }
}
