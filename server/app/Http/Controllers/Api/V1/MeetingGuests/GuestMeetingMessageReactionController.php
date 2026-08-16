<?php

namespace App\Http\Controllers\Api\V1\MeetingGuests;

use App\Http\Controllers\Controller;
use App\Http\Resources\MeetingMessageResource;
use App\Meetings\MeetingGuestAccess;
use App\Meetings\MeetingMessageReactionService;
use App\Models\MeetingGuestSession;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

final class GuestMeetingMessageReactionController extends Controller
{
    public function store(Request $request, string $message, MeetingGuestAccess $access, MeetingMessageReactionService $reactions): MeetingMessageResource
    {
        return $this->mutate($request, $message, $access, $reactions, true);
    }

    public function destroy(Request $request, string $message, MeetingGuestAccess $access, MeetingMessageReactionService $reactions): MeetingMessageResource
    {
        return $this->mutate($request, $message, $access, $reactions, false);
    }

    private function mutate(Request $request, string $message, MeetingGuestAccess $access, MeetingMessageReactionService $reactions, bool $add): MeetingMessageResource
    {
        $validated = $request->validate([
            'kind' => ['required', Rule::in(MeetingMessageReactionService::SUPPORTED_KINDS)],
        ]);
        /** @var MeetingGuestSession $session */
        $session = $request->user('meeting-guest');
        $meeting = $access->meeting($session);
        $updated = $add
            ? $reactions->addAsGuest($meeting, $session->participant, $message, $validated['kind'])
            : $reactions->removeAsGuest($meeting, $session->participant, $message, $validated['kind']);

        return new MeetingMessageResource($updated);
    }
}
