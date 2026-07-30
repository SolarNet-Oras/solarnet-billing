<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('automation_logs', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('job', 64)->index();               // e.g. invoice_reminders, auto_suspend, db_backup, update_overdue
            $table->string('status', 16);                     // success | partial | error
            $table->jsonb('summary')->nullable();             // counts, errors, artifacts
            $table->integer('duration_ms')->default(0);
            $table->string('triggered_by', 32)->default('schedule'); // schedule | manual
            $table->uuid('triggered_by_user_id')->nullable(); // if manual
            $table->timestamp('started_at')->useCurrent();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();

            $table->index(['job', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('automation_logs');
    }
};
