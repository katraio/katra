<?php

namespace App\Http\Controllers\Api\V1\Meetings;

use App\Conversations\ConversationAccess;
use App\Http\Controllers\Controller;
use App\Http\Resources\MeetingResource;
use App\Meetings\ConversationMeetingService;
use App\Meetings\MeetingRoomTransitionException;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class ConversationMeetingController extends Controller
{
    public function __invoke(
        Request $request,
        string $conversation,
        ConversationAccess $access,
        ConversationMeetingService $meetings,
    ): MeetingResource|JsonResponse {
        /** @var User $user */
        $user = $request->user();
        $resolved = $access->resolveReadable($user, $conversation);
        $validated = $request->validate([
            'title' => ['required', 'string', 'max:160'],
        ]);

        try {
            return new MeetingResource(
                $meetings->startOrJoin($resolved, $user, $validated['title']),
            );
        } catch (MeetingRoomTransitionException $exception) {
            return response()->json(['message' => $exception->getMessage()], 409);
        }
    }
}
