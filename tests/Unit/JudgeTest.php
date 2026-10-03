<?php

namespace Tests\Unit;

use App\Grading\Judge;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

// 與 packages/deck/tests/deck.test.ts 共用 packages/schema/fixtures/grading/cases.json（docs/SPEC.md 7.4）。
class JudgeTest extends TestCase
{
    private static function fixtures(string $path = ''): string
    {
        return dirname(__DIR__, 2).'/packages/schema/fixtures/'.$path;
    }

    /**
     * @return array<string, mixed>
     */
    private static function cases(): array
    {
        return json_decode((string) file_get_contents(self::fixtures('grading/cases.json')), true, flags: JSON_THROW_ON_ERROR);
    }

    private static function set(string $path): object
    {
        return json_decode((string) file_get_contents(self::fixtures($path)), flags: JSON_THROW_ON_ERROR);
    }

    public static function judgeCases(): array
    {
        return collect(self::cases()['judge'])->mapWithKeys(fn ($case) => [$case['name'] => [$case]])->all();
    }

    public static function countCases(): array
    {
        return collect(self::cases()['countCorrect'])->mapWithKeys(fn ($case) => [$case['name'] => [$case]])->all();
    }

    #[DataProvider('judgeCases')]
    public function test_judge(array $case): void
    {
        $response = $case['response'];

        $this->assertSame($case['expected'], Judge::judge(
            self::set($case['set']),
            $case['shape'],
            $response['entryId'],
            $response['presented'],
            $response['selected'],
        ));
    }

    #[DataProvider('countCases')]
    public function test_count_correct(array $case): void
    {
        $responses = array_map(fn ($response) => [
            'entry_id' => $response['entryId'],
            'presented' => $response['presented'],
            'selected' => $response['selected'],
        ], $case['responses']);

        $this->assertSame($case['expected'], Judge::countCorrect(self::set($case['set']), $case['shape'], $responses));
    }
}
