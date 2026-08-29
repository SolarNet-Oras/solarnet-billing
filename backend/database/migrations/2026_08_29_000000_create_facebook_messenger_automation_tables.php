<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('facebook_page_connections', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('page_id')->unique();
            $table->string('page_name');
            // Laravel's encrypted model cast keeps this token out of normal
            // API payloads and unreadable in a database backup by itself.
            $table->text('page_access_token');
            $table->timestamp('token_expires_at')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamp('last_webhook_at')->nullable();
            $table->text('last_error')->nullable();
            $table->foreignUuid('connected_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('facebook_messenger_conversations', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('facebook_page_connection_id')->constrained('facebook_page_connections')->cascadeOnDelete();
            // Facebook Page-scoped ID (PSID), not a customer account number.
            $table->string('page_scoped_id');
            $table->string('display_name')->nullable();
            $table->boolean('human_handoff_required')->default(false);
            // Marketing requires a clear opt-in captured by a staff member.
            $table->timestamp('marketing_opt_in_at')->nullable();
            $table->foreignUuid('marketing_opt_in_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('marketing_opt_out_at')->nullable();
            $table->timestamp('last_inbound_at')->nullable();
            $table->timestamp('last_outbound_at')->nullable();
            $table->timestamp('last_message_at')->nullable();
            $table->timestamps();

            $table->unique(['facebook_page_connection_id', 'page_scoped_id'], 'facebook_messenger_page_psid_unique');
            $table->index(['facebook_page_connection_id', 'last_message_at']);
            $table->index(['marketing_opt_in_at', 'marketing_opt_out_at']);
        });

        Schema::create('facebook_marketing_campaigns', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('facebook_page_connection_id')->constrained('facebook_page_connections')->cascadeOnDelete();
            $table->string('name');
            $table->text('message_text');
            // draft | sending | sent | failed. Sending is never scheduled by default.
            $table->string('status', 24)->default('draft');
            $table->unsignedInteger('recipient_count')->default(0);
            $table->unsignedInteger('sent_count')->default(0);
            $table->unsignedInteger('failed_count')->default(0);
            $table->foreignUuid('created_by')->constrained('users')->restrictOnDelete();
            $table->foreignUuid('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->text('last_error')->nullable();
            $table->timestamps();

            $table->index(['facebook_page_connection_id', 'status']);
        });

        Schema::create('facebook_messenger_messages', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('facebook_messenger_conversation_id')->constrained('facebook_messenger_conversations')->cascadeOnDelete();
            $table->foreignUuid('facebook_marketing_campaign_id')->nullable()->constrained('facebook_marketing_campaigns')->nullOnDelete();
            $table->uuid('reply_to_message_id')->nullable()->index();
            $table->string('facebook_mid')->nullable()->unique();
            // inbound | outbound; source is webhook, staff, ai_auto, or campaign.
            $table->string('direction', 16);
            $table->string('source', 24);
            $table->text('message_text')->nullable();
            $table->json('meta_payload')->nullable();
            $table->string('delivery_status', 24)->default('received');
            $table->text('delivery_error')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamps();

            $table->index(['facebook_messenger_conversation_id', 'created_at']);
            $table->unique(['facebook_marketing_campaign_id', 'facebook_messenger_conversation_id'], 'facebook_campaign_conversation_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('facebook_messenger_messages');
        Schema::dropIfExists('facebook_marketing_campaigns');
        Schema::dropIfExists('facebook_messenger_conversations');
        Schema::dropIfExists('facebook_page_connections');
    }
};
