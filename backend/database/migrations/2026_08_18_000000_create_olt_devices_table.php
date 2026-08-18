<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('olt_devices', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('name');
            $table->string('host');
            $table->unsignedInteger('snmp_port')->default(161);
            $table->string('snmp_version', 10)->default('2c');
            $table->text('snmp_community')->nullable();
            $table->string('location')->nullable();
            $table->string('model')->nullable();
            $table->text('notes')->nullable();
            $table->boolean('is_active')->default(true);
            $table->string('connection_status', 20)->default('unknown');
            $table->timestamp('last_checked_at')->nullable();
            $table->json('last_snapshot')->nullable();
            $table->timestamps();

            $table->index(['is_active', 'connection_status']);
            $table->index('host');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('olt_devices');
    }
};
