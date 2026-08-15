<?php

namespace App\Console\Commands;

use App\Models\CustomerProfileChangeRequest;
use App\Models\Ticket;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/** Removes explicitly selected test history only; it never deletes customers or users. */
class RemoveTestHistory extends Command
{
    protected $signature = 'testing:remove-history
                            {--customer-account= : Remove approved/rejected profile-change history for this customer account}
                            {--ticket=* : Exact ticket number(s) to remove}
                            {--apply : Persist the previewed removals}';

    protected $description = 'Remove explicitly selected approved/rejected request history and test tickets';

    public function handle(): int
    {
        $account = trim((string) $this->option('customer-account'));
        $ticketNumbers = array_values(array_filter($this->option('ticket')));
        if ($account === '' && $ticketNumbers === []) {
            $this->error('Provide --customer-account and/or at least one --ticket number.');
            return self::FAILURE;
        }

        $requests = $account === '' ? collect() : CustomerProfileChangeRequest::query()
            ->whereIn('status', ['approved', 'rejected'])
            ->whereHas('customer', fn ($customer) => $customer->where('account_number', $account))
            ->get();
        $tickets = $ticketNumbers === [] ? collect() : Ticket::query()
            ->whereIn('ticket_number', $ticketNumbers)->get();

        $this->line("Profile-change history: {$requests->count()}");
        foreach ($requests as $request) $this->line("  {$request->id} ({$request->status})");
        $this->line("Tickets: {$tickets->count()}");
        foreach ($tickets as $ticket) $this->line("  {$ticket->ticket_number}: {$ticket->subject}");

        if (! $this->option('apply')) {
            $this->info('Preview only. Re-run with --apply to remove exactly these records.');
            return self::SUCCESS;
        }

        DB::transaction(function () use ($requests, $tickets) {
            // Ticket child comments/history cascade downward only. No customer
            // or user record is a deletion target in this operation.
            $requests->each->delete();
            $tickets->each->delete();
        });

        $this->info("Removed {$requests->count()} profile-change request(s) and {$tickets->count()} ticket(s).");
        return self::SUCCESS;
    }
}
