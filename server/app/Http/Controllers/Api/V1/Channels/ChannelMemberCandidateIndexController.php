<?php

namespace App\Http\Controllers\Api\V1\Channels;

use App\Conversations\ChannelAccess;
use App\Conversations\PrivateChannelMembershipDirectory;
use App\Http\Controllers\Controller;
use App\Http\Resources\MentionableUserResource;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

final class ChannelMemberCandidateIndexController extends Controller
{
    public function __invoke(
        Request $request,
        string $channel,
        ChannelAccess $access,
        PrivateChannelMembershipDirectory $directory,
    ): AnonymousResourceCollection {
        $validated = $request->validate([
            'query' => ['sometimes', 'nullable', 'string', 'max:100'],
            'limit' => ['sometimes', 'integer', 'min:1', 'max:50'],
        ]);
        /** @var User $user */
        $user = $request->user();
        $resolved = $access->resolveAddressable($user, $channel);

        return MentionableUserResource::collection($directory->candidates(
            $user,
            $resolved,
            (string) ($validated['query'] ?? ''),
            (int) ($validated['limit'] ?? 20),
        ));
    }
}
