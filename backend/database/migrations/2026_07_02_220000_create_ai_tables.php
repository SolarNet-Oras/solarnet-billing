<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_conversations', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('user_id');
            $table->string('title')->nullable();
            $table->timestamps();

            $table->foreign('user_id')->references('id')->on('users')->onDelete('cascade');
            $table->index(['user_id', 'created_at']);
        });

        Schema::create('ai_messages', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('conversation_id');
            // 'system' | 'user' | 'assistant' | 'tool'
            $table->string('role', 20);
            $table->text('content')->nullable();
            // Function/tool call payload emitted by the assistant
            $table->json('tool_calls')->nullable();
            // If role='tool', the tool_call_id this message answers
            $table->string('tool_call_id')->nullable();
            $table->string('tool_name')->nullable();
            $table->integer('prompt_tokens')->nullable();
            $table->integer('completion_tokens')->nullable();
            $table->timestamps();

            $table->foreign('conversation_id')->references('id')->on('ai_conversations')->onDelete('cascade');
            $table->index(['conversation_id', 'created_at']);
        });

        Schema::create('ai_audit_logs', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('user_id');
            $table->uuid('conversation_id')->nullable();
            $table->string('tool_name');
            $table->json('arguments')->nullable();
            $table->json('result')->nullable();
            $table->integer('latency_ms')->nullable();
            // 'ok' | 'error' | 'denied'
            $table->string('status', 20)->default('ok');
            $table->text('error')->nullable();
            $table->timestamps();

            $table->foreign('user_id')->references('id')->on('users');
            $table->foreign('conversation_id')->references('id')->on('ai_conversations')->onDelete('set null');
            $table->index(['user_id', 'created_at']);
            $table->index(['tool_name', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_audit_logs');
        Schema::dropIfExists('ai_messages');
        Schema::dropIfExists('ai_conversations');
    }
};
