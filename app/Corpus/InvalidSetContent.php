<?php

namespace App\Corpus;

use RuntimeException;

/**
 * 組出的題組內容不符合交換格式。這表示程式有錯，而不是老師輸入有誤
 * （老師的輸入應該在表單驗證時就擋下）。
 */
class InvalidSetContent extends RuntimeException
{
    /**
     * @param  list<string>  $errors
     */
    public function __construct(public readonly array $errors)
    {
        parent::__construct('題組內容不符合交換格式：'.implode('；', $errors));
    }
}
