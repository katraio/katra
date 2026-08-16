<?php

namespace App\Meetings;

use App\Models\Meeting;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class MeetingGuestLinkService
{
    public function revoke(Meeting $meeting, User $actor): Meeting
    {
        return $this->mutate($meeting, $actor, false);
    }

    public function regenerate(Meeting $meeting, User $actor): Meeting
    {
        return $this->mutate($meeting, $actor, true);
    }

    private function mutate(Meeting $meeting, User $actor, bool $regenerate): Meeting
    {
        if ($meeting->organizer_user_id !== $actor->getKey()) {
            throw new AuthorizationException;
        }

        return DB::transaction(function () use ($meeting, $regenerate): Meeting {
            $locked = Meeting::query()->lockForUpdate()->findOrFail($meeting->getKey());
            if ($regenerate) {
                $token = Str::random(64);
                $locked->forceFill([
                    'guest_link_token_hash' => hash('sha256', $token),
                    'guest_link_token' => $token,
                    'guest_link_revoked_at' => null,
                ])->save();
            } elseif ($locked->guest_link_revoked_at === null) {
                $locked->forceFill(['guest_link_revoked_at' => now()])->save();
            }

            return $locked;
        });
    }
}
