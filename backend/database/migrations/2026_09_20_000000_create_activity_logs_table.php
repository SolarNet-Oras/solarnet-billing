<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('activity_logs', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('actor_id')->nullable()->index();
            $table->string('actor_name')->nullable()->index();
            $table->string('actor_type', 40)->nullable();
            $table->string('category', 80)->index();
            $table->string('action', 160)->index();
            $table->string('method', 10);
            $table->string('path', 500);
            $table->string('subject_type', 100)->nullable()->index();
            $table->string('subject_id', 100)->nullable()->index();
            $table->unsignedSmallInteger('response_status')->index();
            $table->json('changes')->nullable();
            $table->string('ip_address', 64)->nullable();
            $table->string('user_agent', 500)->nullable();
            $table->unsignedInteger('duration_ms')->default(0);
            $table->timestamps();
            $table->index(['created_at', 'category']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('activity_logs');
    }
};
