<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            $table->string('cash_signature_fingerprint', 128)->nullable()->after('cash_signature_reference');
        });

        Schema::table('payments', function (Blueprint $table) {
            $table->decimal('payer_signature_similarity', 5, 4)->nullable()->after('payer_signature');
        });
    }

    public function down(): void
    {
        Schema::table('payments', fn (Blueprint $table) => $table->dropColumn('payer_signature_similarity'));
        Schema::table('customers', fn (Blueprint $table) => $table->dropColumn('cash_signature_fingerprint'));
    }
};
