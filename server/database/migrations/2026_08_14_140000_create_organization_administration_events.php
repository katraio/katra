<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('organization_administration_events', function (Blueprint $table): void {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->uuid('request_id')->unique();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->foreignId('actor_user_id')->constrained('users')->restrictOnDelete();
            $table->string('kind', 16);
            $table->string('previous_name')->nullable();
            $table->string('current_name');
            $table->timestampTz('occurred_at');

            $table->index(['organization_id', 'occurred_at']);
        });

        DB::statement(<<<'SQL'
            ALTER TABLE organization_administration_events
            ADD CONSTRAINT organization_administration_events_kind_check
            CHECK (kind IN ('created', 'renamed'))
            SQL);
        DB::statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION katra_organization_administration_events_immutable()
            RETURNS trigger AS $$
            BEGIN
                RAISE EXCEPTION 'Katra organization administration events are immutable';
            END;
            $$ LANGUAGE plpgsql
            SQL);
        DB::statement(<<<'SQL'
            CREATE TRIGGER organization_administration_events_immutable
            BEFORE UPDATE OR DELETE ON organization_administration_events
            FOR EACH ROW EXECUTE FUNCTION katra_organization_administration_events_immutable()
            SQL);
    }

    public function down(): void
    {
        DB::statement('DROP TRIGGER IF EXISTS organization_administration_events_immutable ON organization_administration_events');
        DB::statement('DROP FUNCTION IF EXISTS katra_organization_administration_events_immutable()');
        Schema::dropIfExists('organization_administration_events');
    }
};
