<?php

namespace App\Http\Controllers\Api\V1\MeetingGuests;

use App\Http\Controllers\Controller;
use App\Http\Resources\GuestMeetingResource;
use App\Meetings\MeetingEmailGuestAccess;
use App\Meetings\MeetingGuestSecurityMonitor;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Throwable;

final class EmailMeetingAdmissionController extends Controller
{
    public function __invoke(
        Request $request,
        string $invitation,
        MeetingEmailGuestAccess $access,
        MeetingGuestSecurityMonitor $securityMonitor,
    ): JsonResponse {
        $validated = $request->validate([
            'token' => ['required', 'string', 'max:128'],
            'display_name' => ['required', 'string', 'min:2', 'max:80'],
            'idempotency_key' => ['required', 'string', 'max:64'],
        ]);

        try {
            $admission = $access->admit(
                $invitation,
                $validated['token'],
                $validated['display_name'],
                $validated['idempotency_key'],
            );
        } catch (ModelNotFoundException) {
            $securityMonitor->record('admission-rejected');

            return response()->json(['message' => 'This meeting link is unavailable or has expired.'], 404);
        } catch (Throwable $exception) {
            $securityMonitor->record('admission-rejected');
            throw $exception;
        }

        $session = $admission['session'];
        $resource = new GuestMeetingResource($access->meeting($session));

        return response()->json([
            'data' => [
                'session_token' => $session->token,
                'participant' => [
                    'id' => $session->participant->public_id,
                    'name' => $session->participant->display_name,
                ],
                'meeting' => $resource->resolve($request),
            ],
        ], $admission['created'] ? 201 : 200);
    }
}
