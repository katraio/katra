<?php

namespace App\Models;

use App\Enums\MeetingStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

#[Fillable([
    'organization_id',
    'conversation_id',
    'organizer_user_id',
    'title',
    'starts_at',
    'duration_minutes',
    'desired_outcome',
    'status',
    'media_room_name',
    'media_room_generation',
    'started_at',
    'ended_at',
    'cancelled_at',
    'guest_link_token_hash',
    'guest_link_token',
    'guest_link_expires_at',
    'guest_link_revoked_at',
])]
class Meeting extends Model
{
    protected static function booted(): void
    {
        static::creating(function (Meeting $meeting): void {
            $meeting->public_id ??= (string) Str::ulid();
        });
    }

    public function getRouteKeyName(): string
    {
        return 'public_id';
    }

    /** @return BelongsTo<Organization, $this> */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    /** @return BelongsTo<Conversation, $this> */
    public function conversation(): BelongsTo
    {
        return $this->belongsTo(Conversation::class);
    }

    /** @return BelongsTo<User, $this> */
    public function organizer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'organizer_user_id');
    }

    /** @return HasMany<MeetingParticipant, $this> */
    public function participants(): HasMany
    {
        return $this->hasMany(MeetingParticipant::class);
    }

    /** @return HasMany<MeetingInvitation, $this> */
    public function invitations(): HasMany
    {
        return $this->hasMany(MeetingInvitation::class);
    }

    /** @return HasMany<MeetingAgendaItem, $this> */
    public function agendaItems(): HasMany
    {
        return $this->hasMany(MeetingAgendaItem::class)->orderBy('position');
    }

    /** @return HasMany<MeetingOutcome, $this> */
    public function outcomes(): HasMany
    {
        return $this->hasMany(MeetingOutcome::class)->orderBy('sequence');
    }

    /** @return HasMany<MeetingMessage, $this> */
    public function messages(): HasMany
    {
        return $this->hasMany(MeetingMessage::class)->orderBy('sequence');
    }

    /** @return HasMany<MeetingGuestSession, $this> */
    public function guestSessions(): HasMany
    {
        return $this->hasMany(MeetingGuestSession::class);
    }

    /** @return Attribute<string, never> */
    protected function publicId(): Attribute
    {
        return Attribute::make(
            set: fn (string $value): string => Str::upper($value),
        );
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'starts_at' => 'immutable_datetime',
            'duration_minutes' => 'integer',
            'next_message_sequence' => 'integer',
            'status' => MeetingStatus::class,
            'media_room_generation' => 'integer',
            'started_at' => 'immutable_datetime',
            'ended_at' => 'immutable_datetime',
            'cancelled_at' => 'immutable_datetime',
            'guest_link_token' => 'encrypted',
            'guest_link_expires_at' => 'immutable_datetime',
            'guest_link_revoked_at' => 'immutable_datetime',
        ];
    }
}
