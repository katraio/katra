<script setup lang="ts">
import {
  AtSign,
  CalendarClock,
  ChevronDown,
  CircleAlert,
  CircleCheckBig,
  Hash,
  Headphones,
  Heart,
  LockKeyhole,
  MessageCircle,
  Pencil,
  RefreshCw,
  SendHorizontal,
  SmilePlus,
  ThumbsUp,
  Trash2,
  UsersRound,
  X,
} from "@lucide/vue";
import { computed, nextTick, onBeforeUnmount, ref, watch } from "vue";
import type { AuthUser } from "../../api/auth";
import {
  CommunicationRequestError,
  startOrJoinConversationMeeting,
  advanceConversationReadPosition,
  completeDirectMessage,
  deleteConversationMessage,
  getConversationMessages,
  getMentionableUsers,
  joinChannel,
  reopenDirectMessage,
  requestDirectMessageContinuation,
  setMessageReaction,
  sendConversationMessage,
  updateConversationMessage,
  type CommunicationChannel,
  type CommunicationDirectMessage,
  type CommunicationMeeting,
  type CommunicationReadState,
  type ConversationLiveMeeting,
  type ConversationReaction,
  type ConversationFocusRequest,
  type ConversationMessage,
  type MessagePageMeta,
  type MentionableUser,
} from "../../api/communication";
import { isFiniteNumber, useUiPreference } from "../../composables/useUiPreference";
import type { ConversationRealtimeEvent } from "../../realtime/communicationRealtime";
import ChannelMembersDialog from "../channels/ChannelMembersDialog.vue";
import HuddleMeeting from "../meetings/HuddleMeeting.vue";
import MeetingScheduleDialog, { type MeetingParticipant } from "../meetings/MeetingScheduleDialog.vue";
import MarkdownMessage from "./MarkdownMessage.vue";

type SendAttempt = {
  body: string;
  parentMessageId: string | null;
  mentionUserIds: string[];
  attentionUserIds: string[];
  idempotencyKey: string;
};

type MentionComposer = "root" | "thread";
type TargetKind = "mention" | "attention";
type MessageSegment = { text: string; kind: "plain" | TargetKind };
type ComposerTargetMatch = {
  index: number;
  length: number;
  userId: string;
  kind: TargetKind;
};

const props = defineProps<{
  kind: "channel" | "direct-message";
  channel?: CommunicationChannel;
  directMessage?: CommunicationDirectMessage;
  currentUser: AuthUser;
  realtimeEvent: ConversationRealtimeEvent | null;
  realtimeReconnectGeneration: number;
  focusRequest?: ConversationFocusRequest | null;
}>();

const emit = defineEmits<{
  "channel-updated": [channel: CommunicationChannel];
  "direct-message-updated": [directMessage: CommunicationDirectMessage];
  "read-state-updated": [readState: CommunicationReadState];
  "channel-left": [channelId: string];
  "meeting-created": [meeting: CommunicationMeeting];
}>();

const messages = ref<ConversationMessage[]>([]);
const pageMeta = ref<MessagePageMeta | null>(null);
const loading = ref(true);
const loadingOlder = ref(false);
const loadError = ref("");
const sendError = ref("");
const lifecycleError = ref("");
const interactionError = ref("");
const draft = ref("");
const threadDraft = ref("");
const draftTextarea = ref<HTMLTextAreaElement | null>(null);
const threadTextarea = ref<HTMLTextAreaElement | null>(null);
const membersButton = ref<HTMLButtonElement | null>(null);
const mentionableUsers = ref<MentionableUser[]>([]);
const rootMentionUserIds = ref<string[]>([]);
const threadMentionUserIds = ref<string[]>([]);
const rootAttentionUserIds = ref<string[]>([]);
const threadAttentionUserIds = ref<string[]>([]);
const rootComposerScrollTop = ref(0);
const threadComposerScrollTop = ref(0);
const activeMentionComposer = ref<MentionComposer | null>(null);
const activeTargetKind = ref<TargetKind>("mention");
const mentionQuery = ref("");
const activeMentionIndex = ref(0);
const sending = ref(false);
const joining = ref(false);
const changingLifecycle = ref(false);
const membersDialogOpen = ref(false);
const meetingMenuOpen = ref(false);
const meetingSchedulerOpen = ref(false);
const meetingRoomOpen = ref(false);
const meetingRoomVisible = ref(false);
const activeMeeting = ref<CommunicationMeeting | null>(null);
const localConversationLiveMeeting = ref<ConversationLiveMeeting | null>(null);
const locallyClosedMeetingId = ref<string | null>(null);
const startingMeeting = ref(false);
const meetingScheduleNotice = ref("");
const reactionPickerMessageId = ref<string | null>(null);
const reactionPending = ref<Record<string, boolean>>({});
const editingMessageId = ref<string | null>(null);
const editDraft = ref("");
const deleteConfirmMessageId = ref<string | null>(null);
const messageMutationPending = ref<string | null>(null);
const focusedMessageId = ref<string | null>(null);
const messageList = ref<HTMLElement | null>(null);
const threadList = ref<HTMLElement | null>(null);
const conversationPage = ref<HTMLElement | null>(null);
const pendingRootAttempt = ref<SendAttempt | null>(null);
const pendingThreadAttempt = ref<SendAttempt | null>(null);
const threadWidth = useUiPreference(
  "live-conversation-thread-width",
  390,
  (value): value is number => isFiniteNumber(value) && value >= 280 && value <= 780,
);
const threadResizing = ref(false);
const openThreadIds = useUiPreference<Record<string, string>>(
  "open-conversation-threads",
  {},
  (value): value is Record<string, string> => typeof value === "object"
    && value !== null
    && !Array.isArray(value)
    && Object.values(value).every((threadId) => typeof threadId === "string"),
  "session",
);
let loadAbortController: AbortController | null = null;
let mentionAbortController: AbortController | null = null;
let threadResizeHandle: HTMLElement | null = null;
let threadResizePointerId: number | null = null;
let realtimeRecovery = Promise.resolve();
let focusHighlightTimer: number | null = null;

async function closeMembersDialog(): Promise<void> {
  membersDialogOpen.value = false;
  await nextTick();
  membersButton.value?.focus();
}

function handleChannelLeft(): void {
  membersDialogOpen.value = false;
  if (props.channel) emit("channel-left", props.channel.id);
}

const conversationId = computed(() =>
  props.kind === "channel" ? props.channel?.id ?? "" : props.directMessage?.id ?? "",
);
const selectedThreadId = computed<string | null>({
  get: () => openThreadIds.value[conversationId.value] ?? null,
  set: (threadId) => {
    const next = { ...openThreadIds.value };

    if (threadId) {
      next[conversationId.value] = threadId;
    } else {
      delete next[conversationId.value];
    }

    openThreadIds.value = next;
  },
});

const roots = computed(() => messages.value.filter((message) => message.parent_message_id === null));
const selectedThread = computed(() =>
  roots.value.find((message) => message.id === selectedThreadId.value) ?? null,
);
const threadReplies = computed(() => {
  if (!selectedThread.value) {
    return [];
  }

  return messages.value.filter((message) => message.parent_message_id === selectedThread.value?.id);
});
const channelIsArchived = computed(() => Boolean(props.channel?.archived_at));
const shouldJoinPublicChannel = computed(() =>
  props.kind === "channel"
  && props.channel?.visibility === "public"
  && props.channel.membership === null
  && !channelIsArchived.value,
);
const conversationTitle = computed(() => {
  if (props.kind === "channel") {
    return props.channel?.name ?? "Channel";
  }

  const names = props.directMessage?.participants
    .filter((participant) => participant.id !== props.currentUser.id)
    .map((participant) => participant.name) ?? [];

  return names.length > 0 ? names.join(", ") : "Direct Message";
});
const conversationSubtitle = computed(() => {
  if (props.kind === "channel") {
    if (channelIsArchived.value) {
      return "Archived read-only history";
    }

    if (props.channel?.visibility === "client-team") {
      return "Client team channel";
    }

    return props.channel?.visibility === "private" ? "Private internal channel" : "Internal channel";
  }

  const count = props.directMessage?.participants.length ?? 0;
  return `${count} ${count === 1 ? "participant" : "participants"}`;
});
const meetingOrganizationId = computed(() => props.channel?.organization_id ?? props.directMessage?.organization_id ?? "");
const meetingAudienceLabel = computed(() => props.kind === "channel"
  ? `#${conversationTitle.value}`
  : `Direct message with ${conversationTitle.value}`);
const resourceLiveMeeting = computed(() => props.kind === "channel"
  ? props.channel?.live_meeting ?? null
  : props.directMessage?.live_meeting ?? null);
const conversationLiveMeeting = computed(() => {
  const resourceMeeting = resourceLiveMeeting.value;

  if (resourceMeeting && resourceMeeting.id !== locallyClosedMeetingId.value) {
    return resourceMeeting;
  }

  return localConversationLiveMeeting.value;
});
const meetingMainActionLabel = computed(() => conversationLiveMeeting.value
  ? "Join the live meeting in this conversation"
  : "Start a meeting with this conversation");
const meetingMenuActionTitle = computed(() => conversationLiveMeeting.value
  ? "Join meeting"
  : "Start meeting now");
const meetingMenuActionDescription = computed(() => conversationLiveMeeting.value
  ? `${conversationLiveMeeting.value.organizer.name} started this room`
  : "Open one shared room for this conversation");
const meetingParticipants = computed<MeetingParticipant[]>(() => {
  if (props.kind === "direct-message" && props.directMessage) {
    return props.directMessage.participants.map((participant) => ({
      id: participant.id,
      name: participant.name,
      role: "Direct Message participant",
      avatar: avatarForParticipant(participant.name),
    }));
  }

  return [{
    id: props.currentUser.id,
    name: props.currentUser.name,
    role: "Meeting organizer",
    avatar: avatarForParticipant(props.currentUser.name),
  }];
});
const lifecycleLabel = computed(() => {
  if (props.kind !== "direct-message") {
    return "";
  }

  if (props.directMessage?.state === "continuation-requested") {
    return "Continuation requested";
  }

  return props.directMessage?.state === "completed" ? "Complete" : "Open";
});
const composerDisabled = computed(() => channelIsArchived.value || shouldJoinPublicChannel.value || sending.value);
const reactionDisabled = computed(() => channelIsArchived.value || shouldJoinPublicChannel.value);
const tracksReadState = computed(() => props.kind === "channel"
  ? props.channel?.last_read_sequence !== null && props.channel?.last_read_sequence !== undefined
  : props.directMessage?.last_read_sequence !== null && props.directMessage?.last_read_sequence !== undefined);
const pageStyle = computed(() => ({ "--live-thread-width": `${threadWidth.value}px` }));
const filteredMentionableUsers = computed(() => {
  const query = mentionQuery.value.trim().toLocaleLowerCase();

  return mentionableUsers.value
    .filter((user) => !query || user.name.toLocaleLowerCase().includes(query))
    .slice(0, 8);
});

function avatarForParticipant(name: string): string {
  const key = name.toLowerCase().split(/\s+/)[0];
  return ["artisan", "atlas", "envoy", "katra", "sentinel", "vector"].includes(key)
    ? `/avatars/${key}.png`
    : "/brand/icon.svg";
}

function openMeetingScheduler(): void {
  meetingMenuOpen.value = false;
  meetingSchedulerOpen.value = true;
}

async function startMeetingNow(): Promise<void> {
  if (startingMeeting.value || !meetingOrganizationId.value) return;
  meetingMenuOpen.value = false;
  startingMeeting.value = true;

  try {
    const meeting = await startOrJoinConversationMeeting(
      conversationId.value,
      `${conversationTitle.value} meeting`,
    );
    activeMeeting.value = meeting;
    localConversationLiveMeeting.value = {
      id: meeting.id,
      title: meeting.title,
      status: "live",
      started_at: meeting.started_at,
      organizer: meeting.organizer,
    };
    locallyClosedMeetingId.value = null;
    meetingRoomOpen.value = true;
    meetingRoomVisible.value = true;
    emit("meeting-created", meeting);
  } catch (error) {
    meetingScheduleNotice.value = readableError(error, "The meeting could not be started. Please try again.");
  } finally {
    startingMeeting.value = false;
  }
}

