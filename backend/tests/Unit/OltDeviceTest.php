<?php

namespace Tests\Unit;

use App\Models\OltDevice;
use Tests\TestCase;

class OltDeviceTest extends TestCase
{
    public function test_snmp_community_is_encrypted_and_never_returned_to_the_client(): void
    {
        $olt = new OltDevice([
            'name' => 'Main OLT',
            'host' => '10.0.0.10',
            'snmp_community' => 'read-only-community',
        ]);

        $this->assertNotSame('read-only-community', $olt->getAttributes()['snmp_community']);
        $this->assertSame('read-only-community', $olt->snmp_community);
        $this->assertFalse(array_key_exists('snmp_community', $olt->toSafeArray()));
    }
}
