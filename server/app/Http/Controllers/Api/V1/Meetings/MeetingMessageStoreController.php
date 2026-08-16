<?php

namespace App\Http\Controllers\Api\V1\Meetings;

use App\Http\Controllers\Controller;
use App\Http\Resources\MeetingMessageResource;
use App\Meetings\MeetingAccess;
use App\Meetings\MeetingChatService;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class MeetingMessageStoreController extends Controller
{
    public function __invoke(
        Request $request,
        string $meeting,
        MeetingAccess $access,
        MeetingChatService $chat,
    ): JsonResponse {
        $validated = $request->validate([
            'body' => ['required', 'string', 'max:4000'],
            'idempotency_key' => ['required', 'string', 'max:64'],
        ]);
        /** @var User $user */
        $user = $request->user();
        $resolved = $access->findVisible($user, $meeting);
        $message = $chat->send($resolved, $user, $validated['body'], $validated['idempotency_key']);
        $status = $message->wasRecentlyCreated ? 201 : 200;

        return (new MeetingMessageResource($message))->response()->setStatusCode($status);
    }
}
