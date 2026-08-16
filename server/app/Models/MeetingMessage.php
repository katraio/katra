<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

#[Fillable([
    'meeting_id',
    'sequence',
    'author_user_id',
    'author_meeting_participant_id',
    'body',
    'idempotency_key',
    'request_hash',
])]
class MeetingMessage extends Model
{
    protected static function booted(): void
    {
        static::creating(function (MeetingMessage $message): void {
            $message->public_id ??= (string) Str::ulid();
        });
    }

    public function getRouteKeyName(): string
    {
        return 'public_id';
    }

    /** @return BelongsTo<Meeting, $this> */
    public function meeting(): BelongsTo
    {
        return $this->belongsTo(Meeting::class);
    }

    /** @return BelongsTo<User, $this> */
    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'author_user_id');
    }

    /** @return BelongsTo<MeetingParticipant, $this> */
    public function guestAuthor(): BelongsTo
    {
        return $this->belongsTo(MeetingParticipant::class, 'author_meeting_participant_id');
    }

    /** @return HasMany<MeetingMessageReaction, $this> */
    public function reactions(): HasMany
    {
        return $this->hasMany(MeetingMessageReaction::class);
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
        return ['sequence' => 'integer'];
    }
}
