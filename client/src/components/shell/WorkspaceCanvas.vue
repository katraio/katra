<script setup lang="ts">
import { computed } from "vue";
import { Menu } from "@lucide/vue";
import type { AuthUser, MemberAdministrationScope } from "../../api/auth";
import type {
  CommunicationAttentionItem,
  CommunicationChannel,
  CommunicationDirectMessage,
  CommunicationMeeting,
  CommunicationReadState,
  ConversationFocusRequest,
  MeetingFocusRequest,
} from "../../api/communication";
import type { ConversationRealtimeEvent } from "../../realtime/communicationRealtime";
import InboxPage from "../inbox/InboxPage.vue";
import LiveConversationPage from "../messages/LiveConversationPage.vue";
import SettingsPage from "../settings/SettingsPage.vue";

defineEmits<{
  "open-navigation": [];
  "channel-updated": [channel: CommunicationChannel];
  "direct-message-updated": [directMessage: CommunicationDirectMessage];
  "read-state-updated": [readState: CommunicationReadState];
  "channel-left": [channelId: string];
  "attention-viewed": [attentionId: string];
  "attention-resolved": [attentionId: string];
  "open-attention-destination": [item: CommunicationAttentionItem];
  "meeting-created": [meeting: CommunicationMeeting];
  "user-updated": [user: AuthUser];
  "organizations-updated": [];
}>();

const props = defineProps<{
  activeDestination: string;
  channels: CommunicationChannel[];
  directMessages: CommunicationDirectMessage[];
  currentUser: AuthUser;
  communicationStatus: "loading" | "ready" | "unavailable";
  realtimeEvent: ConversationRealtimeEvent | null;
  realtimeReconnectGeneration: number;
  attentionItems: CommunicationAttentionItem[];
  attentionStatus: "loading" | "ready" | "unavailable";
  meetings: CommunicationMeeting[];
  meetingStatus: "loading" | "ready" | "unavailable";
  conversationFocusRequest: ConversationFocusRequest | null;
  meetingFocusRequest: MeetingFocusRequest | null;
  memberAdministrationScopes: MemberAdministrationScope[];
  memberAdministrationStatus: "loading" | "ready" | "unavailable";
}>();

const channelDestinationId = computed(() =>
  props.activeDestination.startsWith("channel-") ? props.activeDestination.slice("channel-".length) : null,
);
const directMessageDestinationId = computed(() =>
  props.activeDestination.startsWith("dm-") ? props.activeDestination.slice("dm-".length) : null,
);
const isOpaqueConversationId = (value: string | null) =>
  value !== null && /^[0-9A-HJKMNP-TV-Z]{26}$/i.test(value);
const isLiveConversationDestination = computed(() =>
  isOpaqueConversationId(channelDestinationId.value) || isOpaqueConversationId(directMessageDestinationId.value),
);

const liveChannel = computed(() =>
  props.channels.find((channel) => `channel-${channel.id}` === props.activeDestination) ?? null,
);

const liveDirectMessage = computed(() =>
  props.directMessages.find((directMessage) => `dm-${directMessage.id}` === props.activeDestination) ?? null,
);

const waitingForLiveConversation = computed(() =>
  isLiveConversationDestination.value && props.communicationStatus === "loading",
);

const liveConversationUnavailable = computed(() =>
  isLiveConversationDestination.value
  && props.communicationStatus !== "loading"
  && liveChannel.value === null
  && liveDirectMessage.value === null,
);
</script>

