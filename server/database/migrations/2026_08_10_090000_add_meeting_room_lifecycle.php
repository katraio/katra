<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('meetings', function (Blueprint $table): void {
            $table->timestampTz('started_at')->nullable()->after('status');
            $table->timestampTz('ended_at')->nullable()->after('started_at');
            $table->timestampTz('cancelled_at')->nullable()->after('ended_at');
        });

        Schema::table('meeting_participants', function (Blueprint $table): void {
            $table->timestampTz('left_at')->nullable()->after('joined_at');
        });
    }

    public function down(): void
    {
        Schema::table('meeting_participants', function (Blueprint $table): void {
            $table->dropColumn('left_at');
        });

        Schema::table('meetings', function (Blueprint $table): void {
            $table->dropColumn(['started_at', 'ended_at', 'cancelled_at']);
        });
    }
};
