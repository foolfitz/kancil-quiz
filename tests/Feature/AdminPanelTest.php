<?php

namespace Tests\Feature;

use App\Filament\Resources\Users\Pages\EditUser;
use App\Models\Media;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Livewire\Livewire;
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

    public function test_admins_can_manage_users_languages_and_reports(): void
    {
        $admin = $this->userWithRole('admin');

        foreach (['/admin', '/admin/users', '/admin/languages', '/admin/curriculum-refs', '/admin/reports', '/admin/play-stats'] as $url) {
            $this->actingAs($admin)->get($url)->assertOk();
        }

        // 選單可以回到老師端的首頁
        $this->get('/admin')->assertSee('回到首頁')->assertSee(route('dashboard'));

        // 使用者表單可以指定審核者負責的語言
        $this->get("/admin/users/{$admin->id}/edit")->assertOk()->assertSee('負責審核的語言');
    }

    /**
     * 個別老師的上傳上限（docs/SPEC.md A-05、第 9 節）：列表與表單顯示用量，管理員可以調整，審核者進不了使用者頁。
     */
    public function test_admins_adjust_the_upload_quota_of_a_teacher(): void
    {
        config(['kancil.upload_quota_mb' => 200]);
        $admin = $this->userWithRole('admin');
        $teacher = $this->userWithRole('teacher');
        Media::create(['kind' => 'image', 'path' => 'media/a.webp', 'mime' => 'image/webp', 'bytes' => 37 * 1024 * 1024, 'uploaded_by' => $teacher->id]);
        Media::create(['kind' => 'image', 'path' => 'media/b.webp', 'mime' => 'image/webp', 'bytes' => 1024, 'uploaded_by' => $admin->id]);

        $this->actingAs($admin);
        $this->get('/admin/users')->assertOk()->assertSee('37 MB／200 MB')->assertSee('1 KB（不限）');
        $this->get("/admin/users/{$teacher->id}/edit")->assertOk()->assertSee('上傳上限（MB）')->assertSee('目前已上傳 37 MB／200 MB');

        Livewire::test(EditUser::class, ['record' => $teacher->getRouteKey()])
            ->fillForm(['upload_quota_mb' => 500])
            ->call('save')
            ->assertHasNoFormErrors();
        $this->assertSame(500, $teacher->fresh()->upload_quota_mb);
        $this->get('/admin/users')->assertSee('37 MB／500 MB');

        // 調到比已上傳的還少也可以，之後不能再上傳；留空就回到預設值
        Livewire::test(EditUser::class, ['record' => $teacher->getRouteKey()])
            ->fillForm(['upload_quota_mb' => 30])
            ->call('save')
            ->assertHasNoFormErrors();
        $this->get('/admin/users')->assertSee('37 MB／30 MB');
        Livewire::test(EditUser::class, ['record' => $teacher->getRouteKey()])
            ->fillForm(['upload_quota_mb' => -1])
            ->call('save')
            ->assertHasFormErrors(['upload_quota_mb']);
        Livewire::test(EditUser::class, ['record' => $teacher->getRouteKey()])
            ->fillForm(['upload_quota_mb' => null])
            ->call('save')
            ->assertHasNoFormErrors();
        $this->assertNull($teacher->fresh()->upload_quota_mb);

        // 審核者不能改
        $this->actingAs($this->userWithRole('curator'));
        $this->get("/admin/users/{$teacher->id}/edit")->assertForbidden();
        Livewire::test(EditUser::class, ['record' => $teacher->getRouteKey()])->assertForbidden();
        $this->assertNull($teacher->fresh()->upload_quota_mb);
    }

    public function test_curators_only_see_the_popularity_of_lessons(): void
    {
        $curator = $this->userWithRole('curator');

        $this->actingAs($curator)->get('/admin/play-stats')->assertOk()->assertSee('回到首頁');
        foreach (['/admin/users', '/admin/languages', '/admin/reports', '/admin/curriculum-refs'] as $url) {
            $this->actingAs($curator)->get($url)->assertForbidden();
        }
    }

    public function test_guests_are_sent_to_the_site_login(): void
    {
        // 後台沒有自己的登入頁：審核者用 Google 登入（docs/SPEC.md T-03）
        $this->get('/admin')->assertRedirect(route('login'));
    }

    public function test_teachers_cannot_use_the_admin_panel(): void
    {
        $this->actingAs($this->userWithRole('teacher'))->get('/admin')->assertForbidden();
    }

    public function test_the_sidebar_links_to_the_admin_panel_only_for_admins_and_curators(): void
    {
        foreach (['admin' => url('/admin'), 'curator' => url('/admin'), 'teacher' => null] as $role => $url) {
            $this->actingAs($this->userWithRole($role))->get('/dashboard')
                ->assertInertia(fn (Assert $page) => $page->where('auth.adminUrl', $url));
        }
    }
}
