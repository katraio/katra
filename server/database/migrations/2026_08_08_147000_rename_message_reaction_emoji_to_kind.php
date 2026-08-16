<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('message_reactions', 'emoji')) {
            return;
        }

        DB::statement('ALTER TABLE message_reactions DROP CONSTRAINT message_reactions_emoji_check');

        Schema::table('message_reactions', function (Blueprint $table): void {
            $table->dropUnique('message_reactions_message_id_user_id_emoji_unique');
            $table->renameColumn('emoji', 'kind');
        });

        Schema::table('message_reactions', function (Blueprint $table): void {
            $table->unique(['message_id', 'user_id', 'kind']);
        });

        DB::statement(<<<'SQL'
            UPDATE message_reactions
            SET kind = CASE kind
                WHEN chr(128077) THEN 'approve'
                WHEN chr(10084) || chr(65039) THEN 'support'
                WHEN chr(9989) THEN 'done'
                ELSE kind
            END
            SQL);

        DB::statement(<<<'SQL'
            ALTER TABLE message_reactions
            ADD CONSTRAINT message_reactions_kind_check
            CHECK (length(btrim(kind)) > 0)
            SQL);
    }

    public function down(): void
    {
        if (! Schema::hasColumn('message_reactions', 'kind')) {
            return;
        }

        DB::statement('ALTER TABLE message_reactions DROP CONSTRAINT message_reactions_kind_check');

        Schema::table('message_reactions', function (Blueprint $table): void {
            $table->dropUnique('message_reactions_message_id_user_id_kind_unique');
            $table->renameColumn('kind', 'emoji');
        });

        Schema::table('message_reactions', function (Blueprint $table): void {
            $table->unique(['message_id', 'user_id', 'emoji']);
        });

        DB::statement(<<<'SQL'
            UPDATE message_reactions
            SET emoji = CASE emoji
                WHEN 'approve' THEN chr(128077)
                WHEN 'support' THEN chr(10084) || chr(65039)
                WHEN 'done' THEN chr(9989)
                ELSE emoji
            END
            SQL);

        DB::statement(<<<'SQL'
            ALTER TABLE message_reactions
            ADD CONSTRAINT message_reactions_emoji_check
            CHECK (length(btrim(emoji)) > 0)
            SQL);
    }
};
