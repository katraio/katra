<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->ulid('public_id')->nullable()->unique();
        });

        foreach (DB::table('users')->select('id')->orderBy('id')->get() as $user) {
            DB::table('users')->where('id', $user->id)->update([
                'public_id' => (string) Str::ulid(),
            ]);
        }

        DB::statement('ALTER TABLE users ALTER COLUMN public_id SET NOT NULL');
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn('public_id');
        });
    }
};
