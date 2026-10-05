<?php

namespace Tests\Feature\Settings;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * 個人資料：只能改名字，email 來自 Google（docs/SPEC.md T-03）；刪除帳號輸入 email 確認，帳號匿名化
 * （細節見 AccountDeletionTest）。
 */
class ProfileUpdateTest extends TestCase
{
    use RefreshDatabase;

    public function test_profile_page_is_displayed()
    {
        $user = User::factory()->create(['google_id' => '1234']);

        $this->actingAs($user)->get(route('profile.edit'))->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('google', true)
                ->where('canDelete', true)
                ->where('auth.hasPassword', true));
    }

    public function test_only_the_name_can_be_updated()
    {
        $user = User::factory()->create(['email' => 'teacher@example.com']);

        $this->actingAs($user)
            ->patch(route('profile.update'), ['name' => '王老師', 'email' => 'other@example.com'])
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('profile.edit'));

        $user->refresh();
        $this->assertSame('王老師', $user->name);
        $this->assertSame('teacher@example.com', $user->email);
        $this->assertNotNull($user->email_verified_at);
    }

    public function test_users_confirm_with_their_email_to_delete_their_account()
    {
        $user = User::factory()->create(['email' => 'teacher@example.com']);

        $this->actingAs($user)->from(route('profile.edit'))
            ->delete(route('profile.destroy'), ['confirmation' => 'someone@example.com'])
            ->assertSessionHasErrors(['confirmation' => '請輸入你的 email 確認。'])
            ->assertRedirect(route('profile.edit'));
        $this->assertFalse($user->fresh()->isAnonymized());

        // 大小寫與前後的空白不影響
        $this->actingAs($user)
            ->delete(route('profile.destroy'), ['confirmation' => ' Teacher@Example.com '])
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('home'));

        $this->assertGuest();
        $this->assertTrue($user->fresh()->isAnonymized());
    }

    public function test_admins_cannot_delete_their_own_account()
    {
        $admin = User::factory()->create();
        $admin->assignRole('admin');

        $this->actingAs($admin)->get(route('profile.edit'))
            ->assertInertia(fn (Assert $page) => $page->where('canDelete', false));
        $this->actingAs($admin)->delete(route('profile.destroy'), ['confirmation' => $admin->email])->assertForbidden();
        $this->assertFalse($admin->fresh()->isAnonymized());
    }
}
