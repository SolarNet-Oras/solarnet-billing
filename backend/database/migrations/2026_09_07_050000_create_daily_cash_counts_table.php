<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('daily_cash_counts', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->date('count_date')->index();
            $table->json('breakdown');
            $table->decimal('counted_amount', 12, 2);
            $table->decimal('expected_cash_balance', 12, 2);
            $table->decimal('variance', 12, 2);
            $table->uuid('counted_by')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->foreign('counted_by')->references('id')->on('users')->nullOnDelete();
            $table->index(['count_date', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('daily_cash_counts');
    }
};