function handleMeetingUpdated(meeting: CommunicationMeeting): void {
  activeMeeting.value = meeting;
  if (meeting.status === "live" && meeting.conversation_id === conversationId.value) {
    localConversationLiveMeeting.value = {
      id: meeting.id,
      title: meeting.title,
      status: "live",
      started_at: meeting.started_at,
      organizer: meeting.organizer,
    };
    locallyClosedMeetingId.value = null;
  } else {
    localConversationLiveMeeting.value = null;
    locallyClosedMeetingId.value = meeting.id;
  }
  emit("meeting-created", meeting);
}

function handleMeetingScheduled(message: string, meeting?: CommunicationMeeting): void {
  meetingSchedulerOpen.value = false;
  meetingScheduleNotice.value = message;
  if (meeting) emit("meeting-created", meeting);
  window.setTimeout(() => {
    if (meetingScheduleNotice.value === message) meetingScheduleNotice.value = "";
  }, 3200);
}
const activeMentionOptionId = computed(() => {
  const composer = activeMentionComposer.value;
  const user = filteredMentionableUsers.value[activeMentionIndex.value];

  return composer && user ? mentionOptionId(composer, activeTargetKind.value, user.id) : undefined;
});
const activePickerLabel = computed(() => activeTargetKind.value === "attention"
  ? "Request someone's attention"
  : "Mention someone");
const supportedReactions: {
  kind: ConversationReaction["kind"];
  label: string;
  icon: typeof ThumbsUp;
}[] = [
  { kind: "approve", label: "Approve", icon: ThumbsUp },
  { kind: "support", label: "Support", icon: Heart },
  { kind: "done", label: "Done", icon: CircleCheckBig },
];
const reactionLabels: Record<ConversationReaction["kind"], string> = {
  approve: "Approve",
  support: "Support",
  done: "Done",
};
const reactionIcons: Record<ConversationReaction["kind"], typeof ThumbsUp> = {
  approve: ThumbsUp,
  support: Heart,
  done: CircleCheckBig,
};

function readableError(error: unknown, fallback = "Katra Server is unavailable. Please try again."): string {
  if (error instanceof CommunicationRequestError) {
    const firstFieldError = Object.values(error.fields).flat()[0];
    return firstFieldError ?? error.message;
  }

  if (error instanceof DOMException && error.name === "AbortError") {
    return "";
  }

  return fallback;
}

function formatTime(value: string): string {
  const date = new Date(value);

  if (Number.isNaN(date.getTime())) {
    return "";
  }

  return new Intl.DateTimeFormat(undefined, { hour: "numeric", minute: "2-digit" }).format(date);
}

function initials(name: string): string {
  return name
    .split(/\s+/)
    .filter(Boolean)
    .slice(0, 2)
    .map((part) => part[0]?.toUpperCase() ?? "")
    .join("");
}

function repliesFor(messageId: string): ConversationMessage[] {
  return messages.value.filter((message) => message.parent_message_id === messageId);
}

function mentionsCurrentUser(message: ConversationMessage): boolean {
  return message.mention_user_ids.includes(props.currentUser.id);
}

function requestsAttentionCurrentUser(message: ConversationMessage): boolean {
  return message.attention_user_ids.includes(props.currentUser.id);
}

function threadRequestsAttentionCurrentUser(message: ConversationMessage): boolean {
  return requestsAttentionCurrentUser(message)
    || repliesFor(message.id).some((reply) => requestsAttentionCurrentUser(reply));
}

function rootMentionsCurrentUser(message: ConversationMessage): boolean {
  return mentionsCurrentUser(message) && !threadRequestsAttentionCurrentUser(message);
}

function rootAttentionLabel(message: ConversationMessage): string | undefined {
  if (requestsAttentionCurrentUser(message)) {
    return `${message.author.name} requested your attention`;
  }

  if (repliesFor(message.id).some((reply) => requestsAttentionCurrentUser(reply))) {
    return `${message.author.name}'s thread contains an attention request for you`;
  }

  return mentionsCurrentUser(message) ? `${message.author.name} mentioned you` : undefined;
}

function isAttentionFocus(messageId: string): boolean {
  return focusedMessageId.value === messageId
    || (
      props.focusRequest?.conversationId === conversationId.value
      && props.focusRequest.messageId === messageId
    );
}

function composerTargetMatches(composer: MentionComposer): ComposerTargetMatch[] {
  const body = activeDraft(composer);
  const targets = [
    ...selectedMentionIds(composer).map((id) => ({ id, kind: "mention" as const })),
    ...selectedAttentionIds(composer).map((id) => ({ id, kind: "attention" as const })),
  ];

  return targets
    .flatMap((target) => {
      const user = mentionableUsers.value.find((candidate) => candidate.id === target.id);
      if (!user) return [];

      const token = `${target.kind === "attention" ? "!!" : "@"}${user.name}`;
      const index = body.indexOf(token);
      return index < 0 ? [] : [{ index, length: token.length, userId: user.id, kind: target.kind }];
    })
    .sort((left, right) => left.index - right.index);
}

function composerSegments(composer: MentionComposer): MessageSegment[] {
  const body = activeDraft(composer);
  const matches = composerTargetMatches(composer);
  const segments: MessageSegment[] = [];
  let cursor = 0;

  for (const match of matches) {
    if (match.index < cursor) continue;
    if (match.index > cursor) segments.push({ text: body.slice(cursor, match.index), kind: "plain" });
    segments.push({ text: body.slice(match.index, match.index + match.length), kind: match.kind });
    cursor = match.index + match.length;
  }

  if (cursor < body.length) segments.push({ text: body.slice(cursor), kind: "plain" });

  return segments.length > 0 ? segments : [{ text: body, kind: "plain" }];
}

function composerMirrorStyle(composer: MentionComposer): Record<string, string> {
  const scrollTop = composer === "root" ? rootComposerScrollTop.value : threadComposerScrollTop.value;
  return { transform: `translateY(-${scrollTop}px)` };
}

function handleComposerScroll(composer: MentionComposer, event: Event): void {
  const scrollTop = (event.currentTarget as HTMLTextAreaElement).scrollTop;
  if (composer === "root") rootComposerScrollTop.value = scrollTop;
  else threadComposerScrollTop.value = scrollTop;
}

function selectedMentionIds(composer: MentionComposer): string[] {
  return composer === "root" ? rootMentionUserIds.value : threadMentionUserIds.value;
}

function updateSelectedMentionIds(composer: MentionComposer, ids: string[]): void {
  if (composer === "root") rootMentionUserIds.value = ids;
  else threadMentionUserIds.value = ids;
}

function selectedAttentionIds(composer: MentionComposer): string[] {
  return composer === "root" ? rootAttentionUserIds.value : threadAttentionUserIds.value;
}

function updateSelectedAttentionIds(composer: MentionComposer, ids: string[]): void {
  if (composer === "root") rootAttentionUserIds.value = ids;
  else threadAttentionUserIds.value = ids;
}

function activeTextarea(composer: MentionComposer): HTMLTextAreaElement | null {
  return composer === "root" ? draftTextarea.value : threadTextarea.value;
}

function activeDraft(composer: MentionComposer): string {
  return composer === "root" ? draft.value : threadDraft.value;
}

function updateDraft(composer: MentionComposer, value: string): void {
  if (composer === "root") draft.value = value;
  else threadDraft.value = value;
}

function mentionListboxId(composer: MentionComposer): string {
  return `live-${composer}-mention-listbox`;
}

function mentionOptionId(composer: MentionComposer, kind: TargetKind, userId: string): string {
  return `live-${composer}-${kind}-${userId}`;
}

function closeMentionPicker(): void {
  activeMentionComposer.value = null;
  mentionQuery.value = "";
  activeMentionIndex.value = 0;
}

async function scrollActiveMentionIntoView(): Promise<void> {
  await nextTick();
  if (!activeMentionOptionId.value) return;

  document.getElementById(activeMentionOptionId.value)?.scrollIntoView({ block: "nearest" });
}

function setActiveMentionIndex(index: number): void {
  const count = filteredMentionableUsers.value.length;
  activeMentionIndex.value = count === 0 ? 0 : Math.min(Math.max(index, 0), count - 1);
}

function moveActiveMention(delta: number): void {
  const count = filteredMentionableUsers.value.length;
  if (count === 0) return;

  activeMentionIndex.value = (activeMentionIndex.value + delta + count) % count;
  void scrollActiveMentionIntoView();
}

function pruneRemovedMentions(composer: MentionComposer, body: string): void {
  updateSelectedMentionIds(
    composer,
    selectedMentionIds(composer).filter((id) => {
      const user = mentionableUsers.value.find((candidate) => candidate.id === id);
      return user ? body.includes(`@${user.name}`) : false;
    }),
  );
  updateSelectedAttentionIds(
    composer,
    selectedAttentionIds(composer).filter((id) => {
      const user = mentionableUsers.value.find((candidate) => candidate.id === id);
      return user ? body.includes(`!!${user.name}`) : false;
    }),
  );
}

function updateMentionPicker(composer: MentionComposer, textarea: HTMLTextAreaElement): void {
  pruneRemovedMentions(composer, textarea.value);
  const beforeCursor = textarea.value.slice(0, textarea.selectionStart);
  const match = beforeCursor.match(/(?:^|\s)(@|!!)([^@!\n]*)$/);

  if (!match) {
    if (activeMentionComposer.value === composer) closeMentionPicker();
    return;
  }

  const kind: TargetKind = match[1] === "!!" ? "attention" : "mention";
  const query = match[2] ?? "";
  const selectedIds = kind === "attention"
    ? selectedAttentionIds(composer)
    : selectedMentionIds(composer);
  const followsCompletedTarget = selectedIds.some((id) => {
    const user = mentionableUsers.value.find((candidate) => candidate.id === id);
    return user ? query === user.name || query.startsWith(`${user.name} `) : false;
  });

  if (followsCompletedTarget) {
    if (activeMentionComposer.value === composer) closeMentionPicker();
    return;
  }

  activeMentionComposer.value = composer;
  activeTargetKind.value = kind;
  mentionQuery.value = query;
  activeMentionIndex.value = 0;
}

function handleComposerInput(composer: MentionComposer, event: Event): void {
  updateMentionPicker(composer, event.currentTarget as HTMLTextAreaElement);
}

function handleStructuredTargetBackspace(composer: MentionComposer, event: KeyboardEvent): boolean {
  if (
    event.key !== "Backspace"
    || event.isComposing
    || event.altKey
    || event.ctrlKey
    || event.metaKey
  ) {
    return false;
  }

  const textarea = activeTextarea(composer);
  if (!textarea || textarea.selectionStart !== textarea.selectionEnd) return false;

  const body = activeDraft(composer);
  const caret = textarea.selectionStart;
  const target = composerTargetMatches(composer).find((match) => {
    const tokenEnd = match.index + match.length;
    const deletionEnd = body[tokenEnd] === " " ? tokenEnd + 1 : tokenEnd;
    return caret > match.index && caret <= deletionEnd;
  });
  if (!target) return false;

  event.preventDefault();
  const tokenEnd = target.index + target.length;
  const deletionEnd = body[tokenEnd] === " " ? tokenEnd + 1 : tokenEnd;
  updateDraft(composer, `${body.slice(0, target.index)}${body.slice(deletionEnd)}`);

  if (target.kind === "attention") {
    updateSelectedAttentionIds(composer, selectedAttentionIds(composer).filter((id) => id !== target.userId));
  } else {
    updateSelectedMentionIds(composer, selectedMentionIds(composer).filter((id) => id !== target.userId));
  }
  if (activeMentionComposer.value === composer) closeMentionPicker();

  void nextTick(() => {
    textarea.focus();
    textarea.setSelectionRange(target.index, target.index);
  });
  return true;
}

