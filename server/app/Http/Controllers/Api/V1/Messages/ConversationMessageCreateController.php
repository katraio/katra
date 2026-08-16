<?php

namespace App\Http\Controllers\Api\V1\Messages;

use App\Conversations\ConversationMessageService;
use App\Http\Controllers\Controller;
use App\Http\Resources\MessageResource;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class ConversationMessageCreateController extends Controller
{
    public function __invoke(
        Request $request,
        string $conversation,
        ConversationMessageService $messages,
    ): JsonResponse {
        $validated = $request->validate([
            'body' => ['required', 'string', 'max:4000'],
            'idempotency_key' => ['required', 'string', 'max:64'],
            'parent_message_id' => ['nullable', 'string', 'ulid'],
            'mention_user_ids' => ['sometimes', 'array', 'max:25'],
            'mention_user_ids.*' => ['required', 'string', 'ulid', 'distinct'],
            'attention_user_ids' => ['sometimes', 'array', 'max:25'],
            'attention_user_ids.*' => ['required', 'string', 'ulid', 'distinct'],
        ]);
        /** @var User $user */
        $user = $request->user();
        $message = $messages->send(
            $user,
            $conversation,
            $validated['body'],
            $validated['idempotency_key'],
            $validated['parent_message_id'] ?? null,
            $validated['mention_user_ids'] ?? [],
            $validated['attention_user_ids'] ?? [],
        );
        $status = $message->wasRecentlyCreated ? 201 : 200;

        return (new MessageResource($message))->response()->setStatusCode($status);
    }
}
