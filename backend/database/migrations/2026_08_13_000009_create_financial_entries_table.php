<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('financial_entries', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->enum('type', ['sale', 'expense'])->index();
            $table->string('description');
            $table->string('category')->nullable();
            $table->decimal('amount', 12, 2);
            $table->date('entry_date')->index();
            $table->string('payment_method', 32)->default('cash');
            $table->string('reference')->nullable();
            $table->text('notes')->nullable();
            $table->uuid('recorded_by')->nullable();
            $table->timestamps();
            $table->foreign('recorded_by')->references('id')->on('users')->nullOnDelete();
        });
    }

    public function down(): void { Schema::dropIfExists('financial_entries'); }
};
