<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('ai_pending_actions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('user_id')->index();
            $table->uuid('conversation_id')->nullable()->index();
            $table->string('action', 64);
            $table->json('payload');
            $table->string('status', 20)->default('pending')->index();
            $table->timestamp('expires_at')->index();
            $table->timestamp('confirmed_at')->nullable();
            $table->timestamps();
            $table->foreign('user_id')->references('id')->on('users')->onDelete('cascade');
            $table->foreign('conversation_id')->references('id')->on('ai_conversations')->onDelete('set null');
        });
    }

    public function down(): void { Schema::dropIfExists('ai_pending_actions'); }
};
