<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('staff_location_history', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('user_id')->constrained('users')->cascadeOnDelete();
            $table->decimal('latitude', 10, 7);
            $table->decimal('longitude', 10, 7);
            $table->decimal('accuracy_meters', 10, 2)->nullable();
            $table->timestamp('captured_at');
            $table->timestamps();
            $table->index(['user_id', 'captured_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('staff_location_history');
    }
};
