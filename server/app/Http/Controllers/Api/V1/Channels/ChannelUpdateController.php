<?php

namespace App\Http\Controllers\Api\V1\Channels;

use App\Conversations\ChannelAccess;
use App\Conversations\ChannelService;
use App\Http\Controllers\Controller;
use App\Http\Resources\ChannelResource;
use App\Models\User;
use Illuminate\Http\Request;

final class ChannelUpdateController extends Controller
{
    public function __invoke(
        Request $request,
        string $channel,
        ChannelAccess $access,
        ChannelService $channels,
    ): ChannelResource {
        /** @var User $user */
        $user = $request->user();
        $resolved = $access->resolveAddressable($user, $channel);
        $validated = $request->validate(['name' => ['required', 'string', 'max:255']]);
        $channels->rename($resolved, $user, $validated['name']);
        $resolved->load([
            'conversation.memberships' => fn ($membership) => $membership
                ->where('user_id', $user->getKey()),
        ]);

        return new ChannelResource($resolved);
    }
}
