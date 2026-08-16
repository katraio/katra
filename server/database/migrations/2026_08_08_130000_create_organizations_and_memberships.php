<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('organizations', function (Blueprint $table): void {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->string('name');
            $table->string('slug')->unique();
            $table->string('kind', 32);
            $table->foreignId('created_by_user_id')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();
            $table->timestamps();
        });

        DB::statement(<<<'SQL'
            ALTER TABLE organizations
            ADD CONSTRAINT organizations_kind_check
            CHECK (kind IN ('operating', 'client'))
            SQL);

        DB::statement(<<<'SQL'
            CREATE UNIQUE INDEX organizations_single_operating_business
            ON organizations (kind)
            WHERE kind = 'operating'
            SQL);

        Schema::create('organization_memberships', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')
                ->constrained()
                ->cascadeOnDelete();
            $table->foreignId('user_id')
                ->constrained()
                ->cascadeOnDelete();
            $table->string('kind', 32);
            $table->string('status', 32);
            $table->timestampTz('joined_at')->nullable();
            $table->timestampTz('suspended_at')->nullable();
            $table->foreignId('created_by_user_id')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();
            $table->timestamps();

            $table->unique(['organization_id', 'user_id']);
            $table->index(['user_id', 'kind', 'status']);
        });

        DB::statement(<<<'SQL'
            ALTER TABLE organization_memberships
            ADD CONSTRAINT organization_memberships_kind_check
            CHECK (kind IN ('internal', 'client'))
            SQL);

        DB::statement(<<<'SQL'
            ALTER TABLE organization_memberships
            ADD CONSTRAINT organization_memberships_status_check
            CHECK (status IN ('active', 'suspended'))
            SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('organization_memberships');
        Schema::dropIfExists('organizations');
    }
};
