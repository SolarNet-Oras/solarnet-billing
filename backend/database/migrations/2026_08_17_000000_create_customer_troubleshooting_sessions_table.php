<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('customer_troubleshooting_sessions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('customer_id');
            $table->string('status', 32)->default('active');
            $table->string('stage', 48)->default('led_check');
            $table->json('state')->nullable();
            $table->json('messages')->nullable();
            $table->json('diagnosis')->nullable();
            $table->uuid('ticket_id')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->foreign('customer_id')->references('id')->on('customers')->cascadeOnDelete();
            $table->foreign('ticket_id')->references('id')->on('tickets')->nullOnDelete();
            $table->index(['customer_id', 'status', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('customer_troubleshooting_sessions');
    }
};
