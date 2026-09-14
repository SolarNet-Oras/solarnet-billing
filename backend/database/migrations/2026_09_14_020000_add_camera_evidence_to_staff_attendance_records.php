<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('staff_attendance_records', function (Blueprint $table): void {
            $table->string('verification_method', 32)->nullable();
            $table->string('clock_in_photo_path')->nullable();
            $table->string('clock_out_photo_path')->nullable();
            $table->timestamp('clock_in_photo_captured_at')->nullable();
            $table->timestamp('clock_out_photo_captured_at')->nullable();
            $table->string('clock_in_ip_address', 45)->nullable();
            $table->string('clock_out_ip_address', 45)->nullable();
            $table->string('clock_in_device', 500)->nullable();
            $table->string('clock_out_device', 500)->nullable();
        });

        Schema::create('staff_attendance_photo_audits', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('attendance_record_id');
            $table->uuid('actor_id')->nullable();
            $table->string('event', 40);
            $table->string('photo_type', 16)->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->timestamps();
            $table->foreign('attendance_record_id')->references('id')->on('staff_attendance_records')->cascadeOnDelete();
            $table->foreign('actor_id')->references('id')->on('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('staff_attendance_photo_audits');
        Schema::table('staff_attendance_records', function (Blueprint $table): void {
            $table->dropColumn(['verification_method','clock_in_photo_path','clock_out_photo_path','clock_in_photo_captured_at','clock_out_photo_captured_at','clock_in_ip_address','clock_out_ip_address','clock_in_device','clock_out_device']);
        });
    }
};
