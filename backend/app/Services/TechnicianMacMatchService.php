<?php

namespace App\Services;

/**
 * Compares a technician-entered ONU/router MAC with the current DHCP lease.
 *
 * A final-character typo is safe to correct only after the caller has already
 * selected one unique, current, unregistered MikroTik lease. All other fuzzy
 * matches continue through the explicit confirmation workflow.
 */
class TechnicianMacMatchService
{
    public function compare(string $enteredMac, string $leaseMac): array
    {
        $entered = $this->normalize($enteredMac);
        $lease = $this->normalize($leaseMac);

        if (! $entered || ! $lease) {
            return [
                'score' => 0.0,
                'type' => 'invalid',
                'different_positions' => [],
                'entered_mac' => $entered,
                'lease_mac' => $lease,
            ];
        }

        $enteredHex = str_replace(':', '', $entered);
        $leaseHex = str_replace(':', '', $lease);
        $differentPositions = [];

        for ($index = 0; $index < 12; $index++) {
            if ($enteredHex[$index] !== $leaseHex[$index]) {
                $differentPositions[] = $index;
            }
        }

        $samePositions = 12 - count($differentPositions);
        $type = match (true) {
            $samePositions === 12 => 'exact',
            count($differentPositions) === 1 && $differentPositions[0] === 11 => 'last_character_correction',
            default => 'fuzzy_90_plus',
        };

        return [
            'score' => round(($samePositions / 12) * 100, 1),
            'type' => $type,
            'different_positions' => $differentPositions,
            'entered_mac' => $entered,
            'lease_mac' => $lease,
        ];
    }

    private function normalize(?string $value): ?string
    {
        $hex = strtoupper((string) preg_replace('/[^0-9A-F]/i', '', (string) $value));

        if (strlen($hex) !== 12 || ! ctype_xdigit($hex)) {
            return null;
        }

        return implode(':', str_split($hex, 2));
    }
}
