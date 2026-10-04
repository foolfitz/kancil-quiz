<?php

namespace App\Console\Commands;

use App\Curriculum\CurriculumImporter;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Validation\ValidationException;

/**
 * 匯入一冊教材的課名與詞彙，每一課產生一個教材題組（docs/SPEC.md 3.6）。
 *
 *   php artisan kancil:import-curriculum database/curriculum/id/1
 */
#[Signature('kancil:import-curriculum {directory : 一冊教材的目錄，內含 volume.json 與插圖}
    {--force : 覆寫審核者在網站上修正過的教材題組}
    {--refresh-images : 重新匯入插圖（換圖時使用）}')]
#[Description('匯入一冊教材的課名與詞彙，產生教材題組')]
class ImportCurriculumCommand extends Command
{
    public function handle(CurriculumImporter $importer): int
    {
        try {
            $results = $importer->import((string) $this->argument('directory'), (bool) $this->option('force'), (bool) $this->option('refresh-images'));
        } catch (ValidationException $e) {
            foreach ($e->errors() as $messages) {
                foreach ($messages as $message) {
                    $this->error($message);
                }
            }

            return self::FAILURE;
        }

        $labels = ['created' => '新增', 'updated' => '更新', 'unchanged' => '沒有變動', 'skipped' => '略過：審核者在網站上修正過'];
        $this->table(['課', '題組', '詞數', '結果'], array_map(fn (array $row) => [
            $row['lesson'], $row['title'], $row['words'], $labels[$row['result']],
        ], $results));

        if (in_array('skipped', array_column($results, 'result'), true)) {
            $this->warn('略過的課請先把網站上的修正寫回資料檔，再加上 --force 匯入。');
        }

        return self::SUCCESS;
    }
}
