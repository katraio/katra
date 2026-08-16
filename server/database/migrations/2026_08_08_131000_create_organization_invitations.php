<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('organization_invitations', function (Blueprint $table): void {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->string('email');
            $table->string('role', 64);
            $table->char('token_hash', 64)->unique();
            $table->foreignId('invited_by_user_id')->constrained('users')->restrictOnDelete();
            $table->timestampTz('expires_at');
            $table->timestampTz('accepted_at')->nullable();
            $table->foreignId('accepted_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('revoked_at')->nullable();
            $table->timestampTz('last_sent_at')->nullable();
            $table->timestamps();

            $table->index(['organization_id', 'email']);
            $table->index(['organization_id', 'role']);
        });

        DB::statement(<<<'SQL'
            ALTER TABLE organization_invitations
            ADD CONSTRAINT organization_invitations_role_check
            CHECK (role IN (
                'organization-administrator',
                'internal-member',
                'client-administrator',
                'client-member'
            ))
            SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('organization_invitations');
    }
};
