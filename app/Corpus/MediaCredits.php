<?php

namespace App\Corpus;

use App\Models\Media;
use App\Models\User;
use Illuminate\Validation\ValidationException;

/**
 * 媒體的署名：作者、出處、授權（docs/SPEC.md 第 9 節）。署名記在媒體上，不依賴題組，因為同一個媒體
 * 可能被多個題組引用（複製的題組共用媒體）；檔案不可變，署名可以修正，但只有上傳的人能改。
 *
 * 編輯頁送來的格式：media_credits: [{id, author?, source?, license?}]。
 * author 是作者的名字，幾位作者以「、」連起來；空白時以上傳的老師為作者。
 */
class MediaCredits
{
    /**
     * @return list<array{name: string}>
     */
    public static function authors(?string $author, User $fallback): array
    {
        $names = array_values(array_filter(array_map('trim', explode('、', (string) $author)), fn (string $name) => $name !== ''));

        return array_map(fn (string $name) => ['name' => $name], $names ?: [$fallback->name]);
    }

    /**
     * 把編輯頁送來的署名寫回媒體。只能改自己上傳的；別人的（例如教材的插圖、複製來的題組的媒體）編輯頁不會送來。
     *
     * @param  array<int, array{id: string, author?: string|null, source?: string|null, license?: string|null}>  $credits
     */
    public static function apply(array $credits, User $by): void
    {
        if ($credits === []) {
            return;
        }

        $media = Media::whereIn('id', array_column($credits, 'id'))->get()->keyBy('id');

        foreach ($credits as $credit) {
            $item = $media->get($credit['id']);
            if ($item === null || $item->uploaded_by !== $by->id) {
                throw ValidationException::withMessages(['media_credits' => '只能修改自己上傳的音檔與圖片的署名。']);
            }

            $item->fill([
                'authors' => self::authors($credit['author'] ?? null, $by),
                'source' => blank($credit['source'] ?? null) ? null : trim((string) $credit['source']),
                'license' => $credit['license'] ?? null,
            ])->save();
        }
    }
}
