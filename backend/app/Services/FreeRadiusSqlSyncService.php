<?php

namespace App\Services;

use App\Models\RadiusNasClient;
use App\Models\RadiusSubscriber;
use Illuminate\Support\Facades\DB;

/**
 * Writes only SolarNet-owned rows to FreeRADIUS's isolated SQL schema.
 *
 * This class never contacts RouterOS and it cannot make a NAS live by itself.
 * It is disabled unless RADIUS_SQL_SYNC_ENABLED=true. FreeRADIUS also remains
 * loopback-bound by default, so enabling the SQL bridge is not an Internet or
 * DHCP change.
 */
class FreeRadiusSqlSyncService
{
    private const MANAGED_PREFIX = 'solarnet:';

    public function isEnabled(): bool
    {
        return (bool) config('radius.freeradius_enabled') && (bool) config('radius.sql_sync_enabled');
    }

    public function syncSubscriber(RadiusSubscriber $subscriber): array
    {
        if (!$this->isEnabled()) {
            return ['enabled' => false, 'success' => true, 'message' => 'FreeRADIUS SQL synchronization is disabled.'];
        }

        $username = $subscriber->radius_username;
        $managedBy = self::MANAGED_PREFIX . 'subscriber:' . $subscriber->id;

        DB::transaction(function () use ($username, $managedBy, $subscriber): void {
            $this->table('radcheck')->where('managed_by', $managedBy)->delete();
            $this->table('radreply')->where('managed_by', $managedBy)->delete();

            // Clearing a MAC, resolving it as a conflict, or deleting a
            // customer must revoke only this SolarNet-owned policy. Do this
            // before returning so an older Access-Accept row can never be
            // left behind for the former identity.
            if (!$username) return;

            $accepted = in_array($subscriber->authorization_status, ['active', 'grace'], true)
                && !$subscriber->mac_conflict
                && filled($subscriber->rate_limit);
            if (!$accepted) return;

            // RouterOS DHCP sends the client MAC as User-Name. Accept is
            // deliberately installed only for a full, conflict-free, eligible
            // staged identity. NAS packet authentication remains protected by
            // its per-router shared secret.
            $this->table('radcheck')->insert([
                'username' => $username,
                'attribute' => 'Auth-Type',
                'op' => ':=',
                'value' => 'Accept',
                'managed_by' => $managedBy,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            $this->table('radreply')->insert([
                'username' => $username,
                'attribute' => 'Mikrotik-Rate-Limit',
                'op' => ':=',
                'value' => $subscriber->rate_limit,
                'managed_by' => $managedBy,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $interval = (int) config('radius.interim_update_seconds');
            if ($interval > 0) {
                $this->table('radreply')->insert([
                    'username' => $username,
                    'attribute' => 'Acct-Interim-Interval',
                    'op' => ':=',
                    'value' => (string) $interval,
                    'managed_by' => $managedBy,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        });

        return [
            'enabled' => true,
            'success' => true,
            'message' => $username
                ? 'FreeRADIUS SQL policy rows synchronized. No RouterOS command was sent.'
                : 'Any SolarNet-owned FreeRADIUS SQL policy for this subscriber was removed. No RouterOS command was sent.',
        ];
    }

    public function syncNas(RadiusNasClient $nas): array
    {
        if (!$this->isEnabled()) {
            return ['enabled' => false, 'success' => true, 'message' => 'FreeRADIUS SQL synchronization is disabled.'];
        }

        // This release deliberately has no bulk/production NAS rollout. A
        // NAS record is allowed into the FreeRADIUS SQL table only when an
        // administrator has marked it as an isolated test. That gives a
        // reviewer a hard server-side boundary even if the UI is bypassed.
        if ($nas->enabled && !$nas->test_mode) {
            return [
                'enabled' => true,
                'success' => false,
                'message' => 'Production NAS synchronization is not available in this release. Keep the NAS in isolated test mode.',
            ];
        }

        $managedBy = self::MANAGED_PREFIX . 'nas:' . $nas->id;
        DB::transaction(function () use ($nas, $managedBy): void {
            $query = $this->table('nas')->where('managed_by', $managedBy);
            if (!$nas->enabled) {
                $query->delete();
                return;
            }

            $query->delete();
            $this->table('nas')->insert([
                'nasname' => $nas->nas_address,
                'shortname' => $nas->shortname,
                'type' => 'other',
                'secret' => $nas->shared_secret,
                'description' => $nas->test_mode
                    ? 'SolarNet isolated RADIUS test NAS'
                    : 'SolarNet approved RADIUS NAS',
                'managed_by' => $managedBy,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        });

        $nas->forceFill([
            'last_synced_at' => now(),
            'last_error' => null,
        ])->save();

        return [
            'enabled' => true,
            'success' => true,
            'message' => $nas->enabled
                ? 'NAS was synchronized into FreeRADIUS. Restart the FreeRADIUS service before it can load a changed NAS client.'
                : 'NAS was removed from FreeRADIUS. Restart the FreeRADIUS service before it reloads its NAS client list.',
        ];
    }

    private function table(string $name)
    {
        $schema = (string) config('radius.sql_schema', 'radius');
        if (preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $schema) !== 1) {
            throw new \RuntimeException('Invalid FreeRADIUS SQL schema configuration.');
        }
        return DB::table("{$schema}.{$name}");
    }
}
