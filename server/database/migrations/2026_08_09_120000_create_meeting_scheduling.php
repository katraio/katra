<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('meetings', function (Blueprint $table): void {
            $table->id();
            $table->string('public_id', 26)->unique();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->foreignId('organizer_user_id')->constrained('users')->restrictOnDelete();
            $table->string('title', 160);
            $table->timestampTz('starts_at');
            $table->unsignedSmallInteger('duration_minutes');
            $table->text('desired_outcome')->nullable();
            $table->string('status', 32)->default('scheduled');
            $table->string('guest_link_token_hash', 64)->unique();
            $table->text('guest_link_token');
            $table->timestampTz('guest_link_expires_at');
            $table->timestampTz('guest_link_revoked_at')->nullable();
            $table->timestampsTz();

            $table->index(['organization_id', 'starts_at']);
            $table->index(['organizer_user_id', 'starts_at']);
        });

        Schema::create('meeting_invitations', function (Blueprint $table): void {
            $table->id();
            $table->string('public_id', 26)->unique();
            $table->foreignId('meeting_id')->constrained()->cascadeOnDelete();
            $table->string('email', 254);
            $table->string('token_hash', 64)->unique();
            $table->text('token');
            $table->timestampTz('expires_at');
            $table->timestampTz('revoked_at')->nullable();
            $table->foreignId('created_by_user_id')->constrained('users')->restrictOnDelete();
            $table->timestampsTz();

            $table->unique(['meeting_id', 'email']);
        });

        Schema::create('meeting_participants', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('meeting_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('meeting_invitation_id')->nullable()->constrained()->restrictOnDelete();
            $table->string('kind', 24);
            $table->string('display_name', 160)->nullable();
            $table->timestampTz('joined_at')->nullable();
            $table->foreignId('added_by_user_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestampsTz();

            $table->unique(['meeting_id', 'user_id']);
            $table->index(['user_id', 'meeting_id']);
        });

        Schema::create('meeting_agenda_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('meeting_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('position');
            $table->string('title', 240);
            $table->foreignId('owner_user_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->unsignedSmallInteger('duration_minutes');
            $table->timestampsTz();

            $table->unique(['meeting_id', 'position']);
        });

        DB::statement(<<<'SQL'
            ALTER TABLE meetings
            ADD CONSTRAINT meetings_duration_range
            CHECK (duration_minutes IN (15, 30, 45, 60))
            SQL);
        DB::statement(<<<'SQL'
            ALTER TABLE meeting_participants
            ADD CONSTRAINT meeting_participants_identity
            CHECK (
                (kind = 'user' AND user_id IS NOT NULL AND meeting_invitation_id IS NULL AND display_name IS NULL)
                OR
                (kind = 'guest' AND user_id IS NULL AND display_name IS NOT NULL)
            )
            SQL);
        DB::statement(<<<'SQL'
            ALTER TABLE meeting_agenda_items
            ADD CONSTRAINT meeting_agenda_duration_range
            CHECK (duration_minutes IN (5, 10, 15, 20, 30))
            SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('meeting_agenda_items');
        Schema::dropIfExists('meeting_participants');
        Schema::dropIfExists('meeting_invitations');
        Schema::dropIfExists('meetings');
    }
};
