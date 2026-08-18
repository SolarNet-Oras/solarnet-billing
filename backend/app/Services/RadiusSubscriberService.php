<?php

namespace App\Services;

use App\Models\Customer;
use App\Models\RadiusAuthorizationLog;
use App\Models\RadiusSubscriber;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Builds an auditable RADIUS/IPoE policy from SolarNet's existing source of
 * truth. It deliberately does not send UDP packets, edit RouterOS, or modify
 * queues. A RADIUS server can consume these staged records only after an
 * administrator enables and tests a dedicated bridge for one subscriber.
 */
class RadiusSubscriberService
{
    public function __construct(
        private readonly BillingSuspensionService $billingSuspension,
        private readonly CustomerAccountReconciliationService $reconciliation,
        private readonly FreeRadiusSqlSyncService $freeRadiusSql,
    ) {
    }

    public function syncForCustomer(Customer $customer, string $reason = 'customer_changed', ?User $actor = null): array
    {
        $customer->loadMissing(['router', 'servicePlan']);
        $policy = $this->policyForCustomer($customer);

        return DB::transaction(function () use ($customer, $policy, $reason, $actor): array {
            $subscriber = RadiusSubscriber::query()->firstOrNew(['customer_id' => $customer->id]);
            $subscriber->fill([
                'router_id' => $customer->router_id,
                // A conflicting MAC is retained for review but deliberately
                // has no RADIUS username, so it cannot authorize either side
                // of the duplicate binding through this policy projection.
                'radius_username' => $policy['mac_conflict'] ? null : $policy['radius_username'],
                'mac_address' => $policy['mac_address'],
                'ip_address' => $customer->ip_address,
                'authorization_status' => $policy['authorization_status'],
                'billing_status' => $policy['billing_status'],
                'rate_limit' => $policy['rate_limit'],
                'restricted_rate_limit' => $policy['restricted_rate_limit'],
                'requires_captive_portal' => $policy['requires_captive_portal'],
                'mac_conflict' => $policy['mac_conflict'],
                'last_synced_at' => now(),
                'last_error' => in_array($policy['authorization_status'], ['conflict', 'waiting_for_mac'], true)
                    ? $policy['reason']
                    : null,
                'metadata' => [
                    'source' => 'solarnet_billing',
                    'enforcement' => 'staged_only',
                    'account_number' => $customer->account_number,
                    'service_plan_id' => $customer->service_plan_id,
                    'grace_period_end' => $policy['grace_period_end'],
                    'suspension_at' => $policy['suspension_at'],
                ],
            ]);
            $subscriber->save();

            if ($policy['mac_conflict']) {
                $conflictingCustomers = Customer::query()
                    ->whereIn('id', $policy['conflicting_customer_ids'])
                    ->get()
                    ->keyBy('id');

                // Mark any previously staged matching customer as a conflict
                // too. This is intentionally a local policy/audit update;
                // it cannot alter a lease, queue, firewall, or RADIUS server.
                RadiusSubscriber::query()
                    ->whereIn('customer_id', $policy['conflicting_customer_ids'])
                    ->get()
                    ->each(function (RadiusSubscriber $conflict) use ($conflictingCustomers, $reason, $actor): void {
                        $conflict->forceFill([
                            'radius_username' => null,
                            'authorization_status' => 'conflict',
                            'rate_limit' => null,
                            'restricted_rate_limit' => null,
                            'requires_captive_portal' => false,
                            'mac_conflict' => true,
                            'last_synced_at' => now(),
                            'last_error' => 'MAC address conflicts with another active customer. Explicit reassignment is required.',
                        ])->save();

                        if ($otherCustomer = $conflictingCustomers->get($conflict->customer_id)) {
                            $this->audit($conflict, $otherCustomer, 'mac_conflict_detected', 'conflict', $conflict->last_error, $reason, $actor, [
                                'external_enforcement' => false,
                                'network_change_made' => false,
                            ]);

                            // If this subscriber had been synchronized before
                            // the duplicate appeared, remove only its
                            // SolarNet-owned SQL authorization immediately.
                            // A bridge error is audited but must never roll
                            // back the local conflict decision.
                            try {
                                $bridge = $this->freeRadiusSql->syncSubscriber($conflict);
                                if ($bridge['enabled'] ?? false) {
                                    $this->audit($conflict, $otherCustomer, 'freeradius_sql_synchronized', 'conflict', $bridge['message'], $reason, $actor, [
                                        'network_change_made' => false,
                                        'radius_packet_sent' => false,
                                    ]);
                                }
                            } catch (\Throwable $e) {
                                $this->audit($conflict, $otherCustomer, 'freeradius_sql_sync_failed', 'conflict', 'FreeRADIUS SQL synchronization failed; the local MAC conflict remains blocked.', $reason, $actor, [
                                    'error_type' => $e::class,
                                    'network_change_made' => false,
                                ]);
                            }
                        }
                    });
            }

            $this->audit($subscriber, $customer, 'subscriber_synced', $policy['authorization_status'], $policy['reason'], $reason, $actor, [
                'mac_conflict' => $policy['mac_conflict'],
                'requires_captive_portal' => $policy['requires_captive_portal'],
                'rate_limit_present' => $policy['rate_limit'] !== null,
                'radius_enabled' => (bool) config('radius.enabled'),
                'external_enforcement' => false,
            ]);

            if ($policy['mac_conflict']) {
                $this->audit($subscriber, $customer, 'mac_conflict_detected', 'conflict', $policy['reason'], $reason, $actor, [
                    'external_enforcement' => false,
                    'network_change_made' => false,
                ]);
            }

            try {
                $bridge = $this->freeRadiusSql->syncSubscriber($subscriber);
                if ($bridge['enabled'] ?? false) {
                    $this->audit($subscriber, $customer, 'freeradius_sql_synchronized', $subscriber->authorization_status, $bridge['message'], $reason, $actor, [
                        'network_change_made' => false,
                        'radius_packet_sent' => false,
                    ]);
                }
            } catch (\Throwable $e) {
                // A FreeRADIUS SQL bridge failure is never allowed to block
                // existing SolarNet billing, queue, or suspension handling.
                $this->audit($subscriber, $customer, 'freeradius_sql_sync_failed', $subscriber->authorization_status, 'FreeRADIUS SQL synchronization failed; SolarNet network policy was not changed.', $reason, $actor, [
                    'error_type' => $e::class,
                    'network_change_made' => false,
                ]);
            }

            return ['success' => true, 'subscriber' => $subscriber->fresh(['customer', 'router']), 'policy' => $policy];
        });
    }

