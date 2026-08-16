<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('direct_message_participants', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('direct_message_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->timestamps();

            $table->unique(['direct_message_id', 'user_id']);
            $table->index(['user_id', 'direct_message_id']);
        });

        Schema::create('direct_message_transitions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('direct_message_id')->constrained()->cascadeOnDelete();
            $table->string('from_state', 32)->nullable();
            $table->string('to_state', 32);
            $table->foreignId('actor_user_id')->constrained('users')->restrictOnDelete();
            $table->timestampTz('created_at');

            $table->index(['direct_message_id', 'created_at']);
        });

        Schema::table('direct_messages', function (Blueprint $table): void {
            $table->char('participant_set_hash', 32)->nullable();
        });

        foreach (DB::table('direct_messages')->orderBy('id')->get() as $directMessage) {
            $participantIds = collect([
                $directMessage->participant_one_user_id,
                $directMessage->participant_two_user_id,
            ])->sort()->values();

            foreach ($participantIds as $participantId) {
                DB::table('direct_message_participants')->insert([
                    'direct_message_id' => $directMessage->id,
                    'user_id' => $participantId,
                    'created_at' => $directMessage->created_at,
                    'updated_at' => $directMessage->created_at,
                ]);
            }

            DB::table('direct_messages')->where('id', $directMessage->id)->update([
                'participant_set_hash' => md5($participantIds->implode(',')),
            ]);

            DB::table('direct_message_transitions')->insert([
                'direct_message_id' => $directMessage->id,
                'from_state' => null,
                'to_state' => $directMessage->state,
                'actor_user_id' => $directMessage->initiated_by_user_id,
                'created_at' => $directMessage->created_at,
            ]);
        }

        DB::statement(<<<'SQL'
            ALTER TABLE direct_messages
            DROP CONSTRAINT direct_messages_participant_order_check,
            DROP CONSTRAINT direct_messages_initiator_check,
            DROP CONSTRAINT direct_messages_internal_owner_check,
            DROP CONSTRAINT direct_messages_organization_participants_unique
            SQL);

        Schema::table('direct_messages', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('participant_one_user_id');
            $table->dropConstrainedForeignId('participant_two_user_id');
        });

        DB::statement('ALTER TABLE direct_messages ALTER COLUMN participant_set_hash SET NOT NULL');

        Schema::table('direct_messages', function (Blueprint $table): void {
            $table->unique(
                ['organization_id', 'participant_set_hash'],
                'direct_messages_organization_participant_set_unique',
            );
        });

        DB::statement(<<<'SQL'
            ALTER TABLE direct_messages
            ADD CONSTRAINT direct_messages_lifecycle_state_check
            CHECK (
                (
                    state = 'open'
                    AND completed_at IS NULL
                    AND completed_by_user_id IS NULL
                    AND continuation_requested_at IS NULL
                    AND continuation_requested_by_user_id IS NULL
                )
                OR (
                    state = 'completed'
                    AND completed_at IS NOT NULL
                    AND completed_by_user_id IS NOT NULL
                    AND continuation_requested_at IS NULL
                    AND continuation_requested_by_user_id IS NULL
                )
                OR (
                    state = 'continuation-requested'
                    AND completed_at IS NOT NULL
                    AND completed_by_user_id IS NOT NULL
                    AND continuation_requested_at IS NOT NULL
                    AND continuation_requested_by_user_id IS NOT NULL
                )
            )
            SQL);

        DB::statement(<<<'SQL'
            ALTER TABLE direct_message_transitions
            ADD CONSTRAINT direct_message_transitions_from_state_check
            CHECK (from_state IS NULL OR from_state IN ('open', 'completed', 'continuation-requested')),
            ADD CONSTRAINT direct_message_transitions_to_state_check
            CHECK (to_state IN ('open', 'completed', 'continuation-requested'))
            SQL);

        DB::statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION katra_reject_direct_message_transition_mutation()
            RETURNS trigger AS $$
            BEGIN
                RAISE EXCEPTION 'Katra Direct Message transitions are immutable';
            END;
            $$ LANGUAGE plpgsql
            SQL);

        DB::statement(<<<'SQL'
            CREATE TRIGGER direct_message_transitions_immutable
            BEFORE UPDATE OR DELETE ON direct_message_transitions
            FOR EACH ROW EXECUTE FUNCTION katra_reject_direct_message_transition_mutation()
            SQL);
    }

    public function down(): void
    {
        $groupConversationExists = DB::table('direct_message_participants')
            ->select('direct_message_id')
            ->groupBy('direct_message_id')
            ->havingRaw('COUNT(*) <> 2')
            ->exists();

        if ($groupConversationExists) {
            throw new RuntimeException(
                'Cannot roll back group Direct Messages while a conversation has other than two participants.',
            );
        }

        DB::statement('DROP TRIGGER IF EXISTS direct_message_transitions_immutable ON direct_message_transitions');
        DB::statement('DROP FUNCTION IF EXISTS katra_reject_direct_message_transition_mutation()');

        DB::statement('ALTER TABLE direct_messages DROP CONSTRAINT direct_messages_lifecycle_state_check');

        Schema::table('direct_messages', function (Blueprint $table): void {
            $table->dropUnique('direct_messages_organization_participant_set_unique');
            $table->foreignId('participant_one_user_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->foreignId('participant_two_user_id')->nullable()->constrained('users')->restrictOnDelete();
        });

        foreach (DB::table('direct_messages')->select('id')->orderBy('id')->get() as $directMessage) {
            $participantIds = DB::table('direct_message_participants')
                ->where('direct_message_id', $directMessage->id)
                ->orderBy('user_id')
                ->pluck('user_id');

            DB::table('direct_messages')->where('id', $directMessage->id)->update([
                'participant_one_user_id' => $participantIds[0],
                'participant_two_user_id' => $participantIds[1],
            ]);
        }

        DB::statement(<<<'SQL'
            ALTER TABLE direct_messages
            ALTER COLUMN participant_one_user_id SET NOT NULL,
            ALTER COLUMN participant_two_user_id SET NOT NULL,
            ADD CONSTRAINT direct_messages_participant_order_check
                CHECK (participant_one_user_id < participant_two_user_id),
            ADD CONSTRAINT direct_messages_initiator_check
                CHECK (initiated_by_user_id IN (participant_one_user_id, participant_two_user_id)),
            ADD CONSTRAINT direct_messages_internal_owner_check
                CHECK (
                    internal_owner_user_id IS NULL
                    OR internal_owner_user_id IN (participant_one_user_id, participant_two_user_id)
                ),
            ADD CONSTRAINT direct_messages_organization_participants_unique
                UNIQUE (organization_id, participant_one_user_id, participant_two_user_id)
            SQL);

        Schema::dropIfExists('direct_message_transitions');
        Schema::dropIfExists('direct_message_participants');

        Schema::table('direct_messages', function (Blueprint $table): void {
            $table->dropColumn('participant_set_hash');
        });
    }
};
