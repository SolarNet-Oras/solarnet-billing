<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('onu_remote_sessions', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('customer_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('router_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('created_by')->constrained('users')->restrictOnDelete();
            $table->string('customer_ip', 45);
            $table->string('source_ip', 45);
            $table->unsignedSmallInteger('target_port');
            $table->unsignedSmallInteger('public_port')->index();
            $table->string('public_host');
            $table->string('path', 255);
            $table->string('router_comment')->unique();
            $table->string('status', 24)->default('active')->index();
            $table->timestamp('expires_at')->index();
            $table->timestamp('closed_at')->nullable();
            $table->text('last_error')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('onu_remote_sessions');
    }
};
