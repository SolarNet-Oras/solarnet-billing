<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            $table->longText('cash_signature_reference')->nullable()->after('documents');
            $table->timestamp('cash_signature_reference_at')->nullable()->after('cash_signature_reference');
        });

        Schema::table('payments', function (Blueprint $table) {
            $table->longText('payer_signature')->nullable()->after('cash_breakdown');
            $table->string('signature_signer_type', 20)->nullable()->after('payer_signature');
            $table->string('signature_signer_name')->nullable()->after('signature_signer_type');
        });
    }

    public function down(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->dropColumn(['payer_signature', 'signature_signer_type', 'signature_signer_name']);
        });

        Schema::table('customers', function (Blueprint $table) {
            $table->dropColumn(['cash_signature_reference', 'cash_signature_reference_at']);
        });
    }
};
