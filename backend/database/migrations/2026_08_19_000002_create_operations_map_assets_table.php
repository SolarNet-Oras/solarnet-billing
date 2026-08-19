<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('operations_map_assets', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('asset_type', 32); // nap, pole, or fiber_route
            $table->string('name');
            $table->decimal('latitude', 10, 7)->nullable();
            $table->decimal('longitude', 10, 7)->nullable();
            // Fiber routes are a deliberate list of mapped points. They are
            // never inferred from client locations or RouterOS configuration.
            $table->json('route_coordinates')->nullable();
            $table->string('status', 32)->default('active');
            $table->text('notes')->nullable();
            $table->foreignUuid('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['asset_type', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('operations_map_assets');
    }
};