    /** Build a policy without side effects; safe for the admin test action. */
    public function policyForCustomer(Customer $customer): array
    {
        $customer->loadMissing(['router', 'servicePlan']);
        $financial = $this->reconciliation->snapshot($customer);
        $schedule = $this->billingSuspension->gracePeriodSchedule($customer);
        $mac = self::normalizeMac($customer->mac_address);
        // Existing imports contain a mix of colon, dash, dotted, and plain
        // MAC formats. Normalize in PHP rather than relying on a database-
        // specific expression, so a differently formatted duplicate can
        // never be silently authorized.
        $conflictingCustomerIds = $mac === null ? [] : Customer::query()
            ->select(['id', 'mac_address'])
            ->where('id', '!=', $customer->id)
            ->whereNull('deleted_at')
            ->whereIn('status', ['active', 'suspended', 'expired'])
            ->get()
            ->filter(fn (Customer $candidate) => self::normalizeMac($candidate->mac_address) === $mac)
            ->pluck('id')
            ->values()
            ->all();
        $macConflict = $conflictingCustomerIds !== [];

        $status = $this->authorizationStatus($customer, $financial, $schedule, $mac, $macConflict);
        $accepted = in_array($status, ['active', 'grace'], true);
        $rateLimit = $accepted ? self::rateLimitFromPlan($customer->servicePlan) : null;
        $restrictedRate = !$accepted && in_array($status, ['suspended', 'disconnected'], true)
            ? (config('radius.restricted_rate_limit') ?: null)
            : null;

        $reason = match ($status) {
            'active' => 'Billing is current under SolarNet policy.',
            'grace' => 'Invoice is outstanding but remains inside the existing SolarNet grace period.',
            'suspended' => 'SolarNet billing policy requires the existing restricted-access workflow.',
            'disconnected' => 'Customer is marked expired and receives no normal authorization.',
            'pending' => 'Customer installation or approval is still pending.',
            'conflict' => 'The MAC address is already bound to another active customer. Explicit reassignment is required.',
            default => 'A complete, valid MAC address is required before DHCP RADIUS can identify this subscriber.',
        };

        return [
            'authorization_status' => $status,
            'billing_status' => $financial['financial_status'],
            // RouterOS DHCP RADIUS uses the client MAC as its RADIUS username.
            'radius_username' => $mac,
            'mac_address' => $mac,
            'rate_limit' => $rateLimit,
            'restricted_rate_limit' => $restrictedRate,
            'requires_captive_portal' => in_array($status, ['suspended', 'disconnected'], true),
            'mac_conflict' => $macConflict,
            'conflicting_customer_ids' => $conflictingCustomerIds,
            'grace_period_end' => $schedule['grace_period_end']?->toDateString(),
            'suspension_at' => $schedule['suspension_at']?->toDateString(),
            'reason' => $reason,
            'radius_reply_preview' => $accepted && $rateLimit ? ['Mikrotik-Rate-Limit' => $rateLimit] : [],
        ];
    }

    public function auditTest(Customer $customer, ?User $actor = null): array
    {
        $result = $this->syncForCustomer($customer, 'administrator_policy_test', $actor);
        $subscriber = $result['subscriber'];
        $this->audit($subscriber, $customer, 'authorization_tested', $result['policy']['authorization_status'], $result['policy']['reason'], 'administrator_policy_test', $actor, [
            'network_request_sent' => false,
            'note' => 'This is a local SolarNet policy evaluation. No RADIUS packet or MikroTik change was made.',
        ]);
        return $result;
    }

