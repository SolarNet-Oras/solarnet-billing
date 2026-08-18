<?php

namespace Tests\Unit;

use App\Models\OltDevice;
use App\Models\Router;
use App\Services\MikrotikService;
use App\Services\OltSnmpService;
use Mockery;
use Tests\TestCase;

class OltSnmpRelayServiceTest extends TestCase
{
    public function test_it_reads_only_standard_health_values_through_the_selected_router(): void
    {
        $router = new Router(['name' => 'Concentrator', 'is_active' => true]);
        $router->id = '11111111-1111-4111-8111-111111111111';
        $olt = $this->unsavedOlt();
        $olt->router_id = $router->id;
        $olt->setRelation('router', $router);

        $mikrotik = Mockery::mock(MikrotikService::class);
        $mikrotik->shouldReceive('relaySnmpV2cGet')
            ->times(5)
            ->andReturn(
                ['success' => true, 'value' => 'HSGQ-G04R'],
                ['success' => true, 'value' => '1.3.6.1.4.1.9999'],
                ['success' => true, 'value' => '123456'],
                ['success' => true, 'value' => 'Main OLT'],
                ['success' => true, 'value' => '4'],
            );

        $result = (new OltSnmpService($mikrotik))->inspect($olt);

        $this->assertTrue($result['success']);
        $this->assertSame('read_only_standard_mib_via_mikrotik_api_relay', $result['data']['mode']);
        $this->assertSame('Concentrator', $result['data']['relay_router']);
        $this->assertSame(4, $result['data']['interface_count']);
    }

    public function test_it_reports_when_the_router_api_account_lacks_the_snmp_tool_permission(): void
    {
        $router = new Router(['name' => 'Concentrator', 'is_active' => true]);
        $router->id = '11111111-1111-4111-8111-111111111111';
        $olt = $this->unsavedOlt();
        $olt->router_id = $router->id;
        $olt->setRelation('router', $router);

        $mikrotik = Mockery::mock(MikrotikService::class);
        $mikrotik->shouldReceive('relaySnmpV2cGet')->once()->andReturn([
            'success' => false,
            'code' => 'RELAY_ROUTER_PERMISSION_MISSING',
            'message' => 'The router API account cannot run the read-only RouterOS SNMP tool.',
        ]);

        $result = (new OltSnmpService($mikrotik))->inspect($olt);

        $this->assertFalse($result['success']);
        $this->assertSame('RELAY_ROUTER_PERMISSION_MISSING', $result['code']);
        $this->assertStringContainsString('test policy', $result['message']);
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
