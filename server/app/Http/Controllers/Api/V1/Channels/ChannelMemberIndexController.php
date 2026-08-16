<?php

namespace App\Http\Controllers\Api\V1\Channels;

use App\Conversations\ChannelAccess;
use App\Conversations\PrivateChannelMembershipDirectory;
use App\Http\Controllers\Controller;
use App\Http\Resources\ChannelMemberResource;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

final class ChannelMemberIndexController extends Controller
{
    public function __invoke(
        Request $request,
        string $channel,
        ChannelAccess $access,
        PrivateChannelMembershipDirectory $directory,
    ): AnonymousResourceCollection {
        /** @var User $user */
        $user = $request->user();
        $resolved = $access->resolveAddressable($user, $channel);

        return ChannelMemberResource::collection($directory->members($user, $resolved));
    }
}
