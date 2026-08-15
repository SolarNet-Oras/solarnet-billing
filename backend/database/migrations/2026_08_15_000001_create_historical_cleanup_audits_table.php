<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('historical_cleanup_audits', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('user_id')->constrained('users')->restrictOnDelete();
            $table->date('from_date');
            $table->date('to_date');
            $table->json('modules');
            $table->json('summary')->nullable();
            $table->unsignedBigInteger('customer_count_before');
            $table->unsignedBigInteger('customer_count_after')->nullable();
            $table->unsignedInteger('customer_records_deleted')->default(0);
            $table->string('status', 32)->default('running');
            $table->ipAddress('ip_address')->nullable();
            $table->text('error')->nullable();
            $table->timestamps();

            $table->index(['created_at', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('historical_cleanup_audits');
    }
};
