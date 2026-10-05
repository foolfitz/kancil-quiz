<?php

namespace App\Filament\Resources\CurriculumRefs\Tables;

use App\Curriculum\CurriculumImages;
use App\Models\CurriculumRef;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class CurriculumRefsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with('set.entries.item.media'))
            ->columns([
                TextColumn::make('language.name_zh')->label('語言'),
                TextColumn::make('volume')->label('冊')->sortable(),
                TextColumn::make('lesson')->label('課')->sortable(),
                TextColumn::make('title_zh')->label('課名')->searchable(),
                TextColumn::make('title_native')->label('目標語課名')->searchable(),
                TextColumn::make('set_id')->label('教材題組')->formatStateUsing(fn () => '已匯入')->placeholder('—'),
                // 缺插圖的詞（docs/SPEC.md 3.6）：插圖在「匯入教材」頁依檔名批次上傳
                TextColumn::make('images')->label('插圖')
                    ->state(fn (CurriculumRef $record) => self::imageState($record))
                    ->badge()
                    ->color(fn (?string $state) => $state === '齊全' ? 'success' : 'warning')
                    ->wrap()
                    ->placeholder('—'),
            ])
            ->defaultSort('volume')
            ->filters([
                SelectFilter::make('language_code')->label('語言')->relationship('language', 'name_zh'),
                SelectFilter::make('volume')->label('冊')
                    ->options(fn () => CurriculumRef::query()->distinct()->orderBy('volume')->pluck('volume')
                        ->mapWithKeys(fn (int $volume) => [$volume => "第 {$volume} 冊"])),
                Filter::make('missing_images')->label('只看缺插圖的課')->toggle()
                    ->query(fn (Builder $query) => $query->whereHas('set.entries.item', fn (Builder $item) => $item
                        ->whereDoesntHave('media', fn (Builder $media) => $media->where('item_media.role', 'image')))),
            ])
            ->recordActions([
                EditAction::make(),
                DeleteAction::make(),
            ]);
    }

    /**
     * 例：缺 2 個：ayah、ibu。還沒有匯入詞彙的課是 null。
     */
    private static function imageState(CurriculumRef $ref): ?string
    {
        if ($ref->set === null) {
            return null;
        }
        $missing = CurriculumImages::missing($ref->set);

        return $missing === [] ? '齊全' : '缺 '.count($missing).' 個：'.implode('、', $missing);
    }
}
