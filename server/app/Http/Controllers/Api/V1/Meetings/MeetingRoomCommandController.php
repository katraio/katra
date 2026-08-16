<?php

namespace App\Http\Controllers\Api\V1\Meetings;

use App\Http\Controllers\Controller;
use App\Http\Resources\MeetingResource;
use App\Meetings\MeetingAccess;
use App\Meetings\MeetingRoomService;
use App\Meetings\MeetingRoomTransitionException;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class MeetingRoomCommandController extends Controller
{
    public function __invoke(
        Request $request,
        string $meeting,
        string $command,
        MeetingAccess $access,
        MeetingRoomService $rooms,
    ): MeetingResource|JsonResponse {
        /** @var User $user */
        $user = $request->user();
        $resolved = $access->findVisible($user, $meeting);

        try {
            $updated = match ($command) {
                'start' => $rooms->start($resolved, $user),
                'join' => $rooms->enter($resolved, $user),
                'leave' => $rooms->leave($resolved, $user),
                'end' => $rooms->complete($resolved, $user),
                'cancel' => $rooms->cancel($resolved, $user),
            };
        } catch (MeetingRoomTransitionException $exception) {
            return response()->json(['message' => $exception->getMessage()], 409);
        }

        return new MeetingResource($updated);
    }
}
