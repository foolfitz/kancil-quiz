<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

// Filament 後台只給管理員與審核者使用（docs/SPEC.md A-01、10.1）
class AdminPanelTest extends TestCase
{
    use RefreshDatabase;

    private function userWithRole(?string $role): User
    {
        $user = User::factory()->create();
        if ($role !== null) {
            $user->assignRole($role);
        }

        return $user;
    }

    public function test_admins_can_manage_users_languages_and_invitations(): void
    {
        $admin = $this->userWithRole('admin');

        foreach (['/admin', '/admin/users', '/admin/languages', '/admin/curriculum-refs', '/admin/invitations', '/admin/invitations/create'] as $url) {
            $this->actingAs($admin)->get($url)->assertOk();
        }

        // 使用者表單可以指定審核者負責的語言
        $this->get("/admin/users/{$admin->id}/edit")->assertOk()->assertSee('負責審核的語言');
    }

    public function test_curators_can_only_invite(): void
    {
        $curator = $this->userWithRole('curator');

        $this->actingAs($curator)->get('/admin/invitations')->assertOk();
        $this->actingAs($curator)->get('/admin/users')->assertForbidden();
        $this->actingAs($curator)->get('/admin/languages')->assertForbidden();
    }

    public function test_teachers_cannot_use_the_admin_panel(): void
    {
        $this->actingAs($this->userWithRole('teacher'))->get('/admin')->assertForbidden();
    }
}
