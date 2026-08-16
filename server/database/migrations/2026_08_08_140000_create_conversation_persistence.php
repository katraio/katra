<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('conversations', function (Blueprint $table): void {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->string('type', 32);
            $table->unsignedBigInteger('next_message_sequence')->default(1);
            $table->foreignId('created_by_user_id')->constrained('users')->restrictOnDelete();
            $table->timestampTz('archived_at')->nullable();
            $table->timestamps();

            $table->unique(['id', 'organization_id']);
            $table->index(['organization_id', 'type']);
        });

        Schema::create('channels', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('conversation_id')->unique();
            $table->foreignId('organization_id');
            $table->string('name');
            $table->string('slug');
            $table->string('visibility', 32);
            $table->timestamps();

            $table->foreign(['conversation_id', 'organization_id'])
                ->references(['id', 'organization_id'])
                ->on('conversations')
                ->cascadeOnDelete();
            $table->unique(['organization_id', 'slug']);
        });

        Schema::create('direct_messages', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('conversation_id')->unique();
            $table->foreignId('organization_id');
            $table->foreignId('participant_one_user_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('participant_two_user_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('initiated_by_user_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('internal_owner_user_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->string('state', 32)->default('open');
            $table->timestampTz('completed_at')->nullable();
            $table->foreignId('completed_by_user_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestampTz('continuation_requested_at')->nullable();
            $table->foreignId('continuation_requested_by_user_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamps();

            $table->foreign(['conversation_id', 'organization_id'])
                ->references(['id', 'organization_id'])
                ->on('conversations')
                ->cascadeOnDelete();
            $table->unique([
                'organization_id',
                'participant_one_user_id',
                'participant_two_user_id',
            ], 'direct_messages_organization_participants_unique');
        });

        Schema::create('conversation_memberships', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('conversation_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('last_read_sequence')->default(0);
            $table->timestampTz('joined_at');
            $table->timestampTz('left_at')->nullable();
            $table->timestampTz('removed_at')->nullable();
            $table->foreignId('added_by_user_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamps();

            $table->unique(['conversation_id', 'user_id']);
            $table->index(['user_id', 'left_at', 'removed_at']);
        });

        Schema::create('messages', function (Blueprint $table): void {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->foreignId('conversation_id')->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('sequence');
            $table->foreignId('author_user_id')->constrained('users')->restrictOnDelete();
            $table->string('idempotency_key', 64);
            $table->foreignId('parent_message_id')->nullable();
            $table->text('body');
            $table->timestamps();

            $table->unique(['conversation_id', 'sequence']);
            $table->unique(['conversation_id', 'idempotency_key']);
            $table->unique(['id', 'conversation_id']);
            $table->foreign(['parent_message_id', 'conversation_id'])
                ->references(['id', 'conversation_id'])
                ->on('messages')
                ->restrictOnDelete();
        });

        Schema::create('message_mentions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('message_id')->constrained()->cascadeOnDelete();
            $table->foreignId('mentioned_user_id')->constrained('users')->restrictOnDelete();
            $table->timestamps();

            $table->unique(['message_id', 'mentioned_user_id']);
        });

        Schema::create('message_reactions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('message_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->string('emoji', 32);
            $table->timestamps();

            $table->unique(['message_id', 'user_id', 'emoji']);
        });

        DB::statement(<<<'SQL'
            ALTER TABLE conversations
            ADD CONSTRAINT conversations_type_check
            CHECK (type IN ('channel', 'direct-message')),
            ADD CONSTRAINT conversations_next_message_sequence_check
            CHECK (next_message_sequence >= 1)
            SQL);

        DB::statement(<<<'SQL'
            ALTER TABLE channels
            ADD CONSTRAINT channels_visibility_check
            CHECK (visibility IN ('public', 'private', 'client-team')),
            ADD CONSTRAINT channels_name_check
            CHECK (length(btrim(name)) > 0),
            ADD CONSTRAINT channels_slug_check
            CHECK (slug ~ '^[a-z0-9]+(?:-[a-z0-9]+)*$')
            SQL);

        DB::statement(<<<'SQL'
            CREATE UNIQUE INDEX channels_one_client_team_per_organization
            ON channels (organization_id)
            WHERE visibility = 'client-team'
            SQL);

        DB::statement(<<<'SQL'
            ALTER TABLE direct_messages
            ADD CONSTRAINT direct_messages_participant_order_check
            CHECK (participant_one_user_id < participant_two_user_id),
            ADD CONSTRAINT direct_messages_initiator_check
            CHECK (initiated_by_user_id IN (participant_one_user_id, participant_two_user_id)),
            ADD CONSTRAINT direct_messages_internal_owner_check
            CHECK (
                internal_owner_user_id IS NULL
                OR internal_owner_user_id IN (participant_one_user_id, participant_two_user_id)
            ),
            ADD CONSTRAINT direct_messages_state_check
            CHECK (state IN ('open', 'completed', 'continuation-requested'))
            SQL);

        DB::statement(<<<'SQL'
            ALTER TABLE conversation_memberships
            ADD CONSTRAINT conversation_memberships_last_read_sequence_check
            CHECK (last_read_sequence >= 0),
            ADD CONSTRAINT conversation_memberships_exit_state_check
            CHECK (left_at IS NULL OR removed_at IS NULL)
            SQL);

        DB::statement(<<<'SQL'
            ALTER TABLE messages
            ADD CONSTRAINT messages_sequence_check
            CHECK (sequence >= 1),
            ADD CONSTRAINT messages_body_check
            CHECK (length(btrim(body)) > 0)
            SQL);

        DB::statement(<<<'SQL'
            ALTER TABLE message_reactions
            ADD CONSTRAINT message_reactions_emoji_check
            CHECK (length(btrim(emoji)) > 0)
            SQL);

        DB::statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION katra_enforce_conversation_subtype()
            RETURNS trigger AS $$
            DECLARE
                actual_type text;
                expected_type text;
            BEGIN
                expected_type := CASE TG_TABLE_NAME
                    WHEN 'channels' THEN 'channel'
                    WHEN 'direct_messages' THEN 'direct-message'
                END;

                SELECT type INTO actual_type
                FROM conversations
                WHERE id = NEW.conversation_id;

                IF actual_type IS DISTINCT FROM expected_type THEN
                    RAISE EXCEPTION 'Conversation subtype does not match %', TG_TABLE_NAME;
                END IF;

                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql
            SQL);

        DB::statement(<<<'SQL'
            CREATE TRIGGER channels_conversation_subtype
            BEFORE INSERT OR UPDATE ON channels
            FOR EACH ROW EXECUTE FUNCTION katra_enforce_conversation_subtype()
            SQL);

        DB::statement(<<<'SQL'
            CREATE TRIGGER direct_messages_conversation_subtype
            BEFORE INSERT OR UPDATE ON direct_messages
            FOR EACH ROW EXECUTE FUNCTION katra_enforce_conversation_subtype()
            SQL);

        DB::statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION katra_reject_message_mutation()
            RETURNS trigger AS $$
            BEGIN
                RAISE EXCEPTION 'Katra messages are immutable';
            END;
            $$ LANGUAGE plpgsql
            SQL);

        DB::statement(<<<'SQL'
            CREATE TRIGGER messages_immutable
            BEFORE UPDATE OR DELETE ON messages
            FOR EACH ROW EXECUTE FUNCTION katra_reject_message_mutation()
            SQL);

        DB::statement(<<<'SQL'
            CREATE TRIGGER message_mentions_immutable
            BEFORE UPDATE OR DELETE ON message_mentions
            FOR EACH ROW EXECUTE FUNCTION katra_reject_message_mutation()
            SQL);

        DB::statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION katra_reject_nested_thread()
            RETURNS trigger AS $$
            BEGIN
                IF NEW.parent_message_id IS NOT NULL AND EXISTS (
                    SELECT 1 FROM messages
                    WHERE id = NEW.parent_message_id
                    AND parent_message_id IS NOT NULL
                ) THEN
                    RAISE EXCEPTION 'Katra threads support one reply level';
                END IF;

                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql
            SQL);

        DB::statement(<<<'SQL'
            CREATE TRIGGER messages_one_level_threads
            BEFORE INSERT ON messages
            FOR EACH ROW EXECUTE FUNCTION katra_reject_nested_thread()
            SQL);
    }

    public function down(): void
    {
        DB::statement('DROP TRIGGER IF EXISTS message_mentions_immutable ON message_mentions');
        DB::statement('DROP TRIGGER IF EXISTS messages_one_level_threads ON messages');
        DB::statement('DROP FUNCTION IF EXISTS katra_reject_nested_thread()');
        DB::statement('DROP TRIGGER IF EXISTS messages_immutable ON messages');
        DB::statement('DROP FUNCTION IF EXISTS katra_reject_message_mutation()');
        DB::statement('DROP TRIGGER IF EXISTS direct_messages_conversation_subtype ON direct_messages');
        DB::statement('DROP TRIGGER IF EXISTS channels_conversation_subtype ON channels');
        DB::statement('DROP FUNCTION IF EXISTS katra_enforce_conversation_subtype()');

        Schema::dropIfExists('message_reactions');
        Schema::dropIfExists('message_mentions');
        Schema::dropIfExists('messages');
        Schema::dropIfExists('conversation_memberships');
        Schema::dropIfExists('direct_messages');
        Schema::dropIfExists('channels');
        Schema::dropIfExists('conversations');
    }
};
