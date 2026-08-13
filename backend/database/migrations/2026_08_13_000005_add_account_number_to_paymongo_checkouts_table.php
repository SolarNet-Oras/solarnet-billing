<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('paymongo_checkouts', function (Blueprint $table) {
            $table->string('account_number')->nullable()->index()->after('customer_id');
        });
    }

    public function down(): void
    {
        Schema::table('paymongo_checkouts', function (Blueprint $table) {
            $table->dropIndex(['account_number']);
            $table->dropColumn('account_number');
        });
    }
};
