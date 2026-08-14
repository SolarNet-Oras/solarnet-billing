<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('remittances', function (Blueprint $table) {
            $table->uuid('liquidated_by')->nullable()->after('collector_id');
            $table->decimal('cash_counted_amount', 12, 2)->nullable()->after('declared_amount');
            $table->json('cash_breakdown')->nullable()->after('cash_counted_amount');
            $table->timestamp('liquidated_at')->nullable()->after('submitted_at');
            $table->foreign('liquidated_by')->references('id')->on('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('remittances', function (Blueprint $table) {
            $table->dropForeign(['liquidated_by']);
            $table->dropColumn(['liquidated_by', 'cash_counted_amount', 'cash_breakdown', 'liquidated_at']);
        });
    }
};
