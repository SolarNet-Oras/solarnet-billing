<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->string('initial_email_status', 32)->nullable()->index();
            $table->unsignedSmallInteger('initial_email_attempt_count')->default(0);
            $table->timestamp('initial_email_last_attempt_at')->nullable();
            $table->timestamp('initial_email_sent_at')->nullable();
            $table->string('initial_email_failure_reason', 500)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->dropIndex(['initial_email_status']);
            $table->dropColumn([
                'initial_email_status',
                'initial_email_attempt_count',
                'initial_email_last_attempt_at',
                'initial_email_sent_at',
                'initial_email_failure_reason',
            ]);
        });
    }
};
