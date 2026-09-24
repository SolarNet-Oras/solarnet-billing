<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('onu_remote_sessions', function (Blueprint $table): void {
            $table->unsignedInteger('public_port')->change();
        });
    }

    public function down(): void
    {
        // Intentionally retain INTEGER: narrowing an existing ephemeral port
        // above 32767 would fail or corrupt its audit record.
    }
};
