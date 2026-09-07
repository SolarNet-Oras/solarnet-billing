<?php

namespace Tests\Unit;

use App\Http\Controllers\Api\V1\DashboardController;
use Tests\TestCase;

class DashboardTrafficDirectionTest extends TestCase
{
    public function test_routeros_target_upload_download_is_exposed_as_customer_download_upload(): void
    {
        $controller = new class extends DashboardController
        {
            public function customerPair(array $pair): array
            {
                return $this->customerTrafficPair($pair);
            }
        };

        $this->assertSame([93_600_000, 14_800_000], $controller->customerPair([14_800_000, 93_600_000]));
        $this->assertSame([null, 14_800_000], $controller->customerPair([14_800_000, null]));
    }
}
