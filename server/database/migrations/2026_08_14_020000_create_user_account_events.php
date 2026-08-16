<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user_account_events', function (Blueprint $table): void {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->uuid('request_id')->unique();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->foreignId('actor_user_id')->constrained('users')->restrictOnDelete();
            $table->string('kind', 32);
            $table->timestampTz('occurred_at');

            $table->index(['user_id', 'occurred_at']);
        });

        DB::statement(<<<'SQL'
            ALTER TABLE user_account_events
            ADD CONSTRAINT user_account_events_kind_check
            CHECK (kind IN ('profile-updated', 'password-changed'))
            SQL);
        DB::statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION katra_user_account_events_immutable()
            RETURNS trigger AS $$
            BEGIN
                RAISE EXCEPTION 'Katra user account events are immutable';
            END;
            $$ LANGUAGE plpgsql
            SQL);
        DB::statement(<<<'SQL'
            CREATE TRIGGER user_account_events_immutable
            BEFORE UPDATE OR DELETE ON user_account_events
            FOR EACH ROW EXECUTE FUNCTION katra_user_account_events_immutable()
            SQL);
    }

    public function down(): void
    {
        DB::statement('DROP TRIGGER IF EXISTS user_account_events_immutable ON user_account_events');
        DB::statement('DROP FUNCTION IF EXISTS katra_user_account_events_immutable()');
        Schema::dropIfExists('user_account_events');
    }
};
