<?php

namespace App\Models;

use App\Enums\OrganizationAdministrationEventKind;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

#[Fillable([
    'request_id',
    'organization_id',
    'actor_user_id',
    'kind',
    'previous_name',
    'current_name',
    'occurred_at',
])]
class OrganizationAdministrationEvent extends Model
{
    public $timestamps = false;

    protected static function booted(): void
    {
        static::creating(function (OrganizationAdministrationEvent $event): void {
            $event->public_id ??= (string) Str::ulid();
            $event->occurred_at ??= now();
        });
    }

    /** @return BelongsTo<Organization, $this> */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
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
            'kind' => OrganizationAdministrationEventKind::class,
            'occurred_at' => 'immutable_datetime',
        ];
    }
}
