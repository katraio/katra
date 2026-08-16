<?php

namespace App\Models;

use App\Enums\OrganizationInvitationEventKind;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

#[Fillable([
    'organization_invitation_id',
    'kind',
    'actor_user_id',
    'occurred_at',
])]
class OrganizationInvitationEvent extends Model
{
    public $timestamps = false;

    protected static function booted(): void
    {
        static::creating(function (OrganizationInvitationEvent $event): void {
            $event->public_id ??= (string) Str::ulid();
            $event->occurred_at ??= now();
        });
    }

    /** @return BelongsTo<OrganizationInvitation, $this> */
    public function invitation(): BelongsTo
    {
        return $this->belongsTo(OrganizationInvitation::class);
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
        return [
            'kind' => OrganizationInvitationEventKind::class,
            'occurred_at' => 'immutable_datetime',
        ];
    }
}
