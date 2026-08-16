<?php

namespace App\Organizations;

use App\Models\OrganizationInvitation;

final readonly class IssuedOrganizationInvitation
{
    public function __construct(
        public OrganizationInvitation $invitation,
        public string $token,
        public string $acceptanceUrl,
    ) {}
}
