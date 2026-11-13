<?php

namespace App\Curriculum;

use App\Corpus\SetWriter;
use App\Media\MediaProcessor;
use App\Models\CurriculumRef;
use App\Models\Media;
use App\Models\Set;
use App\Models\SetEntry;
use App\Models\User;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;

/**
 * 匯入一冊教材的課名、詞彙與課文句子（docs/SPEC.md 3.6）。資料格式見 database/curriculum/README.md 與 VolumeFile。
 *
 * - 每一課建立或更新冊課對照，並有一個教材題組：公開的詞彙組，由教材帳號擁有（Textbook::owner()）。
 * - 資料檔有課文的課，另有一個句子題組（unit 為 sentence），規則與詞彙題組相同。資料檔沒有課文時，既有的句子題組不動。
 * - 可以重複執行。詞條以目標語文字對應，題目的 ID 不變；內容與上一版相同時不產生新版本。
 * - 審核者在網站上修正過的教材題組不覆寫（$force 除外），以免蓋掉修正：請先把修正寫回資料檔。
 * - 插圖只在詞條還沒有圖片時匯入（媒體不可變，第 9 節）；要換圖時用 $refreshImages。插圖的署名會依資料檔更新。
 *   資料檔沒寫插圖的詞保留原本的圖：在後台上傳的資料檔不含插圖，插圖另外上傳（CurriculumImages）。
 *
 * @phpstan-import-type Volume from VolumeFile
 * @phpstan-import-type Lesson from VolumeFile
 *
 * @phpstan-type Outcome 'created'|'updated'|'unchanged'|'skipped'
 * @phpstan-type Result array{lesson: int, title: string, words: int, result: Outcome, sentences: int, sentences_result: Outcome|null}
 */
class CurriculumImporter
{
    /** @var array<string, Media> 同一次匯入中，同一個圖檔只轉檔一次（例如兩課都有的詞） */
    private array $images = [];

    public function __construct(private SetWriter $writer, private MediaProcessor $media) {}

    /**
     * 匯入 repo 中一冊教材的目錄（volume.json 加插圖）。
     *
     * @return list<Result>
     */
    public function import(string $directory, bool $force = false, bool $refreshImages = false): array
    {
        $directory = rtrim($directory, '/');

        return $this->importVolume(VolumeFile::fromDirectory($directory), $directory, $force, $refreshImages);
    }

    /**
     * @param  Volume  $volume
     * @param  string|null  $directory  插圖路徑的基準目錄；在後台上傳的資料檔沒有插圖，為 null
     * @return list<Result>
     */
    public function importVolume(array $volume, ?string $directory, bool $force = false, bool $refreshImages = false): array
    {
        $owner = Textbook::owner();
        $this->images = [];

        return DB::transaction(fn () => array_map(
            fn (array $lesson) => $this->importLesson($volume, $lesson, $directory, $owner, $force, $refreshImages),
            $volume['lessons'],
        ));
    }

    /**
     * 匯入的結果，但不寫入：在交易中匯入後還原。不指定插圖目錄，所以不會寫入媒體檔。
     *
     * @param  Volume  $volume
     * @return list<Result>
     */
    public function preview(array $volume, bool $force = false): array
    {
        DB::beginTransaction();
        try {
            return $this->importVolume($volume, null, $force);
        } finally {
            DB::rollBack();
        }
    }

    /**
     * 審核者在網站上修正過：最近一次匯入之後，有教材帳號以外的人產生的版本。
     * 在後台上傳插圖的版本記在教材帳號名下，不算修正。詞彙題組與句子題組各自判斷。
     */
    public static function editedOnSite(CurriculumRef $ref, Set $set, User $owner): bool
    {
        $imported = $set->id === $ref->sentence_set_id ? $ref->sentence_imported_revision : $ref->imported_revision;

        return $set->revisions()
            ->where('number', '>', $imported ?? 0)
            ->whereNotNull('created_by')
            ->where('created_by', '!=', $owner->id)
            ->exists();
    }

