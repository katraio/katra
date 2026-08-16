<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('conversation_memberships', function (Blueprint $table): void {
            $table->string('channel_role', 16)->nullable()->after('user_id');
            $table->index(['conversation_id', 'channel_role']);
        });

        DB::statement(<<<'SQL'
            ALTER TABLE conversation_memberships
            ADD CONSTRAINT conversation_memberships_channel_role_check
            CHECK (channel_role IS NULL OR channel_role IN ('owner', 'member'))
            SQL);
    }

    public function down(): void
    {
        Schema::table('conversation_memberships', function (Blueprint $table): void {
            $table->dropColumn('channel_role');
        });
    }
};
