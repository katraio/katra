<?php

namespace App\Http\Resources;

use App\Models\Meeting;
use App\Models\MeetingInvitation;
use App\Models\MeetingParticipant;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Meeting */
final class MeetingResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $isOrganizer = $request->user()?->getKey() === $this->organizer_user_id;
        $includesGuestMaterial = $isOrganizer && $request->routeIs(
            'api.v1.meetings.show',
            'api.v1.meetings.participants.store',
            'api.v1.meetings.participants.remove',
            'api.v1.meetings.room.command',
            'api.v1.meetings.guest-link.revoke',
            'api.v1.meetings.guest-link.regenerate',
            'api.v1.meetings.guest-invitations.store',
            'api.v1.meetings.guest-invitations.resend',
            'api.v1.meetings.guest-invitations.revoke',
            'api.v1.conversations.meeting.store',
            'api.v1.organizations.meetings.store',
            'api.v1.organizations.meetings.instant.store',
        );

        return [
            'id' => $this->public_id,
            'organization' => [
                'id' => $this->organization->public_id,
                'name' => $this->organization->name,
            ],
            'conversation_id' => $this->conversation?->public_id,
            'title' => $this->title,
            'starts_at' => $this->starts_at->toISOString(),
            'duration_minutes' => $this->duration_minutes,
            'desired_outcome' => $this->desired_outcome,
            'status' => $this->status->value,
            'started_at' => $this->started_at?->toISOString(),
            'ended_at' => $this->ended_at?->toISOString(),
            'cancelled_at' => $this->cancelled_at?->toISOString(),
            'organizer' => [
                'id' => $this->organizer->public_id,
                'name' => $this->organizer->name,
            ],
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
                    'can_remove' => $isOrganizer && $participant->user_id !== $this->organizer_user_id,
                    'can_block_reentry' => $isOrganizer
                        && $participant->guest_admission_source === 'email-invitation'
                        && $participant->invitation?->revoked_at === null,
                    'joined_at' => $participant->joined_at?->toISOString(),
                    'left_at' => $participant->left_at?->toISOString(),
                ]),
            'agenda_items' => $this->agendaItems->map(fn ($item): array => [
                'position' => $item->position,
                'title' => $item->title,
                'owner' => $item->owner === null ? null : [
                    'id' => $item->owner->public_id,
                    'name' => $item->owner->name,
                ],
                'duration_minutes' => $item->duration_minutes,
            ]),
            'outcomes' => MeetingOutcomeResource::collection($this->outcomes),
            'guest_link_url' => $includesGuestMaterial && $this->guest_link_revoked_at === null
                ? $this->guestUrl($this->guest_link_token)
                : null,
            'guest_link_expires_at' => $this->guest_link_expires_at->toISOString(),
            'guest_invitations' => $includesGuestMaterial
                ? $this->invitations->map(fn (MeetingInvitation $invitation): array => [
                    'id' => $invitation->public_id,
                    'email' => $invitation->email,
                    'url' => $invitation->revoked_at === null ? $this->invitationUrl($invitation) : null,
                    'expires_at' => $invitation->expires_at->toISOString(),
                    'status' => $this->invitationStatus($invitation),
                    'send_count' => $invitation->send_count,
                    'last_queued_at' => $invitation->last_queued_at?->toISOString(),
                    'last_sent_at' => $invitation->last_sent_at?->toISOString(),
                    'last_failed_at' => $invitation->last_failed_at?->toISOString(),
                    'admitted_at' => $invitation->admitted_at?->toISOString(),
                    'revoked_at' => $invitation->revoked_at?->toISOString(),
                ])
                : [],
            'created_at' => $this->created_at->toISOString(),
        ];
    }

    private function guestUrl(string $token): string
    {
        return sprintf(
            '%s/meeting-guests/%s#token=%s',
            rtrim((string) config('app.client_url'), '/'),
            $this->public_id,
            rawurlencode($token),
        );
    }

    private function invitationUrl(MeetingInvitation $invitation): string
    {
        return sprintf(
            '%s/meeting-invitations/%s#token=%s',
            rtrim((string) config('app.client_url'), '/'),
            $invitation->public_id,
            rawurlencode($invitation->token),
        );
    }

    private function invitationStatus(MeetingInvitation $invitation): string
    {
        if ($invitation->revoked_at !== null) {
            return 'revoked';
        }
        if ($invitation->participant?->removed_at !== null) {
            return 'removed';
        }
        if ($invitation->admitted_at !== null) {
            return 'admitted';
        }
        if ($invitation->last_failed_at !== null && ($invitation->last_sent_at === null || $invitation->last_failed_at->isAfter($invitation->last_sent_at))) {
            return 'failed';
        }
        if ($invitation->last_sent_at !== null) {
            return 'sent';
        }
        if ($invitation->last_queued_at !== null) {
            return 'queued';
        }

        return 'pending';
    }
}
