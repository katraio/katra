<script setup lang="ts">
import { BellRing, X } from "@lucide/vue";
import { computed, onBeforeUnmount, onMounted, ref, watch } from "vue";
import type { ConnectionStatus } from "laravel-echo";
import { useRouter } from "vue-router";
import {
  getAttention,
  getChannels,
  getDirectMessages,
  getMeeting,
  getMeetings,
  getOrganizations,
  markAttentionViewed,
  resolveAttention,
  type CommunicationAttentionItem,
  type CommunicationChannel,
  type CommunicationDirectMessage,
  type CommunicationMeeting,
  type CommunicationOrganization,
  type CommunicationReadState,
  type ConversationFocusRequest,
  type MeetingFocusRequest,
} from "../../api/communication";
import { getKatraServerConnection } from "../../api/katraServer";
import {
  getMemberAdministrationScopes,
  type AuthUser,
  type MemberAdministrationScope,
} from "../../api/auth";
import { adoptAuthenticatedUser, authSession, signOut } from "../../auth/authSession";
import { isFiniteNumber, useUiPreference } from "../../composables/useUiPreference";
import {
  readMeetingNotificationPermission,
  requestMeetingNotificationPermission,
} from "../../meetings/meetingNotifications";
import {
  canManageServerOrganizations,
  canManageServerPeople,
} from "../../settings/settingsPresentation";
import {
  startCommunicationRealtime,
  type CommunicationRealtimeController,
  type ConversationRealtimeEvent,
  type MeetingStateChangedEvent,
} from "../../realtime/communicationRealtime";
import AppSidebar from "./AppSidebar.vue";
import type { GlobalSearchSelection } from "./globalSearch";
import WorkspaceCanvas from "./WorkspaceCanvas.vue";

const SIDEBAR_DEFAULT_WIDTH = 244;
const SIDEBAR_MIN_WIDTH = 208;
const SIDEBAR_MAX_WIDTH = 420;

function isWorkspaceDestination(value: unknown): value is string {
  return typeof value === "string" && (
    ["inbox", "profile", "server-settings"].includes(value)
    || /^(channel|dm)-[0-9A-HJKMNP-TV-Z]{26}$/i.test(value)
  );
}

const router = useRouter();
const sidebarOpen = ref(false);
const sidebarWidth = useUiPreference(
  "sidebar-width",
  SIDEBAR_DEFAULT_WIDTH,
  (value): value is number => isFiniteNumber(value) && value >= SIDEBAR_MIN_WIDTH && value <= SIDEBAR_MAX_WIDTH,
);
const sidebarResizing = ref(false);
const serverConnection = ref<"checking" | "connected" | "unavailable">("checking");
const communicationStatus = ref<"loading" | "ready" | "unavailable">("loading");
const attentionStatus = ref<"loading" | "ready" | "unavailable">("loading");
const realtimeStatus = ref<ConnectionStatus>("connecting");
const realtimeEvent = ref<ConversationRealtimeEvent | null>(null);
const realtimeReconnectGeneration = ref(0);
const channels = ref<CommunicationChannel[]>([]);
const directMessages = ref<CommunicationDirectMessage[]>([]);
const organizations = ref<CommunicationOrganization[]>([]);
const attentionItems = ref<CommunicationAttentionItem[]>([]);
const meetings = ref<CommunicationMeeting[]>([]);
const meetingStatus = ref<"loading" | "ready" | "unavailable">("loading");
const memberAdministrationScopes = ref<MemberAdministrationScope[]>([]);
const memberAdministrationStatus = ref<"loading" | "ready" | "unavailable">("loading");
const meetingNotificationPermission = ref(
  readMeetingNotificationPermission(typeof Notification === "undefined" ? undefined : Notification),
);
const meetingNotificationPromptDismissed = useUiPreference(
  "meeting-notification-prompt-dismissed",
  false,
  (value): value is boolean => typeof value === "boolean",
  "local",
);
const meetingNotificationRequesting = ref(false);
const meetingNotificationFeedback = ref<string | null>(null);
const conversationFocusRequest = ref<ConversationFocusRequest | null>(null);
const meetingFocusRequest = ref<MeetingFocusRequest | null>(null);
const activeDestination = useUiPreference("active-destination", "inbox", isWorkspaceDestination, "session");
const scrollIdleTimers = new WeakMap<HTMLElement, number>();
const serverConnectionAbortController = new AbortController();
const communicationAbortController = new AbortController();
let realtimeController: CommunicationRealtimeController | null = null;
const terminalMeetingIds = new Set<string>();
const meetingNotificationSiteLabel = typeof window === "undefined"
  ? "this Katra site"
  : window.location.host;

