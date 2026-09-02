<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('wireguard_peers', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('router_id')->constrained('routers')->cascadeOnDelete();
            $table->string('name');
            $table->string('interface_name')->default('wg-solarnet');
            $table->string('router_public_key', 64);
            $table->string('server_public_key', 64);
            $table->string('server_endpoint');
            // PostgreSQL has no unsigned smallint; valid UDP ports above 32767
            // (including WireGuard's conventional 51820) require an integer.
            $table->unsignedInteger('server_port')->default(51820);
            $table->string('server_tunnel_address')->default('10.77.0.1/30');
            $table->string('peer_tunnel_address')->unique();
            $table->unsignedInteger('router_listen_port')->default(13231);
            $table->unsignedSmallInteger('persistent_keepalive')->default(25);
            $table->boolean('enabled')->default(true);
            $table->timestamp('latest_handshake_at')->nullable();
            $table->unsignedBigInteger('rx_bytes')->default(0);
            $table->unsignedBigInteger('tx_bytes')->default(0);
            $table->timestamp('last_tested_at')->nullable();
            $table->string('last_test_status', 32)->nullable();
            $table->text('last_error')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('wireguard_peers');
    }
};
