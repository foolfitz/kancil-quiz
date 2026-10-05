<?php

namespace App\Notifications;

use App\Models\Report;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * 有人檢舉活動時寄信給管理員（docs/SPEC.md S-07）。檢舉本身存在後台，沒有設定 SMTP 時信只會寫進 log。
 */
class ActivityReported extends Notification
{
    public function __construct(private Report $report) {}

    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $activity = $this->report->activity;

        return (new MailMessage)
            ->subject('Kancil Quiz：有人檢舉活動')
            ->line("活動：{$activity->set->title}（".route('play', $activity).'）')
            ->line("老師：{$activity->owner->name}（{$activity->owner->email}）")
            ->line("原因：{$this->report->reason}")
            ->action('到後台處理', url('/admin/reports'));
    }
}
