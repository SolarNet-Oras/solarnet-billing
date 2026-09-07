<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        DB::statement('ALTER TABLE tickets ALTER COLUMN customer_id DROP NOT NULL');
        Schema::table('tickets', function (Blueprint $table): void {
            $table->uuid('router_id')->nullable()->after('customer_id');
            $table->uuid('sms_advisory_campaign_id')->nullable()->after('router_id');
            $table->timestamp('scheduled_start_at')->nullable()->after('description');
            $table->timestamp('scheduled_end_at')->nullable()->after('scheduled_start_at');
            $table->foreign('router_id')->references('id')->on('routers')->nullOnDelete();
            $table->foreign('sms_advisory_campaign_id')->references('id')->on('sms_advisory_campaigns')->nullOnDelete();
            $table->index(['ticket_type', 'router_id']);
        });
    }

    public function down(): void
    {
        if (DB::table('tickets')->whereNull('customer_id')->exists()) {
            throw new RuntimeException('Resolve network-wide tickets before reverting this migration.');
        }
        Schema::table('tickets', function (Blueprint $table): void {
            $table->dropForeign(['router_id']);
            $table->dropForeign(['sms_advisory_campaign_id']);
            $table->dropIndex(['ticket_type', 'router_id']);
            $table->dropColumn(['router_id', 'sms_advisory_campaign_id', 'scheduled_start_at', 'scheduled_end_at']);
        });
        DB::statement('ALTER TABLE tickets ALTER COLUMN customer_id SET NOT NULL');
    }
};
