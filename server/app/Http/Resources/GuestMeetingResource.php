<?php

namespace App\Http\Resources;

use App\Models\Meeting;
use App\Models\MeetingParticipant;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Meeting */
final class GuestMeetingResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->public_id,
            'organization' => ['id' => null, 'name' => $this->organization->name],
            'conversation_id' => null,
            'title' => $this->title,
            'starts_at' => $this->starts_at->toISOString(),
            'duration_minutes' => $this->duration_minutes,
            'desired_outcome' => $this->desired_outcome,
            'status' => $this->status->value,
            'started_at' => $this->started_at?->toISOString(),
            'ended_at' => $this->ended_at?->toISOString(),
            'cancelled_at' => $this->cancelled_at?->toISOString(),
            'organizer' => ['id' => $this->organizer->public_id, 'name' => $this->organizer->name],
            'participants' => $this->participants
                ->whereNull('removed_at')
                ->sortBy(fn (MeetingParticipant $participant): string => $participant->user?->name ?? $participant->display_name ?? '')
                ->values()
                ->map(fn (MeetingParticipant $participant): array => [
                    'id' => $participant->user?->public_id ?? $participant->public_id,
                    'participant_id' => $participant->public_id,
                    'name' => $participant->user?->name ?? $participant->display_name,
                    'kind' => $participant->kind,
                    'admission_source' => $participant->guest_admission_source,
                    'can_remove' => false,
                    'can_block_reentry' => false,
                    'joined_at' => $participant->joined_at?->toISOString(),
                    'left_at' => $participant->left_at?->toISOString(),
                ]),
            'agenda_items' => $this->agendaItems->map(fn ($item): array => [
                'position' => $item->position,
                'title' => $item->title,
                'owner' => $item->owner === null ? null : ['id' => $item->owner->public_id, 'name' => $item->owner->name],
                'duration_minutes' => $item->duration_minutes,
            ]),
            'outcomes' => MeetingOutcomeResource::collection($this->outcomes),
            'guest_link_url' => null,
            'guest_link_expires_at' => $this->guest_link_expires_at->toISOString(),
            'guest_invitations' => [],
            'created_at' => $this->created_at->toISOString(),
        ];
    }
}
