<?php

namespace App\Corpus;

use App\Models\Item;
use App\Models\Media;
use App\Models\Set;
use App\Models\SetEntry;
use App\Models\User;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * 把老師編輯後的題組內容寫進資料庫，並產生新的題組版本（docs/SPEC.md 3.3）。
 *
 * 詞彙組的輸入：
 *   faces: {prompt: [...], answer: [...]}
 *   entries: [{id?, item: {text, romanization?, translation_zh, audio_ids: [], image_id?, authors?, license?, source?}}]
 * 問答組的輸入：
 *   entries: [{id?, question: {stem: {text?, audio_id?, image_id?}, options: [{id, text?, image_id?, correct}]}}]
 */
class SetWriter
{
    public function __construct(private SetRevisionRecorder $recorder) {}

    /**
     * @param  array<string, mixed>  $content
     */
    public function write(Set $set, array $content, User $by): void
    {
        $this->authorizeMedia($set, $content['entries'] ?? [], $by);

        $changedItems = DB::transaction(function () use ($set, $content, $by) {
            if ($set->kind === 'vocab') {
                $set->update(['faces' => $content['faces']]);

                return $this->writeVocab($set, $content['entries'] ?? [], $by);
            }

            $this->writeQuiz($set, $content['entries'] ?? []);

            return [];
        });

        $this->recorder->record($set, $by);

        // 同一個詞條可能被擁有者的其他題組引用，那些題組也要產生新版本。
        foreach ($changedItems as $item) {
            $this->recorder->recordForItem($item, $by);
        }
    }

    /**
     * @param  array<int, array<string, mixed>>  $entries
     * @return list<Item> 內容有變動的既有詞條
     */
    private function writeVocab(Set $set, array $entries, User $by): array
    {
        $existing = $set->entries()->with('item')->get()->keyBy('id');
        $kept = [];
        $changed = [];

        foreach (array_values($entries) as $position => $input) {
            $entry = isset($input['id']) ? $existing->get($input['id']) : null;
            $attributes = [
                'language_code' => $set->language_code,
                'text' => $input['item']['text'],
                'romanization' => $input['item']['romanization'] ?? null,
                'translation_zh' => $input['item']['translation_zh'],
                // 只有匯入教材時會指定（App\Curriculum\CurriculumImporter）；老師的編輯畫面沒有這些欄位，維持原值
                ...Arr::only($input['item'], ['authors', 'license', 'source']),
            ];

            $item = $entry?->item;
            if ($item === null) {
                $item = Item::create([...$attributes, 'owner_id' => $set->owner_id]);
            } else {
                $item->fill($attributes);
                if ($item->isDirty()) {
                    $this->creditEditor($item, $set, $by);
                    $item->save();
                    $changed[$item->id] = $item;
                }
            }

            $media = [];
            foreach (array_values($input['item']['audio_ids'] ?? []) as $i => $id) {
                $media[$id] = ['role' => 'audio', 'position' => $i];
            }
            if (! empty($input['item']['image_id'])) {
                $media[$input['item']['image_id']] = ['role' => 'image', 'position' => 0];
            }
            $sync = $item->media()->sync($media);
            if ($entry !== null && array_filter($sync) !== []) {
                $changed[$item->id] = $item;
            }

            if ($entry === null) {
                $entry = $set->entries()->create(['position' => $position, 'item_id' => $item->id]);
            } else {
                $entry->update(['position' => $position]);
            }
            $kept[] = $entry->id;
        }

        $this->deleteEntriesExcept($set, $kept);

        return array_values(array_filter($changed, fn (Item $item) => $item->entries()->where('set_id', '!=', $set->id)->exists()));
    }

    /**
     * 老師修改複製來的詞條時，把自己加進該詞條的作者（docs/SPEC.md 第 5 節）。
     * 審核者修正別人的題組（C-03）不算作者，由題組版本記錄是誰修改的。
     */
    private function creditEditor(Item $item, Set $set, User $by): void
    {
        if ($item->forked_from_id === null || $by->id !== $set->owner_id) {
            return;
        }

        $authors = $item->authors ?? [];
        if (! in_array($by->name, array_column($authors, 'name'), true)) {
            $item->authors = [...$authors, ['name' => $by->name]];
        }
    }

    /**
     * @param  array<int, array<string, mixed>>  $entries
     */
    private function writeQuiz(Set $set, array $entries): void
    {
        $existing = $set->entries()->get()->keyBy('id');
        $kept = [];

        foreach (array_values($entries) as $position => $input) {
            $question = $input['question'];
            $payload = [
                'stem' => array_filter(Arr::only($question['stem'] ?? [], ['text', 'audio_id', 'image_id']), fn ($value) => $value !== null && $value !== ''),
                'options' => array_map(fn (array $option) => array_filter([
                    'id' => $option['id'],
                    'text' => $option['text'] ?? null,
                    'image_id' => $option['image_id'] ?? null,
                    'correct' => (bool) $option['correct'],
                ], fn ($value) => $value !== null && $value !== ''), array_values($question['options'])),
            ];

            $entry = isset($input['id']) ? $existing->get($input['id']) : null;
            if ($entry === null) {
                $entry = $set->entries()->create(['position' => $position, 'payload' => $payload]);
            } else {
                $entry->update(['position' => $position, 'payload' => $payload]);
            }
            $kept[] = $entry->id;
        }

        $this->deleteEntriesExcept($set, $kept);
    }

    /**
     * @param  list<string>  $kept
     */
    private function deleteEntriesExcept(Set $set, array $kept): void
    {
        $set->entries()->whereNotIn('id', $kept)->with('item')->get()->each(function (SetEntry $entry) {
            $item = $entry->item;
            $entry->delete();
            if ($item !== null && ! $item->entries()->exists()) {
                $item->delete();
            }
        });
    }

    /**
     * 只能使用自己上傳的媒體，或這個題組原本就引用的媒體（例如複製來的題組）。
     *
     * @param  array<int, array<string, mixed>>  $entries
     */
    private function authorizeMedia(Set $set, array $entries, User $by): void
    {
        $requested = collect($entries)->flatMap(fn (array $entry) => [
            ...($entry['item']['audio_ids'] ?? []),
            $entry['item']['image_id'] ?? null,
            $entry['question']['stem']['audio_id'] ?? null,
            $entry['question']['stem']['image_id'] ?? null,
            ...array_map(fn ($option) => $option['image_id'] ?? null, $entry['question']['options'] ?? []),
        ])->filter()->unique()->values();

        if ($requested->isEmpty()) {
            return;
        }

        $inRevision = MediaUrls::idsInJson((string) $set->currentRevision?->getAttribute('content'));

        $allowed = Media::whereIn('id', $requested)
            ->where(fn ($query) => $query->where('uploaded_by', $by->id)->orWhereIn('id', $inRevision))
            ->pluck('id');

        if ($requested->diff($allowed)->isNotEmpty()) {
            throw ValidationException::withMessages(['entries' => '只能使用自己上傳的音檔與圖片。']);
        }
    }
}
