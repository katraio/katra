<?php

namespace App\Http\Controllers\Api\V1\Channels;

use App\Conversations\ChannelAccess;
use App\Conversations\ChannelService;
use App\Conversations\PrivateChannelMembershipDirectory;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

final class ChannelMemberDestroyController extends Controller
{
    public function __invoke(
        Request $request,
        string $channel,
        string $member,
        ChannelAccess $access,
        PrivateChannelMembershipDirectory $directory,
        ChannelService $channels,
    ): Response {
        /** @var User $actor */
        $actor = $request->user();
        $resolved = $access->resolveAddressable($actor, $channel);
        $target = $directory->resolveInternal($member);
        $channels->removeInternalMember($resolved, $actor, $target);

        return response()->noContent();
    }
}
