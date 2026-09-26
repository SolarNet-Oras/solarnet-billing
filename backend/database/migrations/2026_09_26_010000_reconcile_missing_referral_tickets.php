<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration {
    public function up(): void
    {
        if (! Schema::hasColumn('tickets', 'referral_id')) {
            return;
        }

        $referrals = DB::table('customer_referrals')
            ->whereNotExists(fn ($query) => $query->selectRaw('1')
                ->from('tickets')
                ->whereColumn('tickets.referral_id', 'customer_referrals.id'))
            ->orderBy('created_at')
            ->get();

        foreach ($referrals as $referral) {
            $createdAt = $referral->created_at ?: now();
            $prefix = 'TKT-'.date('Ym', strtotime((string) $createdAt)).'-';
            $lastNumber = DB::table('tickets')
                ->where('ticket_number', 'like', $prefix.'%')
                ->orderByDesc('ticket_number')
                ->value('ticket_number');
            $sequence = $lastNumber ? ((int) substr($lastNumber, -4)) + 1 : 1;

            DB::table('tickets')->insert([
                'id' => (string) Str::uuid(),
                'ticket_number' => $prefix.str_pad((string) $sequence, 4, '0', STR_PAD_LEFT),
                'customer_id' => $referral->referrer_customer_id,
                'referral_id' => $referral->id,
                'subject' => 'Customer referral: '.$referral->prospect_name,
                'description' => 'Referral submitted for office follow-up and new-client verification.',
                'status' => 'open',
                'priority' => 'medium',
                'category' => 'general',
                'ticket_type' => 'other',
                'workflow_status' => 'open',
                'created_at' => $createdAt,
                'updated_at' => $referral->updated_at ?: $createdAt,
            ]);
        }
    }

    public function down(): void
    {
        // Reconciled tickets are retained as operational audit records.
    }
};
