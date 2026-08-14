<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('remittances', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('collector_id');
            $table->uuid('received_by')->nullable();
            $table->decimal('declared_amount', 12, 2);
            $table->decimal('received_amount', 12, 2)->nullable();
            $table->enum('status', ['submitted', 'received', 'discrepancy'])->default('submitted');
            $table->text('notes')->nullable();
            $table->timestamp('submitted_at');
            $table->timestamp('received_at')->nullable();
            $table->timestamps();
            $table->foreign('collector_id')->references('id')->on('users')->cascadeOnDelete();
            $table->foreign('received_by')->references('id')->on('users')->nullOnDelete();
            $table->index(['status', 'submitted_at']);
        });

        Schema::table('payments', function (Blueprint $table) {
            $table->uuid('collector_id')->nullable()->after('customer_id');
            $table->uuid('remittance_id')->nullable()->after('collector_id');
            $table->foreign('collector_id')->references('id')->on('users')->nullOnDelete();
            $table->foreign('remittance_id')->references('id')->on('remittances')->nullOnDelete();
            $table->index(['collector_id', 'remittance_id']);
        });
    }

    public function down(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->dropForeign(['collector_id']);
            $table->dropForeign(['remittance_id']);
            $table->dropIndex(['collector_id', 'remittance_id']);
            $table->dropColumn(['collector_id', 'remittance_id']);
        });
        Schema::dropIfExists('remittances');
    }
};
