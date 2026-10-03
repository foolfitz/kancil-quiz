<?php

namespace App\Filament\Resources\Invitations\Schemas;

use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Schema;

class InvitationForm
{
    public static function configure(Schema $schema): Schema
    {
        $isAdmin = (bool) auth()->user()?->hasRole('admin');

        return $schema
            ->components([
                TextInput::make('email')
                    ->label('Email（選填）')
                    ->email()
                    ->helperText('有填寫時，只有這個 email 能使用這個邀請連結。'),
                Select::make('role')
                    ->label('角色')
                    ->options($isAdmin
                        ? ['teacher' => '老師', 'curator' => '審核者', 'admin' => '管理員']
                        : ['teacher' => '老師'])
                    ->default('teacher')
                    ->required(),
                TextInput::make('days')
                    ->label('有效天數')
                    ->integer()
                    ->minValue(1)
                    ->maxValue(90)
                    ->default(14)
                    ->required(),
                TextInput::make('note')
                    ->label('備註')
                    ->maxLength(255)
                    ->helperText('例：受邀者的姓名、學校'),
            ]);
    }
}
