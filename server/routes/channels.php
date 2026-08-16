<?php

use App\Conversations\ConversationAccess;
use App\Meetings\MeetingAccess;
use App\Models\MeetingGuestSession;
use App\Models\User;
use Illuminate\Support\Facades\Broadcast;

Broadcast::channel('conversations.{conversation}', function (
    User $user,
    string $conversation,
): array|bool {
    try {
        app(ConversationAccess::class)->resolveReadable($user, $conversation);
    } catch (Throwable) {
        return false;
    }

    return [
        'id' => $user->public_id,
        'name' => $user->name,
    ];
});

Broadcast::channel('users.{user}', function (User $authenticated, string $user): bool {
    return hash_equals($authenticated->public_id, strtoupper($user));
});

Broadcast::channel('meetings.{meeting}', function (
    User|MeetingGuestSession $user,
    string $meeting,
): array|bool {
    if (request()->bearerToken() !== null && ! ($user instanceof MeetingGuestSession)) {
        return false;
    }

    if ($user instanceof MeetingGuestSession) {
        if (
            $user->meeting->public_id !== strtoupper($meeting)
            || $user->meeting->status->value !== 'live'
            || $user->revoked_at !== null
            || $user->expires_at->isPast()
            || $user->participant->removed_at !== null
        ) {
            return false;
        }

        return [
            'id' => $user->participant->public_id,
            'name' => $user->participant->display_name,
        ];
    }

    try {
        app(MeetingAccess::class)->findVisible($user, $meeting);
    } catch (Throwable) {
        return false;
    }

    return [
        'id' => $user->public_id,
        'name' => $user->name,
    ];
}, ['guards' => ['meeting-guest', 'web']]);