const appShellStyle = computed(() => ({
  "--sidebar-width": `${sidebarWidth.value}px`,
}));

const serverConnectionMessage = computed(() => {
  if (serverConnection.value === "connected") {
    return "Katra Server connected.";
  }

  if (serverConnection.value === "unavailable") {
    return "Katra Server unavailable.";
  }

  return "Checking Katra Server connection.";
});
const showMeetingNotificationPrompt = computed(() =>
  meetingNotificationFeedback.value !== null
  || (meetingNotificationPermission.value === "default" && !meetingNotificationPromptDismissed.value),
);
const showServerSettings = computed(() => (
  canManageServerPeople(memberAdministrationScopes.value)
  || (authSession.user.value !== null && canManageServerOrganizations(authSession.user.value))
));

const activeConversationId = computed(() => {
  if (activeDestination.value.startsWith("channel-")) {
    return activeDestination.value.slice("channel-".length);
  }

  return activeDestination.value.startsWith("dm-")
    ? activeDestination.value.slice("dm-".length)
    : null;
});

async function checkServerConnection() {
  try {
    await getKatraServerConnection(serverConnectionAbortController.signal);
    serverConnection.value = "connected";
  } catch (error) {
    if (error instanceof DOMException && error.name === "AbortError") {
      return;
    }

    serverConnection.value = "unavailable";
  }
}

async function loadCommunication() {
  communicationStatus.value = "loading";

  try {
    const [availableOrganizations, availableChannels, availableDirectMessages] = await Promise.all([
      getOrganizations(communicationAbortController.signal),
      getChannels(communicationAbortController.signal),
      getDirectMessages(communicationAbortController.signal),
    ]);
    organizations.value = availableOrganizations;
    channels.value = availableChannels.map((channel) => channel.live_meeting && terminalMeetingIds.has(channel.live_meeting.id)
      ? { ...channel, live_meeting: null }
      : channel);
    directMessages.value = availableDirectMessages.map((directMessage) => directMessage.live_meeting && terminalMeetingIds.has(directMessage.live_meeting.id)
      ? { ...directMessage, live_meeting: null }
      : directMessage);
    realtimeController?.syncConversations([
      ...availableChannels.map((channel) => channel.id),
      ...availableDirectMessages.map((directMessage) => directMessage.id),
    ]);
    communicationStatus.value = "ready";
  } catch (error) {
    if (error instanceof DOMException && error.name === "AbortError") {
      return;
    }

    communicationStatus.value = "unavailable";
  }
}

async function loadAttention() {
  attentionStatus.value = "loading";

  try {
    attentionItems.value = await getAttention(communicationAbortController.signal);
    attentionStatus.value = "ready";
  } catch (error) {
    if (error instanceof DOMException && error.name === "AbortError") return;
    attentionStatus.value = "unavailable";
  }
}

async function loadMeetings() {
  meetingStatus.value = "loading";

  try {
    meetings.value = await getMeetings(communicationAbortController.signal);
    meetingStatus.value = "ready";
  } catch (error) {
    if (error instanceof DOMException && error.name === "AbortError") return;
    meetingStatus.value = "unavailable";
  }
}

async function loadMemberAdministrationScopes() {
  memberAdministrationStatus.value = "loading";

  try {
    memberAdministrationScopes.value = await getMemberAdministrationScopes(
      communicationAbortController.signal,
    );
    memberAdministrationStatus.value = "ready";
  } catch (error) {
    if (error instanceof DOMException && error.name === "AbortError") return;
    memberAdministrationScopes.value = [];
    memberAdministrationStatus.value = "unavailable";
  }
}

