<?php

namespace App\Http\Controllers\Api\V1\Messages;

use App\Conversations\ConversationMessageService;
use App\Http\Controllers\Controller;
use App\Http\Resources\MessageResource;
use App\Models\User;
use Illuminate\Http\Request;

final class ConversationMessageUpdateController extends Controller
{
    public function __invoke(
        Request $request,
        string $conversation,
        string $message,
        ConversationMessageService $messages,
    ): MessageResource {
        $validated = $request->validate([
            'body' => ['required', 'string', 'max:4000'],
        ]);
        /** @var User $user */
        $user = $request->user();

        return new MessageResource($messages->edit($user, $conversation, $message, $validated['body']));
    }
}
