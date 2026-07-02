<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Add a proper hashed portal password to customers so they can log into
 * the customer portal with something other than their account number.
 * The portal login stays backward compatible for existing customers.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            $table->string('portal_password')->nullable()->after('email');
            $table->timestamp('portal_password_set_at')->nullable()->after('portal_password');
            $table->timestamp('welcome_email_sent_at')->nullable()->after('portal_password_set_at');
        });
    }

    public function down(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            $table->dropColumn(['portal_password', 'portal_password_set_at', 'welcome_email_sent_at']);
        });
    }
};
