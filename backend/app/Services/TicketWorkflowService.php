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
                'mac_address' => $lease->mac_address, 'ip_address' => $lease->ip_address,
                'notes' => trim((string) $customer->notes . "\nInstallation approved from ticket {$locked->ticket_number}. {$locked->installation_notes}"),
            ])->save();
            $lease->update(['customer_id' => $customer->id, 'is_matched' => true]);

            $locked->update(['status' => 'closed', 'workflow_status' => 'registered', 'approved_by' => $admin->id, 'approved_at' => now(), 'registered_at' => now(), 'closed_at' => now(), 'closed_by' => $admin->id]);
            $this->history($locked, $admin, 'installation_approved', 'waiting_admin_approval', 'approved', null, ['mac_address' => $mac, 'lease_id' => $lease->id]);
            $this->history($locked, $admin, 'customer_registered', 'approved', 'registered', "Customer {$customer->account_number} registered and ticket closed.");
            $this->history($locked, $admin, 'ticket_closed', 'registered', 'closed', "Installation {$locked->ticket_number} completed.");
            return ['ticket' => $locked->fresh($this->relations()), 'customer' => $customer->fresh(['servicePlan', 'router'])];
        });

        try {
            $result['mikrotik_sync'] = app(QueueService::class)->syncCustomerQueue($result['customer'], true);
        } catch (\Throwable $exception) {
            $result['mikrotik_sync'] = ['success' => false, 'message' => $exception->getMessage()];
        }
        return $result;
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
