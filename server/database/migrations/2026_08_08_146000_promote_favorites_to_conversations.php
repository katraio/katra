<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('conversation_favorites', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('conversation_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['conversation_id', 'user_id']);
            $table->index(['user_id', 'created_at']);
        });

        DB::statement(<<<'SQL'
            INSERT INTO conversation_favorites (conversation_id, user_id, created_at, updated_at)
            SELECT channels.conversation_id, channel_favorites.user_id,
                   channel_favorites.created_at, channel_favorites.updated_at
            FROM channel_favorites
            INNER JOIN channels ON channels.id = channel_favorites.channel_id
        SQL);

        Schema::drop('channel_favorites');
    }

    public function down(): void
    {
        Schema::create('channel_favorites', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('channel_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['channel_id', 'user_id']);
            $table->index(['user_id', 'created_at']);
        });

        DB::statement(<<<'SQL'
            INSERT INTO channel_favorites (channel_id, user_id, created_at, updated_at)
            SELECT channels.id, conversation_favorites.user_id,
                   conversation_favorites.created_at, conversation_favorites.updated_at
            FROM conversation_favorites
            INNER JOIN channels ON channels.conversation_id = conversation_favorites.conversation_id
        SQL);

        Schema::drop('conversation_favorites');
    }
};
