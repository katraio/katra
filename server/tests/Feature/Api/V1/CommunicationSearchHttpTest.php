<?php

namespace Tests\Feature\Api\V1;

use App\Auth\OrganizationAuthorization;
use App\Conversations\ChannelService;
use App\Conversations\ConversationMessageService;
use App\Conversations\ConversationMessageWriter;
use App\Conversations\DirectMessageService;
use App\Enums\ChannelVisibility;
use App\Enums\OrganizationRole;
use App\Models\Organization;
use App\Models\OrganizationMembership;
use App\Models\User;
use Database\Seeders\AuthorizationSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Silber\Bouncer\BouncerFacade as Bouncer;
use Tests\TestCase;

final class CommunicationSearchHttpTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Bouncer::scope()->remove();
        $this->seed(AuthorizationSeeder::class);
        config()->set('scout.driver', 'collection');
    }

    protected function tearDown(): void
    {
        Bouncer::scope()->remove();

        parent::tearDown();
    }

    public function test_search_requires_authentication_and_a_bounded_query(): void
    {
        $this->getJson('/api/v1/search/communications?q=release')
            ->assertUnauthorized();

        $user = User::factory()->create();

        $this->actingAs($user)
            ->getJson('/api/v1/search/communications?q=x')
            ->assertUnprocessable()
            ->assertJsonValidationErrors('q');

        $this->actingAs($user)
            ->getJson('/api/v1/search/communications?q=release&limit=26')
            ->assertUnprocessable()
            ->assertJsonValidationErrors('limit');
    }

    public function test_search_returns_only_currently_readable_channels_and_direct_messages(): void
    {
        $operating = Organization::factory()->operating()->create();
        $owner = $this->memberWithRole($operating, OrganizationRole::Administrator);
        $reader = $this->memberWithRole($operating, OrganizationRole::InternalMember);
        $participant = $this->memberWithRole($operating, OrganizationRole::InternalMember);
        $outsider = $this->memberWithRole($operating, OrganizationRole::InternalMember);
        $channels = app(ChannelService::class);
        $writer = app(ConversationMessageWriter::class);

        $public = $channels->createInternal($operating, $owner, 'General', ChannelVisibility::Public);
        $private = $channels->createInternal($operating, $owner, 'Leadership', ChannelVisibility::Private);
        $directMessage = app(DirectMessageService::class)->create($operating, $reader, [$participant]);

        $publicMessage = $writer->append(
            $public->conversation,
            $owner,
            'The aurora release is public.',
            'search-public',
        );
        $privateMessage = $writer->append(
            $private->conversation,
            $owner,
            'The aurora release is private.',
            'search-private',
        );
        $directMessageResult = $writer->append(
            $directMessage->conversation,
            $reader,
            'The aurora release is in this DM.',
            'search-dm',
        );

        $this->actingAs($reader)
            ->getJson('/api/v1/search/communications?q=aurora')
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonFragment(['message_id' => $publicMessage->public_id])
            ->assertJsonFragment(['message_id' => $directMessageResult->public_id])
            ->assertJsonMissing(['message_id' => $privateMessage->public_id]);

        $this->actingAs($outsider)
            ->getJson('/api/v1/search/communications?q=aurora')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.message_id', $publicMessage->public_id)
            ->assertJsonMissing(['message_id' => $directMessageResult->public_id]);

        $channels->inviteToPrivate($private, $owner, $reader);

        $this->actingAs($reader)
            ->getJson('/api/v1/search/communications?q=aurora')
            ->assertOk()
            ->assertJsonCount(3, 'data')
            ->assertJsonFragment(['message_id' => $privateMessage->public_id]);

        $channels->leave($private, $reader);

        $this->actingAs($reader)
            ->getJson('/api/v1/search/communications?q=aurora')
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonMissing(['message_id' => $privateMessage->public_id]);
    }

    public function test_current_conversation_is_boosted_without_excluding_other_results(): void
    {
        $operating = Organization::factory()->operating()->create();
        $owner = $this->memberWithRole($operating, OrganizationRole::Administrator);
        $channels = app(ChannelService::class);
        $writer = app(ConversationMessageWriter::class);
        $first = $channels->createInternal($operating, $owner, 'First', ChannelVisibility::Public);
        $current = $channels->createInternal($operating, $owner, 'Current', ChannelVisibility::Public);
        $firstMessage = $writer->append(
            $first->conversation,
            $owner,
            'Search for the zephyr plan.',
            'search-first',
        );
        $currentMessage = $writer->append(
            $current->conversation,
            $owner,
            'The zephyr plan is current.',
            'search-current',
        );

        $this->actingAs($owner)
            ->getJson('/api/v1/search/communications?'.http_build_query([
                'q' => 'zephyr',
                'current_conversation_id' => $current->conversation->public_id,
            ]))
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.message_id', $currentMessage->public_id)
            ->assertJsonFragment(['message_id' => $firstMessage->public_id]);
    }

    public function test_search_uses_the_latest_body_and_excludes_tombstoned_messages(): void
    {
        $operating = Organization::factory()->operating()->create();
        $author = $this->memberWithRole($operating, OrganizationRole::InternalMember);
        $participant = $this->memberWithRole($operating, OrganizationRole::InternalMember);
        $directMessage = app(DirectMessageService::class)->create($operating, $author, [$participant]);
        $message = app(ConversationMessageWriter::class)->append(
            $directMessage->conversation,
            $author,
            'The old nebula wording.',
            'search-revision',
        );
        $messages = app(ConversationMessageService::class);

        $messages->edit(
            $author,
            $directMessage->conversation->public_id,
            $message->public_id,
            'The corrected aurora wording.',
        );

        $this->actingAs($participant)
            ->getJson('/api/v1/search/communications?q=aurora')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.message_id', $message->public_id)
            ->assertJsonPath('data.0.body', 'The corrected aurora wording.');

        $messages->delete($author, $directMessage->conversation->public_id, $message->public_id);

        $this->actingAs($participant)
            ->getJson('/api/v1/search/communications?q=aurora')
            ->assertOk()
            ->assertJsonCount(0, 'data');
    }

    private function memberWithRole(Organization $organization, OrganizationRole $role): User
    {
        $user = User::factory()->create();
        OrganizationMembership::factory()->create([
            'organization_id' => $organization,
            'user_id' => $user,
            'kind' => $role->membershipKind(),
        ]);
        app(OrganizationAuthorization::class)->assign($user, $organization, $role);

        return $user;
    }
}
