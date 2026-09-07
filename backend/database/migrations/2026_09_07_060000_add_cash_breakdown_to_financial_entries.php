<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('financial_entries', function (Blueprint $table): void {
            $table->json('cash_breakdown')->nullable()->after('amount');
        });
    }

    public function down(): void
    {
        Schema::table('financial_entries', fn (Blueprint $table) => $table->dropColumn('cash_breakdown'));
    }
};