    /**
     * @param  Volume  $volume
     * @param  Lesson  $lesson
     * @return Result
     */
    private function importLesson(array $volume, array $lesson, ?string $directory, User $owner, bool $force, bool $refreshImages): array
    {
        $ref = CurriculumRef::firstOrNew([
            'language_code' => $volume['language'],
            'volume' => $volume['volume'],
            'lesson' => $lesson['lesson'],
        ]);
        $ref->fill(['title_zh' => $lesson['title_zh'], 'title_native' => $lesson['title_native']])->save();

        $set = $ref->set;
        $result = 'created';
        if ($set === null) {
            $set = $owner->sets()->create(['kind' => 'vocab', 'title' => Textbook::title($ref), 'language_code' => $ref->language_code, 'license' => $volume['license']]);
            $ref->update(['set_id' => $set->id]);
        } elseif (! $force && self::editedOnSite($ref, $set, $owner)) {
            $result = 'skipped';
        } else {
            $result = 'unchanged';
        }

        $summary = [
            'lesson' => $ref->lesson,
            'title' => Textbook::title($ref),
            'words' => count($lesson['vocabulary']),
            'sentences' => count($lesson['sentences']),
            'sentences_result' => $this->importSentences($volume, $lesson, $ref, $owner, $force),
        ];
        if ($result === 'skipped') {
            return [...$summary, 'result' => $result];
        }

        $before = $set->current_revision_id;
        $set->update([
            'title' => Textbook::title($ref),
            'description' => "取自《{$volume['textbook']}》第 {$ref->lesson} 課的詞彙。",
            'license' => $volume['license'],
            'authors' => $volume['authors'],
            'visibility' => 'public',
            'review_status' => 'approved',
        ]);
        $set->curriculumRefs()->sync([$ref->id]);

        $existing = $set->entries()->with('item.media')->get()->keyBy(fn (SetEntry $entry) => Textbook::wordKey($entry->item->text));
        $entries = array_map(function (array $word) use ($volume, $ref, $existing, $directory, $owner, $refreshImages) {
            $entry = $existing->get(Textbook::wordKey($word['text']));
            $media = $entry?->item->media ?? collect();
            $image = $media->firstWhere('pivot.role', 'image');
            if ($word['image'] !== null && $directory !== null && $volume['images'] !== null && ($image === null || $refreshImages)) {
                $image = $this->image("{$directory}/{$word['image']}", $owner, $volume['images']);
            } elseif ($image !== null && $volume['images'] !== null && $image->uploaded_by === $owner->id) {
                // 檔案不可變，署名可以修正（docs/SPEC.md 第 9 節）；審核者上傳的圖不動
                $image->update(Arr::only($volume['images'], ['authors', 'license', 'source']));
            }

            return [
                'id' => $entry?->id,
                'item' => [
                    'text' => $word['text'],
                    'romanization' => $entry?->item->romanization,
                    'translation_zh' => $word['translation_zh'],
                    // 音檔不在資料檔中，保留在網站上加的
                    'audio_ids' => $media->where('pivot.role', 'audio')->pluck('id')->all(),
                    'image_id' => $image?->id,
                    'authors' => $volume['authors'],
                    'license' => $volume['license'],
                    'source' => "《{$volume['textbook']}》第 {$ref->lesson} 課".($word['page'] === null ? '' : "，課本第 {$word['page']} 頁"),
                ],
            ];
        }, $lesson['vocabulary']);

        $this->writer->write($set, ['faces' => Textbook::FACES, 'entries' => $entries], $owner);

        $set->refresh();
        $ref->update(['imported_revision' => $set->currentRevision?->number]);
        if ($result === 'unchanged' && $set->current_revision_id !== $before) {
            $result = 'updated';
        }

        return [...$summary, 'result' => $result];
    }

    /**
     * 這一課的句子題組。資料檔沒有課文時不動既有的句子題組，回傳 null。
     *
     * @param  Volume  $volume
     * @param  Lesson  $lesson
     * @return Outcome|null
     */
    private function importSentences(array $volume, array $lesson, CurriculumRef $ref, User $owner, bool $force): ?string
    {
        if ($lesson['sentences'] === []) {
            return null;
        }

        $set = $ref->sentenceSet;
        $result = 'created';
        if ($set === null) {
            $set = $owner->sets()->create(['kind' => 'vocab', 'unit' => 'sentence', 'title' => Textbook::sentenceTitle($ref), 'language_code' => $ref->language_code, 'license' => $volume['license']]);
            $ref->update(['sentence_set_id' => $set->id]);
        } elseif (! $force && self::editedOnSite($ref, $set, $owner)) {
            return 'skipped';
        } else {
            $result = 'unchanged';
        }

        $before = $set->current_revision_id;
        $set->update([
            'unit' => 'sentence',
            'title' => Textbook::sentenceTitle($ref),
            'description' => "取自《{$volume['textbook']}》第 {$ref->lesson} 課的課文，去掉了說話者。",
            'license' => $volume['license'],
            'authors' => $volume['authors'],
            'visibility' => 'public',
            'review_status' => 'approved',
        ]);
        $set->curriculumRefs()->sync([$ref->id]);

        $existing = $set->entries()->with('item.media')->get()->keyBy(fn (SetEntry $entry) => Textbook::wordKey($entry->item->text));
        $entries = array_map(function (array $sentence) use ($volume, $ref, $existing) {
            $entry = $existing->get(Textbook::wordKey($sentence['text']));
            $media = $entry?->item->media ?? collect();

            return [
                'id' => $entry?->id,
                'item' => [
                    'text' => $sentence['text'],
                    'romanization' => $entry?->item->romanization,
                    'translation_zh' => $sentence['translation_zh'],
                    // 句子的發音與圖片都不在資料檔中，保留在網站上加的
                    'audio_ids' => $media->where('pivot.role', 'audio')->pluck('id')->all(),
                    'image_id' => $media->firstWhere('pivot.role', 'image')?->id,
                    'authors' => $volume['authors'],
                    'license' => $volume['license'],
                    'source' => "《{$volume['textbook']}》第 {$ref->lesson} 課課文".($sentence['page'] === null ? '' : "，課本第 {$sentence['page']} 頁"),
                ],
            ];
        }, $lesson['sentences']);

        $this->writer->write($set, ['faces' => Textbook::SENTENCE_FACES, 'entries' => $entries], $owner);

        $set->refresh();
        $ref->update(['sentence_imported_revision' => $set->currentRevision?->number]);

        return $result === 'unchanged' && $set->current_revision_id !== $before ? 'updated' : $result;
    }

    /**
     * @param  array{authors: list<array{name: string}>, license: string, source: string}  $attribution
     */
    private function image(string $path, User $owner, array $attribution): Media
    {
        return $this->images[$path] ??= $this->media->imageFromPath($path, $owner, $attribution);
    }
}
