<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('facebook_page_post_drafts', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('facebook_page_connection_id')->constrained('facebook_page_connections')->cascadeOnDelete();
            $table->string('topic', 160);
            // The text is encrypted by the model cast because a draft can be
            // commercially sensitive before an administrator publishes it.
            $table->text('message_text');
            // draft | publishing | published | failed. There is deliberately
            // no scheduled or automatic publishing state.
            $table->string('status', 24)->default('draft');
            $table->string('facebook_post_id')->nullable()->unique();
            $table->foreignUuid('created_by')->constrained('users')->restrictOnDelete();
            $table->foreignUuid('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->timestamp('published_at')->nullable();
            $table->text('last_error')->nullable();
            $table->timestamps();

            $table->index(['facebook_page_connection_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('facebook_page_post_drafts');
    }
};