async function openMentionPicker(composer: MentionComposer, kind: TargetKind = "mention"): Promise<void> {
  const textarea = activeTextarea(composer);
  if (!textarea) return;

  const start = textarea.selectionStart;
  const end = textarea.selectionEnd;
  const body = activeDraft(composer);
  const token = kind === "attention" ? "!!" : "@";
  const prefix = start === 0 || /\s/.test(body[start - 1] ?? "") ? token : ` ${token}`;
  updateDraft(composer, `${body.slice(0, start)}${prefix}${body.slice(end)}`);
  activeMentionComposer.value = composer;
  activeTargetKind.value = kind;
  mentionQuery.value = "";
  activeMentionIndex.value = 0;

  await nextTick();
  const caret = start + prefix.length;
  textarea.focus();
  textarea.setSelectionRange(caret, caret);
}

async function selectMention(user: MentionableUser): Promise<void> {
  const composer = activeMentionComposer.value;
  if (!composer) return;
  const textarea = activeTextarea(composer);
  if (!textarea) return;

  const body = activeDraft(composer);
  const caret = textarea.selectionStart;
  const beforeCursor = body.slice(0, caret);
  const prefix = activeTargetKind.value === "attention" ? "!!" : "@";
  const match = beforeCursor.match(/(?:^|\s)(@|!!)([^@!\n]*)$/);
  const atIndex = match ? beforeCursor.lastIndexOf(prefix) : caret;
  const token = `${prefix}${user.name} `;
  const nextBody = `${body.slice(0, atIndex)}${token}${body.slice(caret)}`;

  updateDraft(composer, nextBody);
  if (activeTargetKind.value === "attention") {
    updateSelectedAttentionIds(composer, [...new Set([...selectedAttentionIds(composer), user.id])]);
    updateSelectedMentionIds(composer, selectedMentionIds(composer).filter((id) => id !== user.id));
  } else if (!selectedAttentionIds(composer).includes(user.id)) {
    updateSelectedMentionIds(composer, [...new Set([...selectedMentionIds(composer), user.id])]);
  }
  closeMentionPicker();

  await nextTick();
  const nextCaret = atIndex + token.length;
  textarea.focus();
  textarea.setSelectionRange(nextCaret, nextCaret);
}

function handleMentionKeydown(composer: MentionComposer, event: KeyboardEvent): boolean {
  if (activeMentionComposer.value !== composer) return false;

  if (event.key === "ArrowDown") {
    event.preventDefault();
    moveActiveMention(1);
    return true;
  }

  if (event.key === "ArrowUp") {
    event.preventDefault();
    moveActiveMention(-1);
    return true;
  }

  if (event.key === "Home") {
    event.preventDefault();
    setActiveMentionIndex(0);
    void scrollActiveMentionIntoView();
    return true;
  }

  if (event.key === "End") {
    event.preventDefault();
    setActiveMentionIndex(filteredMentionableUsers.value.length - 1);
    void scrollActiveMentionIntoView();
    return true;
  }

  if (event.key === "Escape") {
    event.preventDefault();
    closeMentionPicker();
    return true;
  }

  if ((event.key === "Enter" || (event.key === "Tab" && !event.shiftKey))) {
    const user = filteredMentionableUsers.value[activeMentionIndex.value];
    if (!user) return false;

    event.preventDefault();
    void selectMention(user);
    return true;
  }

  return false;
}

async function loadMentionableUsers(): Promise<void> {
  mentionAbortController?.abort();
  mentionAbortController = new AbortController();

  try {
    mentionableUsers.value = await getMentionableUsers(conversationId.value, mentionAbortController.signal);
  } catch (error) {
    if (!(error instanceof DOMException && error.name === "AbortError")) mentionableUsers.value = [];
  }
}

function mergeMessages(incoming: ConversationMessage[]): void {
  const byId = new Map(messages.value.map((message) => [message.id, message]));

  for (const message of incoming) {
    byId.set(message.id, message);
  }

  messages.value = [...byId.values()].sort((left, right) => left.sequence - right.sequence);
}

function recordRealtimeLatestSequence(latestSequence: number): void {
  if (!pageMeta.value || latestSequence <= pageMeta.value.latest_sequence) return;

  pageMeta.value = {
    ...pageMeta.value,
    latest_sequence: latestSequence,
  };
}

async function recoverForwardMessages(): Promise<void> {
  let afterSequence = messages.value.reduce((latest, message) => Math.max(latest, message.sequence), 0);
  const shouldFollow = messageList.value
    ? messageList.value.scrollHeight - messageList.value.scrollTop - messageList.value.clientHeight < 96
    : true;

  while (true) {
    const page = await getConversationMessages(conversationId.value, {
      afterSequence,
      limit: 100,
    });
    recordRealtimeLatestSequence(page.meta.latest_sequence);

    if (page.data.length === 0) break;

    mergeMessages(page.data);
    afterSequence = page.data.at(-1)?.sequence ?? afterSequence;

    if (!page.meta.pagination.has_more) break;
  }

  await advanceReadPosition(pageMeta.value?.latest_sequence ?? afterSequence);
  await nextTick();

  if (shouldFollow) {
    messageList.value?.scrollTo({ top: messageList.value.scrollHeight, behavior: "smooth" });
    threadList.value?.scrollTo({ top: threadList.value.scrollHeight, behavior: "smooth" });
  }
}

async function recoverChangedMessage(
  event: Extract<ConversationRealtimeEvent, { type: "reaction-changed" | "message-changed" }>,
): Promise<void> {
  if (!messages.value.some((message) => message.id === event.message_id)) return;

  const page = await getConversationMessages(conversationId.value, {
    afterSequence: Math.max(0, event.message_sequence - 1),
    limit: 1,
  });
  const message = page.data.find((candidate) => candidate.id === event.message_id);

  if (message) mergeMessages([message]);
  recordRealtimeLatestSequence(page.meta.latest_sequence);
}

function beginMessageEdit(message: ConversationMessage): void {
  if (message.deleted_at || message.author.id !== props.currentUser.id) return;
  editingMessageId.value = message.id;
  editDraft.value = message.body ?? "";
  deleteConfirmMessageId.value = null;
  interactionError.value = "";
}

function cancelMessageEdit(): void {
  editingMessageId.value = null;
  editDraft.value = "";
}

async function saveMessageEdit(message: ConversationMessage): Promise<void> {
  if (messageMutationPending.value || editingMessageId.value !== message.id) return;
  messageMutationPending.value = message.id;
  interactionError.value = "";

  try {
    mergeMessages([await updateConversationMessage(conversationId.value, message.id, editDraft.value)]);
    cancelMessageEdit();
  } catch (error) {
    interactionError.value = readableError(error, "The message could not be edited. Please try again.");
  } finally {
    messageMutationPending.value = null;
  }
}

async function deleteMessage(message: ConversationMessage): Promise<void> {
  if (messageMutationPending.value || deleteConfirmMessageId.value !== message.id) return;
  messageMutationPending.value = message.id;
  interactionError.value = "";

  try {
    mergeMessages([await deleteConversationMessage(conversationId.value, message.id)]);
    deleteConfirmMessageId.value = null;
    if (editingMessageId.value === message.id) cancelMessageEdit();
  } catch (error) {
    interactionError.value = readableError(error, "The message could not be deleted. Please try again.");
  } finally {
    messageMutationPending.value = null;
  }
}

async function refreshLoadedConversation(): Promise<void> {
  if (messages.value.length === 0) {
    await loadLatest();
    return;
  }

  let afterSequence = Math.max(0, Math.min(...messages.value.map((message) => message.sequence)) - 1);

  while (true) {
    const page = await getConversationMessages(conversationId.value, {
      afterSequence,
      limit: 100,
    });
    recordRealtimeLatestSequence(page.meta.latest_sequence);

    if (page.data.length === 0) break;

    mergeMessages(page.data);
    afterSequence = page.data.at(-1)?.sequence ?? afterSequence;

    if (!page.meta.pagination.has_more) break;
  }

  await advanceReadPosition(pageMeta.value?.latest_sequence ?? afterSequence);
}

function queueRealtimeRecovery(recovery: () => Promise<void>): void {
  realtimeRecovery = realtimeRecovery
    .then(recovery, recovery)
    .catch((error) => {
      interactionError.value = readableError(error, "Realtime recovery could not complete. Please refresh the conversation.");
    });
}

async function advanceReadPosition(throughSequence: number): Promise<void> {
  if (!tracksReadState.value) {
    return;
  }

  try {
    emit("read-state-updated", await advanceConversationReadPosition(conversationId.value, throughSequence));
  } catch (error) {
    interactionError.value = readableError(error);
  }
}

function reactionFor(message: ConversationMessage, kind: ConversationReaction["kind"]): ConversationReaction | undefined {
  return message.reactions.find((reaction) => reaction.kind === kind);
}

async function toggleReaction(message: ConversationMessage, kind: ConversationReaction["kind"]): Promise<void> {
  const key = `${message.id}:${kind}`;

  if (reactionDisabled.value || reactionPending.value[key]) {
    return;
  }

  reactionPending.value = { ...reactionPending.value, [key]: true };
  reactionPickerMessageId.value = null;
  editingMessageId.value = null;
  editDraft.value = "";
  deleteConfirmMessageId.value = null;
  messageMutationPending.value = null;
  interactionError.value = "";

  try {
    const existing = reactionFor(message, kind);
    mergeMessages([
      await setMessageReaction(
        conversationId.value,
        message.id,
        kind,
        !existing?.reacted_by_current_user,
      ),
    ]);
  } catch (error) {
    interactionError.value = readableError(error);
  } finally {
    const next = { ...reactionPending.value };
    delete next[key];
    reactionPending.value = next;
  }
}

function toggleReactionPicker(messageId: string): void {
  reactionPickerMessageId.value = reactionPickerMessageId.value === messageId ? null : messageId;
}

async function loadLatest(): Promise<void> {
  loadAbortController?.abort();
  loadAbortController = new AbortController();
  loading.value = true;
  loadError.value = "";

  try {
    const page = await getConversationMessages(conversationId.value, {
      limit: 100,
      signal: loadAbortController.signal,
    });
    messages.value = [...page.data].sort((left, right) => left.sequence - right.sequence);
    pageMeta.value = page.meta;
    const requestedMessageId = props.focusRequest?.conversationId === conversationId.value
      ? props.focusRequest.messageId
      : null;

    while (
      [selectedThreadId.value, requestedMessageId]
        .filter((id): id is string => id !== null)
        .some((id) => !messages.value.some((message) => message.id === id))
      && pageMeta.value.pagination.has_more
      && pageMeta.value.pagination.oldest_sequence
    ) {
      const olderPage = await getConversationMessages(conversationId.value, {
        beforeSequence: pageMeta.value.pagination.oldest_sequence,
        limit: 100,
        signal: loadAbortController.signal,
      });
      mergeMessages(olderPage.data);
      pageMeta.value = olderPage.meta;
    }

    if (
      selectedThreadId.value
      && !messages.value.some(
        (message) => message.id === selectedThreadId.value && message.parent_message_id === null,
      )
    ) {
      selectedThreadId.value = null;
    }

    await advanceReadPosition(page.meta.latest_sequence);
  } catch (error) {
    const message = readableError(error);
    if (message) {
      loadError.value = message;
    }
  } finally {
    loading.value = false;
    await nextTick();

    if (!loadError.value) {
      messageList.value?.scrollTo({ top: messageList.value.scrollHeight });
      await revealFocusRequest();
    }
  }
}

async function revealFocusRequest(): Promise<void> {
  const request = props.focusRequest;
  if (!request || request.conversationId !== conversationId.value) return;

  focusedMessageId.value = request.messageId;
  if (focusHighlightTimer !== null) window.clearTimeout(focusHighlightTimer);
  focusHighlightTimer = window.setTimeout(() => {
    focusedMessageId.value = null;
    focusHighlightTimer = null;
  }, 6000);

  if (request.threadRootMessageId) selectedThreadId.value = request.threadRootMessageId;
  await nextTick();

  const container = request.threadRootMessageId ? threadList.value : messageList.value;
  const message = container?.querySelector<HTMLElement>(`[data-message-id="${CSS.escape(request.messageId)}"]`);
  if (!message) return;

  message.scrollIntoView({ block: "center", behavior: "smooth" });
}

