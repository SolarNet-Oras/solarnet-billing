<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('sms_advisory_campaigns', function (Blueprint $table): void {
            $table->uuid('router_id')->nullable()->after('recipient_filter');
            $table->string('router_name')->nullable()->after('router_id');
            $table->foreign('router_id')->references('id')->on('routers')->nullOnDelete();
            $table->index(['recipient_filter', 'router_id']);
        });
    }

    public function down(): void
    {
        Schema::table('sms_advisory_campaigns', function (Blueprint $table): void {
            $table->dropForeign(['router_id']);
            $table->dropIndex(['recipient_filter', 'router_id']);
            $table->dropColumn(['router_id', 'router_name']);
        });
    }
};
