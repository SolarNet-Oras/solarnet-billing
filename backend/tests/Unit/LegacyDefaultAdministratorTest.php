<?php

namespace Tests\Unit;

use App\Support\LegacyDefaultAdministrator;
use PHPUnit\Framework\TestCase;

class LegacyDefaultAdministratorTest extends TestCase
{
    public function test_only_the_legacy_email_is_reserved_case_insensitively(): void
    {
        $this->assertTrue(LegacyDefaultAdministrator::isReservedEmail('ADMIN@ISPBILLING.LOCAL'));
        $this->assertTrue(LegacyDefaultAdministrator::isReservedEmail(' admin@ispbilling.local '));
        $this->assertFalse(LegacyDefaultAdministrator::isReservedEmail('admin@solarnetconnection.com'));
    }
}
