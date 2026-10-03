<?php

namespace App\Support;

use Normalizer;
use Opis\JsonSchema\Errors\ErrorFormatter;
use Opis\JsonSchema\Validator;
use stdClass;

/**
 * 驗證題組交換格式與活動播放格式（docs/SPEC.md 第 6 節）。
 *
 * JSON Schema 放在 packages/schema，是唯一的規格來源；Schema 表達不了的規則
 * （NFC、ID 不重複）在 ruleViolations() 檢查，與 packages/schema/src/rules.ts 對應。
 */
class KancilFormat
{
    public const SCHEMA_BASE = 'https://kancil-quiz.invalid/schema/';

    public const SET_SCHEMA = self::SCHEMA_BASE.'set.v1.schema.json';

    public const ACTIVITY_SCHEMA = self::SCHEMA_BASE.'activity.v1.schema.json';

    private Validator $validator;

    public function __construct(?string $schemaDirectory = null)
    {
        $this->validator = new Validator;
        $this->validator->resolver()?->registerPrefix(
            self::SCHEMA_BASE,
            $schemaDirectory ?? base_path('packages/schema/'),
        );
    }

    /**
     * 題組的所有錯誤：先檢查 schema，通過後再檢查格式規則。
     *
     * @return list<string> 空陣列表示通過
     */
    public function setErrors(mixed $set): array
    {
        return $this->schemaErrors($set, self::SET_SCHEMA)
            ?: $this->ruleViolations($set);
    }

    /**
     * @return list<string> 空陣列表示通過
     */
    public function activityErrors(mixed $activity): array
    {
        return $this->schemaErrors($activity, self::ACTIVITY_SCHEMA)
            ?: $this->ruleViolations($activity->set);
    }

    /**
     * @param  mixed  $data  以 json_decode() 解出的物件（不可用關聯陣列）
     * @return list<string>
     */
    public function schemaErrors(mixed $data, string $schemaId): array
    {
        $error = $this->validator->validate($data, $schemaId)->error();

        if ($error === null) {
            return [];
        }

        $errors = [];
        foreach ((new ErrorFormatter)->format($error) as $path => $messages) {
            foreach ((array) $messages as $message) {
                $errors[] = "{$path}: {$message}";
            }
        }

        return $errors;
    }

    /**
     * 只能用在已通過 schema 的題組上。
     *
     * @return list<string>
     */
    public function ruleViolations(stdClass $set): array
    {
        $violations = [];

        $this->eachString($set, '$', function (string $path, string $text) use (&$violations): void {
            if (! Normalizer::isNormalized($text, Normalizer::FORM_C)) {
                $violations[] = "{$path} 不是 NFC";
            }
        });

        array_push($violations, ...$this->duplicateIds($set->entries, '$.entries'));

        if ($set->kind === 'quiz') {
            foreach ($set->entries as $i => $entry) {
                array_push($violations, ...$this->duplicateIds(
                    $entry->question->options,
                    "\$.entries[{$i}].question.options",
                ));
            }
        }

        return $violations;
    }

    /**
     * @param  list<stdClass>  $list
     * @return list<string>
     */
    private function duplicateIds(array $list, string $path): array
    {
        $seen = [];
        $violations = [];

        foreach ($list as $i => $item) {
            if (isset($seen[$item->id])) {
                $violations[] = "{$path}[{$i}].id 重複：{$item->id}";
            }
            $seen[$item->id] = true;
        }

        return $violations;
    }

    /**
     * @param  callable(string, string): void  $callback
     */
    private function eachString(mixed $value, string $path, callable $callback): void
    {
        if (is_string($value)) {
            $callback($path, $value);
        } elseif (is_array($value)) {
            foreach ($value as $i => $item) {
                $this->eachString($item, "{$path}[{$i}]", $callback);
            }
        } elseif (is_object($value)) {
            foreach (get_object_vars($value) as $key => $item) {
                $this->eachString($item, "{$path}.{$key}", $callback);
            }
        }
    }
}
