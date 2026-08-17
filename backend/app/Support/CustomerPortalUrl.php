<?php

namespace App\Support;

/**
 * Keeps customer-facing links on the customer portal host while APP_URL stays
 * on the staff billing host. This has no effect on authentication, billing,
 * customer, or router data.
 */
final class CustomerPortalUrl
{
    public static function base(): string
    {
        return rtrim((string) config('app.customer_portal_url', config('app.url')), '/');
    }

    public static function to(string $path = '/customer/login'): string
    {
        return self::base() . '/' . ltrim($path, '/');
    }

    /**
     * Retain a deliberate custom reminder URL, but migrate the one known
     * retired billing host without writing to the settings table. This lets
     * existing RouterOS/payment settings continue working during the cutover.
     */
    public static function paymentReminder(?string $configuredUrl, string $fallbackPath = '/customer/login'): string
    {
        $configuredUrl = trim((string) $configuredUrl);
        if ($configuredUrl === '') {
            return self::to($fallbackPath);
        }

        $parts = parse_url($configuredUrl);
        if (!is_array($parts)) {
            return $configuredUrl;
        }
        if (strtolower((string) ($parts['host'] ?? '')) !== 'billing.solarnetconnection.com') {
            return $configuredUrl;
        }

        $path = (string) ($parts['path'] ?? $fallbackPath);
        $query = isset($parts['query']) ? '?' . $parts['query'] : '';

        return self::to($path) . $query;
    }

    public static function isValidHttpsBase(): bool
    {
        $parts = parse_url(self::base());

        return ($parts['scheme'] ?? null) === 'https' && filled($parts['host'] ?? null);
    }
}
