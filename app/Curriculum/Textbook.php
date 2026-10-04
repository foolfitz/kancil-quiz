<?php

namespace App\Curriculum;

use App\Models\CurriculumRef;
use App\Models\User;
use Illuminate\Support\Str;

/**
 * 教材題組的共用設定（docs/SPEC.md 3.6）。
 *
 * 教材題組由一個不能登入的系統帳號擁有：沒有密碼、沒有驗證 email，也沒有角色。
 */
final class Textbook
{
    public const OWNER_EMAIL = 'textbook@kancil-quiz.invalid';

    public const OWNER_NAME = 'Kancil Quiz 教材';

    /**
     * 看圖片與中文意思，選目標語。老師複製後可以自己改。
     */
    public const FACES = ['prompt' => ['image', 'translation_zh'], 'answer' => ['text']];

    /**
     * 教材題組與詞條的作者與授權，資料檔沒寫時使用（App\Curriculum\VolumeFile）。作者寫本專案的名稱，不寫個人。
     */
    public const AUTHORS = [['name' => 'Kancil Quiz']];

    public const LICENSE = 'CC-BY-4.0';

    /**
     * 在後台上傳插圖時的預設署名。
     */
    public const IMAGE_ATTRIBUTION = ['authors' => self::AUTHORS, 'license' => 'CC-BY-4.0', 'source' => 'AI 生成'];

    public static function owner(): User
    {
        return User::firstOrCreate(['email' => self::OWNER_EMAIL], [
            'name' => self::OWNER_NAME,
            'password' => Str::random(64),
        ]);
    }

    /**
     * 例：第 1 冊第 3 課：Keluarga Saya 我的家人
     */
    public static function title(CurriculumRef $ref): string
    {
        $name = trim(($ref->title_native ?? '').' '.($ref->title_zh ?? ''));

        return "第 {$ref->volume} 冊第 {$ref->lesson} 課".($name === '' ? '' : "：{$name}");
    }

    /**
     * 詞的對應鍵：與答案比對相同，不分大小寫、忽略頭尾與重複的空白（docs/SPEC.md 第 8 節）。
     * 重新匯入時以它對應既有的詞條，題目 ID 才不會變。
     */
    public static function wordKey(string $text): string
    {
        return mb_strtolower((string) preg_replace('/\s+/u', ' ', trim($text)));
    }

    public static function lessonUrl(CurriculumRef $ref): string
    {
        return route('curriculum.lesson', [
            'language' => $ref->language_code,
            'volume' => $ref->volume,
            'lesson' => $ref->lesson,
        ]);
    }
}
