<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('meetings', function (Blueprint $table): void {
            $table->unsignedInteger('next_message_sequence')->default(1);
        });

        Schema::create('meeting_messages', function (Blueprint $table): void {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->foreignId('meeting_id')->constrained()->restrictOnDelete();
            $table->unsignedInteger('sequence');
            $table->foreignId('author_user_id')->constrained('users')->restrictOnDelete();
            $table->text('body');
            $table->string('idempotency_key', 64);
            $table->char('request_hash', 64);
            $table->timestamps();

            $table->unique(['meeting_id', 'sequence']);
            $table->unique(['meeting_id', 'idempotency_key']);
        });

        DB::statement(<<<'SQL'
            ALTER TABLE meeting_messages
            ADD CONSTRAINT meeting_messages_body_check
            CHECK (char_length(btrim(body)) BETWEEN 1 AND 4000),
            ADD CONSTRAINT meeting_messages_sequence_check
            CHECK (sequence > 0)
            SQL);

        Schema::create('meeting_message_reactions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('meeting_message_id')->constrained('meeting_messages')->restrictOnDelete();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->string('kind', 16);
            $table->timestamps();

            $table->unique(['meeting_message_id', 'user_id', 'kind']);
        });

        DB::statement(<<<'SQL'
            ALTER TABLE meeting_message_reactions
            ADD CONSTRAINT meeting_message_reactions_kind_check
            CHECK (kind IN ('approve', 'support'))
            SQL);

        DB::statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION katra_validate_meeting_message()
            RETURNS trigger AS $$
            BEGIN
                IF NOT EXISTS (
                    SELECT 1 FROM meetings m
                    INNER JOIN meeting_participants mp
                        ON mp.meeting_id = m.id AND mp.user_id = NEW.author_user_id
                    WHERE m.id = NEW.meeting_id AND m.status = 'live'
                ) THEN
                    RAISE EXCEPTION 'Katra meeting message author or lifecycle is invalid';
                END IF;
                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql
            SQL);

        DB::statement(<<<'SQL'
            CREATE TRIGGER meeting_messages_validate
            BEFORE INSERT ON meeting_messages
            FOR EACH ROW EXECUTE FUNCTION katra_validate_meeting_message()
            SQL);

        DB::statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION katra_restrict_meeting_message_mutation()
            RETURNS trigger AS $$
            BEGIN
                RAISE EXCEPTION 'Katra meeting messages are immutable';
            END;
            $$ LANGUAGE plpgsql
            SQL);

        DB::statement(<<<'SQL'
            CREATE TRIGGER meeting_messages_immutable
            BEFORE UPDATE OR DELETE ON meeting_messages
            FOR EACH ROW EXECUTE FUNCTION katra_restrict_meeting_message_mutation()
            SQL);

        DB::statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION katra_validate_meeting_message_reaction()
            RETURNS trigger AS $$
            DECLARE
                target_message_id bigint;
                target_user_id bigint;
            BEGIN
                IF TG_OP = 'UPDATE' THEN
                    RAISE EXCEPTION 'Katra meeting message reactions cannot be updated';
                ELSIF TG_OP = 'DELETE' THEN
                    target_message_id := OLD.meeting_message_id;
                    target_user_id := OLD.user_id;
                ELSE
                    target_message_id := NEW.meeting_message_id;
                    target_user_id := NEW.user_id;
                END IF;

                IF NOT EXISTS (
                    SELECT 1 FROM meeting_messages mm
                    INNER JOIN meetings m ON m.id = mm.meeting_id
                    INNER JOIN meeting_participants mp
                        ON mp.meeting_id = m.id AND mp.user_id = target_user_id
                    WHERE mm.id = target_message_id AND m.status = 'live'
                ) THEN
                    RAISE EXCEPTION 'Katra meeting message reaction actor or lifecycle is invalid';
                END IF;

                IF TG_OP = 'DELETE' THEN
                    RETURN OLD;
                END IF;
                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql
            SQL);

        DB::statement(<<<'SQL'
            CREATE TRIGGER meeting_message_reactions_validate
            BEFORE INSERT OR UPDATE OR DELETE ON meeting_message_reactions
            FOR EACH ROW EXECUTE FUNCTION katra_validate_meeting_message_reaction()
            SQL);
    }

    public function down(): void
    {
        DB::statement('DROP TRIGGER IF EXISTS meeting_message_reactions_validate ON meeting_message_reactions');
        DB::statement('DROP FUNCTION IF EXISTS katra_validate_meeting_message_reaction()');
        DB::statement('DROP TRIGGER IF EXISTS meeting_messages_immutable ON meeting_messages');
        DB::statement('DROP FUNCTION IF EXISTS katra_restrict_meeting_message_mutation()');
        DB::statement('DROP TRIGGER IF EXISTS meeting_messages_validate ON meeting_messages');
        DB::statement('DROP FUNCTION IF EXISTS katra_validate_meeting_message()');
        Schema::dropIfExists('meeting_message_reactions');
        Schema::dropIfExists('meeting_messages');
        Schema::table('meetings', function (Blueprint $table): void {
            $table->dropColumn('next_message_sequence');
        });
    }
};