function applyMessageCreated(event: ConversationRealtimeEvent) {
  realtimeEvent.value = event;

  if (event.type !== "message-created") return;

  const update = <T extends CommunicationChannel | CommunicationDirectMessage>(conversation: T): T => {
    if (conversation.id !== event.conversation_id || event.sequence <= conversation.latest_sequence) {
      return conversation;
    }

    const active = activeConversationId.value === conversation.id;
    const latestSequence = event.sequence;

    return {
      ...conversation,
      latest_sequence: latestSequence,
      unread_count: conversation.last_read_sequence === null
        ? null
        : active
          ? conversation.unread_count
          : Math.max(0, latestSequence - conversation.last_read_sequence),
      ...(conversation.id === event.conversation_id && "mention_count" in conversation
        ? {
            mention_count: active
              ? conversation.mention_count
              : conversation.mention_count + Number(event.mentioned_user_ids.includes(authSession.user.value?.id ?? "")),
          }
        : {}),
    };
  };

  channels.value = channels.value.map(update);
  directMessages.value = directMessages.value.map(update);
}

function startRealtime() {
  const user = authSession.user.value;
  if (!user || realtimeController) return;

  realtimeController = startCommunicationRealtime({
    userId: user.id,
    onConversationEvent: applyMessageCreated,
    onReadState: updateReadState,
    onAttentionChange: () => {
      void loadAttention();
    },
    onConversationAccessChange: () => {
      void loadCommunication();
    },
    onMeetingAccessChange: () => {
      void loadMeetings();
    },
    onMeetingStateChange: (event) => {
      void handleMeetingStateChange(event);
    },
    onMeetingOutcomeChange: () => {
      void loadMeetings();
    },
    onReconnect: () => {
      realtimeReconnectGeneration.value += 1;
      void loadCommunication();
      void loadAttention();
      void loadMeetings();
    },
    onStatusChange: (status) => {
      realtimeStatus.value = status;
    },
  });
}

function clearTerminalConversationMeeting(event: MeetingStateChangedEvent): void {
  if (!event.conversation_id || !["completed", "cancelled"].includes(event.status)) return;

  terminalMeetingIds.add(event.meeting_id);
  if (terminalMeetingIds.size > 200) {
    const oldestMeetingId = terminalMeetingIds.values().next().value;
    if (oldestMeetingId) terminalMeetingIds.delete(oldestMeetingId);
  }

  channels.value = channels.value.map((channel) => channel.id === event.conversation_id
    ? {
        ...channel,
        live_meeting: channel.live_meeting?.id === event.meeting_id ? null : channel.live_meeting,
      }
    : channel);
  directMessages.value = directMessages.value.map((directMessage) => directMessage.id === event.conversation_id
    ? {
        ...directMessage,
        live_meeting: directMessage.live_meeting?.id === event.meeting_id ? null : directMessage.live_meeting,
      }
    : directMessage);
}

function showChannelMeetingNotification(channel: CommunicationChannel): void {
  const meeting = channel.live_meeting;
  const currentUser = authSession.user.value;

  if (
    !meeting
    || channel.membership === null
    || !currentUser
    || meeting.organizer.id === currentUser.id
    || meetingNotificationPermission.value !== "granted"
    || (document.visibilityState === "visible" && activeConversationId.value === channel.id)
  ) {
    return;
  }

  try {
    const notification = new Notification(`Meeting started in #${channel.name}`, {
      body: `${meeting.organizer.name} started ${meeting.title}. Select to open the Channel and join.`,
      icon: "/brand/icon.svg",
      tag: `katra-channel-meeting-${meeting.id}`,
    });
    notification.onclick = () => {
      window.focus();
      activeDestination.value = `channel-${channel.id}`;
      notification.close();
    };
  } catch {
    // Desktop notification delivery is best-effort; the in-app indicators remain authoritative.
  }
}

