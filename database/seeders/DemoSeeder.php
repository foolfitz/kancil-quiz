<?php

namespace Database\Seeders;

use App\Corpus\SetWriter;
use App\Games\GameRegistry;
use App\Models\CurriculumRef;
use App\Models\Set;
use App\Models\User;
use Illuminate\Database\Seeder;
use RuntimeException;

/**
 * 本機試用與壓力測試用的示範資料：
 *
 * - 管理員、示範老師：示範老師擁有 packages/schema/fixtures 中印尼語、越南語的題組，
 *   每個題組各有選擇題與迷宮追逐兩個活動。
 * - 林老師：在共備庫有兩個公開的題組，另有一個等待審核（docs/SPEC.md M2）。
 * - 審核者：負責印尼語與越南語。
 * - 兩種語言第 1 冊第 1 到 6 課的教材對照（只有冊課，沒有教材內容，D-4）。
 *
 *   php artisan db:seed --class=DemoSeeder
 */
class DemoSeeder extends Seeder
{
    private const FIXTURES = ['id-vocab-fruits', 'id-quiz-greetings', 'vi-vocab-fruits', 'vi-quiz-greetings'];

    public function run(SetWriter $writer): void
    {
        if (app()->isProduction()) {
            throw new RuntimeException('DemoSeeder 不可在正式環境執行。');
        }

        $admin = User::firstOrCreate(['email' => 'admin@example.com'], [
            'name' => '管理員', 'password' => 'password', 'email_verified_at' => now(),
        ]);
        $admin->syncRoles(['admin']);

        $teacher = $this->user('teacher@example.com', '示範老師', 'teacher');
        $colleague = $this->user('lin@example.com', '林老師', 'teacher');
        $curator = $this->user('curator@example.com', '陳審核', 'curator');
        $curator->reviewLanguages()->sync(['id', 'vi']);

        foreach (['id', 'vi'] as $language) {
            foreach (range(1, 6) as $lesson) {
                CurriculumRef::firstOrCreate(['language_code' => $language, 'volume' => 1, 'lesson' => $lesson]);
            }
        }

        foreach (self::FIXTURES as $name) {
            $set = $this->set($writer, $name, $teacher);

            foreach (['quiz', 'maze-chase'] as $game) {
                $this->activity($set, $game, $teacher);
            }
        }

        // 共備庫：林老師的公開題組與一個待審的題組
        foreach (['vi-vocab-fruits' => ['水果', 4], 'id-quiz-greetings' => ['問候', 1]] as $name => [$tag, $lesson]) {
            $this->set($writer, $name, $colleague, ['tags' => [$tag], 'visibility' => 'public', 'review_status' => 'approved'], $lesson);
        }
        $pending = $this->set($writer, 'id-vocab-fruits', $colleague, ['review_status' => 'pending']);
        $pending->reviews()->create([
            'set_revision_id' => $pending->current_revision_id,
            'user_id' => $colleague->id,
            'action' => 'requested',
            'note' => '適合三、四年級',
        ]);

        $this->command->info('老師：teacher@example.com、lin@example.com；審核者：curator@example.com；管理員：admin@example.com（密碼都是 password）');
        foreach ($teacher->activities()->with('set')->get() as $activity) {
            $this->command->line(sprintf('%-28s %-10s %s', $activity->set->title, $activity->game_id, route('play', $activity)));
        }
    }

    private function user(string $email, string $name, string $role): User
    {
        $user = User::firstOrCreate(['email' => $email], [
            'name' => $name, 'password' => 'password', 'email_verified_at' => now(),
        ]);
        $user->syncRoles([$role]);

        return $user;
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @param  int|null  $lesson  對應第 1 冊的第幾課
     */
    private function set(SetWriter $writer, string $fixture, User $owner, array $attributes = [], ?int $lesson = null): Set
    {
        $data = $this->fixture($fixture);
        $set = $owner->sets()->create([
            'kind' => $data['kind'],
            'title' => $data['title'],
            'language_code' => $data['language'],
            'license' => $data['license'],
            'faces' => $data['faces'] ?? null,
            ...$attributes,
        ]);
        if ($lesson !== null) {
            $set->curriculumRefs()->sync(CurriculumRef::where(['language_code' => $set->language_code, 'volume' => 1, 'lesson' => $lesson])->pluck('id'));
        }
        $writer->write($set, $this->content($fixture), $owner);

        return $set->refresh();
    }

    /**
     * @return array<string, mixed>
     */
    private function fixture(string $name): array
    {
        return json_decode((string) file_get_contents(base_path("packages/schema/fixtures/sets/{$name}/set.json")), true, flags: JSON_THROW_ON_ERROR);
    }

    /**
     * SetWriter 的輸入格式。
     *
     * @return array<string, mixed>
     */
    private function content(string $name): array
    {
        $fixture = $this->fixture($name);

        return [
            'faces' => $fixture['faces'] ?? null,
            'entries' => array_map(fn (array $entry) => $fixture['kind'] === 'vocab'
                ? ['item' => $entry['item']]
                : ['question' => $entry['question']], $fixture['entries']),
        ];
    }

    private function activity(Set $set, string $game, User $owner): void
    {
        $manifest = app(GameRegistry::class)->get($game);

        $set->activities()->create([
            'game_id' => $game,
            'game_version' => $manifest['version'],
            'options' => $manifest['defaultOptions'],
            'owner_id' => $owner->id,
        ]);
    }
}
