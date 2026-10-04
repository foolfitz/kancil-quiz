<?php

namespace Database\Seeders;

use App\Corpus\SetCopier;
use App\Corpus\SetRevisionRecorder;
use App\Corpus\SetWriter;
use App\Curriculum\CurriculumImporter;
use App\Games\GameRegistry;
use App\Models\CurriculumRef;
use App\Models\Set;
use App\Models\SetEntry;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Seeder;
use RuntimeException;

/**
 * 本機試用與壓力測試用的示範資料：
 *
 * - 教材：匯入 database/curriculum/id/1，印尼語第 1 冊第 1 到 4 課的課名與詞彙（docs/SPEC.md 3.6）。
 * - 示範老師：用教材第 1、3 課直接建立的活動，以及從第 1、2 課挑詞組成的題組。
 *   越南語還沒有教材資料，沿用 packages/schema/fixtures 中的越南語題組（E2E 用來檢查聲調符號），
 *   各有選擇題與迷宮追逐兩個活動，不對應冊課。
 * - 示範同事：在共備庫有兩個由教材改編的公開題組，另有一個等待審核（M2）。
 * - 審核者：負責印尼語與越南語。
 *
 *   php artisan db:seed --class=DemoSeeder
 */
class DemoSeeder extends Seeder
{
    private const FIXTURES = ['vi-vocab-fruits', 'vi-quiz-greetings'];

    public function run(SetWriter $writer, CurriculumImporter $importer, SetCopier $copier, SetRevisionRecorder $recorder): void
    {
        if (app()->isProduction()) {
            throw new RuntimeException('DemoSeeder 不可在正式環境執行。');
        }

        $admin = User::firstOrCreate(['email' => 'admin@example.com'], [
            'name' => '管理員', 'password' => 'password', 'email_verified_at' => now(),
        ]);
        $admin->syncRoles(['admin']);

        $teacher = $this->user('teacher@example.com', '示範老師', 'teacher');
        $colleague = $this->user('colleague@example.com', '示範同事', 'teacher');
        $curator = $this->user('curator@example.com', '審核者', 'curator');
        $curator->reviewLanguages()->sync(['id', 'vi']);

        $importer->import(database_path('curriculum/id/1'));

        foreach (self::FIXTURES as $name) {
            $set = $this->fixtureSet($writer, $name, $teacher);

            foreach (['quiz', 'maze-chase'] as $game) {
                $this->activity($set, $game, $teacher);
            }
        }

        // 示範老師：直接用教材建立活動，並從第 1、2 課挑詞組成自己的題組（T-18）
        $this->activity($this->lesson(1), 'quiz', $teacher);
        $this->activity($this->lesson(3), 'maze-chase', $teacher);
        $review = $teacher->sets()->create(['kind' => 'vocab', 'title' => '第 1 冊第 1、2 課複習', 'language_code' => 'id', 'license' => 'CC-BY-4.0']);
        $copier->compose($review, $this->words([1 => null, 2 => null]), $teacher);

        // 共備庫：示範同事由教材改編的公開題組，以及一個待審的題組
        $family = $copier->copy($this->lesson(3), $colleague);
        $this->adapt($recorder, $family, $colleague, '第 1 冊第 3 課 我的家人（看圖選詞）', ['prompt' => ['image'], 'answer' => ['text']], ['家人'], public: true);

        $greetings = $colleague->sets()->create(['kind' => 'vocab', 'title' => '打招呼與禮貌用語', 'language_code' => 'id', 'license' => 'CC-BY-4.0']);
        $copier->compose($greetings, $this->words([
            1 => ['selamat pagi'],
            2 => ['terima kasih', 'maaf', 'tidak apa-apa', 'silakan'],
            4 => ['sampai jumpa'],
        ]), $colleague);
        $this->adapt($recorder, $greetings, $colleague, '打招呼與禮貌用語（第 1 冊）', ['prompt' => ['translation_zh'], 'answer' => ['text']], ['問候'], public: true);

        $pending = $copier->copy($this->lesson(2), $colleague);
        $this->adapt($recorder, $pending, $colleague, '第 1 冊第 2 課 請坐（看中文選詞）', ['prompt' => ['translation_zh'], 'answer' => ['text']], ['禮貌'], public: false);
        $pending->update(['review_status' => 'pending']);
        $pending->reviews()->create([
            'set_revision_id' => $pending->current_revision_id,
            'user_id' => $colleague->id,
            'action' => 'requested',
            'note' => '適合三、四年級',
        ]);

        $this->command->info('老師：teacher@example.com、colleague@example.com；審核者：curator@example.com；管理員：admin@example.com（密碼都是 password）');
        foreach ($teacher->activities()->with('set')->get() as $activity) {
            $this->command->line(sprintf('%-40s %-10s %s', $activity->set->title, $activity->game_id, route('play', $activity)));
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
     * 印尼語第 1 冊某一課的教材題組。
     */
    private function lesson(int $lesson): Set
    {
        $set = CurriculumRef::where(['language_code' => 'id', 'volume' => 1, 'lesson' => $lesson])->firstOrFail()->set;

        return $set ?? throw new RuntimeException("第 1 冊第 {$lesson} 課沒有教材題組");
    }

    /**
     * 從教材挑詞，依課的順序。
     *
     * @param  array<int, list<string>|null>  $lessons  課 => 要挑的詞，null 表示整課
     * @return Collection<int, SetEntry>
     */
    private function words(array $lessons): Collection
    {
        $entries = new Collection;
        foreach ($lessons as $lesson => $texts) {
            $this->lesson($lesson)->entries()->with('item')->get()
                ->filter(fn (SetEntry $entry) => $texts === null || in_array($entry->item->text, $texts, true))
                ->each(fn (SetEntry $entry) => $entries->push($entry));
        }

        return $entries;
    }

    /**
     * 示範同事改編教材：改標題、題目的呈現方式與標籤，公開的題組設為已通過審核。
     *
     * @param  array{prompt: list<string>, answer: list<string>}  $faces
     * @param  list<string>  $tags
     */
    private function adapt(SetRevisionRecorder $recorder, Set $set, User $owner, string $title, array $faces, array $tags, bool $public): void
    {
        $set->update([
            'title' => $title,
            'faces' => $faces,
            'tags' => $tags,
            ...($public ? ['visibility' => 'public', 'review_status' => 'approved'] : []),
        ]);
        $recorder->record($set, $owner);
    }

    private function fixtureSet(SetWriter $writer, string $fixture, User $owner): Set
    {
        $data = $this->fixture($fixture);
        $set = $owner->sets()->create([
            'kind' => $data['kind'],
            'title' => $data['title'],
            'language_code' => $data['language'],
            'license' => $data['license'],
            'faces' => $data['faces'] ?? null,
        ]);
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
