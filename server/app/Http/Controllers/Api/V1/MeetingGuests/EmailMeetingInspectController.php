<?php

namespace App\Http\Controllers\Api\V1\MeetingGuests;

use App\Http\Controllers\Controller;
use App\Http\Resources\GuestMeetingLobbyResource;
use App\Meetings\MeetingEmailGuestAccess;
use App\Meetings\MeetingGuestSecurityMonitor;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Throwable;

final class EmailMeetingInspectController extends Controller
{
    public function __invoke(
        Request $request,
        string $invitation,
        MeetingEmailGuestAccess $access,
        MeetingGuestSecurityMonitor $securityMonitor,
    ): GuestMeetingLobbyResource|JsonResponse {
        $validated = $request->validate(['token' => ['required', 'string', 'max:128']]);

        try {
            return new GuestMeetingLobbyResource($access->inspect($invitation, $validated['token'])->meeting);
        } catch (ModelNotFoundException) {
            $securityMonitor->record('inspection-rejected');

            return response()->json(['message' => 'This meeting link is unavailable or has expired.'], 404);
        } catch (Throwable $exception) {
            $securityMonitor->record('inspection-rejected');
            throw $exception;
        }
    }
}
