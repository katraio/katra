<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('meeting_outcomes', function (Blueprint $table): void {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->foreignId('meeting_id')->constrained()->restrictOnDelete();
            $table->unsignedInteger('sequence');
            $table->string('kind', 16);
            $table->text('body');
            $table->foreignId('author_user_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('assignee_user_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamps();

            $table->unique(['meeting_id', 'sequence']);
        });

        DB::statement(<<<'SQL'
            ALTER TABLE meeting_outcomes
            ADD CONSTRAINT meeting_outcomes_kind_check
            CHECK (kind IN ('note', 'decision', 'action')),
            ADD CONSTRAINT meeting_outcomes_assignment_check
            CHECK (
                (kind IN ('note', 'decision') AND assignee_user_id IS NULL)
                OR
                (kind = 'action' AND assignee_user_id IS NOT NULL)
            )
            SQL);

        DB::statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION katra_validate_meeting_outcome()
            RETURNS trigger AS $$
            BEGIN
                IF NOT EXISTS (
                    SELECT 1 FROM meetings m
                    INNER JOIN meeting_participants author_mp
                        ON author_mp.meeting_id = m.id
                        AND author_mp.user_id = NEW.author_user_id
                    WHERE m.id = NEW.meeting_id
                        AND m.status = 'live'
                ) THEN
                    RAISE EXCEPTION 'Katra meeting outcome author or lifecycle is invalid';
                END IF;

                IF NEW.kind = 'action' AND NOT EXISTS (
                    SELECT 1 FROM meeting_participants assignee_mp
                    WHERE assignee_mp.meeting_id = NEW.meeting_id
                        AND assignee_mp.user_id = NEW.assignee_user_id
                ) THEN
                    RAISE EXCEPTION 'Katra meeting action assignee is invalid';
                END IF;

                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql
            SQL);

        DB::statement(<<<'SQL'
            CREATE TRIGGER meeting_outcomes_validate
            BEFORE INSERT ON meeting_outcomes
            FOR EACH ROW EXECUTE FUNCTION katra_validate_meeting_outcome()
            SQL);

        DB::statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION katra_restrict_meeting_outcome_mutation()
            RETURNS trigger AS $$
            BEGIN
                RAISE EXCEPTION 'Katra meeting outcomes are immutable';
            END;
            $$ LANGUAGE plpgsql
            SQL);

        DB::statement(<<<'SQL'
            CREATE TRIGGER meeting_outcomes_immutable
            BEFORE UPDATE OR DELETE ON meeting_outcomes
            FOR EACH ROW EXECUTE FUNCTION katra_restrict_meeting_outcome_mutation()
            SQL);

        Schema::table('attention_items', function (Blueprint $table): void {
            $table->foreignId('meeting_outcome_id')
                ->nullable()
                ->constrained('meeting_outcomes')
                ->restrictOnDelete();
        });
        DB::statement('ALTER TABLE attention_items ALTER COLUMN conversation_id DROP NOT NULL');
        DB::statement('ALTER TABLE attention_items DROP CONSTRAINT attention_items_kind_check');
        DB::statement('ALTER TABLE attention_items DROP CONSTRAINT attention_items_source_check');

        DB::statement(<<<'SQL'
            ALTER TABLE attention_items
            ADD CONSTRAINT attention_items_kind_check
            CHECK (kind IN ('message-mention', 'message-attention-request', 'direct-message-continuation', 'meeting-action')),
            ADD CONSTRAINT attention_items_source_check
            CHECK (
                (
                    kind IN ('message-mention', 'message-attention-request')
                    AND message_id IS NOT NULL
                    AND direct_message_transition_id IS NULL
                    AND meeting_outcome_id IS NULL
                    AND conversation_id IS NOT NULL
                )
                OR
                (
                    kind = 'direct-message-continuation'
                    AND message_id IS NULL
                    AND direct_message_transition_id IS NOT NULL
                    AND meeting_outcome_id IS NULL
                    AND conversation_id IS NOT NULL
                )
                OR
                (
                    kind = 'meeting-action'
                    AND message_id IS NULL
                    AND direct_message_transition_id IS NULL
                    AND meeting_outcome_id IS NOT NULL
                )
            )
            SQL);

        DB::statement(<<<'SQL'
            CREATE UNIQUE INDEX attention_items_meeting_action_unique
            ON attention_items (meeting_outcome_id)
            WHERE kind = 'meeting-action'
            SQL);

        $this->installAttentionSourceValidator();
        $this->installAttentionMutationGuard();
    }

    public function down(): void
    {
        DB::statement("DELETE FROM attention_items WHERE kind = 'meeting-action'");
        DB::statement('DROP INDEX IF EXISTS attention_items_meeting_action_unique');
        DB::statement('ALTER TABLE attention_items DROP CONSTRAINT attention_items_kind_check');
        DB::statement('ALTER TABLE attention_items DROP CONSTRAINT attention_items_source_check');
        Schema::table('attention_items', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('meeting_outcome_id');
        });
        DB::statement('ALTER TABLE attention_items ALTER COLUMN conversation_id SET NOT NULL');
        DB::statement(<<<'SQL'
            ALTER TABLE attention_items
            ADD CONSTRAINT attention_items_kind_check
            CHECK (kind IN ('message-mention', 'message-attention-request', 'direct-message-continuation')),
            ADD CONSTRAINT attention_items_source_check
            CHECK (
                (
                    kind IN ('message-mention', 'message-attention-request')
                    AND message_id IS NOT NULL
                    AND direct_message_transition_id IS NULL
                )
                OR
                (
                    kind = 'direct-message-continuation'
                    AND message_id IS NULL
                    AND direct_message_transition_id IS NOT NULL
                )
            )
            SQL);

        $this->installAttentionSourceValidator(false);
        $this->installAttentionMutationGuard(false);

        DB::statement('DROP TRIGGER IF EXISTS meeting_outcomes_immutable ON meeting_outcomes');
        DB::statement('DROP FUNCTION IF EXISTS katra_restrict_meeting_outcome_mutation()');
        DB::statement('DROP TRIGGER IF EXISTS meeting_outcomes_validate ON meeting_outcomes');
        DB::statement('DROP FUNCTION IF EXISTS katra_validate_meeting_outcome()');
        Schema::dropIfExists('meeting_outcomes');
    }

    private function installAttentionSourceValidator(bool $includeMeetingAction = true): void
    {
        $sql = <<<'SQL'
            CREATE OR REPLACE FUNCTION katra_validate_attention_source()
            RETURNS trigger AS $$
            BEGIN
                IF NEW.kind = 'message-mention' THEN
                    IF NOT EXISTS (
                        SELECT 1 FROM messages m
                        INNER JOIN message_mentions mm ON mm.message_id = m.id AND mm.mentioned_user_id = NEW.user_id
                        INNER JOIN conversations c ON c.id = m.conversation_id
                        WHERE m.id = NEW.message_id AND m.conversation_id = NEW.conversation_id
                            AND m.author_user_id = NEW.actor_user_id AND c.organization_id = NEW.organization_id
                    ) THEN RAISE EXCEPTION 'Katra Attention mention source is invalid'; END IF;
                ELSIF NEW.kind = 'message-attention-request' THEN
                    IF NOT EXISTS (
                        SELECT 1 FROM messages m
                        INNER JOIN message_attention_targets mat ON mat.message_id = m.id AND mat.targeted_user_id = NEW.user_id
                        INNER JOIN conversations c ON c.id = m.conversation_id
                        WHERE m.id = NEW.message_id AND m.conversation_id = NEW.conversation_id
                            AND m.author_user_id = NEW.actor_user_id AND c.organization_id = NEW.organization_id
                    ) THEN RAISE EXCEPTION 'Katra Attention request source is invalid'; END IF;
                ELSIF NEW.kind = 'direct-message-continuation' THEN
                    IF NOT EXISTS (
                        SELECT 1 FROM direct_message_transitions dmt
                        INNER JOIN direct_messages dm ON dm.id = dmt.direct_message_id
                        WHERE dmt.id = NEW.direct_message_transition_id AND dmt.to_state = 'continuation-requested'
                            AND dmt.actor_user_id = NEW.actor_user_id AND dm.conversation_id = NEW.conversation_id
                            AND dm.organization_id = NEW.organization_id AND dm.internal_owner_user_id = NEW.user_id
                    ) THEN RAISE EXCEPTION 'Katra Attention continuation source is invalid'; END IF;
SQL;

        if ($includeMeetingAction) {
            $sql .= <<<'SQL'
                ELSIF NEW.kind = 'meeting-action' THEN
                    IF NOT EXISTS (
                        SELECT 1 FROM meeting_outcomes mo
                        INNER JOIN meetings m ON m.id = mo.meeting_id
                        WHERE mo.id = NEW.meeting_outcome_id AND mo.kind = 'action'
                            AND mo.author_user_id = NEW.actor_user_id AND mo.assignee_user_id = NEW.user_id
                            AND m.organization_id = NEW.organization_id
                            AND m.conversation_id IS NOT DISTINCT FROM NEW.conversation_id
                    ) THEN RAISE EXCEPTION 'Katra Attention meeting action source is invalid'; END IF;
SQL;
        }

        $sql .= <<<'SQL'
                END IF;
                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql
SQL;

        DB::statement($sql);
    }

    private function installAttentionMutationGuard(bool $includeMeetingAction = true): void
    {
        $sourceGuard = <<<'SQL'
                    OR NEW.direct_message_transition_id IS DISTINCT FROM OLD.direct_message_transition_id
SQL;
        if ($includeMeetingAction) {
            $sourceGuard .= <<<'SQL'
                    OR NEW.meeting_outcome_id IS DISTINCT FROM OLD.meeting_outcome_id
SQL;
        }

        $sql = <<<'SQL'
            CREATE OR REPLACE FUNCTION katra_restrict_attention_mutation()
            RETURNS trigger AS $$
            BEGIN
                IF NEW.public_id <> OLD.public_id OR NEW.user_id <> OLD.user_id
                    OR NEW.organization_id <> OLD.organization_id
                    OR NEW.conversation_id IS DISTINCT FROM OLD.conversation_id
                    OR NEW.kind <> OLD.kind OR NEW.priority <> OLD.priority
                    OR NEW.actor_user_id <> OLD.actor_user_id
                    OR NEW.message_id IS DISTINCT FROM OLD.message_id
SQL;
        $sql .= $sourceGuard;
        $sql .= <<<'SQL'
                    OR NEW.created_at <> OLD.created_at
                THEN RAISE EXCEPTION 'Katra Attention identity and source are immutable'; END IF;
                IF OLD.viewed_at IS NOT NULL AND NEW.viewed_at IS DISTINCT FROM OLD.viewed_at
                THEN RAISE EXCEPTION 'Katra Attention viewed state cannot regress'; END IF;
                IF OLD.state = 'resolved' AND (
                    NEW.state <> OLD.state OR NEW.resolved_at IS DISTINCT FROM OLD.resolved_at
                    OR NEW.resolved_by_user_id IS DISTINCT FROM OLD.resolved_by_user_id
                ) THEN RAISE EXCEPTION 'Katra Attention resolution cannot regress'; END IF;
                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql
SQL;

        DB::statement($sql);
    }
};
