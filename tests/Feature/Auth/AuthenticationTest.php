<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\RateLimiter;
use Laravel\Fortify\Features;
use Tests\TestCase;

class AuthenticationTest extends TestCase
{
    use RefreshDatabase;

    public function test_login_screen_can_be_rendered()
    {
        $response = $this->get(route('login'));

        $response->assertOk();
    }

    public function test_users_can_authenticate_using_the_login_screen()
    {
        $user = User::factory()->create();

        $response = $this->post(route('login.store'), [
            'email' => $user->email,
            'password' => 'password',
        ]);

        $this->assertAuthenticated();
        $response->assertRedirect(route('dashboard', absolute: false));
    }

    public function test_users_with_two_factor_enabled_are_redirected_to_two_factor_challenge()
    {
        $this->skipUnlessFortifyHas(Features::twoFactorAuthentication());

        Features::twoFactorAuthentication([
            'confirm' => true,
            'confirmPassword' => true,
        ]);

        $user = User::factory()->withTwoFactor()->create();

        $response = $this->post(route('login'), [
            'email' => $user->email,
            'password' => 'password',
        ]);

        $response->assertRedirect(route('two-factor.login'));
        $response->assertSessionHas('login.id', $user->id);
        $this->assertGuest();
    }

    public function test_users_can_not_authenticate_with_invalid_password()
    {
        $user = User::factory()->create();

        $this->post(route('login.store'), [
            'email' => $user->email,
            'password' => 'wrong-password',
        ]);

        $this->assertGuest();
    }

    public function test_only_active_accounts_with_a_password_can_use_the_password_form()
    {
        // 用 Google 登入建立的老師沒有密碼
        $teacher = User::factory()->create(['password' => null, 'google_id' => '1001']);
        $this->post(route('login.store'), ['email' => $teacher->email, 'password' => ''])->assertSessionHasErrors('password');
        $this->post(route('login.store'), ['email' => $teacher->email, 'password' => 'password'])->assertSessionHasErrors('email');
        $this->assertGuest();

        $disabled = User::factory()->create(['disabled_at' => now()]);
        $this->post(route('login.store'), ['email' => $disabled->email, 'password' => 'password'])
            ->assertSessionHasErrors(['email' => '這個帳號已經停用。']);
        $this->assertGuest();
    }

    public function test_there_is_no_registration_or_password_reset()
    {
        // 老師一律用 Google 登入（docs/SPEC.md T-02、T-03）
        foreach (['/register', '/forgot-password', '/reset-password/token', '/email/verify'] as $url) {
            $this->get($url)->assertNotFound();
        }
        $this->post('/register', ['name' => 'x', 'email' => 'x@example.com', 'password' => 'password', 'password_confirmation' => 'password'])->assertNotFound();
        $this->assertSame(0, User::count());
    }

    public function test_users_can_logout()
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->post(route('logout'));

        $response->assertRedirect(route('home'));

        $this->assertGuest();
    }

    public function test_users_are_rate_limited()
    {
        $user = User::factory()->create();

        RateLimiter::increment(md5('login'.implode('|', [$user->email, '127.0.0.1'])), amount: 5);

        $response = $this->post(route('login.store'), [
            'email' => $user->email,
            'password' => 'wrong-password',
        ]);

        $response->assertTooManyRequests();
    }
}
