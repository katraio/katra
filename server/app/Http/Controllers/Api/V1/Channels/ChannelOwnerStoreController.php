<?php

namespace App\Http\Controllers\Api\V1\Channels;

use App\Conversations\ChannelAccess;
use App\Conversations\ChannelService;
use App\Conversations\PrivateChannelMembershipDirectory;
use App\Http\Controllers\Controller;
use App\Http\Resources\ChannelMemberResource;
use App\Models\User;
use Illuminate\Http\Request;

final class ChannelOwnerStoreController extends Controller
{
    public function __invoke(
        Request $request,
        string $channel,
        string $member,
        ChannelAccess $access,
        PrivateChannelMembershipDirectory $directory,
        ChannelService $channels,
    ): ChannelMemberResource {
        /** @var User $actor */
        $actor = $request->user();
        $resolved = $access->resolveAddressable($actor, $channel);
        $target = $directory->resolveInternal($member);
        $membership = $channels->promoteOwner($resolved, $actor, $target)->load('user');

        return new ChannelMemberResource($membership);
    }
}
