<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('remittances', function (Blueprint $table) {
            $table->uuid('cancelled_by')->nullable();
            $table->timestamp('cancelled_at')->nullable()->index();
            $table->text('cancellation_reason')->nullable();
            $table->foreign('cancelled_by')->references('id')->on('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('remittances', function (Blueprint $table) {
            $table->dropForeign(['cancelled_by']);
            $table->dropColumn(['cancelled_by', 'cancelled_at', 'cancellation_reason']);
        });
    }
};
