<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('meetings', function (Blueprint $table): void {
            $table->string('media_room_name', 64)->nullable()->unique()->after('status');
            $table->unsignedInteger('media_room_generation')->default(0)->after('media_room_name');
        });
    }

    public function down(): void
    {
        Schema::table('meetings', function (Blueprint $table): void {
            $table->dropUnique(['media_room_name']);
            $table->dropColumn(['media_room_name', 'media_room_generation']);
        });
    }
};
