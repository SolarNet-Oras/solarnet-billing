<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('client_migration_date_corrections', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('client_migration_audit_id')->nullable();
            $table->uuid('customer_id');
            $table->uuid('user_id');
            $table->string('customer_name');
            $table->date('old_installation_date')->nullable();
            $table->date('new_installation_date');
            $table->string('source')->default('Excel migration');
            $table->timestamps();

            $table->foreign('client_migration_audit_id')->references('id')->on('client_migration_audits')->nullOnDelete();
            $table->foreign('customer_id')->references('id')->on('customers')->cascadeOnDelete();
            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
            $table->index(['customer_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('client_migration_date_corrections');
    }
};
