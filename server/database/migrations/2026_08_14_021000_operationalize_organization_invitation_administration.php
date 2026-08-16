<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('organization_invitations', function (Blueprint $table): void {
            $table->string('last_delivery_status', 32)->nullable()->after('last_sent_at');
            $table->timestampTz('last_delivery_at')->nullable()->after('last_delivery_status');
        });

        DB::statement(<<<'SQL'
            ALTER TABLE organization_invitations
            ADD CONSTRAINT organization_invitations_delivery_status_check
            CHECK (last_delivery_status IS NULL OR last_delivery_status IN ('copy-link-only', 'queued', 'sent', 'failed'))
            SQL);

        Schema::create('organization_invitation_events', function (Blueprint $table): void {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->foreignId('organization_invitation_id')->constrained()->cascadeOnDelete();
            $table->string('kind', 32);
            $table->foreignId('actor_user_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestampTz('occurred_at');

            $table->index(['organization_invitation_id', 'occurred_at']);
        });

        DB::statement(<<<'SQL'
            ALTER TABLE organization_invitation_events
            ADD CONSTRAINT organization_invitation_events_kind_check
            CHECK (kind IN (
                'issued',
                'reissued',
                'superseded',
                'delivery-skipped',
                'delivery-queued',
                'delivery-sent',
                'delivery-failed',
                'accepted',
                'revoked'
            ))
            SQL);
        DB::statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION katra_organization_invitation_events_immutable()
            RETURNS trigger AS $$
            BEGIN
                RAISE EXCEPTION 'Katra organization invitation events are immutable';
            END;
            $$ LANGUAGE plpgsql
            SQL);
        DB::statement(<<<'SQL'
            CREATE TRIGGER organization_invitation_events_immutable
            BEFORE UPDATE OR DELETE ON organization_invitation_events
            FOR EACH ROW EXECUTE FUNCTION katra_organization_invitation_events_immutable()
            SQL);
    }

    public function down(): void
    {
        DB::statement('DROP TRIGGER IF EXISTS organization_invitation_events_immutable ON organization_invitation_events');
        DB::statement('DROP FUNCTION IF EXISTS katra_organization_invitation_events_immutable()');
        Schema::dropIfExists('organization_invitation_events');

        DB::statement('ALTER TABLE organization_invitations DROP CONSTRAINT IF EXISTS organization_invitations_delivery_status_check');
        Schema::table('organization_invitations', function (Blueprint $table): void {
            $table->dropColumn(['last_delivery_status', 'last_delivery_at']);
        });
    }
};
