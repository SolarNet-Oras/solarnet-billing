<?php

namespace App\Services;

use App\Models\DhcpLease;
use Illuminate\Support\Collection;

class ClientMigrationMatcher
{
    public function normalizeMac(?string $mac): ?string
    {
        $hex = strtoupper(preg_replace('/[^A-Fa-f0-9]/', '', (string) $mac));

        return strlen($hex) === 12 ? implode(':', str_split($hex, 2)) : null;
    }

    /**
     * Match only on a current DHCP lease MAC address. IP addresses are never used.
     *
     * @return array{status:string,lease:?DhcpLease,candidates:Collection,requires_confirmation:bool}
     */
    public function find(string $excelMac): array
    {
        $mac = $this->normalizeMac($excelMac);
        if (! $mac) {
            return $this->result('INVALID MAC ADDRESS');
        }

        $leases = DhcpLease::query()->where('is_current', true)->get();
        $exact = $leases->first(fn (DhcpLease $lease) => $this->normalizeMac($lease->mac_address) === $mac);
        if ($exact) {
            return $this->result('EXACT MAC MATCH', $exact);
        }

        // A partial input is only considered after removing the last hexadecimal character.
        $prefix = substr(str_replace(':', '', $mac), 0, -1);
        $candidates = $leases->filter(
            fn (DhcpLease $lease) => str_starts_with(str_replace(':', '', $this->normalizeMac($lease->mac_address) ?? ''), $prefix)
        )->values();

        return match ($candidates->count()) {
            0 => $this->result('LEASE NOT FOUND'),
            1 => $this->result('PARTIAL MAC MATCH', $candidates->first(), $candidates, true),
            default => $this->result('AMBIGUOUS PARTIAL MAC MATCH', null, $candidates, true),
        };
    }

    private function result(string $status, ?DhcpLease $lease = null, ?Collection $candidates = null, bool $requiresConfirmation = false): array
    {
        return [
            'status' => $status,
            'lease' => $lease,
            'candidates' => $candidates ?? collect(),
            'requires_confirmation' => $requiresConfirmation,
        ];
    }
}
