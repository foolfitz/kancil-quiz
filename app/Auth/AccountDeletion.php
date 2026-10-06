<?php

namespace App\Auth;

use App\Models\Set;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * 老師刪除自己的帳號（docs/SPEC.md 第 5 節）：不真的刪除使用者，改為匿名化。題組、媒體、版本與審核紀錄
 * 都指向使用者，真的刪除會牽動共備庫中別人在用的內容。
 *
 * - 名字改成「已刪除的使用者」，清掉 email、Google ID、密碼、雙重驗證、passkey 與角色，從此不能登入。
 * - 自己的活動，以及沒有公開的題組（私人、用分享連結的）先 soft delete，30 天後由 kancil:prune
 *   連同學生的作答一起刪除（App\Support\Pruner）。
 * - 已經公開到共備庫的題組留下，署名照舊：先把署名（創作者資料的署名名稱與網址，User::author()）寫進
 *   題組的 authors，之後不再列出「已刪除的使用者」（Set::effectiveAuthors()）。詞條與媒體的署名本來就另外
 *   存在它們身上。
 * - 創作者資料（署名、預設授權、學校、教的語言、簡介，T-20）全部清掉，創作者頁面回 404。
 */
class AccountDeletion
{
    public const NAME = '已刪除的使用者';

    public function delete(User $user): void
    {
        DB::transaction(function () use ($user) {
            $user->activities()->delete();
            $user->sets()->where('visibility', '!=', 'public')->delete();

            $user->sets()->where('visibility', 'public')->get()->each(function (Set $set) use ($user) {
                $authors = $set->authors ?? [];
                if (! in_array($user->attributionName(), array_column($authors, 'name'), true)) {
                    $set->forceFill(['authors' => [...$authors, $user->author()]])->save();
                }
            });

            $user->passkeys()->delete();
            $user->roles()->detach();
            $user->reviewLanguages()->detach();
            DB::table('sessions')->where('user_id', $user->id)->delete();

            $user->forceFill([
                'name' => self::NAME,
                // email 欄位不能是空的，也不能重複
                'email' => "deleted-{$user->id}@kancil-quiz.invalid",
                'email_verified_at' => null,
                'google_id' => null,
                'password' => null,
                'remember_token' => null,
                'two_factor_secret' => null,
                'two_factor_recovery_codes' => null,
                'two_factor_confirmed_at' => null,
                'anonymized_at' => now(),
                ...array_fill_keys(User::PROFILE_FIELDS, null),
            ])->save();
        });
    }
}
