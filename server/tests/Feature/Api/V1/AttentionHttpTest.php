<?php

namespace Tests\Feature\Api\V1;

use App\Auth\OrganizationAuthorization;
use App\Conversations\ChannelService;
use App\Conversations\DirectMessageService;
use App\Enums\AttentionKind;
use App\Enums\AttentionState;
use App\Enums\ChannelVisibility;
use App\Enums\DirectMessageState;
use App\Enums\OrganizationRole;
use App\Models\AttentionItem;
use App\Models\Organization;
use App\Models\OrganizationMembership;
use App\Models\User;
use Database\Seeders\AuthorizationSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Silber\Bouncer\BouncerFacade as Bouncer;
use Tests\TestCase;

final class AttentionHttpTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Bouncer::scope()->remove();
        $this->seed(AuthorizationSeeder::class);
    }

    protected function tearDown(): void
    {
        Bouncer::scope()->remove();

        parent::tearDown();
    }

    public function test_guest_cannot_use_attention_resources(): void
    {
        $attentionId = (string) Str::ulid();

        $this->getJson('/api/v1/attention')->assertUnauthorized();
        $this->putJson("/api/v1/attention/{$attentionId}/viewed")->assertUnauthorized();
        $this->postJson("/api/v1/attention/{$attentionId}/resolve")->assertUnauthorized();
    }

    public function test_mentions_create_channel_counters_without_creating_inbox_attention(): void
    {
        $organization = Organization::factory()->operating()->create();
        $author = $this->memberWithRole($organization, OrganizationRole::InternalMember);
        $mentioned = $this->memberWithRole($organization, OrganizationRole::InternalMember);
        $channel = app(ChannelService::class)->createInternal(
            $organization,
            $author,
            'Mention counters',
            ChannelVisibility::Private,
        );
        app(ChannelService::class)->inviteToPrivate($channel, $author, $mentioned);
        $conversationId = $channel->conversation->public_id;
        $command = [
            'body' => 'Please review this durable mention.',
            'idempotency_key' => 'attention-mention-1',
            'mention_user_ids' => [$mentioned->public_id, $author->public_id],
        ];

        $message = $this->actingAs($author)
            ->postJson("/api/v1/conversations/{$conversationId}/messages", $command)
            ->assertCreated();

        $this->actingAs($author)
            ->postJson("/api/v1/conversations/{$conversationId}/messages", $command)
            ->assertOk()
            ->assertJsonPath('data.id', $message->json('data.id'));

        $this->assertDatabaseCount('attention_items', 0);
        $this->actingAs($mentioned)
            ->getJson('/api/v1/attention')
            ->assertOk()
            ->assertJsonCount(0, 'data');
        $this->actingAs($mentioned)
            ->getJson('/api/v1/channels')
            ->assertOk()
            ->assertJsonPath('data.0.mention_count', 1);

        $this->actingAs($mentioned)
            ->putJson("/api/v1/conversations/{$conversationId}/read-position", [
                'through_sequence' => $message->json('data.sequence'),
            ])
            ->assertOk()
            ->assertJsonPath('data.mention_count', 0);

        $this->actingAs($mentioned)
            ->getJson('/api/v1/channels')
            ->assertOk()
            ->assertJsonPath('data.0.mention_count', 0);
    }

    public function test_explicit_message_attention_targets_create_independent_resolvable_inbox_work(): void
    {
        $organization = Organization::factory()->operating()->create();
        $author = $this->memberWithRole($organization, OrganizationRole::InternalMember);
        $target = $this->memberWithRole($organization, OrganizationRole::InternalMember);
        $channel = app(ChannelService::class)->createInternal(
            $organization,
            $author,
            'Attention requests',
            ChannelVisibility::Private,
        );
        app(ChannelService::class)->inviteToPrivate($channel, $author, $target);
        $conversationId = $channel->conversation->public_id;

        $root = $this->actingAs($author)
            ->postJson("/api/v1/conversations/{$conversationId}/messages", [
                'body' => 'Decision context.',
                'idempotency_key' => 'attention-root',
            ])
            ->assertCreated();
        $command = [
            'body' => "!!{$target->name} please make the final decision.",
            'idempotency_key' => 'attention-request-1',
            'parent_message_id' => $root->json('data.id'),
            'mention_user_ids' => [$target->public_id],
            'attention_user_ids' => [$target->public_id],
        ];

        $message = $this->actingAs($author)
            ->postJson("/api/v1/conversations/{$conversationId}/messages", $command)
            ->assertCreated()
            ->assertJsonPath('data.mention_user_ids', [])
            ->assertJsonPath('data.attention_user_ids.0', $target->public_id)
            ->assertJsonPath('data.attention_targets.0.name', $target->name);

        $this->actingAs($author)
            ->postJson("/api/v1/conversations/{$conversationId}/messages", $command)
            ->assertOk()
            ->assertJsonPath('data.id', $message->json('data.id'));

        $this->assertDatabaseCount('message_attention_targets', 1);
        $this->assertDatabaseCount('attention_items', 1);
        $this->assertDatabaseHas('attention_items', [
            'user_id' => $target->getKey(),
            'actor_user_id' => $author->getKey(),
            'kind' => AttentionKind::MessageAttentionRequest->value,
            'state' => AttentionState::Open->value,
        ]);

        $index = $this->actingAs($target)
            ->getJson('/api/v1/attention')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.kind', 'message-attention-request')
            ->assertJsonPath('data.0.priority', 'normal')
            ->assertJsonPath('data.0.destination.type', 'channel')
            ->assertJsonPath('data.0.destination.conversation_id', $conversationId)
            ->assertJsonPath('data.0.destination.message_id', $message->json('data.id'))
            ->assertJsonPath('data.0.destination.thread_root_message_id', $root->json('data.id'))
            ->assertJsonPath('data.0.destination.message_sequence', $message->json('data.sequence'));
        $attentionId = $index->json('data.0.id');

        $this->actingAs($target)
            ->putJson("/api/v1/attention/{$attentionId}/viewed")
            ->assertOk()
            ->assertJsonPath('data.state', 'open');
        $this->actingAs($target)->getJson('/api/v1/attention')->assertJsonCount(1, 'data');

        $this->actingAs($target)
            ->postJson("/api/v1/attention/{$attentionId}/resolve")
            ->assertOk()
            ->assertJsonPath('data.state', 'resolved');
        $this->actingAs($target)->getJson('/api/v1/attention')->assertJsonCount(0, 'data');
        $this->actingAs($author)->getJson('/api/v1/attention')->assertJsonCount(0, 'data');
    }

    public function test_client_continuation_targets_the_internal_owner_once_and_reopen_resolves_it(): void
    {
        $operating = Organization::factory()->operating()->create();
        $clientOrganization = Organization::factory()->create();
        $owner = $this->memberWithRole($operating, OrganizationRole::InternalMember);
        $otherInternal = $this->memberWithRole($operating, OrganizationRole::InternalMember);
        $this->assignRoleInOrganization($owner, $clientOrganization, OrganizationRole::InternalMember);
        $this->assignRoleInOrganization($otherInternal, $clientOrganization, OrganizationRole::InternalMember);
        $client = $this->memberWithRole($clientOrganization, OrganizationRole::ClientMember);
        $directMessages = app(DirectMessageService::class);
        $directMessage = $directMessages->create(
            $clientOrganization,
            $owner,
            [$otherInternal, $client],
        );

        $completed = $directMessages->complete($directMessage, $otherInternal);
        $requested = $directMessages->requestContinuation($completed, $client);
        $replayed = $directMessages->requestContinuation($requested, $client);

        $this->assertSame(DirectMessageState::ContinuationRequested, $replayed->state);
        $this->assertDatabaseCount('attention_items', 1);
        $this->assertDatabaseHas('attention_items', [
            'user_id' => $owner->getKey(),
            'actor_user_id' => $client->getKey(),
            'kind' => AttentionKind::DirectMessageContinuation->value,
            'state' => AttentionState::Open->value,
        ]);

        $index = $this->actingAs($owner)
            ->getJson('/api/v1/attention')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.destination.type', 'direct-message')
            ->assertJsonPath('data.0.destination.conversation_id', $directMessage->conversation->public_id);
        $attentionId = $index->json('data.0.id');

        $this->actingAs($client)->getJson('/api/v1/attention')->assertJsonCount(0, 'data');
        $this->actingAs($otherInternal)->getJson('/api/v1/attention')->assertJsonCount(0, 'data');

        $directMessages->reopen($requested, $otherInternal);

        $item = AttentionItem::query()->where('public_id', $attentionId)->sole();
        $this->assertSame(AttentionState::Resolved, $item->state);
        $this->assertTrue($item->resolvedBy->is($otherInternal));
        $this->assertNotNull($item->viewed_at);
        $this->actingAs($owner)->getJson('/api/v1/attention')->assertJsonCount(0, 'data');
    }

    private function memberWithRole(Organization $organization, OrganizationRole $role): User
    {
        $user = User::factory()->create();
        $this->assignRoleInOrganization($user, $organization, $role);

        return $user;
    }

    private function assignRoleInOrganization(
        User $user,
        Organization $organization,
        OrganizationRole $role,
    ): void {
        OrganizationMembership::factory()->create([
            'organization_id' => $organization,
            'user_id' => $user,
            'kind' => $role->membershipKind(),
        ]);
        app(OrganizationAuthorization::class)->assign($user, $organization, $role);
    }
}
