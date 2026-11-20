<?php

namespace App\Notifications;

use App\Models\User;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class ResetPasswordLink extends Notification
{
    public function __construct(public string $token) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(User $notifiable): MailMessage
    {
        return (new MailMessage)->subject(__('ui.auth.reset'))->line(__('ui.auth.reset_expires'))
            ->action(__('ui.auth.reset'), route('password.reset', ['token' => $this->token, 'email' => $notifiable->email]));
    }
}
