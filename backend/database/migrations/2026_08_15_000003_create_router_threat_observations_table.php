<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('router_threat_observations', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('router_id');
            $table->string('feed_name', 100);
            $table->ipAddress('remote_ip');
            $table->json('connection_directions')->nullable();
            $table->enum('status', ['pending', 'dismissed', 'blocked'])->default('pending');
            $table->timestamp('first_observed_at');
            $table->timestamp('last_observed_at');
            $table->uuid('reviewed_by')->nullable();
            $table->timestamp('reviewed_at')->nullable();
            $table->text('review_note')->nullable();
            $table->timestamp('blocked_at')->nullable();
            $table->timestamps();

            $table->foreign('router_id')->references('id')->on('routers')->cascadeOnDelete();
            $table->foreign('reviewed_by')->references('id')->on('users')->nullOnDelete();
            $table->unique(['router_id', 'feed_name', 'remote_ip'], 'router_threat_feed_ip_unique');
            $table->index(['router_id', 'status', 'last_observed_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('router_threat_observations');
    }
};
