<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('transaction_definitions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('type');
            $table->string('description');
            $table->string('payment_method', 32);
            $table->string('effect_type', 24); // expense, transfer, or cash_in
            $table->string('source_wallet', 24)->nullable();
            $table->string('destination_wallet', 24)->nullable();
            $table->boolean('active')->default(true);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
            $table->unique(['type', 'description', 'payment_method']);
            $table->index(['type', 'active', 'sort_order']);
        });

        Schema::table('financial_entries', function (Blueprint $table) {
            $table->uuid('transaction_definition_id')->nullable()->after('id');
            $table->string('effect_type', 24)->nullable()->after('payment_method');
            $table->string('source_wallet', 24)->nullable()->after('effect_type');
            $table->string('destination_wallet', 24)->nullable()->after('source_wallet');
            $table->uuid('idempotency_key')->nullable()->unique()->after('notes');
            $table->foreign('transaction_definition_id')->references('id')->on('transaction_definitions')->nullOnDelete();
        });

        $walletMethods = ['cash' => 'cash', 'gcash' => 'gcash', 'bank_bpi' => 'bpi', 'bank_landbank' => 'landbank'];
        $expenseTypes = [
            'CCTV Supplies' => ['Monitor', 'DVR/XVR/NVR', 'HDD', 'Camera', 'UTP', 'Media Converter', 'PoE Switch', 'Connector', 'Fiber Optic Cable', 'Switch', 'Outdoor Box', 'Terminating Box', 'Server Cabinet', 'Bracket', 'Accessories'],
            'Internet Supplies' => ['Fiber', 'Modem', 'MikroTik', 'OLT', 'SC Connector', 'FBT', 'UPS', 'NAP Box', 'Cassette', 'F-Clamp', 'Straps', 'Buckle Lock', 'Retractor', 'Tools', 'Accessories', 'Server Tracks', 'Dead End Clamp', 'Computer Parts', 'Sleeve 40mm', 'Sleeve 60mm', 'Transceiver Modules', 'P-Clamp', 'ODF'],
            'Subscriptions Fees' => ['Leased Line', 'SME', 'DIA', 'Rental', 'Loan', 'Mortgage', 'Starlink', 'Domain', 'Electric Bill'],
            'Office Supplies' => ['Bond Paper', 'Ink', 'Folder', 'Pen', 'Thermal Paper', 'Portable Printer', 'Barcode Scanner', 'Uniform', 'Calculator', 'Marketing Supplies', 'Others'],
            'Solar Supplies' => ['Panel', 'PV Wire', 'Inverter', 'Battery', 'Mounting Gear', 'Accessories'],
            'Travel Expenses' => ['Chartered Boat', 'Fuel', 'Food', 'Water', 'Lodge', 'Fare'],
            'Salary' => ['Nelson Cuanico', 'Ricky Maestre', 'Luke Lombendencio', 'Ralph Aculana', 'Richard Phillip Aculana', 'Kirk Lowell Pajanustan', 'Niel Rio Desipeda'],
            'Permit and Licenses' => ['NTC', "Mayor's Permit", 'Brgy. Permit', 'ESAMELCO'],
            'Professional Fees' => ['Notarial Services', 'Book Keeper', 'Accountant', 'Others'],
            'Taxes' => ['LGU', 'BIR'],
            'Labor Expense' => ['Contract', 'Daily Wage', 'Food', 'Water'],
            'Miscellaneous' => ['Others', 'Transaction Charge', 'Share Vendo', 'Shipping Fee', 'Material/Equipment'],
            'Repair and Maintenance' => ['Change Oil', 'Tire', 'Gear Oil', 'Labor'],
            'C/O' => ['Luke Lombendencio', 'Ralph Aculana', 'Nelson Cuanico', 'Ricky Maestre', 'Richard Phillip Aculana'],
            'C/A' => ['Luke Lombendencio', 'Ralph Aculana', 'Nelson Cuanico', 'Ricky Maestre', 'Richard Phillip Aculana', 'Dalmacio Pajanustan', 'Niel Rio Desipeda'],
            'Fiber Laying' => ['Materials', 'Accessories'],
            'Reimbursed' => ['Ralph Aculana', 'Ricky Maestre', 'Nelson Cuanico', 'Richard Phillip Aculana', 'Dalmacio Pajanustan', 'Luke Lombendencio'],
            'Refund' => ['Ralph Aculana', 'Ricky Maestre', 'Nelson Cuanico', 'Richard Phillip Aculana', 'Dalmacio Pajanustan', 'Luke Lombendencio'],
            'Training Expenses' => ['Registration Pay', 'Travel Expenses'],
        ];

        $rows = [];
        $sort = 0;
        foreach ($expenseTypes as $type => $descriptions) {
            foreach ($descriptions as $description) {
                foreach ($walletMethods as $method => $wallet) {
                    $rows[] = $this->row($type, $description, $method, 'expense', $wallet, null, ++$sort);
                }
            }
        }

        $cashIn = [
            'From Cash' => ['deposit_to_bpi' => 'bpi', 'deposit_to_landbank' => 'landbank', 'add_to_gcash' => 'gcash'],
            'From GCash' => ['deposit_to_bpi' => 'bpi', 'deposit_to_landbank' => 'landbank', 'add_to_cash' => 'cash'],
            'From BPI' => ['deposit_to_landbank' => 'landbank', 'add_to_cash' => 'cash', 'add_to_gcash' => 'gcash'],
            'Excess Fund' => ['deposit_to_bpi' => 'bpi', 'deposit_to_landbank' => 'landbank', 'add_to_cash' => 'cash', 'add_to_gcash' => 'gcash'],
            'Refund' => ['deposit_to_bpi' => 'bpi', 'deposit_to_landbank' => 'landbank', 'add_to_cash' => 'cash', 'add_to_gcash' => 'gcash'],
            'Cash Return' => ['deposit_to_bpi' => 'bpi', 'deposit_to_landbank' => 'landbank', 'add_to_cash' => 'cash', 'add_to_gcash' => 'gcash'],
            'Vendo' => ['deposit_to_bpi' => 'bpi', 'deposit_to_landbank' => 'landbank', 'add_to_cash' => 'cash', 'add_to_gcash' => 'gcash'],
            'CCTV' => ['deposit_to_bpi' => 'bpi', 'deposit_to_landbank' => 'landbank', 'add_to_cash' => 'cash', 'add_to_gcash' => 'gcash'],
            'Solar' => ['deposit_to_bpi' => 'bpi', 'deposit_to_landbank' => 'landbank', 'add_to_cash' => 'cash', 'add_to_gcash' => 'gcash'],
            'Sales' => ['deposit_to_bpi' => 'bpi', 'deposit_to_landbank' => 'landbank', 'add_to_cash' => 'cash', 'add_to_gcash' => 'gcash'],
            'From Landbank' => ['deposit_to_bpi' => 'bpi', 'deposit_to_landbank' => 'landbank', 'add_to_cash' => 'cash', 'add_to_gcash' => 'gcash'],
            'Fiber Wire' => ['deposit_to_bpi' => 'bpi', 'deposit_to_landbank' => 'landbank', 'add_to_cash' => 'cash', 'add_to_gcash' => 'gcash'],
            'Credit' => ['deposit_to_bpi' => 'bpi', 'deposit_to_landbank' => 'landbank', 'add_to_cash' => 'cash', 'add_to_gcash' => 'gcash'],
            'Investment' => ['deposit_to_bpi' => 'bpi', 'deposit_to_landbank' => 'landbank', 'add_to_cash' => 'cash', 'add_to_gcash' => 'gcash'],
            'Reconnection' => ['deposit_to_bpi' => 'bpi', 'deposit_to_landbank' => 'landbank', 'add_to_cash' => 'cash', 'add_to_gcash' => 'gcash'],
            'CC Marian' => ['deposit_to_bpi' => 'bpi', 'deposit_to_landbank' => 'landbank', 'add_to_cash' => 'cash', 'add_to_gcash' => 'gcash'],
            'CC Solarnet' => ['deposit_to_bpi' => 'bpi', 'deposit_to_landbank' => 'landbank', 'add_to_cash' => 'cash', 'add_to_gcash' => 'gcash'],
        ];
        $sourceWallets = ['From Cash' => 'cash', 'From GCash' => 'gcash', 'From BPI' => 'bpi', 'From Landbank' => 'landbank'];
        foreach ($cashIn as $description => $destinations) {
            foreach ($destinations as $method => $destination) {
                $rows[] = $this->row('Cash In', $description, $method, isset($sourceWallets[$description]) ? 'transfer' : 'cash_in', $sourceWallets[$description] ?? null, $destination, ++$sort);
            }
        }
        DB::table('transaction_definitions')->insert($rows);
    }

    private function row(string $type, string $description, string $method, string $effect, ?string $source, ?string $destination, int $sort): array
    {
        return ['id' => (string) Str::uuid(), 'type' => $type, 'description' => $description, 'payment_method' => $method, 'effect_type' => $effect, 'source_wallet' => $source, 'destination_wallet' => $destination, 'active' => true, 'sort_order' => $sort, 'created_at' => now(), 'updated_at' => now()];
    }

    public function down(): void
    {
        Schema::table('financial_entries', function (Blueprint $table) {
            $table->dropForeign(['transaction_definition_id']);
            $table->dropUnique(['idempotency_key']);
            $table->dropColumn(['transaction_definition_id', 'effect_type', 'source_wallet', 'destination_wallet', 'idempotency_key']);
        });
        Schema::dropIfExists('transaction_definitions');
    }
};
