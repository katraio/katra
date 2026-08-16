<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('meeting_participants', function (Blueprint $table): void {
            $table->foreignId('removed_by_user_id')
                ->nullable()
                ->after('added_by_user_id')
                ->constrained('users')
                ->restrictOnDelete();
            $table->timestampTz('removed_at')->nullable()->after('removed_by_user_id');
            $table->index(['meeting_id', 'removed_at']);
        });
        DB::statement(<<<'SQL'
            ALTER TABLE meeting_participants
            ADD CONSTRAINT meeting_participants_removal_identity_check
            CHECK (
                (removed_at IS NULL AND removed_by_user_id IS NULL)
                OR (removed_at IS NOT NULL AND removed_by_user_id IS NOT NULL)
            )
            SQL);

        Schema::create('meeting_participant_events', function (Blueprint $table): void {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->foreignId('meeting_id')->constrained()->restrictOnDelete();
            $table->foreignId('meeting_participant_id')->constrained('meeting_participants')->restrictOnDelete();
            $table->string('kind', 16);
            $table->foreignId('actor_user_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestampTz('occurred_at');

            $table->index(['meeting_participant_id', 'occurred_at']);
        });
        DB::statement(<<<'SQL'
            ALTER TABLE meeting_participant_events
            ADD CONSTRAINT meeting_participant_events_kind_check
            CHECK (kind IN ('removed', 'restored'))
            SQL);
        DB::statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION katra_validate_meeting_participant_event()
            RETURNS trigger AS $$
            BEGIN
                IF NOT EXISTS (
                    SELECT 1 FROM meeting_participants mp
                    WHERE mp.id = NEW.meeting_participant_id
                        AND mp.meeting_id = NEW.meeting_id
                ) THEN RAISE EXCEPTION 'Katra meeting participant event meeting is invalid'; END IF;
                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql
            SQL);
        DB::statement(<<<'SQL'
            CREATE TRIGGER meeting_participant_events_validate
            BEFORE INSERT ON meeting_participant_events
            FOR EACH ROW EXECUTE FUNCTION katra_validate_meeting_participant_event()
            SQL);
        DB::statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION katra_meeting_participant_events_immutable()
            RETURNS trigger AS $$
            BEGIN
                RAISE EXCEPTION 'Katra meeting participant events are immutable';
            END;
            $$ LANGUAGE plpgsql
            SQL);
        DB::statement(<<<'SQL'
            CREATE TRIGGER meeting_participant_events_immutable
            BEFORE UPDATE OR DELETE ON meeting_participant_events
            FOR EACH ROW EXECUTE FUNCTION katra_meeting_participant_events_immutable()
            SQL);
    }

    public function down(): void
    {
        DB::statement('DROP TRIGGER IF EXISTS meeting_participant_events_immutable ON meeting_participant_events');
        DB::statement('DROP FUNCTION IF EXISTS katra_meeting_participant_events_immutable()');
        DB::statement('DROP TRIGGER IF EXISTS meeting_participant_events_validate ON meeting_participant_events');
        DB::statement('DROP FUNCTION IF EXISTS katra_validate_meeting_participant_event()');
        Schema::dropIfExists('meeting_participant_events');

        DB::statement('ALTER TABLE meeting_participants DROP CONSTRAINT meeting_participants_removal_identity_check');
        Schema::table('meeting_participants', function (Blueprint $table): void {
            $table->dropIndex(['meeting_id', 'removed_at']);
            $table->dropConstrainedForeignId('removed_by_user_id');
            $table->dropColumn('removed_at');
        });
    }
};
