<?php

namespace App\Corpus;

use App\Models\Item;
use App\Models\Media;
use App\Models\Set;
use App\Models\SetEntry;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * 複製題組（docs/SPEC.md 第 5 節）：詞條也一併複製，之後原作者修改原詞條，不影響複製出去的題組。
 *
 * - 新題組與新詞條的 forked_from_id 指向來源。
 * - 詞條的 authors 沿用原作者；題組的 authors 記下來源的作者，擁有者在組成交換格式時加在最後。
 * - 媒體檔不可變，複製的詞條與原詞條引用同一批媒體。
 */
class SetCopier
{
    public function __construct(private SetRevisionRecorder $recorder) {}

    public function copy(Set $source, User $to): Set
    {
        $source->load(['owner', 'curriculumRefs', 'entries.item.media']);
        $sourceAuthors = $source->effectiveAuthors();

        $copy = DB::transaction(function () use ($source, $to, $sourceAuthors) {
            $upstream = array_values(array_filter($sourceAuthors, fn (array $author) => $author['name'] !== $to->name));

            $copy = $to->sets()->create([
                'kind' => $source->kind,
                'title' => $source->title,
                'description' => $source->description,
                'language_code' => $source->language_code,
                'faces' => $source->faces,
                'license' => $source->license,
                'tags' => $source->tags,
                'authors' => $upstream ?: null,
                'forked_from_id' => $source->id,
            ]);
            $copy->curriculumRefs()->sync($source->curriculumRefs->modelKeys());

            foreach ($source->entries as $entry) {
                $copy->entries()->create([
                    'position' => $entry->position,
                    'item_id' => $entry->item ? $this->copyItem($entry, $to, $sourceAuthors)->id : null,
                    'payload' => $entry->payload,
                ]);
            }

            return $copy;
        });

        $this->recorder->record($copy, $to);

        return $copy;
    }

    /**
     * @param  list<array{name: string}>  $sourceAuthors
     */
    private function copyItem(SetEntry $entry, User $to, array $sourceAuthors): Item
    {
        $item = $entry->item;
        $copy = Item::create([
            'language_code' => $item->language_code,
            'text' => $item->text,
            'romanization' => $item->romanization,
            'translation_zh' => $item->translation_zh,
            'tags' => $item->tags,
            'license' => $item->license,
            'source' => $item->source,
            // 詞條沒有自己的作者時沿用題組的作者（docs/SPEC.md 6.5），複製後要明確記下來
            'authors' => $item->authors ?: $sourceAuthors,
            'owner_id' => $to->id,
            'forked_from_id' => $item->id,
        ]);

        $copy->media()->sync($item->media->mapWithKeys(fn (Media $media) => [
            $media->id => ['role' => $media->getRelationValue('pivot')->role, 'position' => $media->getRelationValue('pivot')->position],
        ])->all());

        return $copy;
    }
}
