<?php

namespace Tests\Unit;

use App\Services\FinancialMonitoringService;
use PHPUnit\Framework\TestCase;

class FinancialMonitoringServiceTest extends TestCase
{
    public function test_it_keeps_channels_separate_and_excludes_transfer_from_net_funds(): void
    {
        $wallets = FinancialMonitoringService::calculateWallets(
            [
                ['payment_method' => 'cash', 'amount' => 1000],
                ['payment_method' => 'gcash', 'amount' => 300],
                ['payment_method' => 'bank_transfer', 'amount' => 200],
            ],
            [
                ['type' => 'expense', 'effect_type' => 'expense', 'source_wallet' => 'cash', 'amount' => 150],
                ['type' => 'sale', 'effect_type' => 'transfer', 'source_wallet' => 'cash', 'destination_wallet' => 'bpi', 'amount' => 400],
                ['type' => 'sale', 'effect_type' => 'cash_in', 'destination_wallet' => 'gcash', 'amount' => 75],
            ],
        );

        $this->assertSame(1000.0, $wallets['cash']['collections']);
        $this->assertSame(150.0, $wallets['cash']['expenses']);
        $this->assertSame(400.0, $wallets['cash']['transfers_out']);
        $this->assertSame(450.0, $wallets['cash']['balance']);
        $this->assertSame(375.0, $wallets['gcash']['balance']);
        $this->assertSame(600.0, $wallets['bpi']['balance']);
    }

    public function test_it_classifies_online_payments_without_merging_them_into_cash_or_gcash(): void
    {
        $wallets = FinancialMonitoringService::calculateWallets([
            ['payment_method' => 'online', 'amount' => 800],
            ['payment_method' => 'credit_card', 'amount' => 250],
        ], []);

        $this->assertSame(1050.0, $wallets['online']['collections']);
        $this->assertSame(0.0, $wallets['cash']['collections']);
        $this->assertSame(0.0, $wallets['gcash']['collections']);
    }

    public function test_it_calculates_the_requested_80_percent_collectibles_plan_without_moving_funds(): void
    {
        $plan = FinancialMonitoringService::allocationPlan(10000);

        $this->assertSame(10000.0, $plan['collection_base']);
        $this->assertSame(8000.0, $plan['planning_base']);
        $this->assertSame(2000.0, $plan['retained_operations']);
        $this->assertSame(3200.0, $plan['allocations'][0]['amount']);
        $this->assertSame(2400.0, $plan['allocations'][1]['amount']);
        $this->assertSame(800.0, $plan['allocations'][2]['amount']);
        $this->assertSame(1600.0, $plan['allocations'][3]['amount']);
    }
}
