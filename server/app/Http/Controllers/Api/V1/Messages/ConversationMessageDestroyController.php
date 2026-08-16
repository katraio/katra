<?php

namespace App\Http\Controllers\Api\V1\Messages;

use App\Conversations\ConversationMessageService;
use App\Http\Controllers\Controller;
use App\Http\Resources\MessageResource;
use App\Models\User;
use Illuminate\Http\Request;

final class ConversationMessageDestroyController extends Controller
{
    public function __invoke(
        Request $request,
        string $conversation,
        string $message,
        ConversationMessageService $messages,
    ): MessageResource {
        /** @var User $user */
        $user = $request->user();

        return new MessageResource($messages->delete($user, $conversation, $message));
    }
}
