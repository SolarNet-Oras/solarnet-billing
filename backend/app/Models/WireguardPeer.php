<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WireguardPeer extends Model
{
    use HasUuids;

    protected $fillable = [
        'router_id', 'name', 'interface_name', 'router_public_key',
        'server_public_key', 'server_endpoint', 'server_port',
        'server_tunnel_address', 'peer_tunnel_address', 'router_listen_port',
        'persistent_keepalive', 'enabled', 'latest_handshake_at', 'rx_bytes',
        'tx_bytes', 'last_tested_at', 'last_test_status', 'last_error',
    ];

    protected function casts(): array
    {
        return [
            'enabled' => 'boolean',
            'server_port' => 'integer',
            'router_listen_port' => 'integer',
            'persistent_keepalive' => 'integer',
            'latest_handshake_at' => 'datetime',
            'last_tested_at' => 'datetime',
            'rx_bytes' => 'integer',
            'tx_bytes' => 'integer',
        ];
    }

    public function router(): BelongsTo
    {
        return $this->belongsTo(Router::class);
    }
}
