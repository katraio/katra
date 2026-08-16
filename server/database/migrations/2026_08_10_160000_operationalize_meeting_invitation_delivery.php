<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('meeting_invitations', function (Blueprint $table): void {
            $table->unsignedSmallInteger('send_count')->default(0)->after('revoked_at');
            $table->timestampTz('last_queued_at')->nullable()->after('send_count');
            $table->timestampTz('last_sent_at')->nullable()->after('last_queued_at');
            $table->timestampTz('last_failed_at')->nullable()->after('last_sent_at');
            $table->timestampTz('admitted_at')->nullable()->after('last_failed_at');
        });

        DB::statement(<<<'SQL'
            CREATE UNIQUE INDEX meeting_participants_email_invitation_unique
            ON meeting_participants (meeting_invitation_id)
            WHERE meeting_invitation_id IS NOT NULL
            SQL);

        Schema::create('meeting_invitation_events', function (Blueprint $table): void {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->foreignId('meeting_invitation_id')->constrained()->cascadeOnDelete();
            $table->string('kind', 24);
            $table->foreignId('actor_user_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestampTz('occurred_at');

            $table->index(['meeting_invitation_id', 'occurred_at']);
        });
        DB::statement(<<<'SQL'
            ALTER TABLE meeting_invitation_events
            ADD CONSTRAINT meeting_invitation_events_kind_check
            CHECK (kind IN ('queued', 'sent', 'failed', 'resent', 'revoked', 'admitted'))
            SQL);
        DB::statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION katra_meeting_invitation_events_immutable()
            RETURNS trigger AS $$
            BEGIN
                RAISE EXCEPTION 'Katra meeting invitation events are immutable';
            END;
            $$ LANGUAGE plpgsql
            SQL);
        DB::statement(<<<'SQL'
            CREATE TRIGGER meeting_invitation_events_immutable
            BEFORE UPDATE OR DELETE ON meeting_invitation_events
            FOR EACH ROW EXECUTE FUNCTION katra_meeting_invitation_events_immutable()
            SQL);
    }

    public function down(): void
    {
        DB::statement('DROP TRIGGER IF EXISTS meeting_invitation_events_immutable ON meeting_invitation_events');
        DB::statement('DROP FUNCTION IF EXISTS katra_meeting_invitation_events_immutable()');
        Schema::dropIfExists('meeting_invitation_events');
        DB::statement('DROP INDEX IF EXISTS meeting_participants_email_invitation_unique');

        Schema::table('meeting_invitations', function (Blueprint $table): void {
            $table->dropColumn([
                'send_count',
                'last_queued_at',
                'last_sent_at',
                'last_failed_at',
                'admitted_at',
            ]);
        });
    }
};
