<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('employee_device_commands', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('device_id')->constrained('employee_devices')->cascadeOnDelete();
            $table->foreignUuid('requested_by')->constrained('users');
            $table->string('command', 32);
            $table->text('message')->nullable();
            $table->string('reason', 255);
            $table->string('status', 24)->default('queued');
            $table->timestamp('delivered_at')->nullable();
            $table->timestamp('responded_at')->nullable();
            $table->text('result_message')->nullable();
            $table->timestamps();
            $table->index(['device_id','status']);
        });
    }
    public function down(): void { Schema::dropIfExists('employee_device_commands'); }
};
