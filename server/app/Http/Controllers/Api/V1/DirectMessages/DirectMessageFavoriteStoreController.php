<?php

namespace App\Http\Controllers\Api\V1\DirectMessages;

use App\Conversations\ConversationFavoriteService;
use App\Conversations\DirectMessageAccess;
use App\Http\Controllers\Controller;
use App\Http\Resources\DirectMessageResource;
use App\Models\User;
use Illuminate\Http\Request;

final class DirectMessageFavoriteStoreController extends Controller
{
    public function __invoke(
        Request $request,
        string $directMessage,
        DirectMessageAccess $access,
        ConversationFavoriteService $favorites,
    ): DirectMessageResource {
        /** @var User $user */
        $user = $request->user();
        $resolved = $access->resolveVisible($user, $directMessage);
        $favorites->favoriteDirectMessage($resolved, $user);

        return new DirectMessageResource($resolved->load([
            'initiatedBy',
            'internalOwner',
            'completedBy',
            'continuationRequestedBy',
            'conversation.favoritedByUsers' => fn ($favorite) => $favorite
                ->whereKey($user->getKey()),
        ]));
    }
}
