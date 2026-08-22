<?php

namespace App\Services;

use App\Models\Customer;
use App\Models\DhcpLease;
use App\Models\Ticket;
use App\Models\TicketHistory;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class TicketWorkflowService
{
    public function claimInstallation(Ticket $ticket, User $technician): Ticket
    {
        return DB::transaction(function () use ($ticket, $technician) {
            $locked = Ticket::lockForUpdate()->findOrFail($ticket->id);
            if ($locked->ticket_type !== 'installation') {
                throw ValidationException::withMessages(['ticket' => 'This is not a New Installation ticket.']);
            }
            if ($locked->assigned_to && $locked->assigned_to !== $technician->id) {
                throw ValidationException::withMessages(['ticket' => 'This installation ticket has already been claimed by another technician.']);
            }
            if (! in_array($locked->workflow_status, ['unclaimed', 'open'], true)) {
                throw ValidationException::withMessages(['status' => 'This installation ticket is no longer available to claim.']);
            }
            $previous = $locked->workflow_status;
            $locked->update(['assigned_to' => $technician->id, 'status' => 'in_progress', 'workflow_status' => 'claimed', 'claimed_at' => now()]);
            $this->history($locked, $technician, 'installation_claimed', $previous, 'claimed');
            return $locked->fresh($this->relations());
        });
    }

    public function markRepairIn(Ticket $ticket, User $technician): Ticket
    {
        return DB::transaction(function () use ($ticket, $technician) {
            $locked = Ticket::lockForUpdate()->findOrFail($ticket->id);
            $this->assertRepair($locked);
            if ($locked->assigned_to && $locked->assigned_to !== $technician->id) {
                throw ValidationException::withMessages(['ticket' => 'This repair ticket belongs to another technician.']);
            }
            if ($locked->workflow_status !== 'open') {
                throw ValidationException::withMessages(['status' => 'Only an open repair ticket can be marked in.']);
            }
            $locked->update(['assigned_to' => $technician->id, 'status' => 'in_progress', 'workflow_status' => 'in_progress', 'claimed_at' => $locked->claimed_at ?: now(), 'started_at' => now()]);
            $this->history($locked, $technician, 'repair_marked_in', 'open', 'in_progress');
            return $locked->fresh($this->relations());
        });
    }

    public function resolveRepair(Ticket $ticket, User $technician, array $data): Ticket
    {
        return DB::transaction(function () use ($ticket, $technician, $data) {
            $locked = Ticket::lockForUpdate()->findOrFail($ticket->id);
            $this->assertRepair($locked);
            $this->assertAssigned($locked, $technician);
            if ($locked->workflow_status !== 'in_progress') {
                throw ValidationException::withMessages(['status' => 'Repair must be in progress before it can be resolved.']);
            }
            $details = array_filter([
                'findings' => $data['findings'] ?? null,
                'actions_performed' => $data['actions_performed'] ?? null,
                'equipment_replaced' => $data['equipment_replaced'] ?? null,
                'materials_used' => $data['materials_used'] ?? null,
            ], fn ($value) => $value !== null && $value !== '');
            $locked->update(['status' => 'resolved', 'workflow_status' => 'resolved', 'resolution_notes' => $data['resolution_notes'], 'repair_details' => $details, 'resolved_at' => now()]);
            $this->history($locked, $technician, 'repair_resolved', 'in_progress', 'resolved', $data['resolution_notes'], $details);
            return $locked->fresh($this->relations());
        });
    }

    public function closeRepair(Ticket $ticket, User $technician, ?string $notes): Ticket
    {
        return DB::transaction(function () use ($ticket, $technician, $notes) {
            $locked = Ticket::lockForUpdate()->findOrFail($ticket->id);
            $this->assertRepair($locked);
            $this->assertAssigned($locked, $technician);
            if ($locked->workflow_status !== 'resolved') {
                throw ValidationException::withMessages(['status' => 'Only a resolved repair ticket can be closed.']);
            }
            $locked->update(['status' => 'closed', 'workflow_status' => 'closed', 'closed_at' => now(), 'closed_by' => $technician->id]);
            $this->history($locked, $technician, 'repair_closed', 'resolved', 'closed', $notes);
            return $locked->fresh($this->relations());
        });
    }

    public function submitInstallation(Ticket $ticket, User $technician, array $data): Ticket
    {
        $mac = $this->normalizeMac($data['mac_address']);
        if (! $mac) {
            throw ValidationException::withMessages(['mac_address' => 'INVALID MAC ADDRESS']);
        }

        return DB::transaction(function () use ($ticket, $technician, $data, $mac) {
            $locked = Ticket::lockForUpdate()->findOrFail($ticket->id);
            if ($locked->ticket_type !== 'installation') throw ValidationException::withMessages(['ticket' => 'This is not an installation ticket.']);
            $this->assertAssigned($locked, $technician);
            if (! in_array($locked->workflow_status, ['claimed', 'returned_for_correction'], true)) {
                throw ValidationException::withMessages(['status' => 'This installation is not ready for technician submission.']);
            }
            $duplicate = Customer::query()->where('id', '!=', $locked->customer_id)->whereNotNull('mac_address')->get(['id', 'account_number', 'full_name', 'mac_address'])->first(fn ($customer) => $this->normalizeMac($customer->mac_address) === $mac);
            if ($duplicate) {
                throw ValidationException::withMessages(['mac_address' => "MAC ADDRESS ALREADY REGISTERED to {$duplicate->full_name} ({$duplicate->account_number})."]);
            }
            $previous = $locked->workflow_status;
            $locked->update([
                'status' => 'in_progress', 'workflow_status' => 'waiting_admin_approval',
                'installation_mac' => $mac, 'installation_notes' => $data['notes'],
                'submitted_for_approval_at' => now(), 'return_reason' => null,
            ]);
            $this->history($locked, $technician, 'mac_address_added', $previous, $previous, null, ['mac_address' => $mac]);
            $this->history($locked, $technician, 'installation_marked_done', $previous, 'waiting_admin_approval', $data['notes'], ['mac_address' => $mac]);
            $this->history($locked, $technician, 'sent_to_admin_approval', 'waiting_admin_approval', 'waiting_admin_approval');
            return $locked->fresh($this->relations());
        });
    }

    public function returnInstallation(Ticket $ticket, User $admin, string $reason): Ticket
    {
        return DB::transaction(function () use ($ticket, $admin, $reason) {
            $locked = Ticket::lockForUpdate()->findOrFail($ticket->id);
            if ($locked->ticket_type !== 'installation' || $locked->workflow_status !== 'waiting_admin_approval') {
                throw ValidationException::withMessages(['status' => 'Only an installation waiting for approval can be returned.']);
            }
            $locked->update(['status' => 'in_progress', 'workflow_status' => 'returned_for_correction', 'return_reason' => $reason, 'returned_at' => now()]);
            $this->history($locked, $admin, 'installation_returned', 'waiting_admin_approval', 'returned_for_correction', $reason);
            return $locked->fresh($this->relations());
        });
    }

    /**
     * Correct a technician-entered MAC before registration. This intentionally
     * changes the installation ticket only: it never overwrites a registered
     * customer's MAC address, DHCP lease, or RouterOS configuration.
     */
    public function correctInstallationMac(Ticket $ticket, User $admin, string $macAddress, string $reason): Ticket
    {
        $mac = $this->normalizeMac($macAddress);
        if (! $mac) {
            throw ValidationException::withMessages(['mac_address' => 'Enter a complete 12-character ONU/router MAC address.']);
        }

        return DB::transaction(function () use ($ticket, $admin, $mac, $reason) {
            $locked = Ticket::lockForUpdate()->findOrFail($ticket->id);
            if ($locked->ticket_type !== 'installation' || $locked->workflow_status !== 'waiting_admin_approval') {
                throw ValidationException::withMessages(['status' => 'Only a pending installation waiting for approval can have its MAC corrected.']);
            }

            $customer = Customer::withTrashed()->lockForUpdate()->find($locked->customer_id);
            if (! $customer || $customer->status !== 'pending') {
                throw ValidationException::withMessages(['customer' => 'MAC correction is restricted to the pending installation application. Registered customer records are not changed here.']);
            }

            $duplicate = Customer::withTrashed()
                ->where('id', '!=', $customer->id)
                ->whereNotNull('mac_address')
                ->get(['id', 'account_number', 'full_name', 'mac_address'])
                ->first(fn (Customer $item) => $this->normalizeMac($item->mac_address) === $mac);
            if ($duplicate) {
                throw ValidationException::withMessages(['mac_address' => "MAC ADDRESS ALREADY REGISTERED to {$duplicate->full_name} ({$duplicate->account_number})."]);
            }

            $previousMac = $this->normalizeMac($locked->installation_mac);
            if ($previousMac === $mac) {
                throw ValidationException::withMessages(['mac_address' => 'The corrected MAC is the same as the submitted MAC address.']);
            }

            $locked->update(['installation_mac' => $mac]);
            $this->history(
                $locked,
                $admin,
                'installation_mac_corrected',
                'waiting_admin_approval',
                'waiting_admin_approval',
                trim($reason),
                ['previous_mac_address' => $previousMac, 'mac_address' => $mac],
            );

            return $locked->fresh($this->relations());
        });
    }

    public function approveInstallation(Ticket $ticket, User $admin): array
    {
        $result = DB::transaction(function () use ($ticket, $admin) {
            $locked = Ticket::with('customer.servicePlan')->lockForUpdate()->findOrFail($ticket->id);
            if ($locked->ticket_type !== 'installation' || $locked->workflow_status !== 'waiting_admin_approval') {
                throw ValidationException::withMessages(['status' => 'Only an installation waiting for approval can be approved.']);
            }
            $customer = Customer::withTrashed()->lockForUpdate()->findOrFail($locked->customer_id);
            $mac = $this->normalizeMac($locked->installation_mac);
            if (! $mac) throw ValidationException::withMessages(['mac_address' => 'INVALID MAC ADDRESS']);

            $duplicate = Customer::withTrashed()->where('id', '!=', $customer->id)->whereNotNull('mac_address')->get()->first(fn ($item) => $this->normalizeMac($item->mac_address) === $mac);
            if ($duplicate) throw ValidationException::withMessages(['mac_address' => "MAC ADDRESS ALREADY REGISTERED to {$duplicate->full_name} ({$duplicate->account_number})."]);

            $leases = DhcpLease::query()->where('is_current', true)->where('status', 'bound')->lockForUpdate()->get();
            $matches = $leases->filter(fn ($lease) => $this->normalizeMac($lease->mac_address) === $mac)->values();
            if ($matches->count() !== 1) {
                throw ValidationException::withMessages(['mac_address' => $matches->isEmpty() ? 'No current bound DHCP lease matches this MAC.' : 'More than one current DHCP lease matches this MAC.']);
            }
            $lease = $matches->first();
            if ($lease->customer_id && $lease->customer_id !== $customer->id) {
                throw ValidationException::withMessages(['mac_address' => 'This DHCP lease is already bound to another customer.']);
            }
            if (! $customer->service_plan_id) throw ValidationException::withMessages(['service_plan' => 'Select a service plan before approving installation.']);

            $customer->restore();
            $customer->forceFill([
                'account_number' => str_starts_with($customer->account_number, 'PENDING-') ? $this->generateAccountNumber() : $customer->account_number,
                'status' => 'active', 'installation_date' => now()->toDateString(),
                'technician_id' => $locked->assigned_to, 'router_id' => $lease->router_id,
                'mac_address' => $lease->mac_address, 'mac_binding_status' => 'matched', 'ip_address' => $lease->ip_address,
                'notes' => trim((string) $customer->notes . "\nInstallation approved from ticket {$locked->ticket_number}. {$locked->installation_notes}"),
            ])->save();
            $lease->update([
                'customer_id' => $customer->id,
                'is_matched' => true,
                'match_source' => 'installation_approval',
                'match_note' => 'Exact current DHCP lease approved for a self-signup installation application.',
            ]);

            $locked->update(['status' => 'closed', 'workflow_status' => 'registered', 'approved_by' => $admin->id, 'approved_at' => now(), 'registered_at' => now(), 'closed_at' => now(), 'closed_by' => $admin->id]);
            $this->history($locked, $admin, 'installation_approved', 'waiting_admin_approval', 'approved', null, ['mac_address' => $mac, 'lease_id' => $lease->id]);
            $this->history($locked, $admin, 'customer_registered', 'approved', 'registered', "Customer {$customer->account_number} registered and ticket closed.");
            $this->history($locked, $admin, 'ticket_closed', 'registered', 'closed', "Installation {$locked->ticket_number} completed.");
            return [
                'ticket' => $locked->fresh($this->relations()),
                'customer' => $customer->fresh(['servicePlan', 'router']),
                'lease_id' => $lease->id,
            ];
        });

        try {
            $lease = DhcpLease::with('router')->findOrFail($result['lease_id']);
            $plan = $result['customer']->servicePlan;
            $rateLimit = $plan->download_speed . 'M/' . $plan->upload_speed . 'M';
            $leaseSync = app(MikrotikService::class)->updateOrMakeStaticLease(
                $lease->router,
                $lease->mac_address,
                $result['customer']->full_name,
                $rateLimit,
                $lease->ip_address,
                $lease->server ?: 'default',
            );

            $queueSync = null;
            if ($leaseSync['success']) {
                $lease->update([
                    'comment' => $result['customer']->full_name,
                    'rate_limit' => $rateLimit,
                    'is_dynamic' => false,
                ]);
                $queueSync = app(QueueService::class)->syncCustomerQueue($result['customer'], true);
            }

            $result['mikrotik_sync'] = [
                'success' => (bool) $leaseSync['success'] && ($queueSync === null || (bool) $queueSync['success']),
                'message' => !$leaseSync['success']
                    ? 'Customer registration completed, but the matching DHCP lease could not be made static: ' . ($leaseSync['message'] ?? 'unknown error')
                    : (($queueSync['success'] ?? false)
                        ? 'The matched DHCP lease was made static and the customer queue was synchronized to the selected plan.'
                        : 'The DHCP lease was made static, but the customer queue needs attention: ' . ($queueSync['message'] ?? 'unknown error')),
                'lease' => $leaseSync,
                'queue' => $queueSync,
            ];
        } catch (\Throwable $exception) {
            $result['mikrotik_sync'] = ['success' => false, 'message' => $exception->getMessage()];
        }
        unset($result['lease_id']);
        return $result;
    }

    /**
     * Read-only preflight shown to administrators before registration.
     * Approval repeats these checks under database locks, so this status can
     * never replace the transaction-level safety checks below.
     */
    public function installationValidation(Ticket $ticket): array
    {
        $mac = $this->normalizeMac($ticket->installation_mac);
        $base = [
            'can_approve' => false,
            'status' => 'invalid_mac',
            'message' => 'The submitted MAC address is invalid.',
            'normalized_mac' => $mac,
            'match_count' => 0,
            'lease' => null,
        ];

        if ($ticket->ticket_type !== 'installation' || $ticket->workflow_status !== 'waiting_admin_approval') {
            return [...$base, 'status' => 'not_waiting', 'message' => 'This installation is not waiting for administrator approval.'];
        }

        if (! $mac) {
            return $base;
        }

        $customer = Customer::withTrashed()->find($ticket->customer_id);
        if (! $customer) {
            return [...$base, 'status' => 'customer_missing', 'message' => 'The pending customer record no longer exists.'];
        }

        $duplicate = Customer::withTrashed()
            ->where('id', '!=', $customer->id)
            ->whereNotNull('mac_address')
            ->get(['id', 'account_number', 'full_name', 'mac_address'])
            ->first(fn (Customer $item) => $this->normalizeMac($item->mac_address) === $mac);

        if ($duplicate) {
            return [
                ...$base,
                'status' => 'registered_elsewhere',
                'message' => "MAC address is already registered to {$duplicate->full_name} ({$duplicate->account_number}).",
                'existing_customer' => $duplicate->only(['id', 'account_number', 'full_name']),
            ];
        }

        $matches = DhcpLease::query()
            ->with('router:id,name')
            ->where('is_current', true)
            ->where('status', 'bound')
            ->get(['id', 'router_id', 'customer_id', 'mac_address', 'ip_address', 'hostname', 'comment', 'status', 'is_current', 'is_matched', 'last_seen_at'])
            ->filter(fn (DhcpLease $lease) => $this->normalizeMac($lease->mac_address) === $mac)
            ->values();

        if ($matches->isEmpty()) {
            return [...$base, 'status' => 'lease_not_found', 'message' => 'MAC not found in the current bound DHCP leases. Sync the correct MikroTik router, then check again.'];
        }

        if ($matches->count() > 1) {
            return [...$base, 'status' => 'multiple_leases', 'message' => 'More than one current bound DHCP lease uses this MAC. Resolve the duplicate lease before approval.', 'match_count' => $matches->count()];
        }

        /** @var DhcpLease $lease */
        $lease = $matches->first();
        $leaseData = [
            'id' => $lease->id,
            'ip_address' => $lease->ip_address,
            'mac_address' => $this->normalizeMac($lease->mac_address),
            'hostname' => $lease->hostname,
            'comment' => $lease->comment,
            'router_name' => $lease->router?->name,
            'last_seen_at' => $lease->last_seen_at?->toIso8601String(),
            'is_unregistered' => ! $lease->customer_id,
            'linked_to_application' => $lease->customer_id === $customer->id,
        ];

        if ($lease->customer_id && $lease->customer_id !== $customer->id) {
            return [...$base, 'status' => 'lease_owned_elsewhere', 'message' => 'The matching DHCP lease is linked to another customer.', 'match_count' => 1, 'lease' => $leaseData];
        }

        if (! $customer->service_plan_id) {
            return [...$base, 'status' => 'service_plan_missing', 'message' => 'Select a service plan for the pending customer before approval.', 'match_count' => 1, 'lease' => $leaseData];
        }

        return [
            ...$base,
            'can_approve' => true,
            'status' => 'matched',
            'message' => $lease->customer_id
                ? 'MAC matched the current bound DHCP lease already linked to this application. Registration is safe to proceed.'
                : 'MAC matched one unregistered current bound DHCP lease. Registration is safe to proceed.',
            'match_count' => 1,
            'lease' => $leaseData,
        ];
    }

    public function history(Ticket $ticket, ?User $user, string $action, ?string $previous, ?string $new, ?string $notes = null, array $metadata = []): void
    {
        TicketHistory::create([
            'ticket_id' => $ticket->id, 'user_id' => $user?->id,
            'role' => $user?->roles()->pluck('name')->join(', '), 'action' => $action,
            'previous_status' => $previous, 'new_status' => $new,
            'notes' => $notes, 'metadata' => $metadata ?: null,
        ]);
    }

    public function normalizeMac(?string $mac): ?string
    {
        $hex = strtoupper(preg_replace('/[^A-Fa-f0-9]/', '', (string) $mac));
        return strlen($hex) === 12 ? implode(':', str_split($hex, 2)) : null;
    }

    private function assertRepair(Ticket $ticket): void
    {
        if ($ticket->ticket_type !== 'repair') throw ValidationException::withMessages(['ticket' => 'This action is only available for repair tickets.']);
    }

    private function assertAssigned(Ticket $ticket, User $technician): void
    {
        if ($ticket->assigned_to !== $technician->id) throw ValidationException::withMessages(['ticket' => 'This ticket is not assigned to you.']);
    }

    private function generateAccountNumber(): string
    {
        do { $number = (string) random_int(1000000000, 9999999999); } while (Customer::withTrashed()->where('account_number', $number)->exists());
        return $number;
    }

    private function relations(): array
    {
        return ['customer.servicePlan', 'assignedTechnician', 'comments', 'histories.user'];
    }
}
