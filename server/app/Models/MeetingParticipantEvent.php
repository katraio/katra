<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

#[Fillable([
    'meeting_id',
    'meeting_participant_id',
    'kind',
    'actor_user_id',
    'occurred_at',
])]
class MeetingParticipantEvent extends Model
{
    public $timestamps = false;

    protected static function booted(): void
    {
        static::creating(function (MeetingParticipantEvent $event): void {
            $event->public_id ??= (string) Str::ulid();
            $event->occurred_at ??= now();
        });
    }

    /** @return BelongsTo<Meeting, $this> */
    public function meeting(): BelongsTo
    {
        return $this->belongsTo(Meeting::class);
    }

    /** @return BelongsTo<MeetingParticipant, $this> */
    public function participant(): BelongsTo
    {
        return $this->belongsTo(MeetingParticipant::class, 'meeting_participant_id');
    }

    /** @return BelongsTo<User, $this> */
    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_user_id');
    }

    /** @return Attribute<string, never> */
    protected function publicId(): Attribute
    {
        return Attribute::make(set: fn (string $value): string => Str::upper($value));
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['occurred_at' => 'immutable_datetime'];
    }
}
