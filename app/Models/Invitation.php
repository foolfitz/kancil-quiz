<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * 註冊邀請（docs/SPEC.md T-02）。只保存 token 的雜湊，連結只在建立時顯示一次。
 *
 * @property int $id
 * @property string $token_hash
 * @property string|null $email
 * @property string $role
 * @property string|null $note
 * @property int|null $invited_by
 * @property CarbonImmutable $expires_at
 * @property CarbonImmutable|null $accepted_at
 * @property int|null $accepted_by
 */
#[Fillable(['token_hash', 'email', 'role', 'note', 'invited_by', 'expires_at', 'accepted_at', 'accepted_by'])]
#[Hidden(['token_hash'])]
class Invitation extends Model
{
    public const ROLES = ['teacher', 'curator', 'admin'];

    protected function casts(): array
    {
        return [
            'expires_at' => 'datetime',
            'accepted_at' => 'datetime',
        ];
    }

    /**
     * 建立邀請，回傳邀請與一次性的 token。
     *
     * @param  array{email?: string|null, role?: string, note?: string|null, invited_by?: int|null, days?: int}  $attributes
     * @return array{0: self, 1: string}
     */
    public static function issue(array $attributes = []): array
    {
        $token = Str::random(40);

        $invitation = self::create([
            'token_hash' => hash('sha256', $token),
            'email' => $attributes['email'] ?? null,
            'role' => $attributes['role'] ?? 'teacher',
            'note' => $attributes['note'] ?? null,
            'invited_by' => $attributes['invited_by'] ?? null,
            'expires_at' => now()->addDays($attributes['days'] ?? 14),
        ]);

        return [$invitation, $token];
    }

    public static function findUsable(?string $token): ?self
    {
        if ($token === null || $token === '') {
            return null;
        }

        return self::where('token_hash', hash('sha256', $token))
            ->whereNull('accepted_at')
            ->where('expires_at', '>', now())
            ->first();
    }

    public static function url(string $token): string
    {
        return route('register', ['invitation' => $token]);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function inviter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'invited_by');
    }
}
