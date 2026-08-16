<?php

namespace App\Http\Controllers\Api\V1\Channels;

use App\Conversations\ChannelAccess;
use App\Http\Controllers\Controller;
use App\Http\Resources\ChannelResource;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

final class ChannelIndexController extends Controller
{
    public function __invoke(Request $request, ChannelAccess $access): AnonymousResourceCollection
    {
        /** @var User $user */
        $user = $request->user();

        $channels = $access->visibleTo($user)
            ->with([
                'conversation.memberships' => fn ($membership) => $membership
                    ->where('user_id', $user->getKey()),
                'conversation.favoritedByUsers' => fn ($favorite) => $favorite
                    ->whereKey($user->getKey()),
            ])
            ->orderBy('name')
            ->get();

        return ChannelResource::collection($channels);
    }
}
