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

    public static function lessonUrl(CurriculumRef $ref): string
    {
        return route('curriculum.lesson', [
            'language' => $ref->language_code,
            'volume' => $ref->volume,
            'lesson' => $ref->lesson,
        ]);
    }
}
