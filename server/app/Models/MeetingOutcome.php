<?php

namespace App\Models;

use App\Enums\MeetingOutcomeKind;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Str;

#[Fillable([
    'meeting_id',
    'sequence',
    'kind',
    'body',
    'author_user_id',
    'author_meeting_participant_id',
    'assignee_user_id',
])]
class MeetingOutcome extends Model
{
    protected static function booted(): void
    {
        static::creating(function (MeetingOutcome $outcome): void {
            $outcome->public_id ??= (string) Str::ulid();
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

    /** @return BelongsTo<User, $this> */
    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assignee_user_id');
    }

    /** @return HasOne<AttentionItem, $this> */
    public function attentionItem(): HasOne
    {
        return $this->hasOne(AttentionItem::class);
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
            'sequence' => 'integer',
            'kind' => MeetingOutcomeKind::class,
        ];
    }
}
