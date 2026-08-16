<?php

namespace App\Http\Controllers\Api\V1\Channels;

use App\Conversations\ChannelAccess;
use App\Conversations\ChannelService;
use App\Conversations\PrivateChannelMembershipDirectory;
use App\Http\Controllers\Controller;
use App\Http\Resources\ChannelMemberResource;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class ChannelMemberStoreController extends Controller
{
    public function __invoke(
        Request $request,
        string $channel,
        ChannelAccess $access,
        PrivateChannelMembershipDirectory $directory,
        ChannelService $channels,
    ): JsonResponse {
        $validated = $request->validate([
            'user_id' => ['required', 'string', 'ulid'],
        ]);
        /** @var User $actor */
        $actor = $request->user();
        $resolved = $access->resolveAddressable($actor, $channel);
        $member = $directory->resolveInternal($validated['user_id']);
        $membership = $channels->inviteToPrivate($resolved, $actor, $member)->load('user');

        return (new ChannelMemberResource($membership))
            ->response()
            ->setStatusCode(JsonResponse::HTTP_CREATED);
    }
}
