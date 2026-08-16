<?php

namespace App\Http\Resources;

use App\Conversations\ChannelAccess;
use App\Conversations\ConversationMentionService;
use App\Models\Channel;
use App\Models\ConversationMembership;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Channel */
final class ChannelResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        /** @var ConversationMembership|null $membership */
        $membership = $this->membershipFor($request);
        $activeMembership = $membership?->isActive() ? $membership : null;
        $latestSequence = $this->conversation->next_message_sequence - 1;
        $lastReadSequence = $activeMembership?->last_read_sequence ?? 0;
        $user = $request->user();

        return [
            'id' => $this->conversation->public_id,
            'organization_id' => $this->organization->public_id,
            'name' => $this->name,
            'slug' => $this->slug,
            'visibility' => $this->visibility->value,
            'archived_at' => $this->conversation->archived_at?->toISOString(),
            'is_favorite' => $this->favoriteStateFor($request),
            'latest_sequence' => $latestSequence,
            'last_read_sequence' => $activeMembership?->last_read_sequence,
            'unread_count' => $activeMembership === null
                ? null
                : max(0, $latestSequence - $activeMembership->last_read_sequence),
            'mention_count' => $user === null
                ? 0
                : app(ConversationMentionService::class)
                    ->unreadCount($this->conversation, $user, $lastReadSequence),
            'membership' => $activeMembership ? [
                'role' => $activeMembership->channel_role?->value,
                'last_read_sequence' => $activeMembership->last_read_sequence,
                'joined_at' => $activeMembership->joined_at->toISOString(),
            ] : null,
            'permissions' => [
                'can_manage_members' => $user !== null
                    && app(ChannelAccess::class)->canManagePrivateMembership($user, $this->resource),
            ],
            'live_meeting' => $this->conversation->liveMeeting === null
                ? null
                : new ConversationLiveMeetingResource($this->conversation->liveMeeting),
        ];
    }

    private function membershipFor(Request $request): ?ConversationMembership
    {
        $user = $request->user();

        if ($user === null) {
            return null;
        }

        if ($this->conversation->relationLoaded('memberships')) {
            return $this->conversation->memberships->firstWhere('user_id', $user->getKey());
        }

        return $this->conversation->memberships()->where('user_id', $user->getKey())->first();
    }

    private function favoriteStateFor(Request $request): bool
    {
        $user = $request->user();

        if ($user === null) {
            return false;
        }

        if ($this->conversation->relationLoaded('favoritedByUsers')) {
            return $this->conversation->favoritedByUsers->contains('id', $user->getKey());
        }

        return $this->conversation->favoritedByUsers()->whereKey($user->getKey())->exists();
    }
}
