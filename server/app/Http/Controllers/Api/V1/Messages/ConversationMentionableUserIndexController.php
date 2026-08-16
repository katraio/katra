<?php

namespace App\Http\Controllers\Api\V1\Messages;

use App\Conversations\ConversationMentionService;
use App\Http\Controllers\Controller;
use App\Http\Resources\MentionableUserResource;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

final class ConversationMentionableUserIndexController extends Controller
{
    public function __invoke(
        Request $request,
        string $conversation,
        ConversationMentionService $mentions,
    ): AnonymousResourceCollection {
        /** @var User $user */
        $user = $request->user();

        return MentionableUserResource::collection($mentions->candidates($user, $conversation));
    }
}
