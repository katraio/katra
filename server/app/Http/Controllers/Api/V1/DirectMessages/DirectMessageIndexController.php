<?php

namespace App\Http\Controllers\Api\V1\DirectMessages;

use App\Conversations\DirectMessageAccess;
use App\Http\Controllers\Controller;
use App\Http\Resources\DirectMessageResource;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

final class DirectMessageIndexController extends Controller
{
    public function __invoke(Request $request, DirectMessageAccess $access): AnonymousResourceCollection
    {
        /** @var User $user */
        $user = $request->user();

        return DirectMessageResource::collection(
            $access->visibleTo($user)
                ->with([
                    'initiatedBy',
                    'internalOwner',
                    'completedBy',
                    'continuationRequestedBy',
                    'conversation.memberships' => fn ($membership) => $membership
                        ->where('user_id', $user->getKey()),
                    'conversation.favoritedByUsers' => fn ($favorite) => $favorite
                        ->whereKey($user->getKey()),
                ])
                ->latest('updated_at')
                ->get(),
        );
    }
}
