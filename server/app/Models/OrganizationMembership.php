<?php

namespace App\Models;

use App\Enums\MembershipKind;
use App\Enums\MembershipStatus;
use Database\Factories\OrganizationMembershipFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'organization_id',
    'user_id',
    'kind',
    'status',
    'joined_at',
    'suspended_at',
    'created_by_user_id',
])]
class OrganizationMembership extends Model
{
    /** @use HasFactory<OrganizationMembershipFactory> */
    use HasFactory;

    /** @return BelongsTo<Organization, $this> */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return BelongsTo<User, $this> */
    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'kind' => MembershipKind::class,
            'status' => MembershipStatus::class,
            'joined_at' => 'immutable_datetime',
            'suspended_at' => 'immutable_datetime',
        ];
    }
}
