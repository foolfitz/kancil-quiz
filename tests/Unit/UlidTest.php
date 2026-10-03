<?php

namespace Tests\Unit;

use App\Models\Activity;
use Illuminate\Support\Str;
use PHPUnit\Framework\TestCase;

class UlidTest extends TestCase
{
    public function test_ids_are_canonical_uppercase_ulids(): void
    {
        $id = (new Activity)->newUniqueId();

        $this->assertMatchesRegularExpression('/^[0-9A-HJKMNP-TV-Z]{26}$/', $id);
        $this->assertTrue(Str::isUlid($id));
    }

    public function test_ids_created_in_the_same_millisecond_are_not_sequential(): void
    {
        $model = new Activity;
        $ids = array_map(fn () => $model->newUniqueId(), range(1, 50));

        // 隨機部分（後 16 碼）兩兩之間至少差好幾個字元，而不是只差最後一碼
        foreach (array_slice($ids, 1) as $i => $id) {
            $this->assertGreaterThan(4, levenshtein(substr($ids[$i], 10), substr($id, 10)));
        }
        $this->assertCount(50, array_unique($ids));
    }
}
