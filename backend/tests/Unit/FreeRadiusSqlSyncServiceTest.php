<?php

namespace Tests\Unit;

use App\Services\FreeRadiusSqlSyncService;
use Tests\TestCase;

class FreeRadiusSqlSyncServiceTest extends TestCase
{
    public function test_the_sql_bridge_requires_both_explicit_feature_flags(): void
    {
        $service = app(FreeRadiusSqlSyncService::class);

        config()->set('radius.freeradius_enabled', false);
        config()->set('radius.sql_sync_enabled', true);
        $this->assertFalse($service->isEnabled());

        config()->set('radius.freeradius_enabled', true);
        config()->set('radius.sql_sync_enabled', false);
        $this->assertFalse($service->isEnabled());

        config()->set('radius.freeradius_enabled', true);
        config()->set('radius.sql_sync_enabled', true);
        $this->assertTrue($service->isEnabled());
    }
}
