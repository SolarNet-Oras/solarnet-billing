<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('collection_registration_evidence', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('customer_id')->index();
            $table->uuid('collection_plan_stop_id')->nullable()->index();
            $table->uuid('collector_id')->index();
            $table->string('router_label_photo_path');
            $table->string('submitted_email')->nullable();
            $table->string('submitted_phone')->nullable();
            $table->string('status')->default('pending')->index();
            $table->string('verified_mac_address', 17)->nullable();
            $table->uuid('reviewed_by')->nullable();
            $table->timestamp('reviewed_at')->nullable();
            $table->text('review_notes')->nullable();
            $table->timestamps();
            $table->foreign('customer_id')->references('id')->on('customers')->cascadeOnDelete();
            $table->foreign('collection_plan_stop_id')->references('id')->on('collection_plan_stops')->nullOnDelete();
            $table->foreign('collector_id')->references('id')->on('users')->restrictOnDelete();
            $table->foreign('reviewed_by')->references('id')->on('users')->nullOnDelete();
        });
    }

    public function down(): void { Schema::dropIfExists('collection_registration_evidence'); }
};
