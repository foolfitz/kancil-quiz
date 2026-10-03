<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * 語言（docs/SPEC.md 附錄 A）。enabled 控制老師端選單中是否出現（1.4）。
 */
#[Fillable(['name_zh', 'name_native', 'script', 'word_spacing', 'enabled', 'sort'])]
class Language extends Model
{
    protected $primaryKey = 'code';

    protected $keyType = 'string';

    public $incrementing = false;

    public $timestamps = false;

    protected function casts(): array
    {
        return [
            'word_spacing' => 'boolean',
            'enabled' => 'boolean',
        ];
    }

    /**
     * @param  Builder<self>  $query
     */
    public function scopeEnabled(Builder $query): void
    {
        $query->where('enabled', true)->orderBy('sort');
    }
}
