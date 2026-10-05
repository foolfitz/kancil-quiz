<?php

namespace Tests\Feature;

use App\Auth\AccountDeletion;
use App\Corpus\SetWriter;
use App\Filament\Resources\Users\Pages\ListUsers;
use App\Models\Activity;
use App\Models\Set;
use App\Models\User;
use App\Support\SessionHandler;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * 帳號的刪除（匿名化，第 5 節）、停用（A-05）、第一位管理員（kancil:create-admin），以及工作階段不存 IP（第 11 節）。
 */
class AccountTest extends TestCase
{
    use RefreshDatabase;

    private User $teacher;

    private User $colleague;

    protected function setUp(): void
    {
        parent::setUp();

        $this->teacher = User::factory()->create(['name' => '林老師', 'google_id' => '1001']);
        $this->teacher->assignRole('teacher');
        $this->colleague = User::factory()->create();
        $this->colleague->assignRole('teacher');
    }

    /**
     * @param  'private'|'unlisted'|'public'  $visibility
     */
    private function set(string $visibility, string $title): Set
    {
        $set = Set::factory()->for($this->teacher, 'owner')->create(['language_code' => 'id', 'title' => $title]);
        app(SetWriter::class)->write($set, [
            'faces' => ['prompt' => ['translation_zh'], 'answer' => ['text']],
            'entries' => [
                ['item' => ['text' => 'ayah', 'translation_zh' => '爸爸']],
                ['item' => ['text' => 'ibu', 'translation_zh' => '媽媽']],
            ],
        ], $this->teacher);
        $set->update([
            'visibility' => $visibility,
            'share_token' => $visibility === 'unlisted' ? str_repeat('a', 32) : null,
            'review_status' => $visibility === 'public' ? 'approved' : 'none',
        ]);

        return $set->refresh();
    }

    private function activity(Set $set): Activity
    {
        return $set->activities()->create([
            'game_id' => 'quiz', 'game_version' => '0.1.0', 'options' => [], 'owner_id' => $this->teacher->id,
        ]);
    }

    private function admin(): User
    {
        $admin = User::factory()->create();
        $admin->assignRole('admin');

        return $admin;
    }

    public function test_deleting_an_account_anonymizes_it(): void
    {
        $private = $this->set('private', '我的家人');
        $unlisted = $this->set('unlisted', '爺爺早安');
        $public = $this->set('public', '我的名字');
        $activity = $this->activity($private);
        $this->activity($public);
        $this->teacher->assignRole('curator');
        $this->teacher->reviewLanguages()->attach('id');
        DB::table('sessions')->insert(['id' => 'session-1', 'user_id' => $this->teacher->id, 'payload' => '', 'last_activity' => time()]);

        app(AccountDeletion::class)->delete($this->teacher);

        $user = $this->teacher->fresh();
        $this->assertTrue($user->isAnonymized());
        $this->assertSame(['已刪除的使用者', "deleted-{$user->id}@kancil-quiz.invalid", null, null], [$user->name, $user->email, $user->google_id, $user->password]);
        $this->assertSame([], $user->getRoleNames()->all());
        $this->assertSame(0, $user->reviewLanguages()->count());
        $this->assertSame(0, DB::table('sessions')->where('user_id', $user->id)->count());

        // 活動與沒有公開的題組先 soft delete，30 天後由 kancil:prune 刪除
        $this->assertSame(2, Activity::onlyTrashed()->count());
        $this->assertTrue($private->fresh()->trashed());
        $this->assertTrue($unlisted->fresh()->trashed());
        $this->get("/p/{$activity->id}")->assertNotFound();

        // 公開的題組留在共備庫，署名照舊
        $this->assertFalse($public->fresh()->trashed());
        $this->assertSame([['name' => '林老師']], $public->fresh()->effectiveAuthors());
        $this->actingAs($this->colleague)->get('/library')->assertInertia(fn (Assert $page) => $page
            ->has('sets.data', 1)
            ->where('sets.data.0.title', '我的名字'));
    }

