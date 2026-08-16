<?php

namespace App\Support;

/**
 * Guards the insecure administrator identity that existed only in early
 * development builds. It must never become a usable production account.
 */
final class LegacyDefaultAdministrator
{
    public const EMAIL = 'admin@ispbilling.local';

    public static function isReservedEmail(?string $email): bool
    {
        return mb_strtolower(trim((string) $email)) === self::EMAIL;
    }
}
