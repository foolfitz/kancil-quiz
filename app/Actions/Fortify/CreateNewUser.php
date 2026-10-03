<?php

namespace App\Actions\Fortify;

use App\Concerns\PasswordValidationRules;
use App\Concerns\ProfileValidationRules;
use App\Models\Invitation;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Laravel\Fortify\Contracts\CreatesNewUsers;

/**
 * 邀請制註冊（docs/SPEC.md T-02）：必須持有有效的邀請連結，避免任意註冊。
 */
class CreateNewUser implements CreatesNewUsers
{
    use PasswordValidationRules, ProfileValidationRules;

    /**
     * Validate and create a newly registered user.
     *
     * @param  array<string, string>  $input
     */
    public function create(array $input): User
    {
        $invitation = Invitation::findUsable($input['invitation'] ?? null);
        if ($invitation === null) {
            throw ValidationException::withMessages(['invitation' => '邀請連結無效或已過期，請向管理員索取新的邀請連結。']);
        }

        Validator::make($input, [
            ...$this->profileRules(),
            'password' => $this->passwordRules(),
        ])->validate();

        if ($invitation->email !== null && strcasecmp($invitation->email, $input['email']) !== 0) {
            throw ValidationException::withMessages(['email' => '這個邀請連結只能用邀請時指定的 email 註冊。']);
        }

        return DB::transaction(function () use ($input, $invitation) {
            $user = User::create([
                'name' => $input['name'],
                'email' => $input['email'],
                'password' => $input['password'],
            ]);

            // 邀請本身就是信任的依據，不必再驗證 email。
            $user->forceFill(['email_verified_at' => now()])->save();
            $user->assignRole($invitation->role);

            $invitation->update(['accepted_at' => now(), 'accepted_by' => $user->id]);

            return $user;
        });
    }
}
