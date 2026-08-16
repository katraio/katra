<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION katra_validate_direct_message_integrity(
                target_direct_message_id bigint,
                require_complete_set boolean
            )
            RETURNS void AS $$
            DECLARE
                direct_message_record direct_messages%ROWTYPE;
                participant_count integer;
                calculated_hash text;
            BEGIN
                SELECT * INTO direct_message_record
                FROM direct_messages
                WHERE id = target_direct_message_id;

                IF NOT FOUND THEN
                    RETURN;
                END IF;

                SELECT
                    COUNT(*),
                    md5(string_agg(user_id::text, ',' ORDER BY user_id))
                INTO participant_count, calculated_hash
                FROM direct_message_participants
                WHERE direct_message_id = target_direct_message_id;

                IF require_complete_set AND participant_count < 2 THEN
                    RAISE EXCEPTION 'A Direct Message requires at least two structural participants';
                END IF;

                IF participant_count < 2 THEN
                    RETURN;
                END IF;

                IF calculated_hash IS DISTINCT FROM direct_message_record.participant_set_hash THEN
                    RAISE EXCEPTION 'Direct Message participant set does not match its canonical identity';
                END IF;

                IF NOT EXISTS (
                    SELECT 1 FROM direct_message_participants
                    WHERE direct_message_id = target_direct_message_id
                    AND user_id = direct_message_record.initiated_by_user_id
                ) THEN
                    RAISE EXCEPTION 'Direct Message initiator must be a structural participant';
                END IF;

                IF direct_message_record.internal_owner_user_id IS NOT NULL AND NOT EXISTS (
                    SELECT 1 FROM direct_message_participants
                    WHERE direct_message_id = target_direct_message_id
                    AND user_id = direct_message_record.internal_owner_user_id
                ) THEN
                    RAISE EXCEPTION 'Direct Message internal owner must be a structural participant';
                END IF;

                IF direct_message_record.completed_by_user_id IS NOT NULL AND NOT EXISTS (
                    SELECT 1 FROM direct_message_participants
                    WHERE direct_message_id = target_direct_message_id
                    AND user_id = direct_message_record.completed_by_user_id
                ) THEN
                    RAISE EXCEPTION 'Direct Message completion actor must be a structural participant';
                END IF;

                IF direct_message_record.continuation_requested_by_user_id IS NOT NULL AND NOT EXISTS (
                    SELECT 1 FROM direct_message_participants
                    WHERE direct_message_id = target_direct_message_id
                    AND user_id = direct_message_record.continuation_requested_by_user_id
                ) THEN
                    RAISE EXCEPTION 'Direct Message continuation actor must be a structural participant';
                END IF;
            END;
            $$ LANGUAGE plpgsql
            SQL);

        DB::statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION katra_validate_direct_message_participant_change()
            RETURNS trigger AS $$
            DECLARE
                target_id bigint;
            BEGIN
                target_id := COALESCE(NEW.direct_message_id, OLD.direct_message_id);

                PERFORM katra_validate_direct_message_integrity(
                    target_id,
                    TG_OP <> 'INSERT'
                );

                RETURN COALESCE(NEW, OLD);
            END;
            $$ LANGUAGE plpgsql
            SQL);

        DB::statement(<<<'SQL'
            CREATE CONSTRAINT TRIGGER direct_message_participants_integrity
            AFTER INSERT OR UPDATE OR DELETE ON direct_message_participants
            DEFERRABLE INITIALLY DEFERRED
            FOR EACH ROW EXECUTE FUNCTION katra_validate_direct_message_participant_change()
            SQL);

        DB::statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION katra_validate_direct_message_update()
            RETURNS trigger AS $$
            BEGIN
                PERFORM katra_validate_direct_message_integrity(NEW.id, true);

                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql
            SQL);

        DB::statement(<<<'SQL'
            CREATE TRIGGER direct_messages_participant_integrity
            AFTER UPDATE ON direct_messages
            FOR EACH ROW EXECUTE FUNCTION katra_validate_direct_message_update()
            SQL);

        DB::statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION katra_validate_direct_message_transition_actor()
            RETURNS trigger AS $$
            BEGIN
                PERFORM katra_validate_direct_message_integrity(NEW.direct_message_id, true);

                IF NOT EXISTS (
                    SELECT 1 FROM direct_message_participants
                    WHERE direct_message_id = NEW.direct_message_id
                    AND user_id = NEW.actor_user_id
                ) THEN
                    RAISE EXCEPTION 'Direct Message transition actor must be a structural participant';
                END IF;

                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql
            SQL);

        DB::statement(<<<'SQL'
            CREATE TRIGGER direct_message_transition_actor_integrity
            BEFORE INSERT ON direct_message_transitions
            FOR EACH ROW EXECUTE FUNCTION katra_validate_direct_message_transition_actor()
            SQL);
    }

    public function down(): void
    {
        DB::statement('DROP TRIGGER IF EXISTS direct_message_transition_actor_integrity ON direct_message_transitions');
        DB::statement('DROP FUNCTION IF EXISTS katra_validate_direct_message_transition_actor()');
        DB::statement('DROP TRIGGER IF EXISTS direct_messages_participant_integrity ON direct_messages');
        DB::statement('DROP FUNCTION IF EXISTS katra_validate_direct_message_update()');
        DB::statement('DROP TRIGGER IF EXISTS direct_message_participants_integrity ON direct_message_participants');
        DB::statement('DROP FUNCTION IF EXISTS katra_validate_direct_message_participant_change()');
        DB::statement('DROP FUNCTION IF EXISTS katra_validate_direct_message_integrity(bigint, boolean)');
    }
};
