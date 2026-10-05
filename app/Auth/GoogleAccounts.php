<?php

namespace App\Auth;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Laravel\Socialite\Contracts\User as GoogleUser;

/**
 * Google 登入的帳號對應（docs/SPEC.md T-03）：任何 Google 帳號都能登入，第一次登入就建立老師帳號；
 * 平台不管老師的密碼，也不寄驗證信，email 由 Google 驗證。
 *
 * 依序找：同一個 Google 帳號 → 同一個 email、還沒連結 Google 的帳號（Google 登入之前建立的帳號，
 * 例如管理員）→ 建立新的老師帳號。
 */
class GoogleAccounts
{
    public static function configured(): bool
    {
        return filled(config('services.google.client_id')) && filled(config('services.google.client_secret'));
    }

    /**
     * @throws ValidationException 這個 Google 帳號不能登入
     */
    public function resolve(GoogleUser $google): User
    {
        $id = (string) $google->getId();
        $email = Str::lower(trim((string) $google->getEmail()));
        // Google 的 userinfo 有 email_verified；沒有驗證的 email 不能用來對應既有的帳號
        $raw = method_exists($google, 'getRaw') ? $google->getRaw() : [];
        if ($id === '' || $email === '' || ($raw['email_verified'] ?? false) !== true) {
            throw $this->fail('這個 Google 帳號的 email 還沒有驗證，請先到 Google 完成驗證。');
        }

        $user = User::where('google_id', $id)->first()
            ?? $this->link($email, $id)
            ?? $this->register($google, $email, $id);

        if ($user->isDisabled()) {
            throw $this->fail('這個帳號已經停用。如有疑問，請聯絡網站管理員。');
        }

        return $user;
    }

    private function link(string $email, string $id): ?User
    {
        $user = User::where('email', $email)->first();
        if ($user === null) {
            return null;
        }
        if ($user->google_id !== null) {
            throw $this->fail('這個 email 的帳號已經連結另一個 Google 帳號。');
        }

        $user->forceFill([
            'google_id' => $id,
            'email_verified_at' => $user->email_verified_at ?? now(),
        ])->save();

        return $user;
    }

    private function register(GoogleUser $google, string $email, string $id): User
    {
        $name = trim((string) $google->getName());

        return DB::transaction(function () use ($name, $email, $id) {
            $user = User::create([
                'name' => Str::limit($name !== '' ? $name : Str::before($email, '@'), 255, ''),
                'email' => $email,
                'google_id' => $id,
            ]);
            $user->forceFill(['email_verified_at' => now()])->save();
            $user->assignRole('teacher');

            return $user;
        });
    }

    private function fail(string $message): ValidationException
    {
        return ValidationException::withMessages(['google' => $message]);
    }
}