async function loadOlder(): Promise<void> {
  const beforeSequence = pageMeta.value?.pagination.oldest_sequence;

  if (!beforeSequence || loadingOlder.value) {
    return;
  }

  loadingOlder.value = true;
  loadError.value = "";

  try {
    const page = await getConversationMessages(conversationId.value, {
      beforeSequence,
      limit: 100,
    });
    mergeMessages(page.data);
    pageMeta.value = page.meta;
  } catch (error) {
    loadError.value = readableError(error);
  } finally {
    loadingOlder.value = false;
  }
}

function attemptFor(
  body: string,
  parentMessageId: string | null,
  mentionUserIds: string[],
  attentionUserIds: string[],
  thread: boolean,
): SendAttempt {
  const pending = thread ? pendingThreadAttempt : pendingRootAttempt;

  if (
    pending.value?.body === body
    && pending.value.parentMessageId === parentMessageId
    && pending.value.mentionUserIds.join(",") === mentionUserIds.join(",")
    && pending.value.attentionUserIds.join(",") === attentionUserIds.join(",")
  ) {
    return pending.value;
  }

  const attempt = {
    body,
    parentMessageId,
    mentionUserIds,
    attentionUserIds,
    idempotencyKey: crypto.randomUUID(),
  };
  pending.value = attempt;
  return attempt;
}

async function send(
  bodyValue: string,
  parentMessageId: string | null,
  selectedIds: string[],
  selectedAttentionUserIds: string[],
): Promise<void> {
  const body = bodyValue.trim();

  if (!body || sending.value) {
    return;
  }

  const thread = parentMessageId !== null;
  const mentionUserIds = selectedIds
    .filter((id) => {
      const user = mentionableUsers.value.find((candidate) => candidate.id === id);
      return user ? body.includes(`@${user.name}`) : false;
    })
    .filter((id) => !selectedAttentionUserIds.includes(id))
    .sort();
  const attentionUserIds = selectedAttentionUserIds
    .filter((id) => {
      const user = mentionableUsers.value.find((candidate) => candidate.id === id);
      return user ? body.includes(`!!${user.name}`) : false;
    })
    .sort();
  const attempt = attemptFor(body, parentMessageId, mentionUserIds, attentionUserIds, thread);
  sending.value = true;
  sendError.value = "";

  try {
    const message = await sendConversationMessage(conversationId.value, {
      body: attempt.body,
      idempotencyKey: attempt.idempotencyKey,
      parentMessageId: attempt.parentMessageId ?? undefined,
      mentionUserIds: attempt.mentionUserIds,
      attentionUserIds: attempt.attentionUserIds,
    });
    mergeMessages([message]);
    await advanceReadPosition(message.sequence);

    if (thread) {
      threadDraft.value = "";
      threadMentionUserIds.value = [];
      threadAttentionUserIds.value = [];
      pendingThreadAttempt.value = null;
      await nextTick();
      threadList.value?.scrollTo({ top: threadList.value.scrollHeight, behavior: "smooth" });
    } else {
      draft.value = "";
      rootMentionUserIds.value = [];
      rootAttentionUserIds.value = [];
      pendingRootAttempt.value = null;
      await nextTick();
      messageList.value?.scrollTo({ top: messageList.value.scrollHeight, behavior: "smooth" });
    }
  } catch (error) {
    sendError.value = readableError(error, "Katra Server is unavailable. Your message has not been discarded.");
  } finally {
    sending.value = false;
  }
}

function handleComposerKeydown(event: KeyboardEvent): void {
  if (handleStructuredTargetBackspace("root", event)) return;
  if (handleMentionKeydown("root", event)) return;

  if (event.key === "Enter" && !event.shiftKey) {
    event.preventDefault();
    void send(draft.value, null, rootMentionUserIds.value, rootAttentionUserIds.value);
  }
}

function handleThreadComposerKeydown(event: KeyboardEvent): void {
  if (handleStructuredTargetBackspace("thread", event)) return;
  if (handleMentionKeydown("thread", event)) return;

  if (event.key === "Enter" && !event.shiftKey && selectedThread.value) {
    event.preventDefault();
    void send(
      threadDraft.value,
      selectedThread.value.id,
      threadMentionUserIds.value,
      threadAttentionUserIds.value,
    );
  }
}

function openThread(message: ConversationMessage): void {
  selectedThreadId.value = message.id;
  threadDraft.value = "";
  threadMentionUserIds.value = [];
  threadAttentionUserIds.value = [];
  sendError.value = "";
  nextTick(() => threadList.value?.scrollTo({ top: threadList.value.scrollHeight }));
}

function closeThread(): void {
  selectedThreadId.value = null;
  threadDraft.value = "";
  threadMentionUserIds.value = [];
  threadAttentionUserIds.value = [];
  pendingThreadAttempt.value = null;
  reactionPickerMessageId.value = null;
}

async function handleJoin(): Promise<void> {
  if (!props.channel || joining.value) {
    return;
  }

  joining.value = true;
  lifecycleError.value = "";

  try {
    emit("channel-updated", await joinChannel(props.channel.id));
    await nextTick();
    await loadMentionableUsers();
    await advanceReadPosition(pageMeta.value?.latest_sequence ?? 0);
  } catch (error) {
    lifecycleError.value = readableError(error);
  } finally {
    joining.value = false;
  }
}

async function changeDirectMessageState(action: "complete" | "continue" | "reopen"): Promise<void> {
  if (!props.directMessage || changingLifecycle.value) {
    return;
  }

  changingLifecycle.value = true;
  lifecycleError.value = "";

  try {
    const updated = action === "complete"
      ? await completeDirectMessage(props.directMessage.id)
      : action === "continue"
        ? await requestDirectMessageContinuation(props.directMessage.id)
        : await reopenDirectMessage(props.directMessage.id);
    emit("direct-message-updated", updated);
  } catch (error) {
    lifecycleError.value = readableError(error);
  } finally {
    changingLifecycle.value = false;
  }
}

function setThreadWidth(width: number): void {
  const bounds = conversationPage.value?.getBoundingClientRect();
  const maximum = bounds ? Math.max(280, Math.min(780, bounds.width - 420)) : 780;
  threadWidth.value = Math.round(Math.min(maximum, Math.max(280, width)));
}

function resizeThread(event: PointerEvent): void {
  if (!threadResizing.value) {
    return;
  }

  const bounds = conversationPage.value?.getBoundingClientRect();
  if (bounds) {
    setThreadWidth(bounds.right - event.clientX);
  }
}

function startThreadResize(event: PointerEvent): void {
  if (window.matchMedia("(max-width: 900px)").matches || (event.pointerType === "mouse" && event.button !== 0)) {
    return;
  }

  event.preventDefault();
  threadResizeHandle = event.currentTarget as HTMLElement;
  threadResizePointerId = event.pointerId;
  threadResizeHandle.setPointerCapture(event.pointerId);
  threadResizing.value = true;
  window.addEventListener("pointermove", resizeThread);
  window.addEventListener("pointerup", stopThreadResize);
  window.addEventListener("pointercancel", stopThreadResize);
  resizeThread(event);
}

function stopThreadResize(): void {
  if (!threadResizing.value) {
    return;
  }

  if (threadResizeHandle && threadResizePointerId !== null && threadResizeHandle.hasPointerCapture(threadResizePointerId)) {
    threadResizeHandle.releasePointerCapture(threadResizePointerId);
  }

  threadResizing.value = false;
  threadResizeHandle = null;
  threadResizePointerId = null;
  window.removeEventListener("pointermove", resizeThread);
  window.removeEventListener("pointerup", stopThreadResize);
  window.removeEventListener("pointercancel", stopThreadResize);
}

function resizeThreadWithKeyboard(event: KeyboardEvent): void {
  if (!["ArrowLeft", "ArrowRight", "Home", "End"].includes(event.key)) {
    return;
  }

  event.preventDefault();
  if (event.key === "Home") setThreadWidth(280);
  else if (event.key === "End") setThreadWidth(780);
  else setThreadWidth(threadWidth.value + (event.key === "ArrowLeft" ? 20 : -20));
}

watch(conversationId, () => {
  draft.value = "";
  threadDraft.value = "";
  rootMentionUserIds.value = [];
  threadMentionUserIds.value = [];
  rootAttentionUserIds.value = [];
  threadAttentionUserIds.value = [];
  closeMentionPicker();
  pendingRootAttempt.value = null;
  pendingThreadAttempt.value = null;
  reactionPickerMessageId.value = null;
  interactionError.value = "";
  localConversationLiveMeeting.value = null;
  locallyClosedMeetingId.value = null;
  if (props.focusRequest?.conversationId === conversationId.value && props.focusRequest.threadRootMessageId) {
    selectedThreadId.value = props.focusRequest.threadRootMessageId;
  }
  void Promise.all([loadLatest(), loadMentionableUsers()]);
}, { immediate: true });

watch(resourceLiveMeeting, (meeting) => {
  if (meeting?.id === localConversationLiveMeeting.value?.id) {
    localConversationLiveMeeting.value = null;
  }

  if (locallyClosedMeetingId.value && meeting?.id !== locallyClosedMeetingId.value) {
    locallyClosedMeetingId.value = null;
  }
});

watch(() => props.focusRequest?.nonce, () => {
  if (props.focusRequest?.conversationId !== conversationId.value) return;
  if (props.focusRequest.threadRootMessageId) selectedThreadId.value = props.focusRequest.threadRootMessageId;

  if (messages.value.some((message) => message.id === props.focusRequest?.messageId)) {
    void revealFocusRequest();
  } else {
    void loadLatest();
  }
});

watch(() => props.realtimeEvent, (event) => {
  if (!event || event.conversation_id !== conversationId.value) return;

  queueRealtimeRecovery(() => event.type === "message-created"
    ? recoverForwardMessages()
    : recoverChangedMessage(event));
});

watch(() => props.realtimeReconnectGeneration, (generation, previous) => {
  if (generation > previous) queueRealtimeRecovery(refreshLoadedConversation);
});

onBeforeUnmount(() => {
  loadAbortController?.abort();
  mentionAbortController?.abort();
  if (focusHighlightTimer !== null) window.clearTimeout(focusHighlightTimer);
  stopThreadResize();
});
</script>

