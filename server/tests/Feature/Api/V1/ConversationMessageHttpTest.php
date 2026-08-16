<?php

namespace Tests\Feature\Api\V1;

use App\Auth\OrganizationAuthorization;
use App\Conversations\ChannelService;
use App\Conversations\ConversationMessageWriter;
use App\Conversations\DirectMessageService;
use App\Enums\ChannelMembershipRole;
use App\Enums\ChannelVisibility;
use App\Enums\ConversationType;
use App\Enums\OrganizationRole;
use App\Enums\SystemRole;
use App\Models\Channel;
use App\Models\Conversation;
use App\Models\ConversationMembership;
use App\Models\Organization;
use App\Models\OrganizationMembership;
use App\Models\User;
use Database\Seeders\AuthorizationSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Silber\Bouncer\BouncerFacade as Bouncer;
use Tests\TestCase;

final class ConversationMessageHttpTest extends TestCase
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

    public function test_guest_cannot_use_message_resources(): void
    {
        $conversationId = (string) Str::ulid();

        $this->getJson("/api/v1/conversations/{$conversationId}/messages")
            ->assertUnauthorized();
        $this->getJson("/api/v1/conversations/{$conversationId}/mentionable-users")
            ->assertUnauthorized();
        $this->postJson("/api/v1/conversations/{$conversationId}/messages")
            ->assertUnauthorized();
        $this->patchJson("/api/v1/conversations/{$conversationId}/messages/{$conversationId}", [
            'body' => 'Changed',
        ])->assertUnauthorized();
        $this->deleteJson("/api/v1/conversations/{$conversationId}/messages/{$conversationId}")
            ->assertUnauthorized();
        $this->putJson("/api/v1/conversations/{$conversationId}/messages/{$conversationId}/reactions", [
            'kind' => 'approve',
        ])->assertUnauthorized();
        $this->deleteJson("/api/v1/conversations/{$conversationId}/messages/{$conversationId}/reactions", [
            'kind' => 'approve',
        ])->assertUnauthorized();
        $this->putJson("/api/v1/conversations/{$conversationId}/read-position", [
            'through_sequence' => 0,
        ])->assertUnauthorized();
    }

    public function test_participants_can_toggle_bounded_reactions_idempotently_without_widening_access(): void
    {
        $operating = Organization::factory()->operating()->create();
        $first = $this->memberWithRole($operating, OrganizationRole::InternalMember);
        $second = $this->memberWithRole($operating, OrganizationRole::InternalMember);
        $outsider = $this->memberWithRole($operating, OrganizationRole::Administrator);
        $directMessage = app(DirectMessageService::class)->create($operating, $first, [$second]);
        $otherDirectMessage = app(DirectMessageService::class)->create($operating, $first, [$outsider]);
        $message = app(ConversationMessageWriter::class)->append(
            $directMessage->conversation,
            $first,
            'React to this.',
            'reaction-http-root',
        );
        $conversationId = $directMessage->conversation->public_id;

        $this->actingAs($first)
            ->getJson("/api/v1/conversations/{$conversationId}/mentionable-users")
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $second->public_id)
            ->assertJsonPath('data.0.name', $second->name);

        $this->actingAs($outsider)
            ->getJson("/api/v1/conversations/{$conversationId}/mentionable-users")
            ->assertNotFound();
        $reactionUrl = "/api/v1/conversations/{$conversationId}/messages/{$message->public_id}/reactions";
        $otherMessage = app(ConversationMessageWriter::class)->append(
            $otherDirectMessage->conversation,
            $first,
            'Different conversation.',
            'reaction-http-other',
        );

        $this->actingAs($first)
            ->putJson($reactionUrl, ['kind' => 'approve'])
            ->assertOk()
            ->assertJsonPath('data.reactions.0.kind', 'approve')
            ->assertJsonPath('data.reactions.0.count', 1)
            ->assertJsonPath('data.reactions.0.reacted_by_current_user', true);

        $this->actingAs($first)
            ->putJson($reactionUrl, ['kind' => 'approve'])
            ->assertOk()
            ->assertJsonPath('data.reactions.0.count', 1);

        $this->actingAs($second)
            ->putJson($reactionUrl, ['kind' => 'approve'])
            ->assertOk()
            ->assertJsonPath('data.reactions.0.count', 2)
            ->assertJsonPath('data.reactions.0.reacted_by_current_user', true);

        $this->assertDatabaseCount('message_reactions', 2);

        $this->actingAs($first)
            ->deleteJson($reactionUrl, ['kind' => 'approve'])
            ->assertOk()
            ->assertJsonPath('data.reactions.0.count', 1)
            ->assertJsonPath('data.reactions.0.reacted_by_current_user', false);

        $this->actingAs($first)
            ->putJson($reactionUrl, ['kind' => 'unsupported'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('kind');

        $this->actingAs($first)
            ->putJson(
                "/api/v1/conversations/{$conversationId}/messages/{$otherMessage->public_id}/reactions",
                ['kind' => 'approve'],
            )
            ->assertNotFound();

        $this->actingAs($outsider)
            ->putJson($reactionUrl, ['kind' => 'approve'])
            ->assertNotFound();
    }

    public function test_read_position_advances_monotonically_and_drives_unread_counts(): void
    {
        $operating = Organization::factory()->operating()->create();
        $first = $this->memberWithRole($operating, OrganizationRole::InternalMember);
        $second = $this->memberWithRole($operating, OrganizationRole::InternalMember);
        $outsider = $this->memberWithRole($operating, OrganizationRole::Administrator);
        $directMessage = app(DirectMessageService::class)->create($operating, $first, [$second]);
        $writer = app(ConversationMessageWriter::class);
        $writer->append($directMessage->conversation, $first, 'First unread.', 'read-http-1');
        $writer->append($directMessage->conversation, $first, 'Second unread.', 'read-http-2');
        $conversationId = $directMessage->conversation->public_id;
        $readUrl = "/api/v1/conversations/{$conversationId}/read-position";

        $this->actingAs($second)
            ->getJson('/api/v1/direct-messages')
            ->assertOk()
            ->assertJsonPath('data.0.latest_sequence', 2)
            ->assertJsonPath('data.0.last_read_sequence', 0)
            ->assertJsonPath('data.0.unread_count', 2);

        $this->actingAs($second)
            ->putJson($readUrl, ['through_sequence' => 1])
            ->assertOk()
            ->assertJsonPath('data.last_read_sequence', 1)
            ->assertJsonPath('data.unread_count', 1);

        $this->actingAs($second)
            ->putJson($readUrl, ['through_sequence' => 0])
            ->assertOk()
            ->assertJsonPath('data.last_read_sequence', 1)
            ->assertJsonPath('data.unread_count', 1);

        $this->actingAs($second)
            ->putJson($readUrl, ['through_sequence' => 2])
            ->assertOk()
            ->assertJsonPath('data.last_read_sequence', 2)
            ->assertJsonPath('data.unread_count', 0);

        $this->actingAs($second)
            ->putJson($readUrl, ['through_sequence' => 3])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('through_sequence');

        $this->actingAs($outsider)
            ->putJson($readUrl, ['through_sequence' => 2])
            ->assertNotFound();
    }

    public function test_public_channel_reader_must_join_before_tracking_read_state_or_reacting(): void
    {
        $operating = Organization::factory()->operating()->create();
        $owner = $this->memberWithRole($operating, OrganizationRole::InternalMember);
        $reader = $this->memberWithRole($operating, OrganizationRole::InternalMember);
        $channel = app(ChannelService::class)->createInternal(
            $operating,
            $owner,
            'Read state',
            ChannelVisibility::Public,
        );
        $message = app(ConversationMessageWriter::class)->append(
            $channel->conversation,
            $owner,
            'Join before participating.',
            'public-read-state',
        );
        $conversationId = $channel->conversation->public_id;

        $this->actingAs($reader)
            ->putJson("/api/v1/conversations/{$conversationId}/read-position", [
                'through_sequence' => 1,
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('conversation');

        $this->actingAs($reader)
            ->putJson(
                "/api/v1/conversations/{$conversationId}/messages/{$message->public_id}/reactions",
                ['kind' => 'approve'],
            )
            ->assertForbidden();
    }

    public function test_public_channel_reader_must_join_before_sending_and_retries_are_idempotent(): void
    {
        $operating = Organization::factory()->operating()->create();
        $owner = $this->memberWithRole($operating, OrganizationRole::InternalMember);
        $reader = $this->memberWithRole($operating, OrganizationRole::InternalMember);
        $channel = app(ChannelService::class)->createInternal(
            $operating,
            $owner,
            'Announcements',
            ChannelVisibility::Public,
        );
        $conversationId = $channel->conversation->public_id;

        $this->actingAs($reader)
            ->getJson("/api/v1/conversations/{$conversationId}/messages")
            ->assertOk()
            ->assertJsonCount(0, 'data');

        $command = [
            'body' => '  Durable hello.  ',
            'idempotency_key' => 'public-message-1',
        ];

        $this->actingAs($reader)
            ->postJson("/api/v1/conversations/{$conversationId}/messages", $command)
            ->assertForbidden();

        app(ChannelService::class)->join($channel, $reader);

        $created = $this->actingAs($reader)
            ->postJson("/api/v1/conversations/{$conversationId}/messages", $command)
            ->assertCreated()
            ->assertJsonPath('data.sequence', 1)
            ->assertJsonPath('data.body', 'Durable hello.')
            ->assertJsonPath('data.author.id', $reader->public_id);

        $this->actingAs($reader)
            ->postJson("/api/v1/conversations/{$conversationId}/messages", $command)
            ->assertOk()
            ->assertJsonPath('data.id', $created->json('data.id'));

        $this->actingAs($reader)
            ->postJson("/api/v1/conversations/{$conversationId}/messages", [
                ...$command,
                'body' => 'A conflicting retry.',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('idempotency_key');

        $this->assertDatabaseCount('messages', 1);
    }

    public function test_message_pages_are_sequence_ordered_and_support_latest_before_and_after_modes(): void
    {
        $operating = Organization::factory()->operating()->create();
        $owner = $this->memberWithRole($operating, OrganizationRole::InternalMember);
        $channel = app(ChannelService::class)->createInternal(
            $operating,
            $owner,
            'Paging',
            ChannelVisibility::Private,
        );
        $conversation = $channel->conversation;
        $writer = app(ConversationMessageWriter::class);

        foreach (range(1, 5) as $number) {
            $writer->append($conversation, $owner, "Message {$number}", "page-{$number}");
        }

        $conversationId = $conversation->public_id;

        $this->actingAs($owner)
            ->getJson("/api/v1/conversations/{$conversationId}/messages?limit=2")
            ->assertOk()
            ->assertJsonPath('data.0.sequence', 4)
            ->assertJsonPath('data.1.sequence', 5)
            ->assertJsonPath('meta.latest_sequence', 5)
            ->assertJsonPath('meta.pagination.mode', 'latest')
            ->assertJsonPath('meta.pagination.has_more', true);

        $this->actingAs($owner)
            ->getJson("/api/v1/conversations/{$conversationId}/messages?before_sequence=4&limit=2")
            ->assertOk()
            ->assertJsonPath('data.0.sequence', 2)
            ->assertJsonPath('data.1.sequence', 3)
            ->assertJsonPath('meta.pagination.mode', 'before')
            ->assertJsonPath('meta.pagination.has_more', true);

        $this->actingAs($owner)
            ->getJson("/api/v1/conversations/{$conversationId}/messages?after_sequence=2&limit=2")
            ->assertOk()
            ->assertJsonPath('data.0.sequence', 3)
            ->assertJsonPath('data.1.sequence', 4)
            ->assertJsonPath('meta.pagination.mode', 'after')
            ->assertJsonPath('meta.pagination.has_more', true);

        $this->actingAs($owner)
            ->getJson(
                "/api/v1/conversations/{$conversationId}/messages?before_sequence=4&after_sequence=2",
            )
            ->assertUnprocessable();
    }

    public function test_threads_and_mentions_must_stay_inside_the_authorized_conversation(): void
    {
        $operating = Organization::factory()->operating()->create();
        $first = $this->memberWithRole($operating, OrganizationRole::InternalMember);
        $second = $this->memberWithRole($operating, OrganizationRole::InternalMember);
        $outsider = $this->memberWithRole($operating, OrganizationRole::InternalMember);
        $directMessage = app(DirectMessageService::class)->create($operating, $first, [$second]);
        $otherDirectMessage = app(DirectMessageService::class)->create($operating, $first, [$outsider]);
        $conversationId = $directMessage->conversation->public_id;

        $root = $this->actingAs($first)
            ->postJson("/api/v1/conversations/{$conversationId}/messages", [
                'body' => 'Root',
                'idempotency_key' => 'thread-root',
                'mention_user_ids' => [$second->public_id],
            ])
            ->assertCreated()
            ->assertJsonPath('data.mention_user_ids.0', $second->public_id)
            ->assertJsonPath('data.mentions.0.name', $second->name);

        $reply = $this->actingAs($second)
            ->postJson("/api/v1/conversations/{$conversationId}/messages", [
                'body' => 'Reply',
                'idempotency_key' => 'thread-reply',
                'parent_message_id' => $root->json('data.id'),
            ])
            ->assertCreated()
            ->assertJsonPath('data.parent_message_id', $root->json('data.id'));

        $this->actingAs($first)
            ->postJson("/api/v1/conversations/{$conversationId}/messages", [
                'body' => 'Nested',
                'idempotency_key' => 'thread-nested',
                'parent_message_id' => $reply->json('data.id'),
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('parent_message_id');

        $otherRoot = app(ConversationMessageWriter::class)->append(
            $otherDirectMessage->conversation,
            $first,
            'Other root',
            'other-root',
        );

        $this->actingAs($first)
            ->postJson("/api/v1/conversations/{$conversationId}/messages", [
                'body' => 'Cross-conversation',
                'idempotency_key' => 'thread-cross',
                'parent_message_id' => $otherRoot->public_id,
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('parent_message_id');

        $this->actingAs($first)
            ->postJson("/api/v1/conversations/{$conversationId}/messages", [
                'body' => 'Unavailable mention',
                'idempotency_key' => 'mention-outsider',
                'mention_user_ids' => [$outsider->public_id],
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('mention_user_ids');

        $this->actingAs($first)
            ->postJson("/api/v1/conversations/{$conversationId}/messages", [
                'body' => 'Unavailable attention request',
                'idempotency_key' => 'attention-outsider',
                'attention_user_ids' => [$outsider->public_id],
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('attention_user_ids');

        $this->actingAs($first)
            ->postJson("/api/v1/conversations/{$conversationId}/messages", [
                'body' => 'Self attention request',
                'idempotency_key' => 'attention-self',
                'attention_user_ids' => [$first->public_id],
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('attention_user_ids');
    }

    public function test_channel_mention_candidates_follow_channel_and_organization_boundaries(): void
    {
        $operating = Organization::factory()->operating()->create();
        $clientOrganization = Organization::factory()->create();
        $otherClientOrganization = Organization::factory()->create();
        $owner = $this->memberWithRole($operating, OrganizationRole::InternalMember);
        $internal = $this->memberWithRole($operating, OrganizationRole::InternalMember);
        $client = $this->memberWithRole($clientOrganization, OrganizationRole::ClientMember);
        $clientPeer = $this->memberWithRole($clientOrganization, OrganizationRole::ClientMember);
        $otherClient = $this->memberWithRole($otherClientOrganization, OrganizationRole::ClientMember);
        $channels = app(ChannelService::class);
        $public = $channels->createInternal($operating, $owner, 'Mentionable public', ChannelVisibility::Public);
        $private = $channels->createInternal($operating, $owner, 'Mentionable private', ChannelVisibility::Private);
        $channels->inviteToPrivate($private, $owner, $internal);
        $clientTeam = $this->createClientTeamChannel($clientOrganization, $owner);

        $this->actingAs($owner)
            ->getJson("/api/v1/conversations/{$public->conversation->public_id}/mentionable-users")
            ->assertOk()
            ->assertJsonFragment(['id' => $internal->public_id])
            ->assertJsonMissing(['id' => $client->public_id]);

        $this->actingAs($owner)
            ->getJson("/api/v1/conversations/{$private->conversation->public_id}/mentionable-users")
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $internal->public_id);

        $this->actingAs($client)
            ->getJson("/api/v1/conversations/{$clientTeam->conversation->public_id}/mentionable-users")
            ->assertOk()
            ->assertJsonFragment(['id' => $owner->public_id])
            ->assertJsonFragment(['id' => $internal->public_id])
            ->assertJsonFragment(['id' => $clientPeer->public_id])
            ->assertJsonMissing(['id' => $otherClient->public_id]);

        $this->actingAs($otherClient)
            ->getJson("/api/v1/conversations/{$clientTeam->conversation->public_id}/mentionable-users")
            ->assertNotFound();
    }

    public function test_client_cannot_read_or_send_internal_channel_messages_even_with_malformed_membership(): void
    {
        $operating = Organization::factory()->operating()->create();
        $clientOrganization = Organization::factory()->create();
        $owner = $this->memberWithRole($operating, OrganizationRole::InternalMember);
        $client = $this->memberWithRole($clientOrganization, OrganizationRole::ClientMember);
        $channel = app(ChannelService::class)->createInternal(
            $operating,
            $owner,
            'Internal',
            ChannelVisibility::Public,
        );
        ConversationMembership::factory()->create([
            'conversation_id' => $channel->conversation_id,
            'user_id' => $client,
            'channel_role' => ChannelMembershipRole::Member,
        ]);
        $conversationId = $channel->conversation->public_id;

        $this->actingAs($client)
            ->getJson("/api/v1/conversations/{$conversationId}/messages")
            ->assertNotFound();
        $this->actingAs($client)
            ->postJson("/api/v1/conversations/{$conversationId}/messages", [
                'body' => 'No access',
                'idempotency_key' => 'malformed-membership',
            ])
            ->assertNotFound();
    }

    public function test_archived_channel_history_remains_readable_but_rejects_new_messages(): void
    {
        $operating = Organization::factory()->operating()->create();
        $owner = $this->memberWithRole($operating, OrganizationRole::InternalMember);
        $administrator = $this->memberWithRole($operating, OrganizationRole::Administrator);
        $channel = app(ChannelService::class)->createInternal(
            $operating,
            $owner,
            'History',
            ChannelVisibility::Private,
        );
        $conversationId = $channel->conversation->public_id;

        $this->actingAs($owner)
            ->postJson("/api/v1/conversations/{$conversationId}/messages", [
                'body' => 'Retained history',
                'idempotency_key' => 'before-archive',
            ])
            ->assertCreated();

        app(ChannelService::class)->archive($channel, $administrator);

        $this->actingAs($owner)
            ->getJson("/api/v1/conversations/{$conversationId}/messages")
            ->assertOk()
            ->assertJsonPath('data.0.body', 'Retained history');

        $this->actingAs($owner)
            ->postJson("/api/v1/conversations/{$conversationId}/messages", [
                'body' => 'Rejected after archive',
                'idempotency_key' => 'after-archive',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('conversation');
    }

    public function test_client_team_mentions_enroll_internal_members_only_when_the_message_is_created(): void
    {
        $operating = Organization::factory()->operating()->create();
        $clientOrganization = Organization::factory()->create();
        $creator = $this->memberWithRole($operating, OrganizationRole::InternalMember);
        $firstMention = $this->memberWithRole($operating, OrganizationRole::InternalMember);
        $secondMention = $this->memberWithRole($operating, OrganizationRole::InternalMember);
        $client = $this->memberWithRole($clientOrganization, OrganizationRole::ClientMember);
        $team = $this->createClientTeamChannel($clientOrganization, $creator);
        $conversationId = $team->conversation->public_id;

        $this->actingAs($client)
            ->postJson("/api/v1/conversations/{$conversationId}/messages", [
                'body' => 'Please review.',
                'idempotency_key' => 'client-mention-1',
                'mention_user_ids' => [$firstMention->public_id],
            ])
            ->assertCreated();

        $this->assertDatabaseHas('conversation_memberships', [
            'conversation_id' => $team->conversation_id,
            'user_id' => $firstMention->getKey(),
            'left_at' => null,
            'removed_at' => null,
        ]);

        $this->actingAs($client)
            ->postJson("/api/v1/conversations/{$conversationId}/messages", [
                'body' => 'Conflicting retry.',
                'idempotency_key' => 'client-mention-1',
                'mention_user_ids' => [$secondMention->public_id],
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('idempotency_key');

        $this->assertDatabaseMissing('conversation_memberships', [
            'conversation_id' => $team->conversation_id,
            'user_id' => $secondMention->getKey(),
        ]);
    }

    public function test_direct_message_content_has_no_administrator_override(): void
    {
        $operating = Organization::factory()->operating()->create();
        $first = $this->memberWithRole($operating, OrganizationRole::InternalMember);
        $second = $this->memberWithRole($operating, OrganizationRole::InternalMember);
        $administrator = $this->memberWithRole($operating, OrganizationRole::Administrator);
        $globalAdministrator = User::factory()->create();
        $globalAdministrator->assign(SystemRole::GlobalAdministrator->value);
        Bouncer::refresh($globalAdministrator);
        $directMessage = app(DirectMessageService::class)->create($operating, $first, [$second]);
        $conversationId = $directMessage->conversation->public_id;

        foreach ([$administrator, $globalAdministrator] as $outsider) {
            $this->actingAs($outsider)
                ->getJson("/api/v1/conversations/{$conversationId}/messages")
                ->assertNotFound();
            $this->actingAs($outsider)
                ->postJson("/api/v1/conversations/{$conversationId}/messages", [
                    'body' => 'No override',
                    'idempotency_key' => "admin-{$outsider->getKey()}",
                ])
                ->assertNotFound();
        }
    }

    public function test_completed_client_direct_message_is_readable_but_rejects_client_sends_until_reopened(): void
    {
        $operating = Organization::factory()->operating()->create();
        $clientOrganization = Organization::factory()->create();
        $internal = $this->memberWithRole($operating, OrganizationRole::InternalMember);
        $this->assignRoleInOrganization($internal, $clientOrganization, OrganizationRole::InternalMember);
        $client = $this->memberWithRole($clientOrganization, OrganizationRole::ClientMember);
        $directMessage = app(DirectMessageService::class)->create($clientOrganization, $internal, [$client]);
        $conversationId = $directMessage->conversation->public_id;

        app(DirectMessageService::class)->complete($directMessage, $internal);

        $this->actingAs($client)
            ->getJson("/api/v1/conversations/{$conversationId}/messages")
            ->assertOk();
        $this->actingAs($client)
            ->postJson("/api/v1/conversations/{$conversationId}/messages", [
                'body' => 'Blocked',
                'idempotency_key' => 'client-blocked',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('conversation');

        $this->actingAs($internal)
            ->postJson("/api/v1/conversations/{$conversationId}/messages", [
                'body' => 'Internal follow-up',
                'idempotency_key' => 'internal-follow-up',
            ])
            ->assertCreated();

        app(DirectMessageService::class)->reopen($directMessage->fresh(), $internal);

        $this->actingAs($client)
            ->postJson("/api/v1/conversations/{$conversationId}/messages", [
                'body' => 'Open again',
                'idempotency_key' => 'client-open',
            ])
            ->assertCreated();
    }

    public function test_author_can_edit_and_tombstone_a_message_without_breaking_its_thread(): void
    {
        $operating = Organization::factory()->operating()->create();
        $author = $this->memberWithRole($operating, OrganizationRole::InternalMember);
        $participant = $this->memberWithRole($operating, OrganizationRole::InternalMember);
        $outsider = $this->memberWithRole($operating, OrganizationRole::Administrator);
        $directMessage = app(DirectMessageService::class)->create($operating, $author, [$participant]);
        $writer = app(ConversationMessageWriter::class);
        $root = $writer->append($directMessage->conversation, $author, 'Original body', 'revision-root');
        $reply = $writer->append($directMessage->conversation, $participant, 'A retained reply', 'revision-reply', $root);
        $messageUrl = "/api/v1/conversations/{$directMessage->conversation->public_id}/messages/{$root->public_id}";

        $this->actingAs($participant)
            ->patchJson($messageUrl, ['body' => 'Participant rewrite'])
            ->assertForbidden();

        $this->actingAs($outsider)
            ->patchJson($messageUrl, ['body' => 'Administrator rewrite'])
            ->assertNotFound();

        $this->actingAs($author)
            ->patchJson($messageUrl, ['body' => '  Corrected **Markdown** body  '])
            ->assertOk()
            ->assertJsonPath('data.body', 'Corrected **Markdown** body')
            ->assertJsonPath('data.deleted_at', null)
            ->assertJson(fn ($json) => $json->whereType('data.edited_at', 'string')->etc());

        $this->assertDatabaseHas('messages', [
            'id' => $root->getKey(),
            'body' => 'Original body',
        ]);
        $this->assertDatabaseHas('message_revisions', [
            'message_id' => $root->getKey(),
            'sequence' => 1,
            'operation' => 'edit',
            'body' => 'Corrected **Markdown** body',
        ]);

        $this->actingAs($author)
            ->putJson("{$messageUrl}/reactions", ['kind' => 'approve'])
            ->assertOk();

        $this->actingAs($author)
            ->deleteJson($messageUrl)
            ->assertOk()
            ->assertJsonPath('data.body', null)
            ->assertJsonPath('data.edited_at', null)
            ->assertJsonPath('data.reactions', [])
            ->assertJson(fn ($json) => $json->whereType('data.deleted_at', 'string')->etc());

        $this->assertDatabaseHas('message_revisions', [
            'message_id' => $root->getKey(),
            'sequence' => 2,
            'operation' => 'delete',
            'body' => null,
        ]);
        $this->assertDatabaseCount('message_reactions', 0);

        $this->actingAs($participant)
            ->getJson("/api/v1/conversations/{$directMessage->conversation->public_id}/messages")
            ->assertOk()
            ->assertJsonPath('data.0.id', $root->public_id)
            ->assertJsonPath('data.0.body', null)
            ->assertJsonPath('data.1.id', $reply->public_id)
            ->assertJsonPath('data.1.parent_message_id', $root->public_id)
            ->assertJsonPath('data.1.body', 'A retained reply');

        $this->actingAs($author)
            ->patchJson($messageUrl, ['body' => 'Restore attempt'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('message');
        $this->actingAs($author)
            ->deleteJson($messageUrl)
            ->assertUnprocessable()
            ->assertJsonValidationErrors('message');
        $this->actingAs($author)
            ->putJson("{$messageUrl}/reactions", ['kind' => 'approve'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('message');
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

    private function createClientTeamChannel(Organization $organization, User $creator): Channel
    {
        $conversation = Conversation::factory()->create([
            'organization_id' => $organization,
            'type' => ConversationType::Channel,
            'created_by_user_id' => $creator,
        ]);

        return Channel::query()->create([
            'conversation_id' => $conversation->getKey(),
            'organization_id' => $organization->getKey(),
            'name' => 'Team',
            'slug' => 'team',
            'visibility' => ChannelVisibility::ClientTeam,
        ])->load(['conversation', 'organization']);
    }
}