async function handleMeetingStateChange(event: MeetingStateChangedEvent): Promise<void> {
  clearTerminalConversationMeeting(event);
  await Promise.all([loadCommunication(), loadMeetings()]);

  if (event.status !== "live" || !event.conversation_id) return;

  const channel = channels.value.find((candidate) => candidate.id === event.conversation_id);
  if (channel?.live_meeting?.id === event.meeting_id) showChannelMeetingNotification(channel);
}

async function enableMeetingNotifications(): Promise<void> {
  if (meetingNotificationRequesting.value) return;

  meetingNotificationRequesting.value = true;
  meetingNotificationFeedback.value = null;

  const result = await requestMeetingNotificationPermission(
    typeof Notification === "undefined" ? undefined : Notification,
  );
  meetingNotificationPermission.value = result.permission;

  if (result.issue === "blocked") {
    meetingNotificationFeedback.value = `Meeting alerts are blocked. Allow notifications for ${meetingNotificationSiteLabel} in your browser's site settings.`;
  } else if (result.issue === "unavailable") {
    meetingNotificationFeedback.value = `Your browser did not open a permission request. Allow notifications for ${meetingNotificationSiteLabel} in its site settings.`;
  }

  meetingNotificationRequesting.value = false;
}

function dismissMeetingNotificationPrompt(): void {
  meetingNotificationFeedback.value = null;
  meetingNotificationPromptDismissed.value = true;
}

async function viewAttention(attentionId: string) {
  const item = attentionItems.value.find((candidate) => candidate.id === attentionId);
  if (!item || item.viewed_at) return;

  try {
    const updated = await markAttentionViewed(attentionId);
    attentionItems.value = attentionItems.value.map((candidate) => candidate.id === updated.id ? updated : candidate);
  } catch {
    await loadAttention();
  }
}

async function completeAttention(attentionId: string) {
  try {
    await resolveAttention(attentionId);
    attentionItems.value = attentionItems.value.filter((candidate) => candidate.id !== attentionId);
    await loadMeetings();
  } catch {
    await loadAttention();
  }
}

async function openAttentionDestination(item: CommunicationAttentionItem) {
  if (item.destination.type === "meeting" && item.destination.meeting_id) {
    if (!meetings.value.some((meeting) => meeting.id === item.destination.meeting_id)) {
      try {
        addMeeting(await getMeeting(item.destination.meeting_id));
      } catch {
        return;
      }
    }

    conversationFocusRequest.value = null;
    meetingFocusRequest.value = {
      meetingId: item.destination.meeting_id,
      nonce: (meetingFocusRequest.value?.nonce ?? 0) + 1,
    };
    activeDestination.value = "inbox";
    return;
  }

  if (!item.destination.conversation_id) return;
  if (item.destination.message_id) {
    conversationFocusRequest.value = {
      conversationId: item.destination.conversation_id,
      messageId: item.destination.message_id,
      threadRootMessageId: item.destination.thread_root_message_id,
      nonce: (conversationFocusRequest.value?.nonce ?? 0) + 1,
    };
  } else {
    conversationFocusRequest.value = null;
  }
  activeDestination.value = `${item.destination.type === "channel" ? "channel" : "dm"}-${item.destination.conversation_id}`;
}

function openSearchResult(selection: GlobalSearchSelection) {
  if (selection.focus) {
    conversationFocusRequest.value = {
      ...selection.focus,
      nonce: (conversationFocusRequest.value?.nonce ?? 0) + 1,
    };
  } else {
    conversationFocusRequest.value = null;
  }

  activeDestination.value = selection.destinationId;
}

function navigateTo(destination: string) {
  if (destination === "server-settings" && !showServerSettings.value) return;
  conversationFocusRequest.value = null;
  activeDestination.value = destination;
}

function updateChannel(updated: CommunicationChannel) {
  channels.value = channels.value.map((channel) => channel.id === updated.id ? updated : channel);
}

function addChannel(created: CommunicationChannel) {
  channels.value = [...channels.value, created];
  activeDestination.value = `channel-${created.id}`;
}