<template>
  <section
    ref="conversationPage"
    class="live-conversation"
    :class="{ 'live-conversation--thread-open': selectedThread, 'is-resizing': threadResizing }"
    :style="pageStyle"
  >
    <section class="live-conversation-main" :aria-label="conversationTitle">
      <header class="live-conversation-header">
        <span class="live-conversation-symbol" aria-hidden="true">
          <LockKeyhole v-if="channel?.visibility === 'private'" :size="19" :stroke-width="1.8" />
          <Hash v-else-if="kind === 'channel'" :size="20" :stroke-width="1.8" />
          <UsersRound v-else :size="20" :stroke-width="1.8" />
        </span>
        <div class="live-conversation-heading">
          <div>
            <h1>{{ conversationTitle }}</h1>
            <span v-if="kind === 'direct-message'" class="live-state">{{ lifecycleLabel }}</span>
            <span v-else-if="channelIsArchived" class="live-state">Archived</span>
          </div>
          <p>{{ conversationSubtitle }}</p>
        </div>
        <div v-if="kind === 'direct-message' && directMessage?.internal_owner_id" class="live-header-actions">
          <button
            v-if="directMessage.state === 'open'"
            type="button"
            :disabled="changingLifecycle"
            @click="changeDirectMessageState('complete')"
          >
            Mark complete
          </button>
          <button
            v-else
            type="button"
            :disabled="changingLifecycle"
            @click="changeDirectMessageState('reopen')"
          >
            Reopen
          </button>
        </div>
        <div v-else-if="kind === 'channel' && channel?.visibility === 'private'" class="live-header-actions">
          <button
            ref="membersButton"
            class="live-members-button"
            type="button"
            aria-label="View Channel members"
            @click="membersDialogOpen = true"
          >
            <UsersRound :size="16" :stroke-width="1.8" aria-hidden="true" />
            <span>Members</span>
          </button>
        </div>
        <button
          v-if="kind === 'channel' && conversationLiveMeeting"
          class="live-active-meeting"
          type="button"
          :aria-label="`Join ${conversationLiveMeeting.title}, started by ${conversationLiveMeeting.organizer.name}`"
          :title="`${conversationLiveMeeting.organizer.name} started this room`"
          :disabled="startingMeeting"
          @click="startMeetingNow"
        >
          <span class="live-active-meeting-dot" aria-hidden="true" />
          <span>Live meeting</span>
          <strong>{{ startingMeeting ? "Joining…" : "Join" }}</strong>
        </button>
        <div class="live-meeting-shell">
          <button type="button" :aria-label="meetingMainActionLabel" :disabled="startingMeeting" @click="startMeetingNow">
            <Headphones :size="17" :stroke-width="1.8" aria-hidden="true" />
          </button>
          <button type="button" aria-label="Meeting options" aria-haspopup="menu" :aria-expanded="meetingMenuOpen" @click="meetingMenuOpen = !meetingMenuOpen">
            <ChevronDown :size="13" aria-hidden="true" />
          </button>
          <div v-if="meetingMenuOpen" class="live-meeting-menu" role="menu">
            <button type="button" role="menuitem" @click="startMeetingNow"><Headphones :size="16" aria-hidden="true" /><span><strong>{{ meetingMenuActionTitle }}</strong><small>{{ meetingMenuActionDescription }}</small></span></button>
            <button type="button" role="menuitem" @click="openMeetingScheduler"><CalendarClock :size="16" aria-hidden="true" /><span><strong>Schedule meeting</strong><small>One time or recurring</small></span></button>
          </div>
        </div>
      </header>

      <p v-if="lifecycleError" class="live-alert" role="alert">{{ lifecycleError }}</p>
      <p v-if="interactionError" class="live-alert" role="alert">{{ interactionError }}</p>
      <p v-if="meetingScheduleNotice" class="live-meeting-notice" role="status">{{ meetingScheduleNotice }}</p>

      <div ref="messageList" class="live-message-list" aria-live="polite">
        <div v-if="loading" class="live-centered-state">
          <RefreshCw class="live-spinner" :size="20" aria-hidden="true" />
          <span>Loading conversation…</span>
        </div>

        <div v-else-if="loadError" class="live-centered-state live-centered-state--error">
          <MessageCircle :size="24" aria-hidden="true" />
          <strong>Conversation unavailable</strong>
          <span>{{ loadError }}</span>
          <button type="button" @click="loadLatest">Try again</button>
        </div>

        <template v-else>
          <button
            v-if="pageMeta?.pagination.has_more"
            class="live-load-older"
            type="button"
            :disabled="loadingOlder"
            @click="loadOlder"
          >
            {{ loadingOlder ? "Loading…" : "Load earlier messages" }}
          </button>

          <div v-if="roots.length === 0" class="live-centered-state">
            <MessageCircle :size="25" aria-hidden="true" />
            <strong>No messages yet</strong>
            <span>Start the conversation when you’re ready.</span>
          </div>

          <article
            v-for="message in roots"
            :key="message.id"
            class="live-message"
            :data-message-id="message.id"
            :class="{
              'live-message--self': message.author.id === currentUser.id,
              'live-message--mentioned': rootMentionsCurrentUser(message),
              'live-message--attention-request': threadRequestsAttentionCurrentUser(message),
              'is-attention-focus': isAttentionFocus(message.id),
            }"
            :aria-label="rootAttentionLabel(message)"
          >
            <span class="live-avatar" aria-hidden="true">{{ initials(message.author.name) }}</span>
            <div class="live-message-copy">
              <header>
                <strong>{{ message.author.name }}</strong>
                <span v-if="message.author.id === currentUser.id" class="live-you">You</span>
                <time :datetime="message.created_at">{{ formatTime(message.created_at) }}</time>
                <span v-if="message.edited_at && !message.deleted_at" class="live-edited">Edited</span>
              </header>
              <p v-if="message.deleted_at" class="live-message-tombstone">Message deleted</p>
              <form v-else-if="editingMessageId === message.id" class="live-message-edit" @submit.prevent="saveMessageEdit(message)">
                <textarea v-model="editDraft" rows="3" maxlength="4000" aria-label="Edit message" @keydown.esc="cancelMessageEdit" />
                <div>
                  <button type="button" :disabled="messageMutationPending === message.id" @click="cancelMessageEdit">Cancel</button>
                  <button type="submit" class="is-primary" :disabled="messageMutationPending === message.id || !editDraft.trim()">{{ messageMutationPending === message.id ? "Saving…" : "Save" }}</button>
                </div>
              </form>
              <MarkdownMessage v-else :body="message.body ?? ''" :mentions="message.mentions" :attention-targets="message.attention_targets" />
              <div v-if="!message.deleted_at" class="live-message-actions">
                <div v-if="message.reactions.length" class="live-reactions" aria-label="Message reactions">
                  <button
                    v-for="reaction in message.reactions"
                    :key="reaction.kind"
                    type="button"
                    :class="{ 'is-active': reaction.reacted_by_current_user }"
                    :disabled="reactionDisabled || reactionPending[`${message.id}:${reaction.kind}`]"
                    :aria-label="`${reaction.reacted_by_current_user ? 'Remove' : 'Add'} ${reactionLabels[reaction.kind]} reaction, ${reaction.count}`"
                    @click="toggleReaction(message, reaction.kind)"
                  >
                    <component :is="reactionIcons[reaction.kind]" :size="14" :stroke-width="1.9" aria-hidden="true" />
                    <span>{{ reaction.count }}</span>
                  </button>
                </div>
                <div class="live-reaction-picker-shell" @keydown.esc="reactionPickerMessageId = null">
                  <button
                    class="live-add-reaction"
                    type="button"
                    :disabled="reactionDisabled"
                    :aria-expanded="reactionPickerMessageId === message.id"
                    aria-haspopup="menu"
                    :aria-label="`Add reaction to ${message.author.name}'s message`"
                    @click="toggleReactionPicker(message.id)"
                  >
                    <SmilePlus :size="14" aria-hidden="true" />
                  </button>
                  <div v-if="reactionPickerMessageId === message.id" class="live-reaction-menu" role="menu" aria-label="Choose a reaction">
                    <button
                      v-for="reaction in supportedReactions"
                      :key="reaction.kind"
                      type="button"
                      role="menuitem"
                      :aria-label="reaction.label"
                      @click="toggleReaction(message, reaction.kind)"
                    ><component :is="reaction.icon" :size="15" :stroke-width="1.9" aria-hidden="true" /></button>
                  </div>
                </div>
                <div v-if="message.author.id === currentUser.id" class="live-message-owner-actions">
                  <button type="button" :disabled="messageMutationPending === message.id" aria-label="Edit message" @click="beginMessageEdit(message)"><Pencil :size="13" aria-hidden="true" /></button>
                  <button type="button" class="is-delete" :disabled="messageMutationPending === message.id" aria-label="Delete message" @click="deleteConfirmMessageId = deleteConfirmMessageId === message.id ? null : message.id"><Trash2 :size="13" aria-hidden="true" /></button>
                </div>
              </div>
              <div v-if="deleteConfirmMessageId === message.id" class="live-delete-confirm">
                <span>Delete this message? Replies and references will remain.</span>
                <button type="button" @click="deleteConfirmMessageId = null">Cancel</button>
                <button type="button" class="is-delete" :disabled="messageMutationPending === message.id" @click="deleteMessage(message)">{{ messageMutationPending === message.id ? "Deleting…" : "Delete" }}</button>
              </div>
              <button class="live-thread-button" type="button" @click="openThread(message)">
                <span v-if="repliesFor(message.id).length">
                  {{ repliesFor(message.id).length }} {{ repliesFor(message.id).length === 1 ? "reply" : "replies" }}
                </span>
                <span v-else>Reply in thread</span>
              </button>
            </div>
          </article>
        </template>
      </div>

      <div v-if="shouldJoinPublicChannel" class="live-join-panel">
        <div>
          <strong>Join #{{ channel?.name }} to participate</strong>
          <span>You can read this internal public Channel before joining.</span>
        </div>
        <button type="button" :disabled="joining" @click="handleJoin">
          {{ joining ? "Joining…" : "Join channel" }}
        </button>
      </div>

      <div v-else-if="channelIsArchived" class="live-readonly-note">This Channel is archived and read-only.</div>

      <form v-else class="live-composer" @submit.prevent="send(draft, null, rootMentionUserIds, rootAttentionUserIds)">
        <div
          v-if="activeMentionComposer === 'root'"
          :id="mentionListboxId('root')"
          class="live-mention-picker"
          role="listbox"
          :aria-label="activePickerLabel"
        >
          <button
            v-for="(user, index) in filteredMentionableUsers"
            :key="user.id"
            :id="mentionOptionId('root', activeTargetKind, user.id)"
            type="button"
            role="option"
            tabindex="-1"
            :aria-selected="index === activeMentionIndex"
            :class="{ 'is-active': index === activeMentionIndex }"
            @mouseenter="setActiveMentionIndex(index)"
            @mousedown.prevent
            @click="selectMention(user)"
          >
            <span class="live-mention-avatar" aria-hidden="true">{{ initials(user.name) }}</span>
            <span>{{ user.name }}</span>
            <small v-if="activeTargetKind === 'attention'">Inbox</small>
          </button>
          <p v-if="filteredMentionableUsers.length === 0">No matching people</p>
        </div>
        <div class="live-composer-editor">
          <div class="live-composer-mirror" aria-hidden="true">
            <div class="live-composer-mirror-content" :style="composerMirrorStyle('root')">
              <span
                v-for="(segment, index) in composerSegments('root')"
                :key="`${index}:${segment.text}`"
                :class="{
                  'live-composer-token': segment.kind !== 'plain',
                  'live-composer-token--mention': segment.kind === 'mention',
                  'live-composer-token--attention': segment.kind === 'attention',
                }"
              >{{ segment.text }}</span>
            </div>
          </div>
          <textarea
            ref="draftTextarea"
            v-model="draft"
            rows="2"
            maxlength="4000"
            :placeholder="`Message ${kind === 'channel' ? '#' : ''}${conversationTitle}`"
            :aria-label="`Message ${conversationTitle}`"
            :disabled="composerDisabled"
            aria-autocomplete="list"
            aria-haspopup="listbox"
            :aria-controls="activeMentionComposer === 'root' ? mentionListboxId('root') : undefined"
            :aria-expanded="activeMentionComposer === 'root'"
            :aria-activedescendant="activeMentionComposer === 'root' ? activeMentionOptionId : undefined"
            @input="handleComposerInput('root', $event)"
            @scroll="handleComposerScroll('root', $event)"
            @keydown="handleComposerKeydown"
          />
        </div>
        <div class="live-composer-footer">
          <span class="live-composer-tools">
            <button
              class="live-mention-trigger"
              type="button"
              :disabled="composerDisabled || mentionableUsers.length === 0"
              aria-label="Mention someone"
              :aria-expanded="activeMentionComposer === 'root' && activeTargetKind === 'mention'"
              @click="openMentionPicker('root')"
            ><AtSign :size="16" :stroke-width="1.9" aria-hidden="true" /></button>
            <button
              class="live-mention-trigger live-attention-trigger"
              type="button"
              :disabled="composerDisabled || mentionableUsers.length === 0"
              aria-label="Request someone's attention"
              :aria-expanded="activeMentionComposer === 'root' && activeTargetKind === 'attention'"
              @click="openMentionPicker('root', 'attention')"
            ><CircleAlert :size="16" :stroke-width="1.9" aria-hidden="true" /></button>
            <span>Enter to send · Shift+Enter for a new line</span>
          </span>
          <button type="submit" :disabled="composerDisabled || !draft.trim()" aria-label="Send message">
            <SendHorizontal :size="17" :stroke-width="1.9" aria-hidden="true" />
          </button>
        </div>
        <p v-if="sendError" class="live-composer-error" role="alert">{{ sendError }}</p>
      </form>
    </section>

    <div
      v-if="selectedThread"
      class="live-thread-resize"
      role="separator"
      aria-label="Resize thread"
      aria-orientation="vertical"
      tabindex="0"
      @pointerdown="startThreadResize"
      @keydown="resizeThreadWithKeyboard"
      @dblclick="setThreadWidth(390)"
    ><span /></div>

    <aside v-if="selectedThread" class="live-thread" aria-label="Thread">
      <header class="live-thread-header">
        <div>
          <strong>Thread</strong>
          <span>{{ conversationTitle }}</span>
        </div>
        <button type="button" aria-label="Close thread" @click="closeThread">
          <X :size="18" aria-hidden="true" />
        </button>
      </header>

      <div ref="threadList" class="live-thread-list">
        <article
          class="live-message live-thread-root"
          :data-message-id="selectedThread.id"
          :class="{
            'live-message--self': selectedThread.author.id === currentUser.id,
            'live-message--mentioned': rootMentionsCurrentUser(selectedThread),
            'live-message--attention-request': threadRequestsAttentionCurrentUser(selectedThread),
            'is-attention-focus': isAttentionFocus(selectedThread.id),
          }"
          :aria-label="rootAttentionLabel(selectedThread)"
        >
          <span class="live-avatar" aria-hidden="true">{{ initials(selectedThread.author.name) }}</span>
          <div class="live-message-copy">
            <header>
              <strong>{{ selectedThread.author.name }}</strong>
              <span v-if="selectedThread.author.id === currentUser.id" class="live-you">You</span>
              <time :datetime="selectedThread.created_at">{{ formatTime(selectedThread.created_at) }}</time>
              <span v-if="selectedThread.edited_at && !selectedThread.deleted_at" class="live-edited">Edited</span>
            </header>
            <p v-if="selectedThread.deleted_at" class="live-message-tombstone">Message deleted</p>
            <form v-else-if="editingMessageId === selectedThread.id" class="live-message-edit" @submit.prevent="saveMessageEdit(selectedThread)">
              <textarea v-model="editDraft" rows="3" maxlength="4000" aria-label="Edit thread root" @keydown.esc="cancelMessageEdit" />
              <div>
                <button type="button" :disabled="messageMutationPending === selectedThread.id" @click="cancelMessageEdit">Cancel</button>
                <button type="submit" class="is-primary" :disabled="messageMutationPending === selectedThread.id || !editDraft.trim()">{{ messageMutationPending === selectedThread.id ? "Saving…" : "Save" }}</button>
              </div>
            </form>
            <MarkdownMessage v-else :body="selectedThread.body ?? ''" :mentions="selectedThread.mentions" :attention-targets="selectedThread.attention_targets" />
            <div v-if="!selectedThread.deleted_at" class="live-message-actions">
              <div v-if="selectedThread.reactions.length" class="live-reactions" aria-label="Message reactions">
                <button
                  v-for="reaction in selectedThread.reactions"
                  :key="reaction.kind"
                  type="button"
                  :class="{ 'is-active': reaction.reacted_by_current_user }"
                  :disabled="reactionDisabled || reactionPending[`${selectedThread.id}:${reaction.kind}`]"
                  :aria-label="`${reaction.reacted_by_current_user ? 'Remove' : 'Add'} ${reactionLabels[reaction.kind]} reaction, ${reaction.count}`"
                  @click="toggleReaction(selectedThread, reaction.kind)"
                ><component :is="reactionIcons[reaction.kind]" :size="14" :stroke-width="1.9" aria-hidden="true" /><span>{{ reaction.count }}</span></button>
              </div>
              <div class="live-reaction-picker-shell">
                <button class="live-add-reaction" type="button" :disabled="reactionDisabled" aria-haspopup="menu" :aria-expanded="reactionPickerMessageId === selectedThread.id" aria-label="Add reaction to thread root" @click="toggleReactionPicker(selectedThread.id)"><SmilePlus :size="14" aria-hidden="true" /></button>
                <div v-if="reactionPickerMessageId === selectedThread.id" class="live-reaction-menu" role="menu" aria-label="Choose a reaction">
                  <button v-for="reaction in supportedReactions" :key="reaction.kind" type="button" role="menuitem" :aria-label="reaction.label" @click="toggleReaction(selectedThread, reaction.kind)"><component :is="reaction.icon" :size="15" :stroke-width="1.9" aria-hidden="true" /></button>
                </div>
              </div>
              <div v-if="selectedThread.author.id === currentUser.id" class="live-message-owner-actions">
                <button type="button" :disabled="messageMutationPending === selectedThread.id" aria-label="Edit thread root" @click="beginMessageEdit(selectedThread)"><Pencil :size="13" aria-hidden="true" /></button>
                <button type="button" class="is-delete" :disabled="messageMutationPending === selectedThread.id" aria-label="Delete thread root" @click="deleteConfirmMessageId = deleteConfirmMessageId === selectedThread.id ? null : selectedThread.id"><Trash2 :size="13" aria-hidden="true" /></button>
              </div>
            </div>
            <div v-if="deleteConfirmMessageId === selectedThread.id" class="live-delete-confirm">
              <span>Delete this message? Replies and references will remain.</span>
              <button type="button" @click="deleteConfirmMessageId = null">Cancel</button>
              <button type="button" class="is-delete" :disabled="messageMutationPending === selectedThread.id" @click="deleteMessage(selectedThread)">{{ messageMutationPending === selectedThread.id ? "Deleting…" : "Delete" }}</button>
            </div>
          </div>
        </article>
        <p class="live-thread-count">{{ threadReplies.length }} {{ threadReplies.length === 1 ? "reply" : "replies" }}</p>
        <article
          v-for="reply in threadReplies"
          :key="reply.id"
          class="live-message"
          :data-message-id="reply.id"
          :class="{
            'live-message--self': reply.author.id === currentUser.id,
            'live-message--mentioned': mentionsCurrentUser(reply),
            'live-message--attention-request': requestsAttentionCurrentUser(reply),
            'is-attention-focus': isAttentionFocus(reply.id),
          }"
          :aria-label="requestsAttentionCurrentUser(reply)
            ? `${reply.author.name} requested your attention`
            : mentionsCurrentUser(reply) ? `${reply.author.name} mentioned you` : undefined"
        >
          <span class="live-avatar" aria-hidden="true">{{ initials(reply.author.name) }}</span>
          <div class="live-message-copy">
            <header>
              <strong>{{ reply.author.name }}</strong>
              <span v-if="reply.author.id === currentUser.id" class="live-you">You</span>
              <time :datetime="reply.created_at">{{ formatTime(reply.created_at) }}</time>
              <span v-if="reply.edited_at && !reply.deleted_at" class="live-edited">Edited</span>
            </header>
            <p v-if="reply.deleted_at" class="live-message-tombstone">Message deleted</p>
            <form v-else-if="editingMessageId === reply.id" class="live-message-edit" @submit.prevent="saveMessageEdit(reply)">
              <textarea v-model="editDraft" rows="3" maxlength="4000" aria-label="Edit reply" @keydown.esc="cancelMessageEdit" />
              <div>
                <button type="button" :disabled="messageMutationPending === reply.id" @click="cancelMessageEdit">Cancel</button>
                <button type="submit" class="is-primary" :disabled="messageMutationPending === reply.id || !editDraft.trim()">{{ messageMutationPending === reply.id ? "Saving…" : "Save" }}</button>
              </div>
            </form>
            <MarkdownMessage v-else :body="reply.body ?? ''" :mentions="reply.mentions" :attention-targets="reply.attention_targets" />
            <div v-if="!reply.deleted_at" class="live-message-actions">
              <div v-if="reply.reactions.length" class="live-reactions" aria-label="Message reactions">
                <button
                  v-for="reaction in reply.reactions"
                  :key="reaction.kind"
                  type="button"
                  :class="{ 'is-active': reaction.reacted_by_current_user }"
                  :disabled="reactionDisabled || reactionPending[`${reply.id}:${reaction.kind}`]"
                  :aria-label="`${reaction.reacted_by_current_user ? 'Remove' : 'Add'} ${reactionLabels[reaction.kind]} reaction, ${reaction.count}`"
                  @click="toggleReaction(reply, reaction.kind)"
                ><component :is="reactionIcons[reaction.kind]" :size="14" :stroke-width="1.9" aria-hidden="true" /><span>{{ reaction.count }}</span></button>
              </div>
              <div class="live-reaction-picker-shell">
                <button class="live-add-reaction" type="button" :disabled="reactionDisabled" aria-haspopup="menu" :aria-expanded="reactionPickerMessageId === reply.id" :aria-label="`Add reaction to ${reply.author.name}'s reply`" @click="toggleReactionPicker(reply.id)"><SmilePlus :size="14" aria-hidden="true" /></button>
                <div v-if="reactionPickerMessageId === reply.id" class="live-reaction-menu" role="menu" aria-label="Choose a reaction">
                  <button v-for="reaction in supportedReactions" :key="reaction.kind" type="button" role="menuitem" :aria-label="reaction.label" @click="toggleReaction(reply, reaction.kind)"><component :is="reaction.icon" :size="15" :stroke-width="1.9" aria-hidden="true" /></button>
                </div>
              </div>
              <div v-if="reply.author.id === currentUser.id" class="live-message-owner-actions">
                <button type="button" :disabled="messageMutationPending === reply.id" aria-label="Edit reply" @click="beginMessageEdit(reply)"><Pencil :size="13" aria-hidden="true" /></button>
                <button type="button" class="is-delete" :disabled="messageMutationPending === reply.id" aria-label="Delete reply" @click="deleteConfirmMessageId = deleteConfirmMessageId === reply.id ? null : reply.id"><Trash2 :size="13" aria-hidden="true" /></button>
              </div>
            </div>
            <div v-if="deleteConfirmMessageId === reply.id" class="live-delete-confirm">
              <span>Delete this reply?</span>
              <button type="button" @click="deleteConfirmMessageId = null">Cancel</button>
              <button type="button" class="is-delete" :disabled="messageMutationPending === reply.id" @click="deleteMessage(reply)">{{ messageMutationPending === reply.id ? "Deleting…" : "Delete" }}</button>
            </div>
          </div>
        </article>
      </div>

      <form class="live-thread-composer" @submit.prevent="selectedThread && send(threadDraft, selectedThread.id, threadMentionUserIds, threadAttentionUserIds)">
        <div
          v-if="activeMentionComposer === 'thread'"
          :id="mentionListboxId('thread')"
          class="live-mention-picker live-mention-picker--thread"
          role="listbox"
          :aria-label="activeTargetKind === 'attention' ? 'Request attention in thread' : 'Mention someone in thread'"
        >
          <button
            v-for="(user, index) in filteredMentionableUsers"
            :key="user.id"
            :id="mentionOptionId('thread', activeTargetKind, user.id)"
            type="button"
            role="option"
            tabindex="-1"
            :aria-selected="index === activeMentionIndex"
            :class="{ 'is-active': index === activeMentionIndex }"
            @mouseenter="setActiveMentionIndex(index)"
            @mousedown.prevent
            @click="selectMention(user)"
          >
            <span class="live-mention-avatar" aria-hidden="true">{{ initials(user.name) }}</span>
            <span>{{ user.name }}</span>
            <small v-if="activeTargetKind === 'attention'">Inbox</small>
          </button>
          <p v-if="filteredMentionableUsers.length === 0">No matching people</p>
        </div>
        <div class="live-composer-editor">
          <div class="live-composer-mirror" aria-hidden="true">
            <div class="live-composer-mirror-content" :style="composerMirrorStyle('thread')">
              <span
                v-for="(segment, index) in composerSegments('thread')"
                :key="`${index}:${segment.text}`"
                :class="{
                  'live-composer-token': segment.kind !== 'plain',
                  'live-composer-token--mention': segment.kind === 'mention',
                  'live-composer-token--attention': segment.kind === 'attention',
                }"
              >{{ segment.text }}</span>
            </div>
          </div>
          <textarea
            ref="threadTextarea"
            v-model="threadDraft"
            rows="2"
            maxlength="4000"
            placeholder="Reply in thread"
            aria-label="Reply in thread"
            :disabled="composerDisabled"
            aria-autocomplete="list"
            aria-haspopup="listbox"
            :aria-controls="activeMentionComposer === 'thread' ? mentionListboxId('thread') : undefined"
            :aria-expanded="activeMentionComposer === 'thread'"
            :aria-activedescendant="activeMentionComposer === 'thread' ? activeMentionOptionId : undefined"
            @input="handleComposerInput('thread', $event)"
            @scroll="handleComposerScroll('thread', $event)"
            @keydown="handleThreadComposerKeydown"
          />
        </div>
        <div class="live-thread-composer-actions">
          <button
            class="live-mention-trigger"
            type="button"
            :disabled="composerDisabled || mentionableUsers.length === 0"
            aria-label="Mention someone in thread"
            :aria-expanded="activeMentionComposer === 'thread' && activeTargetKind === 'mention'"
            @click="openMentionPicker('thread')"
          ><AtSign :size="16" :stroke-width="1.9" aria-hidden="true" /></button>
          <button
            class="live-mention-trigger live-attention-trigger"
            type="button"
            :disabled="composerDisabled || mentionableUsers.length === 0"
            aria-label="Request attention in thread"
            :aria-expanded="activeMentionComposer === 'thread' && activeTargetKind === 'attention'"
            @click="openMentionPicker('thread', 'attention')"
          ><CircleAlert :size="16" :stroke-width="1.9" aria-hidden="true" /></button>
          <button type="submit" :disabled="composerDisabled || !threadDraft.trim()" aria-label="Send thread reply">
            <SendHorizontal :size="17" aria-hidden="true" />
          </button>
        </div>
      </form>
    </aside>

    <ChannelMembersDialog
      v-if="membersDialogOpen && channel"
      :channel="channel"
      @close="closeMembersDialog"
      @left="handleChannelLeft"
      @channel-updated="$emit('channel-updated', $event)"
    />
    <MeetingScheduleDialog
      v-if="meetingSchedulerOpen"
      :default-title="`${conversationTitle} meeting`"
      :audience-label="meetingAudienceLabel"
      :participants="meetingParticipants"
      :organization-id="meetingOrganizationId"
      @close="meetingSchedulerOpen = false"
      @scheduled="handleMeetingScheduled"
    />
    <HuddleMeeting
      v-if="meetingRoomOpen"
      v-show="meetingRoomVisible"
      :title="`${conversationTitle} meeting`"
      :subtitle="meetingAudienceLabel"
      :participants="meetingParticipants"
      :organization-id="meetingOrganizationId"
      :meeting="activeMeeting"
      :current-user="currentUser"
      @meeting-updated="handleMeetingUpdated"
      @minimize="meetingRoomVisible = false"
      @leave="meetingRoomOpen = false; meetingRoomVisible = false"
    />
    <button v-if="meetingRoomOpen && !meetingRoomVisible" class="live-return-meeting" type="button" @click="meetingRoomVisible = true"><Headphones :size="16" aria-hidden="true" /> Return to {{ activeMeeting?.title ?? "meeting" }}</button>
  </section>
