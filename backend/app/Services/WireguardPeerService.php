<?php

namespace App\Services;

use App\Models\WireguardPeer;

class WireguardPeerService
{
    public function scripts(WireguardPeer $peer): array
    {
        $serverIp = explode('/', $peer->server_tunnel_address, 2)[0];
        $peerIp = explode('/', $peer->peer_tunnel_address, 2)[0];
        $endpoint = $this->routerOsQuote($peer->server_endpoint);
        $interface = $this->routerOsQuote($peer->interface_name);
        $comment = $this->routerOsQuote("SolarNet WireGuard: {$peer->name}");

        $mikrotik = implode("\n", [
            '# Review on the selected MikroTik before running. Existing VPN/firewall rules are not removed.',
            ":if ([:len [/interface/wireguard find where name={$interface}]] = 0) do={ /interface/wireguard add name={$interface} listen-port={$peer->router_listen_port} comment={$comment} }",
            ":if ([:len [/ip/address find where interface={$interface} and address=\"{$peer->peer_tunnel_address}\"]] = 0) do={ /ip/address add address=\"{$peer->peer_tunnel_address}\" interface={$interface} comment={$comment} }",
            ":if ([:len [/interface/wireguard/peers find where interface={$interface} and public-key=\"{$peer->server_public_key}\"]] = 0) do={ /interface/wireguard/peers add interface={$interface} public-key=\"{$peer->server_public_key}\" endpoint-address={$endpoint} endpoint-port={$peer->server_port} allowed-address=\"{$serverIp}/32\" persistent-keepalive={$peer->persistent_keepalive}s comment={$comment} }",
            '# The interface private key is generated and retained by RouterOS. Never paste it into SolarNet.',
        ]);

        $vpsPeer = implode("\n", [
            '# Add only this peer block to the existing VPS WireGuard interface.',
            '[Peer]',
            "# {$peer->name}",
            "PublicKey = {$peer->router_public_key}",
            "AllowedIPs = {$peerIp}/32",
            '',
            '# Then apply safely on the VPS:',
            "sudo wg syncconf wg-radius <(sudo wg-quick strip wg-radius)",
        ]);

        $firewall = implode("\n", [
            '# VPS firewall: expose only WireGuard UDP. Do not expose RADIUS UDP 1812/1813 publicly.',
            "sudo ufw allow {$peer->server_port}/udp comment 'SolarNet WireGuard'",
            "sudo ufw allow in on wg-radius from {$peerIp} to {$serverIp} comment 'SolarNet tunnel peer'",
            '',
            '# MikroTik: an outbound persistent peer normally needs no new input rule.',
            '# If this router must accept inbound WireGuard, replace <WAN_INTERFACE> and <VPS_PUBLIC_IP>, then review before running:',
            "/ip/firewall/filter add chain=input action=accept protocol=udp dst-port={$peer->router_listen_port} in-interface=<WAN_INTERFACE> src-address=<VPS_PUBLIC_IP> comment={$comment}",
        ]);

        return compact('mikrotik', 'vpsPeer', 'firewall');
    }

    private function routerOsQuote(string $value): string
    {
        return '"'.str_replace(['\\', '"'], ['\\\\', '\\"'], $value).'"';
    }
}