function removeChannel(channelId: string) {
  channels.value = channels.value.filter((channel) => channel.id !== channelId);
  realtimeController?.syncConversations([
    ...channels.value.map((channel) => channel.id),
    ...directMessages.value.map((directMessage) => directMessage.id),
  ]);

  if (activeDestination.value === `channel-${channelId}`) {
    activeDestination.value = "inbox";
  }
}

function updateDirectMessage(updated: CommunicationDirectMessage) {
  directMessages.value = directMessages.value.map(
    (directMessage) => directMessage.id === updated.id ? updated : directMessage,
  );
}

function addDirectMessage(created: CommunicationDirectMessage) {
  const exists = directMessages.value.some((directMessage) => directMessage.id === created.id);
  directMessages.value = exists
    ? directMessages.value.map((directMessage) => directMessage.id === created.id ? created : directMessage)
    : [...directMessages.value, created];
  realtimeController?.syncConversations([
    ...channels.value.map((channel) => channel.id),
    ...directMessages.value.map((directMessage) => directMessage.id),
  ]);
  activeDestination.value = `dm-${created.id}`;
}

function addMeeting(created: CommunicationMeeting) {
  const existing = meetings.value.some((meeting) => meeting.id === created.id);
  meetings.value = (existing
    ? meetings.value.map((meeting) => meeting.id === created.id ? created : meeting)
    : [...meetings.value, created])
    .sort((left, right) => Date.parse(left.starts_at) - Date.parse(right.starts_at));
  meetingStatus.value = "ready";
}

function updateReadState(updated: CommunicationReadState) {
  channels.value = channels.value.map((channel) => channel.id === updated.conversation_id
    ? {
        ...channel,
        latest_sequence: updated.latest_sequence,
        last_read_sequence: updated.last_read_sequence,
        unread_count: updated.unread_count,
        mention_count: updated.mention_count,
        membership: channel.membership
          ? { ...channel.membership, last_read_sequence: updated.last_read_sequence }
          : null,
      }
    : channel);
  directMessages.value = directMessages.value.map((directMessage) =>
    directMessage.id === updated.conversation_id
      ? {
          ...directMessage,
          latest_sequence: updated.latest_sequence,
          last_read_sequence: updated.last_read_sequence,
          unread_count: updated.unread_count,
        }
      : directMessage,
  );
}

function updateSidebarWidth(width: number) {
  sidebarWidth.value = Math.min(SIDEBAR_MAX_WIDTH, Math.max(SIDEBAR_MIN_WIDTH, width));
}

function handleScrollableActivity(event: Event) {
  const target = event.target;

  if (!(target instanceof HTMLElement)) {
    return;
  }

  target.classList.add("is-scroll-active");
  const existingTimer = scrollIdleTimers.get(target);

  if (existingTimer) {
    window.clearTimeout(existingTimer);
  }

  scrollIdleTimers.set(
    target,
    window.setTimeout(() => {
      target.classList.remove("is-scroll-active");
      scrollIdleTimers.delete(target);
    }, 800),
  );
}

async function handleLogout() {
  try {
    await signOut();
    await router.replace({ name: "login" });
  } catch {
    // Keep the authenticated workspace mounted when the Server cannot confirm logout.
  }
}

function handleUserUpdated(user: AuthUser) {
  adoptAuthenticatedUser(user);
}

async function handleOrganizationsUpdated() {
  await Promise.all([loadMemberAdministrationScopes(), loadCommunication()]);
}

watch(
  [activeDestination, memberAdministrationStatus, showServerSettings],
  ([destination, status, canManagePeople]) => {
    if (destination === "server-settings" && status !== "loading" && !canManagePeople) {
      activeDestination.value = "profile";
    }
  },
  { immediate: true },
);

onMounted(() => {
  document.addEventListener("scroll", handleScrollableActivity, true);
  void checkServerConnection();
  startRealtime();
  void loadCommunication();
  void loadAttention();
  void loadMeetings();
  void loadMemberAdministrationScopes();
});

onBeforeUnmount(() => {
  document.removeEventListener("scroll", handleScrollableActivity, true);
  serverConnectionAbortController.abort();
  communicationAbortController.abort();
  realtimeController?.stop();
  realtimeController = null;
});
</script>