    /**
     * A soft-deleted customer must never retain an external SQL authorization
     * row. This changes only the staged record and its SolarNet-owned
     * FreeRADIUS SQL entries; it does not contact a router or alter billing
     * history.
     */
    public function revokeForDeletedCustomer(Customer $customer, string $reason = 'customer_deleted'): void
    {
        $subscriber = RadiusSubscriber::query()->where('customer_id', $customer->id)->first();
        if (!$subscriber) return;

        DB::transaction(function () use ($subscriber, $customer, $reason): void {
            $subscriber->forceFill([
                'radius_username' => null,
                'authorization_status' => 'disconnected',
                'rate_limit' => null,
                'restricted_rate_limit' => null,
                'requires_captive_portal' => false,
                'last_synced_at' => now(),
                'last_error' => 'Customer record was deleted; staged RADIUS authorization was revoked.',
            ])->save();

            try {
                $bridge = $this->freeRadiusSql->syncSubscriber($subscriber);
                $bridgeMessage = $bridge['message'];
            } catch (\Throwable $e) {
                $bridgeMessage = 'FreeRADIUS SQL synchronization failed; the staged identity remains revoked locally.';
                $this->audit($subscriber, $customer, 'freeradius_sql_sync_failed', 'disconnected', $bridgeMessage, $reason, null, [
                    'error_type' => $e::class,
                    'network_change_made' => false,
                ]);
            }

            $this->audit($subscriber, $customer, 'subscriber_revoked', 'disconnected', $bridgeMessage, $reason, null, [
                'network_change_made' => false,
                'radius_packet_sent' => false,
            ]);
        });
    }

    public function configurationStatus(): array
    {
        $host = trim((string) config('radius.host'));
        return [
            'enabled' => (bool) config('radius.enabled'),
            'host_configured' => $host !== '',
            'auth_port' => (int) config('radius.auth_port'),
            'acct_port' => (int) config('radius.acct_port'),
            'coa_port' => (int) config('radius.coa_port'),
            'timeout' => (int) config('radius.timeout'),
            'retries' => (int) config('radius.retries'),
            'freeradius_enabled' => (bool) config('radius.freeradius_enabled'),
            'sql_sync_enabled' => (bool) config('radius.sql_sync_enabled'),
            // The transport bridge is intentionally not implemented until an
            // administrator selects and provisions a RADIUS server. Reporting
            // it explicitly avoids a configuration toggle that looks live but
            // could affect all DHCP clients.
            'external_bridge_installed' => (bool) config('radius.freeradius_enabled') && (bool) config('radius.sql_sync_enabled'),
            'coa_available' => false,
            'mode' => config('radius.freeradius_enabled')
                ? (config('radius.sql_sync_enabled') ? 'freeradius_sql_bridge' : 'freeradius_isolated')
                : 'staged_only',
            'safety_note' => config('radius.freeradius_enabled')
                ? 'FreeRADIUS is deployed as an isolated service. RouterOS RADIUS, DHCP RADIUS, HotSpot, CoA, firewall, queue, and customer access are still unchanged until an administrator approves one allow-listed test NAS.'
                : 'SolarNet has not enabled RouterOS RADIUS, HotSpot, CoA, or external RADIUS writes. Existing DHCP, queues, firewall rules, and customer access remain unchanged.',
        ];
    }

    public static function normalizeMac(?string $value): ?string
    {
        $hex = strtoupper((string) preg_replace('/[^A-Fa-f0-9]/', '', trim((string) $value)));
        if (strlen($hex) !== 12 || preg_match('/^[0-9A-F]{12}$/', $hex) !== 1 || $hex === '000000000000') return null;
        return implode(':', str_split($hex, 2));
    }

    /** RouterOS reads RX as customer upload and TX as customer download. */
    public static function rateLimitFromPlan(?object $plan): ?string
    {
        $download = (int) ($plan?->download_speed ?? 0);
        $upload = (int) ($plan?->upload_speed ?? 0);
        return $download > 0 && $upload > 0 ? "{$upload}M/{$download}M" : null;
    }

    private function authorizationStatus(Customer $customer, array $financial, array $schedule, ?string $mac, bool $macConflict): string
    {
        if ($macConflict) return 'conflict';
        if ($mac === null) return 'waiting_for_mac';
        if ($customer->status === 'pending') return 'pending';
        if ($customer->status === 'expired') return 'disconnected';
        if ($customer->status === 'suspended' || ($schedule['should_suspend'] ?? false)) return 'suspended';
        if (($financial['outstanding_balance'] ?? 0) > 0 && ($schedule['oldest_due_date'] ?? null) !== null) return 'grace';
        return 'active';
    }

    private function audit(RadiusSubscriber $subscriber, Customer $customer, string $event, string $decision, string $reason, string $source, ?User $actor, array $metadata = []): void
    {
        RadiusAuthorizationLog::create([
            'radius_subscriber_id' => $subscriber->id,
            'customer_id' => $customer->id,
            'router_id' => $customer->router_id,
            'actor_id' => $actor?->id,
            'event' => $event,
            'decision' => $decision,
            'reason' => $reason,
            'source' => $source,
            'metadata' => $metadata,
        ]);
    }
}
