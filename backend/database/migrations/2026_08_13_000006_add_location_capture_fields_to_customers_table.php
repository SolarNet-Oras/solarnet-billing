<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            $table->string('location_status', 24)->default('not_captured')->index();
            $table->string('location_source', 32)->nullable();
            $table->decimal('location_accuracy_meters', 10, 2)->nullable();
            $table->timestamp('location_captured_at')->nullable();
            $table->timestamp('location_confirmed_at')->nullable();
        });

        // Keep manually recorded coordinates intact: they are already an
        // installation location and must never trigger an automatic request.
        DB::table('customers')->whereNotNull('gps_coordinates')->update([
            'location_status' => 'confirmed',
            'location_source' => 'existing_record',
            'location_confirmed_at' => now(),
        ]);
    }

    public function down(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            $table->dropColumn([
                'location_status', 'location_source', 'location_accuracy_meters',
                'location_captured_at', 'location_confirmed_at',
            ]);
        });
    }
};
