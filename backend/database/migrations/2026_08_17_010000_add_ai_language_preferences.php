<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ai_conversations', function (Blueprint $table) {
            $table->string('language', 12)->nullable()->after('title');
            $table->string('language_source', 32)->nullable()->after('language');
        });

        Schema::table('customers', function (Blueprint $table) {
            $table->string('preferred_language', 12)->nullable()->after('email');
        });
    }

    public function down(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            $table->dropColumn('preferred_language');
        });

        Schema::table('ai_conversations', function (Blueprint $table) {
            $table->dropColumn(['language', 'language_source']);
        });
    }
};
