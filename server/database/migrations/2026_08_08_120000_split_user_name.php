<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('first_name')->nullable()->after('id');
            $table->string('last_name')->nullable()->after('first_name');
        });

        DB::table('users')
            ->select(['id', 'name'])
            ->orderBy('id')
            ->eachById(function (object $user): void {
                $parts = preg_split('/\s+/', trim((string) $user->name), 2);

                DB::table('users')
                    ->where('id', $user->id)
                    ->update([
                        'first_name' => $parts[0] ?? '',
                        'last_name' => $parts[1] ?? '',
                    ]);
            });

        Schema::table('users', function (Blueprint $table) {
            $table->string('first_name')->nullable(false)->change();
            $table->string('last_name')->nullable(false)->change();
            $table->dropColumn('name');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('name')->nullable()->after('id');
        });

        DB::table('users')
            ->select(['id', 'first_name', 'last_name'])
            ->orderBy('id')
            ->eachById(function (object $user): void {
                DB::table('users')
                    ->where('id', $user->id)
                    ->update([
                        'name' => trim($user->first_name.' '.$user->last_name),
                    ]);
            });

        Schema::table('users', function (Blueprint $table) {
            $table->string('name')->nullable(false)->change();
            $table->dropColumn(['first_name', 'last_name']);
        });
    }
};
