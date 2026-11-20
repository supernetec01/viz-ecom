<?php

namespace App\Notifications;

use App\Models\User;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class EmployerInvitation extends Notification
{
    public function __construct(public string $token, private string $companyName) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(User $notifiable): MailMessage
    {
        return (new MailMessage)->subject(__('ui.employers.invitation_subject'))
            ->line(__('ui.employers.invitation_line', ['company' => $this->companyName]))
            ->line(__('ui.auth.reset_expires'))
            ->action(__('ui.employers.set_password'), route('password.reset', ['token' => $this->token, 'email' => $notifiable->email]));
    }
}
