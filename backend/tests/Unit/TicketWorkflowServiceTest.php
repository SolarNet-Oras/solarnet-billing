<?php

namespace Tests\Unit;

use App\Services\TicketWorkflowService;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class TicketWorkflowServiceTest extends TestCase
{
    #[DataProvider('validMacAddresses')]
    public function test_it_normalizes_supported_mac_address_formats(string $input): void
    {
        $this->assertSame('AA:BB:CC:11:22:33', (new TicketWorkflowService())->normalizeMac($input));
    }

    public static function validMacAddresses(): array
    {
        return [
            ['AA:BB:CC:11:22:33'],
            ['aa-bb-cc-11-22-33'],
            ['AABBCC112233'],
        ];
    }

    #[DataProvider('invalidMacAddresses')]
    public function test_it_rejects_malformed_mac_addresses(?string $input): void
    {
        $this->assertNull((new TicketWorkflowService())->normalizeMac($input));
    }

    public static function invalidMacAddresses(): array
    {
        return [[null], [''], ['AA:BB:CC:11:22'], ['AA:BB:CC:11:22:GG'], ['AABBCC11223344']];
    }
}
