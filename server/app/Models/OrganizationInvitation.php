<?php

namespace App\Models;

use App\Enums\OrganizationInvitationDeliveryStatus;
use App\Enums\OrganizationRole;
use Database\Factories\OrganizationInvitationFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

#[Fillable([
    'organization_id',
    'email',
    'role',
    'token_hash',
    'invited_by_user_id',
    'expires_at',
    'accepted_at',
    'accepted_by_user_id',
    'revoked_at',
    'last_sent_at',
    'last_delivery_status',
    'last_delivery_at',
])]
class OrganizationInvitation extends Model
{
    /** @use HasFactory<OrganizationInvitationFactory> */
    use HasFactory;

    protected static function booted(): void
    {
        static::creating(function (OrganizationInvitation $invitation): void {
            $invitation->public_id ??= (string) Str::ulid();
        });
    }

    /** @return BelongsTo<Organization, $this> */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    /** @return BelongsTo<User, $this> */
    public function invitedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'invited_by_user_id');
    }

    /** @return BelongsTo<User, $this> */
    public function acceptedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'accepted_by_user_id');
    }

    /** @return HasMany<OrganizationInvitationEvent, $this> */
    public function events(): HasMany
    {
        return $this->hasMany(OrganizationInvitationEvent::class);
    }

    public function isAcceptable(): bool
    {
        return $this->accepted_at === null
            && $this->revoked_at === null
            && $this->expires_at->isFuture();
    }

    public function lifecycleStatus(): string
    {
        if ($this->revoked_at !== null) {
            return 'revoked';
        }

        if ($this->accepted_at !== null) {
            return 'accepted';
        }

        if ($this->expires_at->isPast()) {
            return 'expired';
        }

        return 'pending';
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
            'role' => OrganizationRole::class,
            'expires_at' => 'immutable_datetime',
            'accepted_at' => 'immutable_datetime',
            'revoked_at' => 'immutable_datetime',
            'last_sent_at' => 'immutable_datetime',
            'last_delivery_status' => OrganizationInvitationDeliveryStatus::class,
            'last_delivery_at' => 'immutable_datetime',
        ];
    }
}
