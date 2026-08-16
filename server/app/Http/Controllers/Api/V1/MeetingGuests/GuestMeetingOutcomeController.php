<?php

namespace App\Http\Controllers\Api\V1\MeetingGuests;

use App\Enums\MeetingOutcomeKind;
use App\Http\Controllers\Controller;
use App\Http\Resources\MeetingOutcomeResource;
use App\Meetings\MeetingGuestAccess;
use App\Meetings\MeetingOutcomeService;
use App\Models\MeetingGuestSession;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rule;

final class GuestMeetingOutcomeController extends Controller
{
    public function index(Request $request, MeetingGuestAccess $access, MeetingOutcomeService $outcomes): AnonymousResourceCollection
    {
        $session = $this->session($request);

        return MeetingOutcomeResource::collection($outcomes->list($access->meeting($session)));
    }

    public function store(Request $request, MeetingGuestAccess $access, MeetingOutcomeService $outcomes): MeetingOutcomeResource
    {
        $validated = $request->validate([
            'kind' => ['required', Rule::in([MeetingOutcomeKind::Note->value, MeetingOutcomeKind::Decision->value])],
            'body' => ['required', 'string', 'max:2000'],
        ]);
        $session = $this->session($request);

        return new MeetingOutcomeResource($outcomes->createAsGuest(
            $access->meeting($session),
            $session->participant,
            MeetingOutcomeKind::from($validated['kind']),
            $validated['body'],
        ));
    }

    private function session(Request $request): MeetingGuestSession
    {
        /** @var MeetingGuestSession $session */
        $session = $request->user('meeting-guest');

        return $session;
    }
}