    public function test_admins_disable_and_enable_accounts(): void
    {
        $public = $this->set('public', '我的名字');
        $unlisted = $this->set('unlisted', '爺爺早安');
        $activity = $this->activity($public);
        $admin = $this->admin();

        $this->actingAs($admin);
        Livewire::test(ListUsers::class)
            ->assertTableActionHidden('disable', $admin)
            ->callTableAction('disable', $this->teacher);
        $this->assertTrue($this->teacher->fresh()->isDisabled());

        // 已經登入的老師在下一個請求被登出
        $this->actingAs($this->teacher->fresh())->get('/dashboard')
            ->assertRedirect(route('login'))
            ->assertSessionHasErrors('google');
        $this->assertGuest();

        // 活動連結、分享連結與開放資料都失效，公開的題組不再列在共備庫
        $this->get("/p/{$activity->id}")->assertNotFound();
        $this->getJson("/api/v1/activities/{$activity->id}")->assertNotFound();
        $this->postJson("/api/v1/activities/{$activity->id}/attempts", [])->assertNotFound();
        $this->postJson("/api/v1/activities/{$activity->id}/reports", ['reason' => '廣告'])->assertNotFound();
        $this->get("/api/v1/sets/{$public->id}/export")->assertNotFound();
        $this->actingAs($this->colleague)->get('/library')->assertInertia(fn (Assert $page) => $page->has('sets.data', 0));
        $this->actingAs($this->colleague)->get("/sets/{$public->id}")->assertForbidden();
        $this->actingAs($this->colleague)->get("/shared/{$unlisted->share_token}")->assertNotFound();

        // 審核者仍看得到，可以下架
        $curator = User::factory()->create();
        $curator->assignRole('curator');
        $curator->reviewLanguages()->attach('id');
        $this->actingAs($curator)->get("/sets/{$public->id}")->assertOk();

        $this->actingAs($admin);
        Livewire::test(ListUsers::class)->callTableAction('enable', $this->teacher);
        $this->assertFalse($this->teacher->fresh()->isDisabled());
        $this->getJson("/api/v1/activities/{$activity->id}")->assertOk();
        $this->actingAs($this->colleague)->get('/library')->assertInertia(fn (Assert $page) => $page->has('sets.data', 1));
    }

    public function test_admins_delete_accounts_for_teachers(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin);
        Livewire::test(ListUsers::class)
            ->assertTableActionHidden('anonymize', $admin)
            ->callTableAction('anonymize', $this->teacher);

        $this->assertTrue($this->teacher->fresh()->isAnonymized());
        // 刪除的帳號預設不列出
        Livewire::test(ListUsers::class)
            ->assertCanNotSeeTableRecords([$this->teacher])
            ->assertCanSeeTableRecords([$this->colleague]);
    }

    public function test_the_first_admin_is_created_from_the_command_line(): void
    {
        $this->artisan('kancil:create-admin', ['email' => 'Admin@Example.org'])
            ->expectsQuestion('設定密碼', 'secret-pass-123')
            ->expectsQuestion('再輸入一次', 'secret-pass-123')
            ->assertSuccessful();
        $admin = User::where('email', 'admin@example.org')->sole();
        $this->assertTrue($admin->hasRole('admin'));
        $this->assertNotNull($admin->email_verified_at);
        $this->post(route('login.store'), ['email' => 'admin@example.org', 'password' => 'secret-pass-123']);
        $this->assertAuthenticatedAs($admin);

        // 已經用 Google 登入過的人：加上角色，設定密碼
        $this->teacher->forceFill(['password' => null])->save();
        $this->artisan('kancil:create-admin', ['email' => $this->teacher->email])
            ->expectsQuestion('設定密碼', 'other-pass-456')
            ->expectsQuestion('再輸入一次', 'typo')
            ->assertFailed();
        $this->assertFalse($this->teacher->fresh()->hasRole('admin'));
        $this->artisan('kancil:create-admin', ['email' => $this->teacher->email])
            ->expectsQuestion('設定密碼', 'other-pass-456')
            ->expectsQuestion('再輸入一次', 'other-pass-456')
            ->assertSuccessful();
        $this->assertTrue($this->teacher->fresh()->hasRole('admin'));
        $this->assertTrue($this->teacher->fresh()->hasPassword());
        $this->assertSame(2, User::role('admin')->count());

        // 已經有密碼的管理員：不再詢問
        $this->artisan('kancil:create-admin', ['email' => 'admin@example.org'])->assertSuccessful();
    }

    public function test_sessions_do_not_store_ip_addresses(): void
    {
        $this->app->instance('request', Request::create('/', server: ['REMOTE_ADDR' => '203.0.113.5', 'HTTP_USER_AGENT' => 'Safari']));
        $handler = $this->app['session']->driver('database')->getHandler();
        $this->assertInstanceOf(SessionHandler::class, $handler);

        $handler->write('session-1', 'payload');

        $this->assertDatabaseHas('sessions', ['id' => 'session-1', 'ip_address' => null, 'user_agent' => null]);
    }

    public function test_teachers_without_a_password_have_no_security_settings(): void
    {
        $this->teacher->forceFill(['password' => null])->save();

        $this->actingAs($this->teacher)->get('/settings/profile')
            ->assertInertia(fn (Assert $page) => $page->where('auth.hasPassword', false));
        $this->actingAs($this->teacher)->get('/settings/security')->assertNotFound();
        $this->actingAs($this->teacher)->put('/settings/password', [])->assertNotFound();
    }
}
