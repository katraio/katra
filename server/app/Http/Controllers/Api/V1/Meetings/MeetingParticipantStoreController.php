<?php

namespace App\Http\Controllers\Api\V1\Meetings;

use App\Http\Controllers\Controller;
use App\Http\Resources\MeetingResource;
use App\Meetings\MeetingAccess;
use App\Meetings\MeetingService;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

final class MeetingParticipantStoreController extends Controller
{
    public function __invoke(
        Request $request,
        string $meeting,
        MeetingAccess $access,
        MeetingService $meetings,
    ): MeetingResource {
        /** @var User $user */
        $user = $request->user();
        $resolvedMeeting = $access->findVisible($user, $meeting);
        $validated = $request->validate([
            'participant_ids' => ['required', 'array', 'min:1', 'max:50'],
            'participant_ids.*' => ['required', 'string', 'ulid', 'distinct'],
        ]);
        $participants = User::query()->whereIn('public_id', $validated['participant_ids'])->get();

        if ($participants->count() !== count($validated['participant_ids'])) {
            throw ValidationException::withMessages([
                'participant_ids' => ['One or more selected people are unavailable.'],
            ]);
        }

        return new MeetingResource($meetings->addParticipants($resolvedMeeting, $user, $participants));
    }
}
