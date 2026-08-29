<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('facebook_page_post_drafts', function (Blueprint $table): void {
            $table->string('image_path')->nullable()->after('facebook_post_id');
            $table->string('image_mime', 100)->nullable()->after('image_path');
        });
    }

    public function down(): void
    {
        Schema::table('facebook_page_post_drafts', function (Blueprint $table): void {
            $table->dropColumn(['image_path', 'image_mime']);
        });
    }
};