<template>
  <main class="workspace-canvas" aria-label="Workspace canvas">
    <button
      class="workspace-nav-trigger"
      type="button"
      aria-label="Open navigation"
      @click="$emit('open-navigation')"
    >
      <Menu :size="20" :stroke-width="1.8" aria-hidden="true" />
    </button>
    <InboxPage
      v-if="activeDestination === 'inbox'"
      :attention-items="attentionItems"
      :attention-status="attentionStatus"
      :meetings="meetings"
      :meeting-status="meetingStatus"
      :current-user="currentUser"
      :focus-request="meetingFocusRequest"
      @viewed="$emit('attention-viewed', $event)"
      @resolved="$emit('attention-resolved', $event)"
      @open-destination="$emit('open-attention-destination', $event)"
      @meeting-updated="$emit('meeting-created', $event)"
    />
    <SettingsPage
      v-else-if="activeDestination === 'profile'"
      key="profile-settings"
      mode="profile"
      :current-user="currentUser"
      @user-updated="$emit('user-updated', $event)"
    />
    <SettingsPage
      v-else-if="activeDestination === 'server-settings' && (memberAdministrationScopes.length > 0 || currentUser.is_global_administrator)"
      key="server-settings"
      mode="server"
      :current-user="currentUser"
      :administration-scopes="memberAdministrationScopes"
      :administration-status="memberAdministrationStatus"
      @user-updated="$emit('user-updated', $event)"
      @organizations-updated="$emit('organizations-updated')"
    />
    <LiveConversationPage
      v-else-if="liveChannel"
      kind="channel"
      :channel="liveChannel"
      :current-user="currentUser"
      :realtime-event="realtimeEvent"
      :realtime-reconnect-generation="realtimeReconnectGeneration"
      :focus-request="conversationFocusRequest"
      @channel-updated="$emit('channel-updated', $event)"
      @channel-left="$emit('channel-left', $event)"
      @read-state-updated="$emit('read-state-updated', $event)"
      @meeting-created="$emit('meeting-created', $event)"
    />
    <LiveConversationPage
      v-else-if="liveDirectMessage"
      kind="direct-message"
      :direct-message="liveDirectMessage"
      :current-user="currentUser"
      :realtime-event="realtimeEvent"
      :realtime-reconnect-generation="realtimeReconnectGeneration"
      :focus-request="conversationFocusRequest"
      @direct-message-updated="$emit('direct-message-updated', $event)"
      @read-state-updated="$emit('read-state-updated', $event)"
      @meeting-created="$emit('meeting-created', $event)"
    />
    <section v-else-if="waitingForLiveConversation" class="conversation-route-state" aria-label="Loading conversation">
      <header>
        <span class="conversation-route-symbol" />
        <span>
          <strong>Loading conversation…</strong>
          <small>Restoring your Server-backed workspace</small>
        </span>
      </header>
      <div class="conversation-route-lines" aria-hidden="true">
        <span />
        <span />
      </div>
      <div class="conversation-route-composer" aria-hidden="true" />
    </section>
    <section v-else-if="liveConversationUnavailable" class="conversation-route-state conversation-route-state--unavailable" aria-label="Conversation unavailable">
      <div>
        <strong>Conversation unavailable</strong>
        <span>The conversation may have been removed or your access may have changed.</span>
      </div>
    </section>
    <slot v-else />
  </main>
</template>

<style scoped>
.conversation-route-state {
  display: flex;
  width: 100%;
  min-width: 0;
  min-height: 0;
  flex-direction: column;
  border-radius: 12px;
  overflow: hidden;
  background: #2e3745;
  color: #d8dee9;
}

.conversation-route-state > header {
  display: flex;
  min-height: 76px;
  align-items: center;
  gap: 12px;
  padding: 0 24px;
  background: #303947;
}

.conversation-route-symbol {
  width: 38px;
  height: 38px;
  flex: 0 0 38px;
  border-radius: 10px;
  background: #252d38;
}

.conversation-route-state header > span:last-child {
  display: grid;
  gap: 5px;
}

.conversation-route-state strong {
  color: #e4e8ee;
  font-size: 14px;
}

.conversation-route-state small {
  color: #7f8a9a;
  font-size: 11px;
}

.conversation-route-lines {
  display: grid;
  gap: 12px;
  padding: 32px 24px;
}

.conversation-route-lines span {
  width: min(72%, 620px);
  height: 72px;
  border-radius: 10px;
  background: rgb(216 222 233 / 3.5%);
}

.conversation-route-lines span:last-child {
  width: min(58%, 490px);
}

.conversation-route-composer {
  min-height: 104px;
  margin: auto 20px 18px;
  border-radius: 11px;
  background: #252d38;
}

.conversation-route-state--unavailable {
  align-items: center;
  justify-content: center;
}

.conversation-route-state--unavailable > div {
  display: grid;
  max-width: 360px;
  gap: 7px;
  padding: 24px;
  text-align: center;
}

.conversation-route-state--unavailable span {
  color: #8b95a5;
  font-size: 12px;
  line-height: 1.5;
}

@media (max-width: 900px) {
  .conversation-route-state > header {
    min-height: 68px;
    padding-left: 58px;
  }

  .conversation-route-lines {
    padding-right: 14px;
    padding-left: 14px;
  }

  .conversation-route-composer {
    margin-right: 12px;
    margin-left: 12px;
  }
}
</style>
