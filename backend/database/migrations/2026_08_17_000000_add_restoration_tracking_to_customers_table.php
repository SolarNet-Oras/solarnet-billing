<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            $table->string('restoration_status')->nullable()->after('suspension_source')->index();
            $table->text('restoration_reason')->nullable()->after('restoration_status');
            $table->text('restoration_last_error')->nullable()->after('restoration_reason');
            $table->timestamp('restoration_attempted_at')->nullable()->after('restoration_last_error');
            $table->timestamp('restoration_confirmed_at')->nullable()->after('restoration_attempted_at');
        });
    }

    public function down(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            $table->dropIndex(['restoration_status']);
            $table->dropColumn([
                'restoration_status',
                'restoration_reason',
                'restoration_last_error',
                'restoration_attempted_at',
                'restoration_confirmed_at',
            ]);
        });
    }
};
