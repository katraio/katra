<?php

namespace App\Http\Resources;

use App\Models\ConversationMembership;
use App\Models\DirectMessage;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin DirectMessage */
final class DirectMessageResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $membership = $this->membershipFor($request);
        $activeMembership = $membership?->isActive() ? $membership : null;
        $latestSequence = $this->conversation->next_message_sequence - 1;

        return [
            'id' => $this->conversation->public_id,
            'organization_id' => $this->organization->public_id,
            'state' => $this->state->value,
            'is_favorite' => $this->favoriteStateFor($request),
            'latest_sequence' => $latestSequence,
            'last_read_sequence' => $activeMembership?->last_read_sequence,
            'unread_count' => $activeMembership === null
                ? null
                : max(0, $latestSequence - $activeMembership->last_read_sequence),
            'participants' => $this->participants
                ->sortBy('id')
                ->values()
                ->map(fn (User $participant): array => [
                    'id' => $participant->public_id,
                    'first_name' => $participant->first_name,
                    'last_name' => $participant->last_name,
                    'name' => $participant->name,
                    'email' => $participant->email,
                ]),
            'initiated_by_id' => $this->initiatedBy->public_id,
            'internal_owner_id' => $this->internalOwner?->public_id,
            'completed_at' => $this->completed_at?->toISOString(),
            'completed_by_id' => $this->completedBy?->public_id,
            'continuation_requested_at' => $this->continuation_requested_at?->toISOString(),
            'continuation_requested_by_id' => $this->continuationRequestedBy?->public_id,
            'created_at' => $this->created_at->toISOString(),
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
