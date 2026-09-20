<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
 public function up(): void { Schema::create('staff_leave_requests', function(Blueprint $t):void{$t->uuid('id')->primary();$t->foreignUuid('user_id')->constrained('users')->cascadeOnDelete();$t->date('start_date');$t->date('end_date');$t->unsignedTinyInteger('credit_days');$t->string('leave_type',40)->default('personal');$t->text('reason');$t->string('status',24)->default('pending')->index();$t->foreignUuid('reviewed_by')->nullable()->constrained('users')->nullOnDelete();$t->timestamp('reviewed_at')->nullable();$t->text('review_notes')->nullable();$t->timestamps();$t->index(['user_id','start_date','end_date']);}); }
 public function down(): void { Schema::dropIfExists('staff_leave_requests'); }
};
