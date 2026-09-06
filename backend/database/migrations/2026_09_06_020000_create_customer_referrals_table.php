<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('customer_referrals', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('referrer_customer_id');
            $table->uuid('referred_customer_id')->nullable();
            $table->string('prospect_name');
            $table->string('phone', 30);
            $table->string('phone_normalized', 20)->unique();
            $table->string('email')->nullable();
            $table->string('email_normalized')->nullable()->unique();
            $table->text('address');
            $table->string('status', 32)->default('submitted');
            $table->string('reward_choice', 20)->nullable();
            $table->decimal('reward_amount', 12, 2)->default(200);
            $table->timestamp('qualified_at')->nullable();
            $table->timestamp('choice_at')->nullable();
            $table->timestamp('rewarded_at')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->foreign('referrer_customer_id')->references('id')->on('customers')->cascadeOnDelete();
            $table->foreign('referred_customer_id')->references('id')->on('customers')->nullOnDelete();
            $table->index(['referrer_customer_id', 'created_at']);
            $table->index(['status', 'qualified_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('customer_referrals');
    }
};
