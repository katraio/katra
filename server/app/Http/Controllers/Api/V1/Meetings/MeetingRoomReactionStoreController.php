<?php

namespace App\Http\Controllers\Api\V1\Meetings;

use App\Http\Controllers\Controller;
use App\Meetings\MeetingAccess;
use App\Meetings\MeetingRoomReactionService;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

final class MeetingRoomReactionStoreController extends Controller
{
    public function __invoke(
        Request $request,
        string $meeting,
        MeetingAccess $access,
        MeetingRoomReactionService $reactions,
    ): JsonResponse {
        $validated = $request->validate([
            'kind' => ['required', 'string', Rule::in(MeetingRoomReactionService::SUPPORTED_KINDS)],
        ]);
        /** @var User $user */
        $user = $request->user();
        $resolved = $access->findVisible($user, $meeting);
        $reactions->send($resolved, $user, $validated['kind']);

        return response()->json(['data' => ['accepted' => true]]);
    }
}
