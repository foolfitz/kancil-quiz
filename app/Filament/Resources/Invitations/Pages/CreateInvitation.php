<?php

namespace App\Filament\Resources\Invitations\Pages;

use App\Filament\Resources\Invitations\InvitationResource;
use App\Models\Invitation;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;

class CreateInvitation extends CreateRecord
{
    protected static string $resource = InvitationResource::class;

    /**
     * @param  array<string, mixed>  $data
     */
    protected function handleRecordCreation(array $data): Model
    {
        $isAdmin = (bool) auth()->user()?->hasRole('admin');

        $text = fn (string $key) => is_string($data[$key] ?? null) && $data[$key] !== '' ? $data[$key] : null;

        [$invitation, $token] = Invitation::issue([
            'email' => $text('email'),
            'role' => $isAdmin ? ($text('role') ?? 'teacher') : 'teacher',
            'note' => $text('note'),
            'invited_by' => auth()->user()?->id,
            'days' => (int) $data['days'],
        ]);

        // 只保存 token 的雜湊，所以連結只能在這裡顯示一次。
        Notification::make()
            ->title('邀請連結已建立（只會顯示這一次，請立即複製）')
            ->body(Invitation::url($token))
            ->success()
            ->persistent()
            ->send();

        return $invitation;
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }

    protected function getCreatedNotification(): ?Notification
    {
        return null;
    }
}
