<?php

namespace Tests\Unit;

use App\Support\KancilFormat;
use FilesystemIterator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use stdClass;

// 與 packages/schema/tests/schema.test.ts 共用同一批 fixture（docs/SPEC.md 第 12 節）。
class KancilFormatTest extends TestCase
{
    private static function schemaPackage(string $path = ''): string
    {
        return dirname(__DIR__, 2).'/packages/schema/'.$path;
    }

    /**
     * fixtures 底下某個目錄中所有 JSON 檔，以相對路徑為 data set 名稱。
     *
     * @return array<string, array{stdClass}>
     */
    private static function fixturesIn(string $directory): array
    {
        $root = self::schemaPackage('fixtures/');
        $files = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($root.$directory, FilesystemIterator::SKIP_DOTS),
        );

        $fixtures = [];
        foreach ($files as $file) {
            if ($file->getExtension() === 'json') {
                $fixtures[substr($file->getPathname(), strlen($root))] = [
                    json_decode(file_get_contents($file->getPathname()), flags: JSON_THROW_ON_ERROR),
                ];
            }
        }
        ksort($fixtures);

        return $fixtures;
    }

    public static function validSets(): array
    {
        return self::fixturesIn('sets');
    }

    public static function validActivities(): array
    {
        return self::fixturesIn('activities');
    }

    public static function invalidSets(): array
    {
        return self::fixturesIn('invalid/set');
    }

    public static function invalidActivities(): array
    {
        return self::fixturesIn('invalid/activity');
    }

    public static function ruleViolatingSets(): array
    {
        return self::fixturesIn('invalid/rules');
    }

    private KancilFormat $format;

    protected function setUp(): void
    {
        $this->format = new KancilFormat(self::schemaPackage());
    }

    #[DataProvider('validSets')]
    public function test_valid_set_fixtures_have_no_errors(stdClass $set): void
    {
        $this->assertSame([], $this->format->setErrors($set));
    }

    #[DataProvider('validActivities')]
    public function test_valid_activity_fixtures_have_no_errors(stdClass $activity): void
    {
        $this->assertSame([], $this->format->activityErrors($activity));
    }

    #[DataProvider('invalidSets')]
    public function test_invalid_set_fixtures_fail_the_schema(stdClass $set): void
    {
        $this->assertNotSame([], $this->format->schemaErrors($set, KancilFormat::SET_SCHEMA));
    }

    #[DataProvider('invalidActivities')]
    public function test_invalid_activity_fixtures_fail_the_schema(stdClass $activity): void
    {
        $this->assertNotSame([], $this->format->schemaErrors($activity, KancilFormat::ACTIVITY_SCHEMA));
    }

    #[DataProvider('ruleViolatingSets')]
    public function test_rule_violations_pass_the_schema_but_fail_the_rules(stdClass $set): void
    {
        $this->assertSame([], $this->format->schemaErrors($set, KancilFormat::SET_SCHEMA));
        $this->assertNotSame([], $this->format->ruleViolations($set));
    }
}
