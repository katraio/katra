<?php

namespace App\Http\Controllers\Api\V1\Channels;

use App\Conversations\ChannelAccess;
use App\Http\Controllers\Controller;
use App\Http\Resources\ChannelResource;
use App\Models\User;
use Illuminate\Http\Request;

final class ChannelShowController extends Controller
{
    public function __invoke(Request $request, string $channel, ChannelAccess $access): ChannelResource
    {
        /** @var User $user */
        $user = $request->user();
        $resolved = $access->resolveVisible($user, $channel);
        $resolved->load([
            'conversation.memberships' => fn ($membership) => $membership
                ->where('user_id', $user->getKey()),
            'conversation.favoritedByUsers' => fn ($favorite) => $favorite
                ->whereKey($user->getKey()),
        ]);

        return new ChannelResource($resolved);
    }
}
