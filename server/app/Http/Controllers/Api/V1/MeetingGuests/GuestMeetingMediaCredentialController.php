<?php

namespace App\Http\Controllers\Api\V1\MeetingGuests;

use App\Http\Controllers\Controller;
use App\Meetings\MeetingMediaService;
use App\Models\MeetingGuestSession;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class GuestMeetingMediaCredentialController extends Controller
{
    public function __invoke(Request $request, MeetingMediaService $media): JsonResponse
    {
        /** @var MeetingGuestSession $session */
        $session = $request->user('meeting-guest');

        return response()
            ->json(['data' => $media->credentialForGuest($session)])
            ->header('Cache-Control', 'private, no-store')
            ->header('Pragma', 'no-cache');
    }
}
