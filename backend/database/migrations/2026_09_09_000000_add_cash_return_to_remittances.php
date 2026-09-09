<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('remittances', function (Blueprint $table): void {
            $table->decimal('cash_returned_amount', 12, 2)->default(0)->after('cash_counted_amount');
            $table->json('cash_return_breakdown')->nullable()->after('cash_breakdown');
        });
    }

    public function down(): void
    {
        Schema::table('remittances', fn (Blueprint $table) => $table->dropColumn(['cash_returned_amount', 'cash_return_breakdown']));
    }
};
