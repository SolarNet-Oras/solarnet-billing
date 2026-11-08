<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class SaasTenantResetPassword extends Notification
{
    use Queueable;

    public function __construct(private readonly string $token, private readonly string $tenantSlug) {}
    public function via(object $notifiable): array { return ['mail']; }
    public function toMail(object $notifiable): MailMessage
    {
        $base = 'https://'.config('saas.domains.app');
        $url = $base.'/reset-password?tenant='.rawurlencode($this->tenantSlug)
            .'&token='.rawurlencode($this->token).'&email='.rawurlencode((string) $notifiable->email);

        return (new MailMessage())
            ->subject('Reset your tenant workspace password')
            ->line('A password reset was requested for your ISP company workspace.')
            ->action('Reset password', $url)
            ->line('This link expires according to the SolarNet password-reset policy.');
    }
}
