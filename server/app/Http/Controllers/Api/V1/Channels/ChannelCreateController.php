<?php

namespace App\Http\Controllers\Api\V1\Channels;

use App\Auth\OrganizationAccess;
use App\Conversations\ChannelService;
use App\Enums\ChannelVisibility;
use App\Http\Controllers\Controller;
use App\Http\Resources\ChannelResource;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

final class ChannelCreateController extends Controller
{
    public function __invoke(
        Request $request,
        string $organization,
        OrganizationAccess $organizations,
        ChannelService $channels,
    ): JsonResponse {
        /** @var User $user */
        $user = $request->user();
        $resolvedOrganization = $organizations->visibleTo($user)
            ->where('public_id', $organization)
            ->firstOrFail();
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'visibility' => ['required', Rule::enum(ChannelVisibility::class)],
        ]);

        $channel = $channels->createInternal(
            $resolvedOrganization,
            $user,
            $validated['name'],
            ChannelVisibility::from($validated['visibility']),
        );
        $channel->load([
            'conversation.memberships' => fn ($membership) => $membership
                ->where('user_id', $user->getKey()),
        ]);

        return (new ChannelResource($channel))->response()->setStatusCode(201);
    }
}
