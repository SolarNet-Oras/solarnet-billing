<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('facebook_page_connections', function (Blueprint $table): void {
            $table->timestamp('webhook_subscribed_at')->nullable()->after('is_active');
        });
    }

    public function down(): void
    {
        Schema::table('facebook_page_connections', function (Blueprint $table): void {
            $table->dropColumn('webhook_subscribed_at');
        });
    }
};
