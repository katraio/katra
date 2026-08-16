<?php

namespace App\Http\Controllers\Api\V1\Meetings;

use App\Http\Controllers\Controller;
use App\Http\Resources\MeetingResource;
use App\Meetings\MeetingAccess;
use App\Meetings\MeetingParticipantAccessService;
use App\Models\User;
use Illuminate\Http\Request;

final class MeetingParticipantRemoveController extends Controller
{
    public function __invoke(
        Request $request,
        string $meeting,
        string $participant,
        MeetingAccess $access,
        MeetingParticipantAccessService $participantAccess,
    ): MeetingResource {
        /** @var User $user */
        $user = $request->user();
        $resolvedMeeting = $access->findVisible($user, $meeting);
        $resolvedParticipant = $resolvedMeeting->participants()
            ->where('public_id', $participant)
            ->firstOrFail();
        $validated = $request->validate([
            'block_reentry' => ['sometimes', 'boolean'],
        ]);

        return new MeetingResource($participantAccess->remove(
            $resolvedMeeting,
            $resolvedParticipant,
            $user,
            (bool) ($validated['block_reentry'] ?? false),
        ));
    }
}
