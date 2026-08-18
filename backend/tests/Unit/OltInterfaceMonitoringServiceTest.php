<?php

namespace Tests\Unit;

use App\Models\OltDevice;
use App\Models\Router;
use App\Services\MikrotikService;
use App\Services\OltSnmpService;
use Mockery;
use Tests\TestCase;

class OltInterfaceMonitoringServiceTest extends TestCase
{
    public function test_it_builds_a_bounded_read_only_standard_interface_snapshot(): void
    {
        $router = new Router(['name' => 'Concentrator', 'is_active' => true]);
        $router->id = '11111111-1111-4111-8111-111111111111';
        $olt = $this->unsavedOlt();
        $olt->router_id = $router->id;
        $olt->setRelation('router', $router);

        $mikrotik = Mockery::mock(MikrotikService::class);
        $mikrotik->shouldReceive('relaySnmpV2cWalk')
            ->times(10)
            ->andReturn(
                $this->walk([1 => 'gpon0/1', 2 => 'uplink1']),
                $this->walk([1 => '1', 2 => '1']),
                $this->walk([1 => '1', 2 => '2']),
                $this->walk([1 => '2500', 2 => '1000']),
                $this->walk([1 => '5000', 2 => '9000']),
                $this->walk([1 => '7000', 2 => '12000']),
                $this->walk([1 => '0', 2 => '2']),
                $this->walk([1 => '1', 2 => '0']),
                $this->walk([1 => '3', 2 => '0']),
                $this->walk([1 => '4', 2 => '1']),
            );

        $result = (new OltSnmpService($mikrotik))->refreshInterfaceMonitoring($olt);

        $this->assertTrue($result['success']);
        $this->assertSame('read_only_standard_if_mib_via_mikrotik_api_relay', $result['data']['mode']);
        $this->assertSame(2, $result['data']['interface_count']);
        $this->assertSame('gpon0/1', $result['data']['interfaces'][0]['name']);
        $this->assertSame('up', $result['data']['interfaces'][0]['oper_status']);
        $this->assertSame('down', $result['data']['interfaces'][1]['oper_status']);
        $this->assertSame(1, $result['data']['interfaces'][0]['out_errors']);
        $this->assertSame(4, $result['data']['interfaces'][0]['out_discards']);
    }

    /** @param array<int, string> $values */
    private function walk(array $values): array
    {
        return [
            'success' => true,
            'rows' => array_map(
                static fn (int $index, string $value) => ['index' => $index, 'value' => $value],
                array_keys($values),
                $values,
            ),
            'truncated' => false,
        ];
    }

    private function unsavedOlt(): OltDevice
    {
        $olt = new class extends OltDevice {
            public function save(array $options = []): bool
            {
                return true;
            }
        };
        $olt->id = '22222222-2222-4222-8222-222222222222';
        $olt->fill([
            'name' => 'Main OLT',
            'host' => '192.168.88.10',
            'snmp_port' => 161,
            'snmp_version' => '2c',
            'snmp_community' => 'read-only-community',
            'is_active' => true,
        ]);

        return $olt;
    }
}
