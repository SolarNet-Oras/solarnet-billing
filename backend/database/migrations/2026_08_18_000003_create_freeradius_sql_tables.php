<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // SolarNet-side NAS approvals are separate from FreeRADIUS's standard
        // `nas` table so disabled/review records can never become live merely
        // because the RADIUS container restarts.
        Schema::create('radius_nas_clients', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('router_id')->nullable()->unique();
            $table->string('name', 128);
            $table->string('nas_address', 45)->unique();
            $table->string('shortname', 64)->unique();
            $table->text('shared_secret');
            $table->boolean('enabled')->default(false)->index();
            $table->boolean('test_mode')->default(true)->index();
            $table->timestamp('last_synced_at')->nullable();
            $table->text('last_error')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->foreign('router_id')->references('id')->on('routers')->nullOnDelete();
        });

        // FreeRADIUS receives only its standard SQL tables in a dedicated
        // PostgreSQL schema. The application tables remain in `public`.
        DB::statement('CREATE SCHEMA IF NOT EXISTS radius');

        Schema::create('radius.nas', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->string('nasname', 45)->unique();
            $table->string('shortname', 64)->nullable();
            $table->string('type', 30)->default('other');
            $table->integer('ports')->nullable();
            $table->text('secret');
            $table->string('server', 64)->nullable();
            $table->string('community', 64)->nullable();
            $table->text('description')->nullable();
            $table->string('managed_by', 96)->nullable()->index();
            $table->timestamps();
        });

        Schema::create('radius.radcheck', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->string('username', 64)->index();
            $table->string('attribute', 64);
            $table->string('op', 2)->default(':=');
            $table->text('value');
            $table->string('managed_by', 96)->nullable()->index();
            $table->timestamps();
            $table->unique(['username', 'attribute', 'managed_by']);
        });

        Schema::create('radius.radreply', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->string('username', 64)->index();
            $table->string('attribute', 64);
            $table->string('op', 2)->default(':=');
            $table->text('value');
            $table->string('managed_by', 96)->nullable()->index();
            $table->timestamps();
            $table->unique(['username', 'attribute', 'managed_by']);
        });

        foreach (['radgroupcheck', 'radgroupreply'] as $tableName) {
            Schema::create("radius.{$tableName}", function (Blueprint $table): void {
                $table->bigIncrements('id');
                $table->string('groupname', 64)->index();
                $table->string('attribute', 64);
                $table->string('op', 2)->default(':=');
                $table->text('value');
            });
        }

        Schema::create('radius.radusergroup', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->string('username', 64)->index();
            $table->string('groupname', 64)->index();
            $table->integer('priority')->default(1);
        });

        Schema::create('radius.radacct', function (Blueprint $table): void {
            $table->bigIncrements('radacctid');
            $table->string('acctsessionid', 64)->nullable()->index();
            $table->string('acctuniqueid', 64)->nullable()->unique();
            $table->string('username', 64)->nullable()->index();
            $table->string('realm', 64)->nullable();
            $table->string('nasipaddress', 45)->nullable()->index();
            $table->string('nasportid', 64)->nullable();
            $table->string('nasporttype', 32)->nullable();
            $table->timestamp('acctstarttime')->nullable()->index();
            $table->timestamp('acctupdatetime')->nullable()->index();
            $table->timestamp('acctstoptime')->nullable()->index();
            $table->integer('acctinterval')->nullable();
            $table->unsignedBigInteger('acctsessiontime')->nullable();
            $table->string('acctauthentic', 32)->nullable();
            $table->string('connectinfo_start', 50)->nullable();
            $table->string('connectinfo_stop', 50)->nullable();
            $table->unsignedBigInteger('acctinputoctets')->nullable();
            $table->unsignedBigInteger('acctoutputoctets')->nullable();
            $table->string('calledstationid', 64)->nullable();
            $table->string('callingstationid', 64)->nullable()->index();
            $table->string('acctterminatecause', 64)->nullable();
            $table->string('servicetype', 32)->nullable();
            $table->string('framedprotocol', 32)->nullable();
            $table->string('framedipaddress', 45)->nullable()->index();
        });

        Schema::create('radius.radpostauth', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->string('username', 64)->nullable()->index();
            $table->string('pass', 255)->nullable();
            $table->string('reply', 32)->nullable()->index();
            $table->timestamp('authdate')->useCurrent()->index();
        });
    }

    public function down(): void
    {
        foreach (['radpostauth', 'radacct', 'radusergroup', 'radgroupreply', 'radgroupcheck', 'radreply', 'radcheck', 'nas'] as $tableName) {
            Schema::dropIfExists("radius.{$tableName}");
        }
        Schema::dropIfExists('radius_nas_clients');
        // Do not drop the schema: an administrator may later place approved
        // RADIUS data in it that is unrelated to this migration.
    }
};
