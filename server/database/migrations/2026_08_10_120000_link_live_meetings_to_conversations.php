<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('meetings', function (Blueprint $table): void {
            $table->foreignId('conversation_id')
                ->nullable()
                ->after('organization_id')
                ->constrained()
                ->restrictOnDelete();
        });

        DB::statement(<<<'SQL'
            CREATE UNIQUE INDEX meetings_one_live_conversation
            ON meetings (conversation_id)
            WHERE conversation_id IS NOT NULL AND status = 'live'
            SQL);
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS meetings_one_live_conversation');

        Schema::table('meetings', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('conversation_id');
        });
    }
};
