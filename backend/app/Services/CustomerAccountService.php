<?php

namespace App\Services;

use App\Models\Customer;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

/**
 * Creates portal credentials for a customer and (optionally) delivers them
 * via a welcome email. Falls back to log driver when MAIL_MAILER=log so
 * dev environments still surface the plain-text password in laravel.log.
 */
class CustomerAccountService
{
    /**
     * Generate a random human-friendly portal password.
     */
    public function generatePlainPassword(int $length = 10): string
    {
        // Avoid confusing chars (0/O, 1/I/l)
        $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnpqrstuvwxyz23456789';
        $pw = '';
        for ($i = 0; $i < $length; $i++) {
            $pw .= $alphabet[random_int(0, strlen($alphabet) - 1)];
        }
        return $pw;
    }

    /**
     * Provision portal credentials on a customer. Returns the plaintext password
     * exactly once so the caller can show / email it. Never persisted plaintext.
     */
    public function provisionPortalCredentials(Customer $customer): string
    {
        $plain = $this->generatePlainPassword();
        $customer->forceFill([
            'portal_password'        => Hash::make($plain),
            'portal_password_set_at' => now(),
        ])->save();
        return $plain;
    }

    /**
     * Send the welcome email. If no email is configured on the customer, no-op.
     * Uses the current mail driver — log driver writes the message to laravel.log.
     */
    public function sendWelcomeEmail(Customer $customer, string $plainPassword, ?string $portalUrl = null): bool
    {
        if (empty($customer->email)) return false;

        $portalUrl = $portalUrl ?: rtrim(config('app.url'), '/') . '/customer/login';

        $subject = 'Welcome to Solarnet Internet';
        $body = $this->buildWelcomeEmailBody($customer, $plainPassword, $portalUrl);

        try {
            Mail::raw($body, function ($m) use ($customer, $subject) {
                $m->to($customer->email, $customer->full_name)->subject($subject);
            });
            $customer->forceFill(['welcome_email_sent_at' => now()])->save();
            return true;
        } catch (\Throwable $e) {
            Log::warning('Welcome email failed', [
                'customer_id' => $customer->id,
                'error' => $e->getMessage(),
            ]);
            return false;
        }
    }

    protected function buildWelcomeEmailBody(Customer $customer, string $password, string $portalUrl): string
    {
        $name = $customer->full_name;
        $acct = $customer->account_number;
        $plan = $customer->servicePlan->name ?? 'Solarnet Internet';
        return <<<TEXT
Hi {$name},

Welcome to Solarnet Internet!

Your account is ready. Log in to the customer portal at:
    {$portalUrl}

Login details:
    Email        : {$customer->email}
    Account No.  : {$acct}
    Password     : {$password}

For your security, please change your password after your first login.

Your service plan: {$plan}

If you need help, reply to this email or open a support ticket in the portal.

Thank you for choosing Solarnet.

— The Solarnet Team
TEXT;
    }
}
