<?php

namespace App\Http\Controllers\Api\V1\Messages;

use App\Conversations\ConversationReactionService;
use App\Http\Controllers\Controller;
use App\Http\Resources\MessageResource;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

final class MessageReactionDestroyController extends Controller
{
    public function __invoke(
        Request $request,
        string $conversation,
        string $message,
        ConversationReactionService $reactions,
    ): MessageResource {
        $validated = $request->validate([
            'kind' => ['required', 'string', Rule::in(ConversationReactionService::SUPPORTED_KINDS)],
        ]);
        /** @var User $user */
        $user = $request->user();

        return new MessageResource($reactions->remove($user, $conversation, $message, $validated['kind']));
    }
}
