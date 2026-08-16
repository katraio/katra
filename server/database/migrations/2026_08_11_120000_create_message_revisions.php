<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('message_revisions', function (Blueprint $table): void {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->foreignId('message_id')->constrained()->restrictOnDelete();
            $table->foreignId('actor_user_id')->constrained('users')->restrictOnDelete();
            $table->unsignedInteger('sequence');
            $table->string('operation', 16);
            $table->text('body')->nullable();
            $table->timestamps();

            $table->unique(['message_id', 'sequence']);
            $table->index(['message_id', 'created_at']);
        });

        DB::statement(<<<'SQL'
            ALTER TABLE message_revisions
            ADD CONSTRAINT message_revisions_operation_body_check
            CHECK (
                (operation = 'edit' AND body IS NOT NULL AND length(btrim(body)) > 0)
                OR (operation = 'delete' AND body IS NULL)
            )
            SQL);

        DB::statement(<<<'SQL'
            CREATE TRIGGER message_revisions_immutable
            BEFORE UPDATE OR DELETE ON message_revisions
            FOR EACH ROW EXECUTE FUNCTION katra_reject_message_mutation()
            SQL);
    }

    public function down(): void
    {
        DB::statement('DROP TRIGGER IF EXISTS message_revisions_immutable ON message_revisions');
        Schema::dropIfExists('message_revisions');
    }
};
