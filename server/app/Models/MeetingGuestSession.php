<?php

namespace App\Models;

use Illuminate\Auth\Authenticatable;
use Illuminate\Contracts\Auth\Authenticatable as AuthenticatableContract;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

#[Fillable([
    'meeting_id',
    'meeting_participant_id',
    'token_hash',
    'token',
    'admission_idempotency_key',
    'admission_request_hash',
    'expires_at',
    'revoked_at',
    'last_seen_at',
])]
class MeetingGuestSession extends Model implements AuthenticatableContract
{
    use Authenticatable;

    public function getAuthIdentifierForBroadcasting(): string
    {
        return "meeting-guest:{$this->public_id}";
    }

    protected static function booted(): void
    {
        static::creating(function (MeetingGuestSession $session): void {
            $session->public_id ??= (string) Str::ulid();
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

    /** @return Attribute<string, never> */
    protected function publicId(): Attribute
    {
        return Attribute::make(set: fn (string $value): string => Str::upper($value));
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'token' => 'encrypted',
            'expires_at' => 'immutable_datetime',
            'revoked_at' => 'immutable_datetime',
            'last_seen_at' => 'immutable_datetime',
        ];
    }
}
