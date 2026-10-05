<?php

namespace App\Filament\Resources\Users\Schemas;

use App\Media\UploadQuota;
use App\Models\User;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Schema;

class UserForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('name')
                    ->label('姓名')
                    ->required()
                    ->maxLength(255),
                TextInput::make('email')
                    ->label('Email')
                    ->email()
                    ->disabled(),
                Select::make('roles')
                    ->label('角色')
                    ->relationship('roles', 'name')
                    ->multiple()
                    ->preload()
                    ->helperText('teacher：老師；curator：審核者；admin：管理員。新的審核者請對方先用 Google 登入一次，再在這裡指定'),
                Select::make('reviewLanguages')
                    ->label('負責審核的語言')
                    ->relationship('reviewLanguages', 'name_zh')
                    ->multiple()
                    ->preload()
                    ->helperText('審核者只能審核與修正這些語言的公開題組；管理員不受限制'),
                // 個別老師的上傳上限（docs/SPEC.md A-05、第 9 節）；只有管理員進得來這個表單（UserResource::canAccess()）
                TextInput::make('upload_quota_mb')
                    ->label('上傳上限（MB）')
                    ->integer()
                    ->minValue(0)
                    ->maxValue(1_000_000)
                    ->nullable()
                    ->placeholder(fn (): string => '預設 '.config('kancil.upload_quota_mb').' MB')
                    ->helperText(fn (?User $record): string => '留空就用預設值，0 表示不能再上傳；管理員不受限制。'
                        .($record ? '目前'.UploadQuota::of($record)->describe().'。' : '')),
            ]);
    }
}
