<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Adds MikroTik queue-sync bookkeeping columns to customers.
     *
     * NOTE: this migration was originally created empty; CustomerObserver
     * and Customer::$fillable already reference these columns, so writes
     * were silently swallowed by the observer's try/catch — but any write
     * done inside a DB::transaction aborted the entire transaction.
     */
    public function up(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            if (!Schema::hasColumn('customers', 'queue_synced')) {
                $table->boolean('queue_synced')->default(false)->after('notes');
            }
            if (!Schema::hasColumn('customers', 'queue_last_synced_at')) {
                $table->timestamp('queue_last_synced_at')->nullable()->after('queue_synced');
            }
            if (!Schema::hasColumn('customers', 'queue_sync_status')) {
                $table->string('queue_sync_status')->nullable()->after('queue_last_synced_at');
            }
        });
    }

    public function down(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            foreach (['queue_sync_status', 'queue_last_synced_at', 'queue_synced'] as $col) {
                if (Schema::hasColumn('customers', $col)) {
                    $table->dropColumn($col);
                }
            }
        });
    }
};
