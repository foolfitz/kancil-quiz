<?php

namespace Database\Seeders;

use App\Corpus\SetWriter;
use App\Games\GameRegistry;
use App\Models\Set;
use App\Models\User;
use Illuminate\Database\Seeder;
use RuntimeException;

/**
 * 本機試用與壓力測試用的示範資料：一位管理員、一位老師，
 * 以及 packages/schema/fixtures 中印尼語、越南語的題組，每個題組各有選擇題與迷宮追逐兩個活動。
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

        $teacher = User::firstOrCreate(['email' => 'teacher@example.com'], [
            'name' => '示範老師', 'password' => 'password', 'email_verified_at' => now(),
        ]);
        $teacher->syncRoles(['teacher']);

        foreach (self::FIXTURES as $name) {
            $fixture = json_decode((string) file_get_contents(base_path("packages/schema/fixtures/sets/{$name}/set.json")), true, flags: JSON_THROW_ON_ERROR);

            $set = $teacher->sets()->create([
                'kind' => $fixture['kind'],
                'title' => $fixture['title'],
                'language_code' => $fixture['language'],
                'license' => $fixture['license'],
                'faces' => $fixture['faces'] ?? null,
            ]);

            $writer->write($set, [
                'faces' => $fixture['faces'] ?? null,
                'entries' => array_map(fn (array $entry) => $fixture['kind'] === 'vocab'
                    ? ['item' => $entry['item']]
                    : ['question' => $entry['question']], $fixture['entries']),
            ], $teacher);

            foreach (['quiz', 'maze-chase'] as $game) {
                $this->activity($set, $game, $teacher);
            }
        }

        $this->command->info('老師：teacher@example.com／password；管理員：admin@example.com／password');
        foreach ($teacher->activities()->with('set')->get() as $activity) {
            $this->command->line(sprintf('%-28s %-10s %s', $activity->set->title, $activity->game_id, route('play', $activity)));
        }
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
