<?php

namespace App\Http\Controllers\Api\V1\MeetingGuests;

use App\Http\Controllers\Controller;
use App\Http\Resources\GuestMeetingResource;
use App\Meetings\MeetingGuestAccess;
use App\Meetings\MeetingGuestRoomService;
use App\Models\MeetingGuestSession;
use Illuminate\Http\Request;

final class GuestMeetingSessionController extends Controller
{
    public function show(Request $request, MeetingGuestAccess $access): GuestMeetingResource
    {
        return new GuestMeetingResource($access->meeting($this->session($request)));
    }

    public function join(Request $request, MeetingGuestAccess $access, MeetingGuestRoomService $rooms): GuestMeetingResource
    {
        $session = $this->session($request);
        $rooms->enter($session);

        return new GuestMeetingResource($access->meeting($session->fresh(['participant', 'meeting'])));
    }

    public function leave(Request $request, MeetingGuestAccess $access, MeetingGuestRoomService $rooms): GuestMeetingResource
    {
        $session = $this->session($request);
        $rooms->leave($session);

        return new GuestMeetingResource($access->meeting($session->fresh(['participant', 'meeting'])));
    }

    private function session(Request $request): MeetingGuestSession
    {
        /** @var MeetingGuestSession $session */
        $session = $request->user('meeting-guest');

        return $session;
    }
}
