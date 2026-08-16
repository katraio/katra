<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('message_attention_targets', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('message_id')->constrained()->restrictOnDelete();
            $table->foreignId('targeted_user_id')->constrained('users')->restrictOnDelete();
            $table->timestamps();
            $table->unique(['message_id', 'targeted_user_id']);
        });

        DB::statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION katra_restrict_message_attention_target_mutation()
            RETURNS trigger AS $$
            BEGIN
                RAISE EXCEPTION 'Katra message attention targets are immutable';
            END;
            $$ LANGUAGE plpgsql
            SQL);

        DB::statement(<<<'SQL'
            CREATE TRIGGER message_attention_targets_immutable
            BEFORE UPDATE OR DELETE ON message_attention_targets
            FOR EACH ROW EXECUTE FUNCTION katra_restrict_message_attention_target_mutation()
            SQL);

        DB::statement('ALTER TABLE attention_items DROP CONSTRAINT attention_items_kind_check');
        DB::statement('ALTER TABLE attention_items DROP CONSTRAINT attention_items_source_check');

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

        DB::statement(<<<'SQL'
            CREATE UNIQUE INDEX attention_items_message_attention_request_unique
            ON attention_items (message_id, user_id)
            WHERE kind = 'message-attention-request'
            SQL);

        $this->installAttentionSourceValidator();
    }

    public function down(): void
    {
        DB::statement("DELETE FROM attention_items WHERE kind = 'message-attention-request'");
        DB::statement('DROP INDEX IF EXISTS attention_items_message_attention_request_unique');
        DB::statement('ALTER TABLE attention_items DROP CONSTRAINT attention_items_kind_check');
        DB::statement('ALTER TABLE attention_items DROP CONSTRAINT attention_items_source_check');
        DB::statement(<<<'SQL'
            ALTER TABLE attention_items
            ADD CONSTRAINT attention_items_kind_check
            CHECK (kind IN ('message-mention', 'direct-message-continuation')),
            ADD CONSTRAINT attention_items_source_check
            CHECK (
                (
                    kind = 'message-mention'
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

        DB::statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION katra_validate_attention_source()
            RETURNS trigger AS $$
            BEGIN
                IF NEW.kind = 'message-mention' THEN
                    IF NOT EXISTS (
                        SELECT 1
                        FROM messages m
                        INNER JOIN message_mentions mm
                            ON mm.message_id = m.id
                            AND mm.mentioned_user_id = NEW.user_id
                        INNER JOIN conversations c ON c.id = m.conversation_id
                        WHERE m.id = NEW.message_id
                            AND m.conversation_id = NEW.conversation_id
                            AND m.author_user_id = NEW.actor_user_id
                            AND c.organization_id = NEW.organization_id
                    ) THEN
                        RAISE EXCEPTION 'Katra Attention mention source is invalid';
                    END IF;
                ELSIF NEW.kind = 'direct-message-continuation' THEN
                    IF NOT EXISTS (
                        SELECT 1
                        FROM direct_message_transitions dmt
                        INNER JOIN direct_messages dm ON dm.id = dmt.direct_message_id
                        WHERE dmt.id = NEW.direct_message_transition_id
                            AND dmt.to_state = 'continuation-requested'
                            AND dmt.actor_user_id = NEW.actor_user_id
                            AND dm.conversation_id = NEW.conversation_id
                            AND dm.organization_id = NEW.organization_id
                            AND dm.internal_owner_user_id = NEW.user_id
                    ) THEN
                        RAISE EXCEPTION 'Katra Attention continuation source is invalid';
                    END IF;
                END IF;

                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql
            SQL);

        DB::statement('DROP TRIGGER IF EXISTS message_attention_targets_immutable ON message_attention_targets');
        DB::statement('DROP FUNCTION IF EXISTS katra_restrict_message_attention_target_mutation()');
        Schema::dropIfExists('message_attention_targets');
    }

    private function installAttentionSourceValidator(): void
    {
        DB::statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION katra_validate_attention_source()
            RETURNS trigger AS $$
            BEGIN
                IF NEW.kind = 'message-mention' THEN
                    IF NOT EXISTS (
                        SELECT 1
                        FROM messages m
                        INNER JOIN message_mentions mm
                            ON mm.message_id = m.id
                            AND mm.mentioned_user_id = NEW.user_id
                        INNER JOIN conversations c ON c.id = m.conversation_id
                        WHERE m.id = NEW.message_id
                            AND m.conversation_id = NEW.conversation_id
                            AND m.author_user_id = NEW.actor_user_id
                            AND c.organization_id = NEW.organization_id
                    ) THEN
                        RAISE EXCEPTION 'Katra Attention mention source is invalid';
                    END IF;
                ELSIF NEW.kind = 'message-attention-request' THEN
                    IF NOT EXISTS (
                        SELECT 1
                        FROM messages m
                        INNER JOIN message_attention_targets mat
                            ON mat.message_id = m.id
                            AND mat.targeted_user_id = NEW.user_id
                        INNER JOIN conversations c ON c.id = m.conversation_id
                        WHERE m.id = NEW.message_id
                            AND m.conversation_id = NEW.conversation_id
                            AND m.author_user_id = NEW.actor_user_id
                            AND c.organization_id = NEW.organization_id
                    ) THEN
                        RAISE EXCEPTION 'Katra Attention request source is invalid';
                    END IF;
                ELSIF NEW.kind = 'direct-message-continuation' THEN
                    IF NOT EXISTS (
                        SELECT 1
                        FROM direct_message_transitions dmt
                        INNER JOIN direct_messages dm ON dm.id = dmt.direct_message_id
                        WHERE dmt.id = NEW.direct_message_transition_id
                            AND dmt.to_state = 'continuation-requested'
                            AND dmt.actor_user_id = NEW.actor_user_id
                            AND dm.conversation_id = NEW.conversation_id
                            AND dm.organization_id = NEW.organization_id
                            AND dm.internal_owner_user_id = NEW.user_id
                    ) THEN
                        RAISE EXCEPTION 'Katra Attention continuation source is invalid';
                    END IF;
                END IF;

                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql
            SQL);
    }
};
