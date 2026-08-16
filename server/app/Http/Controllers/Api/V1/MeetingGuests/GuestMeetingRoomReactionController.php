<?php

namespace App\Http\Controllers\Api\V1\MeetingGuests;

use App\Http\Controllers\Controller;
use App\Meetings\MeetingGuestAccess;
use App\Meetings\MeetingRoomReactionService;
use App\Models\MeetingGuestSession;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

final class GuestMeetingRoomReactionController extends Controller
{
    public function __invoke(Request $request, MeetingGuestAccess $access, MeetingRoomReactionService $reactions): JsonResponse
    {
        $validated = $request->validate([
            'kind' => ['required', Rule::in(MeetingRoomReactionService::SUPPORTED_KINDS)],
        ]);
        /** @var MeetingGuestSession $session */
        $session = $request->user('meeting-guest');
        $reactions->sendAsGuest($access->meeting($session), $session->participant, $validated['kind']);

        return response()->json(['data' => ['accepted' => true]]);
    }
}
