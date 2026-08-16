<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Str;

#[Fillable([
    'meeting_id',
    'public_id',
    'user_id',
    'meeting_invitation_id',
    'kind',
    'guest_admission_source',
    'display_name',
    'joined_at',
    'left_at',
    'added_by_user_id',
    'removed_by_user_id',
    'removed_at',
])]
class MeetingParticipant extends Model
{
    protected static function booted(): void
    {
        static::creating(function (MeetingParticipant $participant): void {
            $participant->public_id ??= (string) Str::ulid();
        });
    }

    /** @return BelongsTo<Meeting, $this> */
    public function meeting(): BelongsTo
    {
        return $this->belongsTo(Meeting::class);
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return BelongsTo<MeetingInvitation, $this> */
    public function invitation(): BelongsTo
    {
        return $this->belongsTo(MeetingInvitation::class, 'meeting_invitation_id');
    }

    /** @return BelongsTo<User, $this> */
    public function addedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'added_by_user_id');
    }

    /** @return BelongsTo<User, $this> */
    public function removedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'removed_by_user_id');
    }

    /** @return HasMany<MeetingParticipantEvent, $this> */
    public function events(): HasMany
    {
        return $this->hasMany(MeetingParticipantEvent::class);
    }

    /** @return HasOne<MeetingGuestSession, $this> */
    public function guestSession(): HasOne
    {
        return $this->hasOne(MeetingGuestSession::class);
    }

    /** @return Attribute<string, never> */
    protected function publicId(): Attribute
    {
        return Attribute::make(set: fn (string $value): string => Str::upper($value));
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'joined_at' => 'immutable_datetime',
            'left_at' => 'immutable_datetime',
            'removed_at' => 'immutable_datetime',
        ];
    }
}
