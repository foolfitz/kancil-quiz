<?php

namespace App\Models;

use Database\Factories\UserFactory;
use Filament\Models\Contracts\FilamentUser;
use Filament\Panel;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Carbon;
use Laravel\Fortify\Contracts\PasskeyUser;
use Laravel\Fortify\PasskeyAuthenticatable;
use Laravel\Fortify\TwoFactorAuthenticatable;
use Spatie\Permission\Traits\HasRoles;

/**
 * @property int $id
 * @property string $name
 * @property string $email
 * @property Carbon|null $email_verified_at
 * @property string|null $password 只有管理員（與 Google 登入之前建立的帳號）有密碼；老師用 Google 登入
 * @property string|null $google_id Google 帳號的 ID（sub）
 * @property Carbon|null $disabled_at 管理員停用帳號的時間（docs/SPEC.md A-05）
 * @property Carbon|null $anonymized_at 老師刪除帳號的時間；帳號匿名化，不真的刪除（App\Auth\AccountDeletion）
 * @property string|null $two_factor_secret
 * @property string|null $two_factor_recovery_codes
 * @property Carbon|null $two_factor_confirmed_at
 * @property string|null $remember_token
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['name', 'email', 'password', 'google_id'])]
#[Hidden(['password', 'google_id', 'two_factor_secret', 'two_factor_recovery_codes', 'remember_token'])]
class User extends Authenticatable implements FilamentUser, PasskeyUser
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, HasRoles, Notifiable, PasskeyAuthenticatable, TwoFactorAuthenticatable;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'two_factor_confirmed_at' => 'datetime',
            'disabled_at' => 'datetime',
            'anonymized_at' => 'datetime',
        ];
    }

    /**
     * 管理員停用的帳號不能登入，已經登入的也會被登出；他的活動不能玩，題組不再列在共備庫（docs/SPEC.md A-05）。
     */
    public function isDisabled(): bool
    {
        return $this->disabled_at !== null;
    }

    public function isAnonymized(): bool
    {
        return $this->anonymized_at !== null;
    }

    /**
     * 有密碼的帳號才能用密碼登入、改密碼、設定雙重驗證與 passkey（設定頁的「安全性」）。
     */
    public function hasPassword(): bool
    {
        return $this->password !== null;
    }

    /**
     * Filament 後台只給管理員與審核者使用（docs/SPEC.md 10.1）。
     */
    public function canAccessPanel(Panel $panel): bool
    {
        return $this->hasAnyRole(['admin', 'curator']) && ! $this->isDisabled();
    }

    /**
     * @return HasMany<Set, $this>
     */
    public function sets(): HasMany
    {
        return $this->hasMany(Set::class, 'owner_id');
    }

    /**
     * @return HasMany<Activity, $this>
     */
    public function activities(): HasMany
    {
        return $this->hasMany(Activity::class, 'owner_id');
    }

    /**
     * 審核者負責的語言（docs/SPEC.md 第 2 節：審核者的權限可以限定在特定語言）。
     *
     * @return BelongsToMany<Language, $this>
     */
    public function reviewLanguages(): BelongsToMany
    {
        return $this->belongsToMany(Language::class, 'language_user', 'user_id', 'language_code');
    }

    /**
     * 能否審核與修正這種語言的題組：管理員全部都可以，審核者只限負責的語言。
     */
    public function canReview(string $languageCode): bool
    {
        if ($this->hasRole('admin')) {
            return true;
        }

        return $this->hasRole('curator')
            && $this->reviewLanguages()->whereKey($languageCode)->exists();
    }

    /**
     * 能審核的語言代碼；null 表示全部（管理員）。
     *
     * @return list<string>|null
     */
    public function reviewableLanguageCodes(): ?array
    {
        if ($this->hasRole('admin')) {
            return null;
        }

        if (! $this->hasRole('curator')) {
            return [];
        }

        return array_values($this->reviewLanguages()->pluck('languages.code')->all());
    }
}
