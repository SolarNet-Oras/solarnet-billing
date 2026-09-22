<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->string('signup_status', 24)->nullable()->index();
            $table->timestamp('signup_requested_at')->nullable();
            $table->foreignUuid('signup_reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('signup_reviewed_at')->nullable();
        });

        // Existing inactive, never-used accounts were created by the original
        // public signup workflow but had no explicit approval marker.
        DB::table('users')
            ->where('is_active', false)
            ->whereNull('last_login_at')
            ->whereNull('deleted_at')
            ->update([
                'signup_status' => 'pending',
                'signup_requested_at' => DB::raw('created_at'),
            ]);
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('signup_reviewed_by');
            $table->dropColumn(['signup_status', 'signup_requested_at', 'signup_reviewed_at']);
        });
    }
};
