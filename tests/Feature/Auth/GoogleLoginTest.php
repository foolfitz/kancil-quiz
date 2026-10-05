<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Laravel\Fortify\Features;
use Laravel\Socialite\Contracts\Provider;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\InvalidStateException;
use Laravel\Socialite\Two\User as GoogleUser;
use Mockery;
use Tests\TestCase;

/**
 * 用 Google 帳號登入（docs/SPEC.md T-03）：任何 Google 帳號都能登入，第一次登入就建立老師帳號。
 * Google 的回應以假的 Socialite provider 代替。
 */
class GoogleLoginTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['services.google.client_id' => 'test-client', 'services.google.client_secret' => 'test-secret']);
    }

    /**
     * @param  array<string, mixed>  $raw
     */
    private function google(string $id = '1001', string $email = 'teacher@gmail.com', ?string $name = '林老師', array $raw = []): GoogleUser
    {
        return (new GoogleUser)
            ->setRaw(['sub' => $id, 'email' => $email, 'email_verified' => true, ...$raw])
            ->map(['id' => $id, 'name' => $name, 'email' => $email]);
    }

    private GoogleUser|\Throwable|null $fromGoogle = null;

    /**
     * Google 登入完成後，Socialite 拿到的使用者（或錯誤）。假的 provider 只設定一次，之後只換結果。
     */
    private function returnsFromGoogle(GoogleUser|\Throwable $result): void
    {
        if ($this->fromGoogle === null) {
            $provider = Mockery::mock(Provider::class);
            $provider->shouldReceive('user')->andReturnUsing(fn () => $this->fromGoogle instanceof \Throwable ? throw $this->fromGoogle : $this->fromGoogle);
            Socialite::shouldReceive('driver')->with('google')->andReturn($provider);
        }
        $this->fromGoogle = $result;
    }

    public function test_the_login_page_offers_google_when_it_is_configured(): void
    {
        $this->get(route('login'))->assertInertia(fn (Assert $page) => $page->component('auth/Login')->where('googleLogin', true));

        $redirect = $this->get(route('google.redirect'))->assertRedirect()->headers->get('Location');
        $this->assertStringStartsWith('https://accounts.google.com/o/oauth2/auth?', (string) $redirect);
        // 只要求基本的 scope
        $this->assertStringContainsString('scope=openid+profile+email&', (string) $redirect);
        $this->assertStringContainsString('redirect_uri='.urlencode(url('/auth/google/callback')), (string) $redirect);

        config(['services.google.client_id' => null]);
        $this->get(route('login'))->assertInertia(fn (Assert $page) => $page->where('googleLogin', false)->where('setupHint', false));
        $this->get(route('google.redirect'))->assertNotFound();
        $this->get(route('google.callback'))->assertNotFound();
    }

    public function test_the_first_login_creates_a_teacher_without_a_password(): void
    {
        $this->returnsFromGoogle($this->google(email: 'Teacher@Gmail.com'));

        $this->get(route('google.callback'))->assertRedirect(route('dashboard'));

        $user = User::sole();
        $this->assertAuthenticatedAs($user);
        $this->assertSame(['林老師', 'teacher@gmail.com', '1001'], [$user->name, $user->email, $user->google_id]);
        $this->assertNotNull($user->email_verified_at);
        $this->assertFalse($user->hasPassword());
        $this->assertTrue($user->hasRole('teacher'));

        // 之後依 Google 帳號找到同一個人
        auth()->logout();
        $this->returnsFromGoogle($this->google(name: '林老師（新名字）'));
        $this->get(route('google.callback'))->assertRedirect(route('dashboard'));
        $this->assertSame(1, User::count());
        $this->assertSame('林老師', $user->fresh()->name);

        // 沒有名字時用 email 的前半
        auth()->logout();
        $this->returnsFromGoogle($this->google(id: '1002', email: 'siti@example.org', name: null));
        $this->get(route('google.callback'));
        $this->assertSame('siti', User::where('google_id', '1002')->value('name'));
    }

    public function test_an_existing_account_with_the_same_email_is_linked(): void
    {
        $admin = User::factory()->create(['email' => 'admin@example.org']);
        $admin->assignRole('admin');

        $this->returnsFromGoogle($this->google(email: 'admin@example.org'));
        $this->get(route('google.callback'))->assertRedirect(route('dashboard'));

        $this->assertAuthenticatedAs($admin);
        $this->assertSame('1001', $admin->fresh()->google_id);
        // 管理員保留密碼，Google 出問題時仍能登入
        $this->assertTrue($admin->fresh()->hasPassword());
        $this->assertSame(1, User::count());
    }

    public function test_some_google_accounts_cannot_log_in(): void
    {
        // email 沒有驗證：不能用來對應既有的帳號，也不建立新帳號
        $this->returnsFromGoogle($this->google(raw: ['email_verified' => false]));
        $this->get(route('google.callback'))->assertRedirect(route('login'))
            ->assertSessionHasErrors(['google' => '這個 Google 帳號的 email 還沒有驗證，請先到 Google 完成驗證。']);

        // 同一個 email 已經連結另一個 Google 帳號
        User::factory()->create(['email' => 'teacher@gmail.com', 'google_id' => '9999']);
        $this->returnsFromGoogle($this->google());
        $this->get(route('google.callback'))->assertSessionHasErrors(['google' => '這個 email 的帳號已經連結另一個 Google 帳號。']);

        // 停用的帳號
        User::factory()->create(['google_id' => '1003', 'disabled_at' => now()]);
        $this->returnsFromGoogle($this->google(id: '1003', email: 'disabled@gmail.com'));
        $this->get(route('google.callback'))->assertSessionHasErrors(['google' => '這個帳號已經停用。如有疑問，請聯絡網站管理員。']);

        // 在 Google 的畫面按了取消，或停留太久
        $this->get(route('google.callback', ['error' => 'access_denied']))->assertSessionHasErrors(['google' => '沒有完成 Google 登入。']);
        $this->returnsFromGoogle(new InvalidStateException);
        $this->get(route('google.callback', ['code' => 'x']))->assertSessionHasErrors(['google' => 'Google 登入逾時或沒有完成，請再試一次。']);

        $this->assertGuest();
        $this->assertSame(2, User::count());
    }

    public function test_teachers_return_to_the_page_they_wanted(): void
    {
        $this->get(route('sets.create'))->assertRedirect(route('login'));

        $this->returnsFromGoogle($this->google());
        $this->get(route('google.callback'))->assertRedirect(route('sets.create'));
    }

    public function test_accounts_with_two_factor_authentication_still_need_the_code(): void
    {
        if (! Features::canManageTwoFactorAuthentication()) {
            $this->markTestSkipped('Two-factor authentication is not enabled.');
        }

        $admin = User::factory()->create(['google_id' => '1001']);
        $admin->forceFill([
            'two_factor_secret' => encrypt('test-secret'),
            'two_factor_recovery_codes' => encrypt(json_encode(['code1', 'code2'])),
            'two_factor_confirmed_at' => now(),
        ])->save();

        $this->returnsFromGoogle($this->google());
        $this->get(route('google.callback'))
            ->assertRedirect(route('two-factor.login'))
            ->assertSessionHas('login.id', $admin->id);
        $this->assertGuest();
    }
}
