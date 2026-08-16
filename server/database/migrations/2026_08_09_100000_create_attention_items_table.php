<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('attention_items', function (Blueprint $table): void {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->foreignId('organization_id');
            $table->foreignId('conversation_id');
            $table->string('kind', 64);
            $table->string('priority', 16)->default('normal');
            $table->string('state', 16)->default('open');
            $table->foreignId('actor_user_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('message_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('direct_message_transition_id')
                ->nullable()
                ->constrained('direct_message_transitions')
                ->restrictOnDelete();
            $table->timestampTz('viewed_at')->nullable();
            $table->timestampTz('resolved_at')->nullable();
            $table->foreignId('resolved_by_user_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamps();

            $table->foreign(['conversation_id', 'organization_id'])
                ->references(['id', 'organization_id'])
                ->on('conversations')
                ->restrictOnDelete();
            $table->index(['user_id', 'state', 'priority', 'created_at']);
        });

        DB::statement(<<<'SQL'
            ALTER TABLE attention_items
            ADD CONSTRAINT attention_items_kind_check
            CHECK (kind IN ('message-mention', 'direct-message-continuation')),
            ADD CONSTRAINT attention_items_priority_check
            CHECK (priority IN ('normal', 'high')),
            ADD CONSTRAINT attention_items_state_check
            CHECK (
                (state = 'open' AND resolved_at IS NULL AND resolved_by_user_id IS NULL)
                OR
                (state = 'resolved' AND resolved_at IS NOT NULL AND resolved_by_user_id IS NOT NULL)
            ),
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
            CREATE UNIQUE INDEX attention_items_message_mention_unique
            ON attention_items (message_id, user_id)
            WHERE kind = 'message-mention'
            SQL);

        DB::statement(<<<'SQL'
            CREATE UNIQUE INDEX attention_items_continuation_unique
            ON attention_items (direct_message_transition_id, user_id)
            WHERE kind = 'direct-message-continuation'
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

        DB::statement(<<<'SQL'
            CREATE TRIGGER attention_items_validate_source
            BEFORE INSERT OR UPDATE ON attention_items
            FOR EACH ROW EXECUTE FUNCTION katra_validate_attention_source()
            SQL);

        DB::statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION katra_restrict_attention_mutation()
            RETURNS trigger AS $$
            BEGIN
                IF NEW.public_id <> OLD.public_id
                    OR NEW.user_id <> OLD.user_id
                    OR NEW.organization_id <> OLD.organization_id
                    OR NEW.conversation_id <> OLD.conversation_id
                    OR NEW.kind <> OLD.kind
                    OR NEW.priority <> OLD.priority
                    OR NEW.actor_user_id <> OLD.actor_user_id
                    OR NEW.message_id IS DISTINCT FROM OLD.message_id
                    OR NEW.direct_message_transition_id IS DISTINCT FROM OLD.direct_message_transition_id
                    OR NEW.created_at <> OLD.created_at
                THEN
                    RAISE EXCEPTION 'Katra Attention identity and source are immutable';
                END IF;

                IF OLD.viewed_at IS NOT NULL AND NEW.viewed_at IS DISTINCT FROM OLD.viewed_at THEN
                    RAISE EXCEPTION 'Katra Attention viewed state cannot regress';
                END IF;

                IF OLD.state = 'resolved' AND (
                    NEW.state <> OLD.state
                    OR NEW.resolved_at IS DISTINCT FROM OLD.resolved_at
                    OR NEW.resolved_by_user_id IS DISTINCT FROM OLD.resolved_by_user_id
                ) THEN
                    RAISE EXCEPTION 'Katra Attention resolution cannot regress';
                END IF;

                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql
            SQL);

        DB::statement(<<<'SQL'
            CREATE TRIGGER attention_items_restrict_mutation
            BEFORE UPDATE ON attention_items
            FOR EACH ROW EXECUTE FUNCTION katra_restrict_attention_mutation()
            SQL);
    }

    public function down(): void
    {
        DB::statement('DROP TRIGGER IF EXISTS attention_items_restrict_mutation ON attention_items');
        DB::statement('DROP FUNCTION IF EXISTS katra_restrict_attention_mutation()');
        DB::statement('DROP TRIGGER IF EXISTS attention_items_validate_source ON attention_items');
        DB::statement('DROP FUNCTION IF EXISTS katra_validate_attention_source()');
        Schema::dropIfExists('attention_items');
    }
};
