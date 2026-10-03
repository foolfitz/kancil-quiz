<?php

namespace App\Console\Commands;

use App\Models\Invitation;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/**
 * 從命令列建立邀請連結，例如架站後建立第一位管理員。之後的邀請可以在後台建立。
 */
#[Signature('kancil:invite {--email= : 只允許這個 email 使用} {--role=teacher : teacher、curator 或 admin} {--days=14 : 有效天數}')]
#[Description('建立註冊邀請連結')]
class InviteCommand extends Command
{
    public function handle(): int
    {
        $role = (string) $this->option('role');
        if (! in_array($role, Invitation::ROLES, true)) {
            $this->error('角色必須是 '.implode('、', Invitation::ROLES));

            return self::FAILURE;
        }

        [, $token] = Invitation::issue([
            'email' => $this->option('email') ?: null,
            'role' => $role,
            'days' => (int) $this->option('days'),
        ]);

        $this->info('邀請連結（只會顯示這一次）：');
        $this->line(Invitation::url($token));

        return self::SUCCESS;
    }
}
