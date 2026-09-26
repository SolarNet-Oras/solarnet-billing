<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('staff_cash_advances', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('user_id')->constrained('users')->restrictOnDelete();
            $table->foreignUuid('financial_entry_id')->nullable()->unique()->constrained('financial_entries')->nullOnDelete();
            $table->foreignUuid('payroll_disbursement_id')->nullable()->constrained('staff_payroll_disbursements')->nullOnDelete();
            $table->decimal('amount', 12, 2);
            $table->decimal('settled_amount', 12, 2)->default(0);
            $table->string('status', 24)->default('outstanding')->index();
            $table->timestamp('issued_at');
            $table->timestamp('settled_at')->nullable();
            $table->string('notes', 500)->nullable();
            $table->timestamps();
            $table->index(['user_id', 'status']);
        });

        DB::table('staff_compensations')
            ->where('cash_advance_deduction', '>', 0)
            ->orderBy('user_id')
            ->get(['user_id', 'cash_advance_deduction'])
            ->each(function (object $profile): void {
                DB::table('staff_cash_advances')->insert([
                    'id' => (string) Str::uuid(),
                    'user_id' => $profile->user_id,
                    'amount' => $profile->cash_advance_deduction,
                    'settled_amount' => 0,
                    'status' => 'outstanding',
                    'issued_at' => now(),
                    'notes' => 'Opening balance migrated from employee salary setup.',
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            });

        DB::table('staff_compensations')->where('cash_advance_deduction', '>', 0)->update(['cash_advance_deduction' => 0]);

        DB::table('transaction_definitions')->where('type', 'C/A')->update(['active' => false]);
        $sort = ((int) DB::table('transaction_definitions')->max('sort_order')) + 1;
        foreach ([
            'cash' => 'cash',
            'gcash' => 'gcash',
            'bank_bpi' => 'bpi',
            'bank_landbank' => 'landbank',
        ] as $method => $wallet) {
            DB::table('transaction_definitions')->insert([
                'id' => (string) Str::uuid(),
                'type' => 'C/A',
                'description' => 'Employee Cash Advance',
                'payment_method' => $method,
                'effect_type' => 'expense',
                'source_wallet' => $wallet,
                'destination_wallet' => null,
                'active' => true,
                'sort_order' => $sort++,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('staff_cash_advances');
        DB::table('transaction_definitions')->where('type', 'C/A')->where('description', 'Employee Cash Advance')->delete();
        DB::table('transaction_definitions')->where('type', 'C/A')->update(['active' => true]);
    }
};
