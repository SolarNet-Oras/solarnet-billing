<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration { public function up(): void { Schema::create('client_migration_audits', function (Blueprint $table) { $table->uuid('id')->primary(); $table->uuid('user_id'); $table->string('filename'); $table->unsignedInteger('total_rows')->default(0); $table->json('summary')->nullable(); $table->json('preview')->nullable(); $table->timestamps(); $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete(); }); } public function down(): void { Schema::dropIfExists('client_migration_audits'); } };
