<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('financial_entries', function (Blueprint $table): void {
            $table->string('external_recipient_name')->nullable()->after('destination_wallet');
            $table->string('external_recipient_institution')->nullable()->after('external_recipient_name');
            $table->string('external_recipient_account_last4', 4)->nullable()->after('external_recipient_institution');
            $table->string('provider_transaction_id')->nullable()->after('external_recipient_account_last4');
            $table->string('provider_reference_number')->nullable()->after('provider_transaction_id');
            $table->decimal('provider_fee', 12, 2)->default(0)->after('provider_reference_number');
        });
    }

    public function down(): void
    {
        Schema::table('financial_entries', function (Blueprint $table): void {
            $table->dropColumn([
                'external_recipient_name',
                'external_recipient_institution',
                'external_recipient_account_last4',
                'provider_transaction_id',
                'provider_reference_number',
                'provider_fee',
            ]);
        });
    }
};
