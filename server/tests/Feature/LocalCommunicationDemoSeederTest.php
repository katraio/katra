<?php

namespace Tests\Feature;

use App\Enums\SystemRole;
use App\Models\Channel;
use App\Models\DirectMessage;
use App\Models\Message;
use App\Models\Organization;
use App\Models\OrganizationMembership;
use App\Models\User;
use Database\Seeders\LocalCommunicationDemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Silber\Bouncer\BouncerFacade as Bouncer;
use Tests\TestCase;

final class LocalCommunicationDemoSeederTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Bouncer::scope()->remove();

        parent::tearDown();
    }

    public function test_local_communication_demo_is_idempotent(): void
    {
        $owner = User::factory()->create();

        $this->seed(LocalCommunicationDemoSeeder::class);
        $this->seed(LocalCommunicationDemoSeeder::class);

        $this->assertSame(2, User::query()->count());
        $this->assertSame(1, Organization::query()->count());
        $this->assertSame(2, OrganizationMembership::query()->count());
        $this->assertSame(1, Channel::query()->count());
        $this->assertSame(1, DirectMessage::query()->count());
        $this->assertSame(3, Message::query()->count());
        $this->assertTrue($owner->fresh()->isGlobalAdministrator());
        $this->assertTrue($owner->fresh()->isAn(SystemRole::GlobalAdministrator->value));
        $this->assertFalse(User::query()
            ->where('email', 'katra-demo-teammate@localhost.invalid')
            ->sole()
            ->isGlobalAdministrator());
    }
}