<template>
  <div
    v-if="authSession.user.value"
    class="app-shell"
    :class="{
      'sidebar-is-open': sidebarOpen,
      'sidebar-is-resizing': sidebarResizing,
    }"
    :data-server-connection="serverConnection"
    :data-realtime-connection="realtimeStatus"
    :style="appShellStyle"
  >
    <p class="sr-only" role="status" aria-live="polite">{{ serverConnectionMessage }}</p>
    <AppSidebar
      :active-destination="activeDestination"
      :open="sidebarOpen"
      :width="sidebarWidth"
      :min-width="SIDEBAR_MIN_WIDTH"
      :max-width="SIDEBAR_MAX_WIDTH"
      :user="authSession.user.value"
      :channels="channels"
      :direct-messages="directMessages"
      :organizations="organizations"
      :operating-organization-id="organizations.find((organization) => organization.kind === 'operating')?.id ?? null"
      :communication-status="communicationStatus"
      :attention-count="attentionItems.length"
      :show-server-settings="showServerSettings"
      @close="sidebarOpen = false"
      @logout="handleLogout"
      @navigate="navigateTo"
      @channel-created="addChannel"
      @channel-updated="updateChannel"
      @direct-message-updated="updateDirectMessage"
      @direct-message-created="addDirectMessage"
      @open-search-result="openSearchResult"
      @resize="updateSidebarWidth"
      @resize-start="sidebarResizing = true"
      @resize-end="sidebarResizing = false"
    />

    <section class="workspace-shell" aria-label="Katra workspace">
      <WorkspaceCanvas
        :active-destination="activeDestination"
        :channels="channels"
        :direct-messages="directMessages"
        :current-user="authSession.user.value"
        :communication-status="communicationStatus"
        :attention-items="attentionItems"
        :attention-status="attentionStatus"
        :meetings="meetings"
        :meeting-status="meetingStatus"
        :realtime-event="realtimeEvent"
        :realtime-reconnect-generation="realtimeReconnectGeneration"
        :conversation-focus-request="conversationFocusRequest"
        :meeting-focus-request="meetingFocusRequest"
        :member-administration-scopes="memberAdministrationScopes"
        :member-administration-status="memberAdministrationStatus"
        @open-navigation="sidebarOpen = true"
        @channel-updated="updateChannel"
        @channel-left="removeChannel"
        @direct-message-updated="updateDirectMessage"
        @read-state-updated="updateReadState"
        @attention-viewed="viewAttention"
        @attention-resolved="completeAttention"
        @open-attention-destination="openAttentionDestination"
        @meeting-created="addMeeting"
        @user-updated="handleUserUpdated"
        @organizations-updated="handleOrganizationsUpdated"
      />
    </section>

    <aside v-if="showMeetingNotificationPrompt" class="meeting-notification-prompt" aria-labelledby="meeting-notification-title">
      <span class="meeting-notification-icon" aria-hidden="true"><BellRing :size="18" :stroke-width="1.8" /></span>
      <span>
        <strong id="meeting-notification-title">Meeting alerts</strong>
        <small role="status" aria-live="polite">{{ meetingNotificationFeedback ?? "Get a desktop alert when a meeting starts in one of your Channels." }}</small>
      </span>
      <button
        v-if="meetingNotificationPermission === 'default' && meetingNotificationFeedback === null"
        class="meeting-notification-enable"
        type="button"
        :disabled="meetingNotificationRequesting"
        @click="enableMeetingNotifications"
      >
        {{ meetingNotificationRequesting ? "Enabling…" : "Enable" }}
      </button>
      <button class="meeting-notification-dismiss" type="button" aria-label="Dismiss meeting notification prompt" @click="dismissMeetingNotificationPrompt"><X :size="15" aria-hidden="true" /></button>
    </aside>

    <button
      v-if="sidebarOpen"
      class="sidebar-scrim"
      type="button"
      aria-label="Close navigation"
      @click="sidebarOpen = false"
    />
  </div>
</template>
