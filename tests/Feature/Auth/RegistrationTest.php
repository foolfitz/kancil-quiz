<?php

namespace Tests\Feature\Auth;

use App\Models\Invitation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Laravel\Fortify\Features;
use Tests\TestCase;

// 邀請制註冊（docs/SPEC.md T-02）
class RegistrationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->skipUnlessFortifyHas(Features::registration());
    }

    private function register(array $overrides = [])
    {
        return $this->post(route('register.store'), [
            'name' => 'Test User',
            'email' => 'test@example.com',
            'password' => 'password',
            'password_confirmation' => 'password',
            ...$overrides,
        ]);
    }

    public function test_registration_screen_can_be_rendered()
    {
        [, $token] = Invitation::issue();

        $this->get(route('register', ['invitation' => $token]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('invitation', $token));
    }

    public function test_registration_screen_without_invitation_hides_the_form()
    {
        $this->get(route('register'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('invitation', null));
    }

    public function test_new_users_can_register_with_an_invitation()
    {
        [$invitation, $token] = Invitation::issue(['role' => 'curator']);

        $this->register(['invitation' => $token])->assertRedirect(route('dashboard', absolute: false));

        $this->assertAuthenticated();
        $user = User::where('email', 'test@example.com')->firstOrFail();
        $this->assertTrue($user->hasRole('curator'));
        $this->assertNotNull($user->email_verified_at);
        $this->assertSame($user->id, $invitation->fresh()->accepted_by);
    }

    public function test_users_cannot_register_without_an_invitation()
    {
        $this->register()->assertSessionHasErrors('invitation');
        $this->assertGuest();
    }

    public function test_invitations_can_only_be_used_once()
    {
        [, $token] = Invitation::issue();
        $this->register(['invitation' => $token]);
        auth()->logout();

        $this->register(['invitation' => $token, 'email' => 'other@example.com'])->assertSessionHasErrors('invitation');
    }

    public function test_expired_invitations_are_rejected()
    {
        [$invitation, $token] = Invitation::issue();
        $invitation->update(['expires_at' => now()->subMinute()]);

        $this->register(['invitation' => $token])->assertSessionHasErrors('invitation');
    }

    public function test_invitations_for_a_specific_email_reject_other_emails()
    {
        [, $token] = Invitation::issue(['email' => 'teacher@example.com']);

        $this->register(['invitation' => $token])->assertSessionHasErrors('email');
        $this->register(['invitation' => $token, 'email' => 'teacher@example.com'])->assertSessionHasNoErrors();
    }

    public function test_the_invite_command_prints_a_registration_link()
    {
        $this->artisan('kancil:invite', ['--role' => 'admin'])
            ->expectsOutputToContain('/register?invitation=')
            ->assertSuccessful();

        $this->assertDatabaseHas('invitations', ['role' => 'admin']);
    }
}
