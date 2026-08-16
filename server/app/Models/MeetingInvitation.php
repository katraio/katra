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
    'email',
    'token_hash',
    'token',
    'expires_at',
    'revoked_at',
    'send_count',
    'last_queued_at',
    'last_sent_at',
    'last_failed_at',
    'admitted_at',
    'created_by_user_id',
])]
class MeetingInvitation extends Model
{
    protected static function booted(): void
    {
        static::creating(function (MeetingInvitation $invitation): void {
            $invitation->public_id ??= (string) Str::ulid();
        });
    }

    /** @return BelongsTo<Meeting, $this> */
    public function meeting(): BelongsTo
    {
        return $this->belongsTo(Meeting::class);
    }

    /** @return BelongsTo<User, $this> */
    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    /** @return HasMany<MeetingInvitationEvent, $this> */
    public function events(): HasMany
    {
        return $this->hasMany(MeetingInvitationEvent::class);
    }

    /** @return HasOne<MeetingParticipant, $this> */
    public function participant(): HasOne
    {
        return $this->hasOne(MeetingParticipant::class);
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
            'token' => 'encrypted',
            'expires_at' => 'immutable_datetime',
            'revoked_at' => 'immutable_datetime',
            'send_count' => 'integer',
            'last_queued_at' => 'immutable_datetime',
            'last_sent_at' => 'immutable_datetime',
            'last_failed_at' => 'immutable_datetime',
            'admitted_at' => 'immutable_datetime',
        ];
    }
}
