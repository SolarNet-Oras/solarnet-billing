<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tickets', function (Blueprint $table) {
            $table->string('ticket_type', 32)->default('other')->index();
            $table->string('workflow_status', 40)->default('open')->index();
            $table->timestamp('claimed_at')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->text('resolution_notes')->nullable();
            $table->json('repair_details')->nullable();
            $table->string('installation_mac', 17)->nullable()->index();
            $table->text('installation_notes')->nullable();
            $table->timestamp('submitted_for_approval_at')->nullable();
            $table->uuid('approved_by')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->text('return_reason')->nullable();
            $table->timestamp('returned_at')->nullable();
            $table->timestamp('registered_at')->nullable();
            $table->uuid('closed_by')->nullable();
            $table->foreign('approved_by')->references('id')->on('users')->nullOnDelete();
            $table->foreign('closed_by')->references('id')->on('users')->nullOnDelete();
        });

        DB::table('tickets')->where('subject', 'like', 'New Installation Application%')->update([
            'ticket_type' => 'installation',
            'workflow_status' => 'unclaimed',
        ]);
        DB::table('tickets')
            ->where('ticket_type', 'installation')
            ->whereNotNull('assigned_to')
            ->where('status', 'in_progress')
            ->update(['workflow_status' => 'claimed']);
        DB::table('tickets')
            ->where('ticket_type', 'installation')
            ->whereNotNull('assigned_to')
            ->where('status', 'resolved')
            ->update([
                'status' => 'in_progress',
                'workflow_status' => 'returned_for_correction',
                'return_reason' => 'Please confirm the MAC address and installation notes in the structured installation form.',
                'returned_at' => now(),
            ]);
        DB::table('tickets')
            ->where('ticket_type', 'installation')
            ->where('status', 'closed')
            ->update(['workflow_status' => 'registered']);
        DB::table('tickets')->where('ticket_type', '!=', 'installation')->whereIn('category', ['technical', 'network_issue'])->update([
            'ticket_type' => 'repair',
        ]);
        DB::table('tickets')->where('ticket_type', '!=', 'installation')->where('status', 'in_progress')->update(['workflow_status' => 'in_progress']);
        DB::table('tickets')->where('ticket_type', '!=', 'installation')->where('status', 'resolved')->update(['workflow_status' => 'resolved']);
        DB::table('tickets')->where('ticket_type', '!=', 'installation')->where('status', 'closed')->update(['workflow_status' => 'closed']);
    }

    public function down(): void
    {
        Schema::table('tickets', function (Blueprint $table) {
            $table->dropForeign(['approved_by']);
            $table->dropForeign(['closed_by']);
            $table->dropColumn([
                'ticket_type', 'workflow_status', 'claimed_at', 'started_at', 'resolution_notes',
                'repair_details', 'installation_mac', 'installation_notes', 'submitted_for_approval_at',
                'approved_by', 'approved_at', 'return_reason', 'returned_at', 'registered_at', 'closed_by',
            ]);
        });
    }
};
