<?php

namespace Tests\Unit;

use App\Corpus\RevisionDiff;
use PHPUnit\Framework\TestCase;
use stdClass;

class RevisionDiffTest extends TestCase
{
    /**
     * @param  list<array{0: string, 1: string, 2: string}>  $words  [entry id, 目標語, 中文]
     * @param  array<string, mixed>  $extra
     */
    private function set(array $words, array $extra = []): stdClass
    {
        $set = json_decode(json_encode([
            'kind' => 'vocab',
            'title' => '水果',
            'license' => 'CC-BY-4.0',
            'faces' => ['prompt' => ['translation_zh'], 'answer' => ['text']],
            'entries' => array_map(fn (array $word) => [
                'id' => $word[0],
                'item' => ['text' => $word[1], 'translation_zh' => $word[2]],
            ], $words),
            ...$extra,
        ], JSON_THROW_ON_ERROR), flags: JSON_THROW_ON_ERROR);
        assert($set instanceof stdClass);

        return $set;
    }

    public function test_the_first_revision_is_the_creation(): void
    {
        $this->assertSame('created', RevisionDiff::between(null, $this->set([]))[0]['kind']);
    }

    public function test_fields_and_entries_are_compared_by_id(): void
    {
        $old = $this->set([['A', 'quả chuối', '香蕉'], ['B', 'quả táo', '蘋果'], ['C', 'quả cam', '柳橙']]);
        $new = $this->set(
            [['C', 'quả cam', '橘子'], ['A', 'quả chuối', '香蕉'], ['D', 'quả nho', '葡萄']],
            ['title' => '水果（一）', 'tags' => ['水果']],
        );

        $this->assertSame([
            ['kind' => 'field', 'label' => '標題', 'before' => '水果', 'after' => '水果（一）'],
            ['kind' => 'field', 'label' => '標籤', 'before' => null, 'after' => '水果'],
            ['kind' => 'changed', 'label' => '修改', 'before' => 'quả cam（柳橙）', 'after' => 'quả cam（橘子）'],
            ['kind' => 'added', 'label' => '新增', 'before' => null, 'after' => 'quả nho（葡萄）'],
            ['kind' => 'removed', 'label' => '刪除', 'before' => 'quả táo（蘋果）', 'after' => null],
            ['kind' => 'reordered', 'label' => '調整題目順序', 'before' => null, 'after' => null],
        ], RevisionDiff::between($old, $new));
    }

    public function test_a_credit_change_is_labelled_as_such(): void
    {
        $old = $this->set([['A', 'quả chuối', '香蕉']]);
        $new = $this->set([['A', 'quả chuối', '香蕉']]);
        $old->entries[0]->item->image = (object) ['src' => 'media/01M3ZYR27GF5QQW62SVZ5NA266.webp'];
        $new->entries[0]->item->image = (object) ['src' => 'media/01M3ZYR27GF5QQW62SVZ5NA266.webp', 'authors' => [(object) ['name' => '李老師']]];

        $this->assertSame([
            ['kind' => 'changed', 'label' => '修改署名', 'before' => null, 'after' => 'quả chuối（香蕉） [圖片 #A266]'],
        ], RevisionDiff::between($old, $new));
    }

    public function test_curriculum_and_faces_are_described_in_words(): void
    {
        $old = $this->set([], ['curriculum' => [['volume' => 3, 'lesson' => 2]]]);
        $new = $this->set([], [
            'curriculum' => [['volume' => 3, 'lesson' => 2], ['volume' => 3, 'lesson' => 3]],
            'faces' => ['prompt' => ['audio'], 'answer' => ['text', 'romanization']],
        ]);

        $this->assertSame([
            ['kind' => 'field', 'label' => '出題方式', 'before' => '看中文，選目標語', 'after' => '看發音，選目標語＋羅馬拼寫'],
            ['kind' => 'field', 'label' => '對應教材', 'before' => '第 3 冊第 2 課', 'after' => '第 3 冊第 2 課、第 3 冊第 3 課'],
        ], RevisionDiff::between($old, $new));
    }

    public function test_replacing_media_is_visible(): void
    {
        $old = $this->set([['A', 'quả chuối', '香蕉']]);
        $new = $this->set([['A', 'quả chuối', '香蕉']]);
        $new->entries[0]->item->image = (object) ['src' => 'media/01M3ZYR27GF5QQW62SVZ5NA266.webp'];

        $changes = RevisionDiff::between($old, $new);
        $this->assertSame('quả chuối（香蕉）', $changes[0]['before']);
        $this->assertSame('quả chuối（香蕉） [圖片 #A266]', $changes[0]['after']);
    }
}