</template>

<style scoped>
.live-conversation { position: relative; display: grid; width: 100%; min-width: 0; min-height: 0; height: 100%; grid-template-columns: minmax(0, 1fr); color: #d8dee9; }
.live-conversation--thread-open { grid-template-columns: minmax(420px, 1fr) 0 minmax(280px, var(--live-thread-width)); }
.live-conversation-main, .live-thread { min-width: 0; min-height: 0; background: #2e3745; }
.live-conversation-main { display: flex; flex-direction: column; border-radius: 12px; overflow: hidden; }
.live-conversation--thread-open .live-conversation-main { border-radius: 12px 0 0 12px; }
.live-conversation-header { display: flex; min-height: 76px; align-items: center; gap: 12px; padding: 0 24px; background: #303947; }
.live-conversation-symbol { display: grid; width: 38px; height: 38px; flex: 0 0 38px; place-items: center; border-radius: 10px; background: #242b36; color: #b48ead; }
.live-conversation-heading { min-width: 0; flex: 1; }
.live-conversation-heading > div { display: flex; align-items: center; gap: 9px; }
.live-conversation-heading h1 { overflow: hidden; margin: 0; color: #eef1f6; font-size: 18px; font-weight: 650; line-height: 1.2; text-overflow: ellipsis; white-space: nowrap; }
.live-conversation-heading p { margin: 4px 0 0; color: #8994a5; font-size: 12px; }
.live-state { padding: 3px 7px; border-radius: 999px; background: rgb(180 142 173 / 12%); color: #caa6c3; font-size: 10px; font-weight: 650; text-transform: uppercase; letter-spacing: .04em; }
.live-header-actions button, .live-join-panel button, .live-centered-state button { border: 0; border-radius: 8px; background: #b48ead; color: #20252d; font: inherit; font-size: 12px; font-weight: 700; padding: 8px 12px; cursor: pointer; }
.live-header-actions .live-members-button { display: inline-flex; align-items: center; gap: 7px; background: rgb(216 222 233 / 7%); color: #cbd2dc; }
.live-header-actions .live-members-button:hover, .live-header-actions .live-members-button:focus-visible { outline: 0; background: rgb(180 142 173 / 16%); color: #e3cde0; }
.live-header-actions button:disabled, .live-join-panel button:disabled { opacity: .6; cursor: default; }
.live-active-meeting { display: inline-flex; min-height: 34px; flex: 0 0 auto; align-items: center; gap: 7px; padding: 0 10px; border: 1px solid rgb(163 190 140 / 30%); border-radius: 999px; background: rgb(163 190 140 / 11%); color: #d6e5cd; font: inherit; font-size: 11px; font-weight: 650; cursor: pointer; }
.live-active-meeting:hover, .live-active-meeting:focus-visible { outline: 0; border-color: rgb(163 190 140 / 52%); background: rgb(163 190 140 / 18%); }
.live-active-meeting:disabled { opacity: .65; cursor: default; }
.live-active-meeting strong { color: #b7d5a6; font-size: 10px; font-weight: 800; letter-spacing: .03em; text-transform: uppercase; }
.live-active-meeting-dot { width: 7px; height: 7px; flex: 0 0 7px; border-radius: 999px; background: #a3be8c; box-shadow: 0 0 0 3px rgb(163 190 140 / 12%); }
.live-meeting-shell { position: relative; display: flex; flex: 0 0 auto; align-items: stretch; border-radius: 8px; background: rgb(216 222 233 / 7%); }
.live-return-meeting { position: absolute; right: 22px; bottom: 22px; z-index: 20; display: inline-flex; align-items: center; gap: 8px; border: 0; border-radius: 999px; padding: 10px 14px; background: #b48ead; color: #20252d; font: inherit; font-size: 12px; font-weight: 750; box-shadow: 0 8px 24px rgb(10 13 18 / 28%); cursor: pointer; }
.live-meeting-shell > button { display: grid; width: 36px; height: 34px; place-items: center; border: 0; background: transparent; color: #cbd2dc; cursor: pointer; }
.live-meeting-shell > button:first-child { width: 40px; border-radius: 8px 0 0 8px; }
.live-meeting-shell > button:nth-child(2) { width: 28px; border-radius: 0 8px 8px 0; box-shadow: inset 1px 0 rgb(216 222 233 / 7%); }
.live-meeting-shell > button:hover, .live-meeting-shell > button:focus-visible { outline: 0; background: rgb(180 142 173 / 16%); color: #eadde8; }
.live-meeting-menu { position: absolute; z-index: 40; top: calc(100% + 7px); right: 0; display: grid; width: 258px; gap: 3px; padding: 6px; border-radius: 10px; background: #252c36; box-shadow: 0 18px 42px rgb(5 8 12 / 38%); }
.live-meeting-menu button { display: grid; min-height: 50px; grid-template-columns: 24px minmax(0, 1fr); align-items: center; gap: 8px; padding: 8px 10px; border: 0; border-radius: 8px; background: transparent; color: #aeb7c4; text-align: left; cursor: pointer; }
.live-meeting-menu button:hover, .live-meeting-menu button:focus-visible { outline: 0; background: rgb(216 222 233 / 7%); }
.live-meeting-menu span { display: grid; gap: 3px; }
.live-meeting-menu strong { color: #e5e9ef; font-size: 11px; }
.live-meeting-menu small { color: #7f8a99; font-size: 9px; }
.live-meeting-notice { margin: 9px 24px 0; color: #a7d7b7; font-size: 11px; }
.live-alert { margin: 10px 24px 0; padding: 9px 11px; border-radius: 8px; background: rgb(191 97 106 / 16%); color: #e5b2b8; font-size: 12px; }
.live-message-list, .live-thread-list { min-height: 0; overflow-y: auto; padding: 22px 24px 14px; }
.live-message-list { flex: 1 1 auto; }
.live-message { position: relative; display: grid; grid-template-columns: 38px minmax(0, 1fr); gap: 12px; margin: 0 -10px 5px; padding: 10px; border-radius: 10px; background: transparent; transition: background-color .14s ease, box-shadow .14s ease; }
.live-message:hover, .live-message:focus-within { background: rgb(216 222 233 / 5%); box-shadow: 0 0 0 5px rgb(216 222 233 / 5%); }
.live-message--mentioned { --live-message-signal: #b48ead; --live-message-signal-glow: rgb(180 142 173 / 14%); }
.live-message--attention-request { --live-message-signal: #ebcb8b; --live-message-signal-glow: rgb(235 203 139 / 14%); }
.live-message--mentioned > .live-avatar::before, .live-message--attention-request > .live-avatar::before { position: absolute; inset: -4px; border: 2px solid var(--live-message-signal); border-radius: 50%; box-shadow: 0 0 0 2px var(--live-message-signal-glow), 0 0 8px var(--live-message-signal-glow); content: ""; pointer-events: none; }
.live-message.is-attention-focus { animation: live-attention-focus 5.5s ease-out; }
.live-avatar { position: relative; display: grid; width: 38px; height: 38px; place-items: center; border-radius: 50%; background: #414b5b; color: #e8d6e4; font-size: 12px; font-weight: 700; }
.live-message--self .live-avatar { box-shadow: 0 0 0 2px rgb(180 142 173 / 46%); }
.live-message-copy { min-width: 0; }
.live-message-copy header { display: flex; align-items: baseline; gap: 7px; }
.live-message-copy strong { color: #eef1f6; font-size: 13px; }
.live-message-copy time { color: #717d8e; font-size: 10px; }
.live-you { color: #c69fc0; font-size: 10px; font-weight: 650; }
.live-edited { color: #778394; font-size: 10px; }
.live-message-copy p { margin: 4px 0 0; color: #d2d8e2; font-size: 13px; line-height: 1.55; white-space: pre-wrap; overflow-wrap: anywhere; }
.live-message-copy .live-message-tombstone { color: #7f8a99; font-style: italic; }
.live-inline-mention { color: #d4a7d0; font-weight: 650; }
.live-inline-attention { color: #ebcb8b; font-weight: 750; }
.live-thread-button { margin: 7px 0 0; padding: 0; border: 0; background: transparent; color: #b993b2; font: inherit; font-size: 11px; font-weight: 650; cursor: pointer; }
.live-thread-button:hover, .live-thread-button:focus-visible { color: #d2b5cd; outline: 0; }
.live-message-actions { display: flex; align-items: center; gap: 5px; margin-top: 7px; }
.live-message-owner-actions { display: inline-flex; gap: 3px; margin-left: 2px; opacity: 0; transition: opacity .14s ease; }
.live-message:hover .live-message-owner-actions, .live-message:focus-within .live-message-owner-actions { opacity: 1; }
.live-message-owner-actions button { display: grid; width: 27px; height: 25px; place-items: center; border: 0; border-radius: 7px; background: transparent; color: #8995a6; cursor: pointer; }
.live-message-owner-actions button:hover, .live-message-owner-actions button:focus-visible { outline: 0; background: rgb(180 142 173 / 16%); color: #d6b7d1; }
.live-message-owner-actions button.is-delete:hover, .live-message-owner-actions button.is-delete:focus-visible { background: rgb(191 97 106 / 16%); color: #e5aab1; }
.live-message-owner-actions button:disabled { cursor: default; opacity: .45; }
.live-message-edit { display: grid; gap: 7px; margin-top: 6px; }
.live-message-edit textarea { box-sizing: border-box; width: 100%; resize: vertical; border: 1px solid rgb(180 142 173 / 46%); border-radius: 8px; outline: 0; padding: 9px 10px; background: #252d38; color: #e8ebf0; font: inherit; font-size: 13px; line-height: 1.5; }
.live-message-edit textarea:focus { border-color: #b48ead; box-shadow: 0 0 0 2px rgb(180 142 173 / 12%); }
.live-message-edit > div, .live-delete-confirm { display: flex; align-items: center; justify-content: flex-end; gap: 6px; }
.live-message-edit button, .live-delete-confirm button { min-height: 27px; border: 0; border-radius: 7px; padding: 0 9px; background: rgb(216 222 233 / 7%); color: #aeb7c4; font: inherit; font-size: 10px; font-weight: 700; cursor: pointer; }
.live-message-edit button:hover, .live-message-edit button:focus-visible, .live-delete-confirm button:hover, .live-delete-confirm button:focus-visible { outline: 0; background: rgb(216 222 233 / 12%); color: #e5e9ef; }
.live-message-edit button.is-primary { background: #b48ead; color: #20252d; }
.live-delete-confirm { justify-content: flex-start; margin-top: 7px; padding: 7px 8px; border-radius: 8px; background: rgb(191 97 106 / 10%); }
.live-delete-confirm span { flex: 1 1 auto; color: #c7aab0; font-size: 10px; }
.live-delete-confirm button.is-delete { background: #8e4d57; color: #f4e5e7; }
.live-message-edit button:disabled, .live-delete-confirm button:disabled { cursor: default; opacity: .45; }
.live-reactions { display: flex; flex-wrap: wrap; gap: 4px; }
.live-reactions button, .live-add-reaction { display: inline-flex; min-width: 27px; height: 25px; align-items: center; justify-content: center; gap: 4px; padding: 0 7px; border: 0; border-radius: 7px; background: rgb(216 222 233 / 6%); color: #aab3c1; font: inherit; font-size: 11px; cursor: pointer; }
.live-reactions button:hover, .live-reactions button:focus-visible, .live-reactions button.is-active, .live-add-reaction:hover, .live-add-reaction:focus-visible { outline: 0; background: rgb(180 142 173 / 18%); color: #d6b7d1; }
.live-reactions button:disabled, .live-add-reaction:disabled { cursor: default; opacity: .45; }
.live-reaction-picker-shell { position: relative; }
.live-add-reaction { width: 27px; padding: 0; opacity: .58; }
.live-message:hover .live-add-reaction, .live-add-reaction:focus-visible, .live-add-reaction[aria-expanded="true"] { opacity: 1; }
.live-reaction-menu { position: absolute; z-index: 8; bottom: calc(100% + 6px); left: 0; display: flex; gap: 3px; padding: 4px; border-radius: 9px; background: #222a35; box-shadow: 0 12px 30px rgb(8 11 16 / 28%); }
.live-reaction-menu button { display: grid; width: 30px; height: 30px; place-items: center; border: 0; border-radius: 7px; background: transparent; font-size: 16px; cursor: pointer; }
.live-reaction-menu button:hover, .live-reaction-menu button:focus-visible { outline: 0; background: rgb(180 142 173 / 16%); }
.live-centered-state { display: flex; min-height: 100%; align-items: center; justify-content: center; flex-direction: column; gap: 8px; color: #8d98a9; text-align: center; font-size: 12px; }
.live-centered-state strong { color: #dce1e9; font-size: 14px; }
.live-centered-state--error svg { color: #bf616a; }
.live-spinner { animation: live-spin 1s linear infinite; }
.live-load-older { display: block; margin: 0 auto 16px; border: 0; background: transparent; color: #b48ead; font: inherit; font-size: 11px; cursor: pointer; }
.live-composer { position: relative; margin: 8px 20px 18px; border-radius: 11px; background: #252d38; box-shadow: inset 0 0 0 1px rgb(216 222 233 / 8%); }
.live-composer:focus-within { box-shadow: inset 0 0 0 1px rgb(180 142 173 / 62%); }
.live-composer-editor { position: relative; min-width: 0; }
.live-composer textarea, .live-thread-composer textarea { position: relative; display: block; box-sizing: border-box; width: 100%; resize: none; border: 0; outline: 0; background: transparent; color: #e8ebf0; font: inherit; font-size: 13px; line-height: 1.45; padding: 13px 14px 6px; }
.live-composer textarea::placeholder, .live-thread-composer textarea::placeholder { color: #707b8b; }
.live-composer-mirror { position: absolute; inset: 0; overflow: hidden; pointer-events: none; }
.live-composer-mirror-content { box-sizing: border-box; min-height: 100%; padding: 13px 14px 6px; color: transparent; font: inherit; font-size: 13px; line-height: 1.45; white-space: pre-wrap; overflow-wrap: anywhere; }
.live-composer-token { border-radius: 4px; box-decoration-break: clone; -webkit-box-decoration-break: clone; }
.live-composer-token--mention { background: rgb(180 142 173 / 24%); box-shadow: 0 0 0 1px rgb(180 142 173 / 18%); }
.live-composer-token--attention { background: rgb(235 203 139 / 19%); box-shadow: 0 0 0 1px rgb(235 203 139 / 16%); }
.live-composer-footer { display: flex; min-height: 38px; align-items: center; justify-content: space-between; padding: 2px 8px 7px 9px; }
.live-composer-tools { display: inline-flex; min-width: 0; align-items: center; gap: 7px; color: #667283; font-size: 10px; }
.live-composer-footer > button, .live-thread-composer-actions > button { display: grid; width: 32px; height: 32px; place-items: center; border: 0; border-radius: 8px; background: #b48ead; color: #20252d; cursor: pointer; }
.live-composer-footer > button:disabled, .live-thread-composer-actions > button:disabled { background: #343d4a; color: #6d7888; cursor: default; }
.live-composer-tools .live-mention-trigger, .live-thread-composer-actions .live-mention-trigger { display: grid; width: 30px; height: 30px; flex: 0 0 auto; place-items: center; padding: 0; border: 0; border-radius: 7px; background: transparent; color: #9ca7b7; cursor: pointer; }
.live-composer-tools .live-mention-trigger:hover, .live-composer-tools .live-mention-trigger:focus-visible, .live-thread-composer-actions .live-mention-trigger:hover, .live-thread-composer-actions .live-mention-trigger:focus-visible, .live-mention-trigger[aria-expanded="true"] { outline: 0; background: rgb(180 142 173 / 16%); color: #d5b3cf; }
.live-composer-tools .live-attention-trigger:hover, .live-composer-tools .live-attention-trigger:focus-visible, .live-thread-composer-actions .live-attention-trigger:hover, .live-thread-composer-actions .live-attention-trigger:focus-visible, .live-attention-trigger[aria-expanded="true"] { background: rgb(235 203 139 / 13%); color: #ebcb8b; }
.live-composer-tools .live-mention-trigger:disabled, .live-thread-composer-actions .live-mention-trigger:disabled { opacity: .38; cursor: default; }
.live-mention-picker { position: absolute; z-index: 12; bottom: calc(100% + 7px); left: 0; width: min(320px, 100%); max-height: 250px; overflow-y: auto; padding: 6px; border-radius: 10px; background: #202833; box-shadow: 0 16px 38px rgb(7 10 15 / 38%); }
.live-mention-picker button { display: grid; width: 100%; min-height: 42px; grid-template-columns: 30px minmax(0, 1fr) auto; align-items: center; gap: 9px; padding: 5px 8px; border: 0; border-radius: 8px; background: transparent; color: #dce2eb; font: inherit; font-size: 12px; text-align: left; cursor: pointer; }
.live-mention-picker button:hover, .live-mention-picker button:focus-visible, .live-mention-picker button.is-active { outline: 0; background: rgb(180 142 173 / 15%); color: #f0e4ee; }
.live-mention-picker button.is-active { box-shadow: inset 2px 0 0 #b48ead; }
.live-mention-picker > p { margin: 0; padding: 10px; color: #7f8a9a; font-size: 11px; text-align: center; }
.live-mention-avatar { display: grid; width: 30px; height: 30px; place-items: center; border-radius: 50%; background: #3d4756; color: #e3cce0; font-size: 10px; font-weight: 700; }
.live-mention-picker button small { color: #c7a963; font-size: 9px; font-weight: 750; text-transform: uppercase; letter-spacing: .06em; }
.live-composer-error { margin: 0; padding: 0 14px 10px; color: #e5b2b8; font-size: 11px; }
.live-join-panel { display: flex; align-items: center; justify-content: space-between; gap: 18px; margin: 8px 20px 18px; padding: 13px 14px; border-radius: 10px; background: #252d38; }
.live-join-panel div { display: flex; flex-direction: column; gap: 3px; }
.live-join-panel strong { color: #e2e6ec; font-size: 12px; }
.live-join-panel span, .live-readonly-note { color: #8691a1; font-size: 11px; }
.live-readonly-note { margin: 8px 20px 18px; padding: 13px; border-radius: 10px; background: #252d38; text-align: center; }
.live-thread-resize { position: relative; z-index: 2; width: 12px; height: 100%; margin-left: -6px; cursor: col-resize; outline: 0; background: transparent; touch-action: none; }
.live-thread-resize span { position: absolute; top: 50%; left: 5px; width: 2px; height: 32px; border-radius: 999px; background: #566172; transform: translateY(-50%); transition: height .16s ease, background .16s ease; }
.live-thread-resize:hover span, .live-thread-resize:focus-visible span, .is-resizing .live-thread-resize span { height: 58px; background: #b48ead; }
.live-thread { display: grid; grid-template-rows: auto minmax(0, 1fr) auto; border-radius: 0 12px 12px 0; background: #293240; overflow: hidden; }
.live-thread-header { display: flex; min-height: 64px; align-items: center; justify-content: space-between; padding: 0 16px; background: #2c3542; }
.live-thread-header div { display: flex; flex-direction: column; gap: 2px; }
.live-thread-header strong { color: #eef1f6; font-size: 14px; }
.live-thread-header span { color: #8590a0; font-size: 10px; }
.live-thread-header button { display: grid; width: 32px; height: 32px; place-items: center; border: 0; border-radius: 8px; background: transparent; color: #9aa5b5; cursor: pointer; }
.live-thread-header button:hover, .live-thread-header button:focus-visible { background: rgb(216 222 233 / 7%); color: #eef1f6; outline: 0; }
.live-thread-list { padding: 14px 16px; }
.live-thread-root { padding-bottom: 14px; }
.live-thread-count { margin: 4px 0 10px; color: #7f8a9a; font-size: 10px; text-transform: uppercase; letter-spacing: .04em; }
.live-thread-composer { position: relative; margin: 10px 12px 14px; border-radius: 10px; background: #222a35; box-shadow: inset 0 0 0 1px rgb(216 222 233 / 8%); }
.live-thread-composer textarea { padding-right: 82px; padding-bottom: 11px; }
.live-thread-composer .live-composer-mirror-content { padding-right: 82px; padding-bottom: 11px; }
.live-thread-composer-actions { position: absolute; right: 7px; bottom: 7px; display: flex; align-items: center; gap: 3px; }
.live-mention-picker--thread { width: 100%; }
@keyframes live-spin { to { transform: rotate(360deg); } }
@keyframes live-attention-focus {
  0%, 28% { background: rgb(235 203 139 / 20%); }
  100% { background: transparent; }
}
@media (max-width: 900px) {
  .live-conversation--thread-open { grid-template-columns: 1fr; }
  .live-conversation--thread-open .live-conversation-main, .live-thread { border-radius: 12px; }
  .live-thread-resize { display: none; }
  .live-thread { position: absolute; inset: 0; z-index: 5; }
  .live-conversation-header { min-height: 68px; padding: 0 14px 0 58px; }
  .live-message-list { padding-right: 14px; padding-left: 14px; }
  .live-composer { margin-right: 12px; margin-left: 12px; }
  .live-composer-tools > span { display: none; }
  .live-members-button span { display: none; }
  .live-header-actions .live-members-button { width: 34px; height: 34px; justify-content: center; padding: 0; }
  .live-active-meeting > span:not(.live-active-meeting-dot) { display: none; }
}
</style>
