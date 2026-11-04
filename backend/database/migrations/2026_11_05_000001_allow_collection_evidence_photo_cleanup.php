<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('collection_registration_evidence', function (Blueprint $table): void {
            $table->string('router_label_photo_path')->nullable()->change();
        });
    }

    public function down(): void
    {
        DB::table('collection_registration_evidence')
            ->whereNull('router_label_photo_path')
            ->update(['router_label_photo_path'=>'']);
        Schema::table('collection_registration_evidence', function (Blueprint $table): void {
            $table->string('router_label_photo_path')->nullable(false)->change();
        });
    }
};
