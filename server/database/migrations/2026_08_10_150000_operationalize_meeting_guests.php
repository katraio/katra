<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        $this->dropParticipantAuthorshipGuards();

        Schema::table('meeting_participants', function (Blueprint $table): void {
            $table->ulid('public_id')->nullable()->after('id');
            $table->string('guest_admission_source', 24)->nullable()->after('kind');
        });
        DB::table('meeting_participants')->orderBy('id')->each(function (object $participant): void {
            DB::table('meeting_participants')->where('id', $participant->id)->update([
                'public_id' => (string) Str::ulid(),
            ]);
        });
        DB::statement('ALTER TABLE meeting_participants ALTER COLUMN public_id SET NOT NULL');
        Schema::table('meeting_participants', function (Blueprint $table): void {
            $table->unique('public_id');
        });
        DB::statement(<<<'SQL'
            ALTER TABLE meeting_participants
            ADD CONSTRAINT meeting_participants_guest_admission_source_check
            CHECK (
                (kind = 'user' AND guest_admission_source IS NULL)
                OR
                (kind = 'guest' AND guest_admission_source IN ('copied-link', 'email-invitation'))
            )
            SQL);

        Schema::create('meeting_guest_sessions', function (Blueprint $table): void {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->foreignId('meeting_id')->constrained()->restrictOnDelete();
            $table->foreignId('meeting_participant_id')->unique()->constrained('meeting_participants')->restrictOnDelete();
            $table->char('token_hash', 64)->unique();
            $table->text('token');
            $table->string('admission_idempotency_key', 64);
            $table->char('admission_request_hash', 64);
            $table->timestampTz('expires_at');
            $table->timestampTz('revoked_at')->nullable();
            $table->timestampTz('last_seen_at')->nullable();
            $table->timestampsTz();

            $table->unique(['meeting_id', 'admission_idempotency_key']);
        });
        DB::statement(<<<'SQL'
            ALTER TABLE meeting_guest_sessions
            ADD CONSTRAINT meeting_guest_sessions_token_hash_check
            CHECK (token_hash ~ '^[0-9a-f]{64}$'),
            ADD CONSTRAINT meeting_guest_sessions_request_hash_check
            CHECK (admission_request_hash ~ '^[0-9a-f]{64}$')
            SQL);

        Schema::table('meeting_outcomes', function (Blueprint $table): void {
            $table->unsignedBigInteger('author_user_id')->nullable()->change();
            $table->foreignId('author_meeting_participant_id')
                ->nullable()
                ->after('author_user_id')
                ->constrained('meeting_participants')
                ->restrictOnDelete();
        });
        DB::statement(<<<'SQL'
            ALTER TABLE meeting_outcomes
            ADD CONSTRAINT meeting_outcomes_author_identity_check
            CHECK (
                (author_user_id IS NOT NULL AND author_meeting_participant_id IS NULL)
                OR
                (author_user_id IS NULL AND author_meeting_participant_id IS NOT NULL)
            ),
            ADD CONSTRAINT meeting_outcomes_guest_kind_check
            CHECK (author_meeting_participant_id IS NULL OR kind IN ('note', 'decision'))
            SQL);

        Schema::table('meeting_messages', function (Blueprint $table): void {
            $table->unsignedBigInteger('author_user_id')->nullable()->change();
            $table->foreignId('author_meeting_participant_id')
                ->nullable()
                ->after('author_user_id')
                ->constrained('meeting_participants')
                ->restrictOnDelete();
        });
        DB::statement(<<<'SQL'
            ALTER TABLE meeting_messages
            ADD CONSTRAINT meeting_messages_author_identity_check
            CHECK (
                (author_user_id IS NOT NULL AND author_meeting_participant_id IS NULL)
                OR
                (author_user_id IS NULL AND author_meeting_participant_id IS NOT NULL)
            )
            SQL);

        Schema::table('meeting_message_reactions', function (Blueprint $table): void {
            $table->dropUnique(['meeting_message_id', 'user_id', 'kind']);
            $table->unsignedBigInteger('user_id')->nullable()->change();
            $table->foreignId('meeting_participant_id')
                ->nullable()
                ->after('user_id')
                ->constrained('meeting_participants')
                ->restrictOnDelete();
        });
        DB::statement(<<<'SQL'
            ALTER TABLE meeting_message_reactions
            ADD CONSTRAINT meeting_message_reactions_actor_identity_check
            CHECK (
                (user_id IS NOT NULL AND meeting_participant_id IS NULL)
                OR
                (user_id IS NULL AND meeting_participant_id IS NOT NULL)
            )
            SQL);
        DB::statement(<<<'SQL'
            CREATE UNIQUE INDEX meeting_message_reactions_user_unique
            ON meeting_message_reactions (meeting_message_id, user_id, kind)
            WHERE user_id IS NOT NULL
            SQL);
        DB::statement(<<<'SQL'
            CREATE UNIQUE INDEX meeting_message_reactions_guest_unique
            ON meeting_message_reactions (meeting_message_id, meeting_participant_id, kind)
            WHERE meeting_participant_id IS NOT NULL
            SQL);

        $this->installParticipantAuthorshipGuards();
    }

    public function down(): void
    {
        if (
            DB::table('meeting_outcomes')->whereNotNull('author_meeting_participant_id')->exists()
            || DB::table('meeting_messages')->whereNotNull('author_meeting_participant_id')->exists()
            || DB::table('meeting_message_reactions')->whereNotNull('meeting_participant_id')->exists()
        ) {
            throw new RuntimeException('Cannot roll back operational meeting guests while guest-authored content exists.');
        }

        $this->dropParticipantAuthorshipGuards();
        DB::statement('DROP INDEX IF EXISTS meeting_message_reactions_user_unique');
        DB::statement('DROP INDEX IF EXISTS meeting_message_reactions_guest_unique');
        DB::statement('ALTER TABLE meeting_message_reactions DROP CONSTRAINT meeting_message_reactions_actor_identity_check');
        Schema::table('meeting_message_reactions', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('meeting_participant_id');
            $table->unsignedBigInteger('user_id')->nullable(false)->change();
            $table->unique(['meeting_message_id', 'user_id', 'kind']);
        });

        DB::statement('ALTER TABLE meeting_messages DROP CONSTRAINT meeting_messages_author_identity_check');
        Schema::table('meeting_messages', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('author_meeting_participant_id');
            $table->unsignedBigInteger('author_user_id')->nullable(false)->change();
        });

        DB::statement('ALTER TABLE meeting_outcomes DROP CONSTRAINT meeting_outcomes_author_identity_check');
        DB::statement('ALTER TABLE meeting_outcomes DROP CONSTRAINT meeting_outcomes_guest_kind_check');
        Schema::table('meeting_outcomes', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('author_meeting_participant_id');
            $table->unsignedBigInteger('author_user_id')->nullable(false)->change();
        });

        Schema::dropIfExists('meeting_guest_sessions');
        DB::statement('ALTER TABLE meeting_participants DROP CONSTRAINT meeting_participants_guest_admission_source_check');
        Schema::table('meeting_participants', function (Blueprint $table): void {
            $table->dropUnique(['public_id']);
            $table->dropColumn(['public_id', 'guest_admission_source']);
        });
        $this->installParticipantAuthorshipGuards(false);
    }

    private function dropParticipantAuthorshipGuards(): void
    {
        DB::statement('DROP TRIGGER IF EXISTS meeting_outcomes_validate ON meeting_outcomes');
        DB::statement('DROP FUNCTION IF EXISTS katra_validate_meeting_outcome()');
        DB::statement('DROP TRIGGER IF EXISTS meeting_messages_validate ON meeting_messages');
        DB::statement('DROP FUNCTION IF EXISTS katra_validate_meeting_message()');
        DB::statement('DROP TRIGGER IF EXISTS meeting_message_reactions_validate ON meeting_message_reactions');
        DB::statement('DROP FUNCTION IF EXISTS katra_validate_meeting_message_reaction()');
    }

    private function installParticipantAuthorshipGuards(bool $includeGuests = true): void
    {
        $guestOutcome = $includeGuests ? <<<'SQL'
                    OR (
                        NEW.author_user_id IS NULL
                        AND EXISTS (
                            SELECT 1 FROM meeting_participants author_mp
                            WHERE author_mp.id = NEW.author_meeting_participant_id
                                AND author_mp.meeting_id = NEW.meeting_id
                                AND author_mp.kind = 'guest'
                        )
                    )
SQL : '';
        $guestMessage = $includeGuests ? <<<'SQL'
                    OR (
                        NEW.author_user_id IS NULL
                        AND EXISTS (
                            SELECT 1 FROM meeting_participants author_mp
                            WHERE author_mp.id = NEW.author_meeting_participant_id
                                AND author_mp.meeting_id = NEW.meeting_id
                                AND author_mp.kind = 'guest'
                        )
                    )
SQL : '';
        $reactionIdentity = $includeGuests ? <<<'SQL'
                target_participant_id bigint;
SQL : '';
        $reactionAssignments = $includeGuests ? <<<'SQL'
                    target_participant_id := OLD.meeting_participant_id;
SQL : '';
        $reactionInsertAssignment = $includeGuests ? <<<'SQL'
                    target_participant_id := NEW.meeting_participant_id;
SQL : '';
        $guestReaction = $includeGuests ? <<<'SQL'
                    OR (
                        target_user_id IS NULL
                        AND EXISTS (
                            SELECT 1 FROM meeting_participants mp
                            WHERE mp.id = target_participant_id
                                AND mp.meeting_id = m.id
                                AND mp.kind = 'guest'
                        )
                    )
SQL : '';

        DB::statement(<<<SQL
            CREATE OR REPLACE FUNCTION katra_validate_meeting_outcome()
            RETURNS trigger AS \$\$
            BEGIN
                IF NOT EXISTS (
                    SELECT 1 FROM meetings m
                    WHERE m.id = NEW.meeting_id AND m.status = 'live'
                    AND (
                        (NEW.author_user_id IS NOT NULL AND EXISTS (
                            SELECT 1 FROM meeting_participants author_mp
                            WHERE author_mp.meeting_id = m.id AND author_mp.user_id = NEW.author_user_id
                        ))
                        {$guestOutcome}
                    )
                ) THEN RAISE EXCEPTION 'Katra meeting outcome author or lifecycle is invalid'; END IF;
                IF NEW.kind = 'action' AND NOT EXISTS (
                    SELECT 1 FROM meeting_participants assignee_mp
                    WHERE assignee_mp.meeting_id = NEW.meeting_id AND assignee_mp.user_id = NEW.assignee_user_id
                ) THEN RAISE EXCEPTION 'Katra meeting action assignee is invalid'; END IF;
                RETURN NEW;
            END;
            \$\$ LANGUAGE plpgsql
            SQL);
        DB::statement(<<<'SQL'
            CREATE TRIGGER meeting_outcomes_validate
            BEFORE INSERT ON meeting_outcomes
            FOR EACH ROW EXECUTE FUNCTION katra_validate_meeting_outcome()
            SQL);

        DB::statement(<<<SQL
            CREATE OR REPLACE FUNCTION katra_validate_meeting_message()
            RETURNS trigger AS \$\$
            BEGIN
                IF NOT EXISTS (
                    SELECT 1 FROM meetings m
                    WHERE m.id = NEW.meeting_id AND m.status = 'live'
                    AND (
                        (NEW.author_user_id IS NOT NULL AND EXISTS (
                            SELECT 1 FROM meeting_participants author_mp
                            WHERE author_mp.meeting_id = m.id AND author_mp.user_id = NEW.author_user_id
                        ))
                        {$guestMessage}
                    )
                ) THEN RAISE EXCEPTION 'Katra meeting message author or lifecycle is invalid'; END IF;
                RETURN NEW;
            END;
            \$\$ LANGUAGE plpgsql
            SQL);
        DB::statement(<<<'SQL'
            CREATE TRIGGER meeting_messages_validate
            BEFORE INSERT ON meeting_messages
            FOR EACH ROW EXECUTE FUNCTION katra_validate_meeting_message()
            SQL);

        DB::statement(<<<SQL
            CREATE OR REPLACE FUNCTION katra_validate_meeting_message_reaction()
            RETURNS trigger AS \$\$
            DECLARE
                target_message_id bigint;
                target_user_id bigint;
                {$reactionIdentity}
            BEGIN
                IF TG_OP = 'UPDATE' THEN
                    RAISE EXCEPTION 'Katra meeting message reactions cannot be updated';
                ELSIF TG_OP = 'DELETE' THEN
                    target_message_id := OLD.meeting_message_id;
                    target_user_id := OLD.user_id;
                    {$reactionAssignments}
                ELSE
                    target_message_id := NEW.meeting_message_id;
                    target_user_id := NEW.user_id;
                    {$reactionInsertAssignment}
                END IF;
                IF NOT EXISTS (
                    SELECT 1 FROM meeting_messages mm
                    INNER JOIN meetings m ON m.id = mm.meeting_id
                    WHERE mm.id = target_message_id AND m.status = 'live'
                    AND (
                        (target_user_id IS NOT NULL AND EXISTS (
                            SELECT 1 FROM meeting_participants mp
                            WHERE mp.meeting_id = m.id AND mp.user_id = target_user_id
                        ))
                        {$guestReaction}
                    )
                ) THEN RAISE EXCEPTION 'Katra meeting message reaction actor or lifecycle is invalid'; END IF;
                IF TG_OP = 'DELETE' THEN RETURN OLD; END IF;
                RETURN NEW;
            END;
            \$\$ LANGUAGE plpgsql
            SQL);
        DB::statement(<<<'SQL'
            CREATE TRIGGER meeting_message_reactions_validate
            BEFORE INSERT OR UPDATE OR DELETE ON meeting_message_reactions
            FOR EACH ROW EXECUTE FUNCTION katra_validate_meeting_message_reaction()
            SQL);
    }
};
