<?php

namespace App\Http\Controllers\Api\V1\DirectMessages;

use App\Conversations\DirectMessageAccess;
use App\Http\Controllers\Controller;
use App\Http\Resources\DirectMessageResource;
use App\Models\User;
use Illuminate\Http\Request;

final class DirectMessageShowController extends Controller
{
    public function __invoke(
        Request $request,
        string $directMessage,
        DirectMessageAccess $access,
    ): DirectMessageResource {
        /** @var User $user */
        $user = $request->user();

        return new DirectMessageResource(
            $access->resolveVisible($user, $directMessage)->load([
                'initiatedBy',
                'internalOwner',
                'completedBy',
                'continuationRequestedBy',
                'conversation.memberships' => fn ($membership) => $membership
                    ->where('user_id', $user->getKey()),
                'conversation.favoritedByUsers' => fn ($favorite) => $favorite
                    ->whereKey($user->getKey()),
            ]),
        );
    }
}
