<?php

namespace App\Models;

use App\Curriculum\Textbook;
use App\Support\Ulid;
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
 * @property int|null $upload_quota_mb 個別調整的上傳上限（MB）；null 用預設值（App\Media\UploadQuota）
 * @property string|null $public_id 創作者頁面網址用的 ULID（docs/SPEC.md T-20）；網址不暴露遞增的 id
 * @property string|null $attribution_name 署名名稱，null 表示用帳號的名字（attributionName()）
 * @property string|null $attribution_url 署名附的網址，只收 http、https
 * @property string|null $default_license 新題組的預設授權，null 表示 Set::LICENSES 的第一個
 * @property string|null $school
 * @property list<string>|null $teaching_languages 教的語言（languages.code）
 * @property string|null $bio 簡介，純文字
 * @property string|null $two_factor_secret
 * @property string|null $two_factor_recovery_codes
 * @property Carbon|null $two_factor_confirmed_at
 * @property string|null $remember_token
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['name', 'email', 'password', 'google_id', 'upload_quota_mb', 'attribution_name', 'attribution_url', 'default_license', 'school', 'teaching_languages', 'bio'])]
#[Hidden(['password', 'google_id', 'two_factor_secret', 'two_factor_recovery_codes', 'remember_token'])]
class User extends Authenticatable implements FilamentUser, PasskeyUser
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, HasRoles, Notifiable, PasskeyAuthenticatable, TwoFactorAuthenticatable;

    /**
     * 創作者資料的欄位（docs/SPEC.md T-20）；刪除帳號時全部清掉（App\Auth\AccountDeletion）。
     */
    public const PROFILE_FIELDS = ['attribution_name', 'attribution_url', 'default_license', 'school', 'teaching_languages', 'bio'];

    protected static function booted(): void
    {
        static::creating(function (User $user) {
            $user->public_id ??= Ulid::make();
        });
    }

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
            'teaching_languages' => 'array',
        ];
    }

    /**
     * 署名時用的名字（docs/SPEC.md T-20）：老師在創作者資料填的署名名稱，沒填就是帳號的名字。
     */
    public function attributionName(): string
    {
        return $this->attribution_name ?? $this->name;
    }

    /**
     * 交換格式中的作者（6.5 的 Author：{name, url?}）。寫進題組、詞條與媒體的 authors 時用這個，
     * 之後老師改了署名，已經寫下的不會跟著變（第 5 節）。
     *
     * @return array{name: string, url?: string}
     */
    public function author(): array
    {
        $author = ['name' => $this->attributionName()];
        if ($this->attribution_url !== null && $this->attribution_url !== '') {
            $author['url'] = $this->attribution_url;
        }

        return $author;
    }

    /**
     * 新題組的預設授權（docs/SPEC.md T-20、D-3）。
     */
    public function defaultLicense(): string
    {
        return in_array($this->default_license, Set::LICENSES, true) ? $this->default_license : Set::LICENSES[0];
    }

    /**
     * 教材題組的系統帳號（App\Curriculum\Textbook）：不能登入，也沒有創作者頁面。
     */
    public function isTextbook(): bool
    {
        return $this->email === Textbook::OWNER_EMAIL;
    }

    /**
     * 有創作者頁面（docs/SPEC.md T-20）：停用、已刪除（匿名化）的帳號與教材帳號沒有。
     */
    public function hasProfilePage(): bool
    {
        return $this->public_id !== null && ! $this->isDisabled() && ! $this->isAnonymized() && ! $this->isTextbook();
    }

    /**
     * 創作者頁面的網址，只有登入的老師看得到；沒有頁面時為 null。
     */
    public function profileUrl(): ?string
    {
        return $this->hasProfilePage() ? route('teachers.show', $this->public_id) : null;
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
     * 這個人上傳的媒體；上傳的總量上限以此計算（App\Media\UploadQuota）。
     *
     * @return HasMany<Media, $this>
     */
    public function media(): HasMany
    {
        return $this->hasMany(Media::class, 'uploaded_by');
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
