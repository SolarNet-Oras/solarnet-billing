<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('tickets', function (Blueprint $table): void {
            $table->uuid('referral_id')->nullable()->unique()->after('customer_id');
            $table->foreign('referral_id')->references('id')->on('customer_referrals')->nullOnDelete();
        });

        $referrals = DB::table('customer_referrals')->orderBy('created_at')->get();
        foreach ($referrals as $referral) {
            if (DB::table('tickets')->where('referral_id', $referral->id)->exists()) {
                continue;
            }

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
        Schema::table('tickets', function (Blueprint $table): void {
            $table->dropForeign(['referral_id']);
            $table->dropColumn('referral_id');
        });
    }
};
