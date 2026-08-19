<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Store the agreed monthly due-day separately from the historical
     * installation date. This is important for subscribers migrated after
     * SolarNet Billing went live: importing them today must not make today
     * their billing anniversary.
     */
    public function up(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            $table->unsignedTinyInteger('billing_cycle_day')->nullable()->after('installation_date');
        });
    }

    public function down(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            $table->dropColumn('billing_cycle_day');
        });
    }
};
