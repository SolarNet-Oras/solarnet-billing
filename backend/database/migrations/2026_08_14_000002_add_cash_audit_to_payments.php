<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->uuid('received_by')->nullable()->after('collector_id');
            $table->decimal('cash_counted_amount', 12, 2)->nullable()->after('amount');
            $table->json('cash_breakdown')->nullable()->after('cash_counted_amount');
            $table->foreign('received_by')->references('id')->on('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->dropForeign(['received_by']);
            $table->dropColumn(['received_by', 'cash_counted_amount', 'cash_breakdown']);
        });
    }
};
