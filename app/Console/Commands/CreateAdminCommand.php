<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password;

/**
 * 從命令列建立管理員，例如架站後的第一位管理員（docs/deploy.md）。管理員保留密碼，Google 登入出問題時
 * 仍能進後台；之後的管理員與審核者，請對方先用 Google 登入一次，再由管理員在後台指定角色。
 * 帳號已經存在時（例如已經用 Google 登入過），加上管理員的角色，還沒有密碼就設定密碼。
 */
#[Signature('kancil:create-admin {email} {--name=管理員 : 新建帳號時的名字}')]
#[Description('建立管理員，或把既有的帳號設為管理員')]
class CreateAdminCommand extends Command
{
    public function handle(): int
    {
        $email = Str::lower(trim((string) $this->argument('email')));
        if (Validator::make(['email' => $email], ['email' => ['required', 'email']])->fails()) {
            $this->error('email 格式不正確');

            return self::FAILURE;
        }

        $user = User::where('email', $email)->first();
        if ($user?->isAnonymized()) {
            $this->error('這個帳號已經刪除');

            return self::FAILURE;
        }

        $password = null;
        if ($user === null || ! $user->hasPassword()) {
            $password = (string) $this->secret('設定密碼');
            $errors = Validator::make(
                ['password' => $password, 'password_confirmation' => (string) $this->secret('再輸入一次')],
                ['password' => ['required', 'confirmed', Password::defaults()]],
            )->errors();
            if ($errors->isNotEmpty()) {
                $this->error(implode("\n", $errors->all()));

                return self::FAILURE;
            }
        }

        $user ??= User::create(['name' => (string) $this->option('name'), 'email' => $email]);
        $user->forceFill(array_filter([
            'password' => $password,
            'email_verified_at' => $user->email_verified_at ?? now(),
        ]))->save();
        $user->assignRole('admin');

        $this->info("{$email} 已經是管理員，可以在 /login 用 email 與密碼登入，再進入 /admin。");

        return self::SUCCESS;
    }
}
