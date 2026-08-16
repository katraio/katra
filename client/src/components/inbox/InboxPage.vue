<script setup lang="ts">
import {
  ArrowLeft,
  AtSign,
  CalendarDays,
  CalendarPlus,
  Check,
  ChevronDown,
  Circle,
  CircleAlert,
  Clock3,
  Code2,
  Copy,
  Ellipsis,
  ExternalLink,
  FileText,
  Hand,
  Heart,
  Info,
  Link2,
  ListFilter,
  ListChecks,
  MailPlus,
  Maximize2,
  MessageSquareText,
  MessageSquareWarning,
  Mic,
  MicOff,
  Minimize2,
  MonitorUp,
  Paperclip,
  PartyPopper,
  Play,
  PhoneOff,
  Plus,
  ShieldAlert,
  ShieldCheck,
  SlidersHorizontal,
  Smile,
  Search,
  Square,
  ThumbsUp,
  UserPlus,
  UserMinus,
  UsersRound,
  Video,
  VideoOff,
  X,
} from "@lucide/vue";
import { computed, nextTick, onBeforeUnmount, onMounted, ref, watch } from "vue";
import type { AuthUser } from "../../api/auth";
import {
  CommunicationRequestError,
  addMeetingGuestInvitations,
  addMeetingParticipants,
  createMeetingMessage,
  createMeetingOutcome,
  getMeetingCalendar,
  getMeetingCandidates,
  getMeeting,
  getMeetingMediaCredential,
  getMeetingMessages,
  getMeetingOutcomes,
  removeMeetingParticipant,
  sendMeetingRoomReaction,
  setMeetingMessageReaction,
  updateMeetingGuestLink,
  updateMeetingGuestInvitation,
  updateMeetingRoom,
  type CommunicationAttentionItem,
  type CommunicationMeeting,
  type MeetingCandidate,
  type MeetingFocusRequest,
  type MeetingMessage,
  type MeetingMessageReaction,
  type MeetingOutcome,
  type MeetingRoomReactionKind,
} from "../../api/communication";
import { isFiniteNumber, useUiPreference } from "../../composables/useUiPreference";
import {
  retainPresentRaisedParticipantIds,
  updateRaisedParticipantIds,
} from "../../meetings/meetingPresentation";
import { useMeetingElapsedTime } from "../../meetings/useMeetingElapsedTime";
import { useMeetingMedia } from "../../meetings/useMeetingMedia";
import {
  startMeetingRoomRealtime,
  type MeetingPresence,
  type MeetingRoomReactionEvent,
  type MeetingRoomRealtimeController,
} from "../../realtime/meetingRoomRealtime";
import MeetingDeviceControl from "../meetings/MeetingDeviceControl.vue";
import MeetingParticipantAudio from "../meetings/MeetingParticipantAudio.vue";
import MeetingParticipantMedia from "../meetings/MeetingParticipantMedia.vue";
import MarkdownMessage from "../messages/MarkdownMessage.vue";

type InboxState = "needs-decision" | "waiting-on-you" | "in-progress";
type Priority = "high" | "normal";
type QueueView = "all" | InboxState;
type FindingSeverity = "high" | "medium" | "low";

type Finding = {
  id: string;
  title: string;
  location: string;
  severity: FindingSeverity;
  delta?: string;
  detail?: string;
  recommendation?: string;
};

type FindingDecision = "approved" | "changes-requested";
type MeetingPanel = "notes" | "chat" | "people";

type InboxItem = {
  id: string;
  state: InboxState;
  title: string;
  organization: string;
  product: string;
  project: string;
  owner: string;
  role: string;
  avatar: string;
  priority: Priority;
  priorityRank: number;
  queuedHours: number;
  queuedLabel: string;
  context: string;
  summary: string;
  findings: Finding[];
  attention?: CommunicationAttentionItem;
};

const props = defineProps<{
  attentionItems: CommunicationAttentionItem[];
  attentionStatus: "loading" | "ready" | "unavailable";
  meetings: CommunicationMeeting[];
  meetingStatus: "loading" | "ready" | "unavailable";
  currentUser: AuthUser;
  focusRequest: MeetingFocusRequest | null;
}>();

const calendarExportPending = ref(false);
const calendarExportMessage = ref("");

const emit = defineEmits<{
  viewed: [attentionId: string];
  resolved: [attentionId: string];
  "open-destination": [item: CommunicationAttentionItem];
  "meeting-updated": [meeting: CommunicationMeeting];
}>();

const tabs: { id: InboxState; label: string }[] = [
  { id: "needs-decision", label: "Needs decision" },
  { id: "waiting-on-you", label: "Waiting on you" },
  { id: "in-progress", label: "In progress" },
];

const priorities: { id: Priority; label: string }[] = [
  { id: "high", label: "High priority" },
  { id: "normal", label: "Normal priority" },
];

const organizationOptions = computed(() => {
  const names = new Set<string>();
  props.attentionItems.forEach((item) => names.add(item.organization.name));

  return [
    { id: "all", label: "All organizations" },
    ...[...names].sort().map((name) => ({ id: name, label: name })),
  ];
});

function queuedAge(createdAt: string): { hours: number; label: string } {
  const hours = Math.max(0, Math.floor((Date.now() - new Date(createdAt).getTime()) / 3_600_000));

  if (hours < 1) return { hours, label: "Queued just now" };
  if (hours < 24) return { hours, label: `Queued ${hours}h ago` };
  const days = Math.floor(hours / 24);
  return { hours, label: `Queued ${days}d ago` };
}

const allItems = computed<InboxItem[]>(() => props.attentionItems.map((attention) => {
    const age = queuedAge(attention.created_at);
    const destination = attention.destination.type === "meeting"
      ? "Meeting"
      : attention.destination.type === "channel" ? "Channel" : "Direct Message";

    return {
      id: `attention-${attention.id}`,
      state: "waiting-on-you" as const,
      title: attention.title,
      organization: attention.organization.name,
      product: "Communication",
      project: destination,
      owner: attention.actor.name,
      role: attention.kind === "meeting-action"
        ? "Meeting action"
        : attention.kind === "message-attention-request" ? "Conversation request" : "Continuation request",
      avatar: "/brand/icon.svg",
      priority: attention.priority,
      priorityRank: attention.priority === "high" ? 2 : 1,
      queuedHours: age.hours,
      queuedLabel: age.label,
      context: attention.context,
      summary: attention.reason,
      findings: [],
      attention,
    };
  }));

const organizationFilter = ref("all");
const organizationMenuOpen = ref(false);
const organizationCombobox = ref<HTMLElement | null>(null);
const organizationOptionsElement = ref<HTMLElement | null>(null);
const queueViewMenuOpen = ref(false);
const queueViewControl = ref<HTMLElement | null>(null);
const refineMenuOpen = ref(false);
const refineControl = ref<HTMLElement | null>(null);
const selectedStates = ref<InboxState[]>(tabs.map((tab) => tab.id));
const selectedPriorities = ref<Priority[]>(["high", "normal"]);
const selectedItemId = ref("");
const selectedSurface = ref<"task" | "meeting">("task");
const selectedMeetingId = ref<string | null>(null);
const feedback = ref("");
const reviewMessage = ref("");
const meetingJoined = ref(false);
const meetingRoomOpen = ref(false);
const activeRoomMeeting = ref<CommunicationMeeting | null>(null);
const meetingRoomPresence = ref<MeetingPresence[]>([]);
const meetingRoomConnecting = ref(false);
const meetingCancellationPending = ref(false);
const meetingMedia = useMeetingMedia();
const meetingMicOn = meetingMedia.microphoneEnabled;
const meetingCameraOn = meetingMedia.cameraEnabled;
const meetingCameraVisible = meetingMedia.cameraVisible;
const meetingRoomPanel = ref<MeetingPanel>("notes");
const meetingMobilePanelOpen = ref(false);
const meetingRoomNotice = ref("");
const meetingOutcomes = ref<MeetingOutcome[]>([]);
const meetingOutcomeKind = ref<MeetingOutcome["kind"]>("note");
const meetingOutcomeBody = ref("");
const meetingOutcomeAssigneeId = ref(props.currentUser.id);
const meetingOutcomeSaving = ref(false);
const meetingOutcomeKinds = ["note", "decision", "action"] as const;
const meetingRecordingPromptOpen = ref(false);
const meetingRecordingActive = ref(false);
const meetingRecordingSaved = ref(false);
const meetingRecordingSeconds = ref(0);
const meetingRecordingTranscript = ref(true);
const meetingRecordingActionItems = ref(true);
const meetingInviteOpen = ref(false);
const meetingInviteSelections = ref<string[]>([]);
const meetingInvitedPeople = ref<string[]>([]);
const meetingInviteSearch = ref("");
const meetingGuestEmail = ref("");
const meetingGuestEmails = ref<string[]>([]);
const meetingScreenShareActive = computed(() => meetingMedia.activeScreenShare.value !== null);
const meetingShareFocused = ref(false);
const meetingPanelWidth = useUiPreference(
  "meeting-panel-width",
  300,
  (value): value is number => isFiniteNumber(value) && value >= 260 && value <= 520,
);
const meetingPanelResizing = ref(false);
const meetingRoomBodyElement = ref<HTMLElement | null>(null);
const meetingReactionMenuOpen = ref(false);
const meetingReactionEvent = ref<{ kind: MeetingRoomReactionKind; label: string } | null>(null);
const meetingHandRaised = ref(false);
const meetingRaisedParticipantIds = ref<ReadonlySet<string>>(new Set());
const meetingChatDraft = ref("");
const meetingUnreadChat = ref(0);
const meetingChatMessages = ref<MeetingMessage[]>([]);
const meetingChatSaving = ref(false);
const meetingInviteCandidates = ref<Array<MeetingCandidate & { role: string; avatar: string }>>([]);
const meetingInviteCandidatesLoading = ref(false);
const meetingInvitationsSending = ref(false);
const meetingRemovingParticipantId = ref("");
const meetingGuestLinkUpdating = ref(false);
const speechListening = ref(false);
const mobileDetailOpen = ref(false);
const feedbackEditorHeight = useUiPreference(
  "inbox-feedback-editor-height",
  60,
  (value): value is number => isFiniteNumber(value) && value >= 60 && value <= 240,
);
const feedbackEditorResizing = ref(false);
const findingFeedbackHeights = useUiPreference<Record<string, number>>(
  "inbox-finding-feedback-heights",
  {},
  (value): value is Record<string, number> => {
    if (!value || typeof value !== "object" || Array.isArray(value)) return false;
    return Object.values(value).every((height) => isFiniteNumber(height) && height >= 54 && height <= 180);
  },
);
const findingFeedbackResizingId = ref<string | null>(null);
const inboxPageElement = ref<HTMLElement | null>(null);
const queueWidthPercent = useUiPreference(
  "inbox-queue-width-percent",
  39,
  (value): value is number => isFiniteNumber(value) && value >= 22 && value <= 70,
);
const paneResizing = ref(false);
const expandedFindingId = ref<string | null>(null);
const findingFeedback = ref<Record<string, string>>({});
const findingDecisions = ref<Record<string, FindingDecision>>({});
const findingMessages = ref<Record<string, string>>({});

let paneResizeHandle: HTMLElement | null = null;
let paneResizePointerId: number | null = null;
let meetingRoomRealtime: MeetingRoomRealtimeController | null = null;
let meetingRecordingTimer: number | null = null;
let meetingPanelResizeHandle: HTMLElement | null = null;
let meetingPanelResizePointerId: number | null = null;
let feedbackResizeHandle: HTMLElement | null = null;
let feedbackResizePointerId: number | null = null;
let feedbackResizeStartY = 0;
let feedbackResizeStartHeight = 60;
let findingFeedbackResizeHandle: HTMLElement | null = null;
let findingFeedbackResizePointerId: number | null = null;
let findingFeedbackResizeStartY = 0;
let findingFeedbackResizeStartHeight = 54;

const inboxPageStyle = computed(() => ({
  "--inbox-queue-width": `${queueWidthPercent.value}%`,
}));

const feedbackEditorStyle = computed(() => ({
  "--feedback-editor-height": `${feedbackEditorHeight.value}px`,
}));

const meetingRoomBodyStyle = computed(() => ({
  "--meeting-panel-width": `${meetingPanelWidth.value}px`,
}));

const meetingRecordingTime = computed(() => {
  const minutes = Math.floor(meetingRecordingSeconds.value / 60).toString().padStart(2, "0");
  const seconds = (meetingRecordingSeconds.value % 60).toString().padStart(2, "0");
  return `${minutes}:${seconds}`;
});

const filteredMeetingInviteCandidates = computed(() => {
  const query = meetingInviteSearch.value.trim().toLowerCase();
  if (!query) return meetingInviteCandidates.value;
  return meetingInviteCandidates.value.filter((candidate) => `${candidate.name} ${candidate.role}`.toLowerCase().includes(query));
});

const selectedOrganizationLabel = computed(
  () => organizationOptions.value.find((organization) => organization.id === organizationFilter.value)?.label ?? "All organizations",
);

const stateCounts = computed(() =>
  Object.fromEntries(
    tabs.map((tab) => [
      tab.id,
      allItems.value.filter(
        (item) =>
          item.state === tab.id &&
          (organizationFilter.value === "all" || item.organization === organizationFilter.value),
      ).length,
    ]),
  ) as Record<InboxState, number>,
);

const openTaskCount = computed(
  () => allItems.value.filter((item) => organizationFilter.value === "all" || item.organization === organizationFilter.value).length,
);

const upcomingMeetings = computed(() => props.meetings
  .filter((meeting) => meeting.status === "live" || (meeting.status === "scheduled" && Date.parse(meeting.starts_at) >= Date.now() - 60 * 60 * 1000))
  .sort((left, right) => Date.parse(left.starts_at) - Date.parse(right.starts_at)));
const featuredMeeting = computed(() => (
  selectedMeetingId.value
    ? props.meetings.find((meeting) => meeting.id === selectedMeetingId.value) ?? null
    : upcomingMeetings.value[0] ?? null
));
const currentUserOrganizesFeaturedMeeting = computed(() => featuredMeeting.value?.organizer.id === props.currentUser.id);
const roomMeeting = computed(() => activeRoomMeeting.value ?? featuredMeeting.value);
const meetingElapsedTime = useMeetingElapsedTime(() => roomMeeting.value?.started_at ?? null);
const currentUserOrganizesRoomMeeting = computed(() => roomMeeting.value?.organizer.id === props.currentUser.id);
const meetingEmailInvitations = computed(() => roomMeeting.value?.guest_invitations ?? []);
const meetingOutcomeAssignees = computed(() => roomMeeting.value?.participants ?? []);
const meetingJoinLabel = computed(() => {
  if (meetingRoomConnecting.value) return "Connecting…";
  if (meetingJoined.value) return "Joined";
  if (featuredMeeting.value?.status === "scheduled" && currentUserOrganizesFeaturedMeeting.value) return "Start meeting";
  return "Join meeting";
});
const roomMeetingParticipants = computed(() => {
  const meeting = roomMeeting.value;

  if (!meeting) {
    return [];
  }

  if (activeRoomMeeting.value) {
    const present = meetingRoomPresence.value.length
      ? meetingRoomPresence.value
      : [{ id: props.currentUser.id, name: props.currentUser.name }];

    return present
      .sort((left, right) => Number(right.id === meeting.organizer.id) - Number(left.id === meeting.organizer.id))
      .map((participant) => ({
        ...(() => {
          const details = meeting.participants.find((candidate) => candidate.id === participant.id);
          return {
            participantId: details?.participant_id,
            canRemove: details?.can_remove,
            canBlockReentry: details?.can_block_reentry,
          };
        })(),
        id: participant.id,
        name: participant.name,
        role: participant.id === meeting.organizer.id ? "Meeting organizer" : "Meeting participant",
        avatar: meetingAvatar(participant.name),
      }));
  }

  return meeting.participants
    .filter((participant) => !meetingInvitedPeople.value.includes(participant.name))
    .sort((left, right) => Number(right.id === meeting.organizer.id) - Number(left.id === meeting.organizer.id))
    .map((participant) => ({
      id: participant.id,
      participantId: participant.participant_id,
      name: participant.name,
      role: participant.id === meeting.organizer.id ? "Meeting organizer" : "Meeting participant",
      avatar: meetingAvatar(participant.name),
      canRemove: participant.can_remove,
      canBlockReentry: participant.can_block_reentry,
    }));
});
const roomMeetingPeople = computed(() => {
  const meeting = roomMeeting.value;
  if (!meeting) return roomMeetingParticipants.value.map((participant) => ({ ...participant, isPresent: true }));
  const rosterMeeting = props.meetings.find((candidate) => candidate.id === meeting.id) ?? meeting;
  const presentIds = new Set(meetingRoomPresence.value.map((participant) => participant.id));

  return rosterMeeting.participants
    .map((participant) => ({
      id: participant.id,
      participantId: participant.participant_id,
      name: participant.name,
      role: participant.id === meeting.organizer.id
        ? "Meeting organizer"
        : participant.kind === "guest"
          ? presentIds.has(participant.id) ? "Meeting guest" : "Meeting guest · not in room"
          : presentIds.has(participant.id) ? "Meeting participant" : "Meeting participant · not in room",
      avatar: meetingAvatar(participant.name),
      canRemove: participant.can_remove,
      canBlockReentry: participant.can_block_reentry,
      isPresent: presentIds.has(participant.id),
    }))
    .sort((left, right) => Number(right.id === meeting.organizer.id) - Number(left.id === meeting.organizer.id));
});
const activeMeetingScreenShare = meetingMedia.activeScreenShare;
const sharingRoomParticipant = computed(() => {
  const identity = activeMeetingScreenShare.value?.identity;
  return roomMeetingParticipants.value.find((participant) => meetingMediaIdentity(participant) === identity) ?? null;
});

function meetingMediaIdentity(participant: { participantId?: string }): string | null {
  return participant.participantId ? `mp_${participant.participantId.toLowerCase()}` : null;
}

function meetingMediaFor(participant: { participantId?: string }) {
  return meetingMedia.forIdentity(meetingMediaIdentity(participant));
}

function meetingParticipantHasVisibleCamera(participant: { id: string; participantId?: string }): boolean {
  return Boolean(meetingMediaFor(participant)?.camera)
    && (participant.id !== props.currentUser.id || meetingCameraVisible.value);
}

function meetingParticipantMicrophoneMuted(participant: { participantId?: string }): boolean {
  return meetingMediaFor(participant)?.microphoneMuted === true;
}

function meetingParticipantHasRaisedHand(participant: { id: string }): boolean {
  return meetingRaisedParticipantIds.value.has(participant.id);
}

function meetingTimeLabel(startsAt: string, status?: CommunicationMeeting["status"]): string {
  if (status === "completed") return "Ended";
  if (status === "cancelled") return "Cancelled";

  const minutes = Math.round((Date.parse(startsAt) - Date.now()) / 60000);
  if (minutes <= 0) return "Starting now";
  if (minutes < 60) return `In ${minutes}m`;
  if (minutes < 24 * 60) return `In ${Math.round(minutes / 60)}h`;
  return new Intl.DateTimeFormat(undefined, { month: "short", day: "numeric", hour: "numeric", minute: "2-digit" }).format(new Date(startsAt));
}

function meetingAvatar(name: string): string {
  const key = name.toLowerCase().split(/\s+/)[0];
  return ["artisan", "atlas", "envoy", "katra", "sentinel", "vector"].includes(key)
    ? `/avatars/${key}.png`
    : "/brand/icon.svg";
}

function readableMeetingError(error: unknown, fallback = "Meeting invitations are unavailable. Please try again."): string {
  if (error instanceof CommunicationRequestError) {
    return Object.values(error.fields).flat()[0] ?? error.message;
  }

  return fallback;
}

async function loadMeetingInviteCandidates(): Promise<void> {
  const meeting = roomMeeting.value;
  meetingInviteCandidates.value = [];
  meetingInviteSelections.value = [];
  if (!meeting || !meeting.organization.id || meeting.organizer.id !== props.currentUser.id) return;

  meetingInviteCandidatesLoading.value = true;

  try {
    const existingIds = new Set(meeting.participants.map((participant) => participant.id));
    const candidates = await getMeetingCandidates(meeting.organization.id);
    meetingInviteCandidates.value = candidates
      .filter((candidate) => !existingIds.has(candidate.id))
      .map((candidate) => ({
        ...candidate,
        role: candidate.kind === "client" ? "Client participant" : "DevOption",
        avatar: meetingAvatar(candidate.name),
      }));
  } catch (error) {
    showMeetingRoomNotice(readableMeetingError(error));
  } finally {
    meetingInviteCandidatesLoading.value = false;
  }
}

watch([featuredMeeting, activeRoomMeeting], () => { void loadMeetingInviteCandidates(); }, { immediate: true });
watch([selectedSurface, featuredMeeting], ([surface, meeting]) => {
  if (surface !== "meeting" || !meeting || activeRoomMeeting.value?.id === meeting.id) return;
  applyMeetingChat([]);
  void loadMeetingChat(meeting);
});

const queueViewLabel = computed(() => {
  const hasEveryPriority = selectedPriorities.value.length === 2;

  if (hasEveryPriority && selectedStates.value.length === tabs.length) {
    return "All open tasks";
  }

  if (hasEveryPriority && selectedStates.value.length === 1) {
    return tabs.find((tab) => tab.id === selectedStates.value[0])?.label ?? "Filtered tasks";
  }

  return "Filtered tasks";
});

const activeRefineCount = computed(
  () => tabs.length - selectedStates.value.length + (2 - selectedPriorities.value.length),
);

const visibleItems = computed(() =>
  allItems.value
    .filter(
      (item) =>
        selectedStates.value.includes(item.state) &&
        selectedPriorities.value.includes(item.priority) &&
        (organizationFilter.value === "all" || item.organization === organizationFilter.value),
    )
    .sort(
      (left, right) =>
        right.priorityRank - left.priorityRank || right.queuedHours - left.queuedHours,
    ),
);

const selectedItem = computed(
  () => allItems.value.find((item) => item.id === selectedItemId.value) ?? allItems.value[0] ?? null,
);

watch([organizationFilter, selectedStates, selectedPriorities, allItems], () => {
  const nextItem = visibleItems.value[0];

  if (nextItem && !visibleItems.value.some((item) => item.id === selectedItemId.value)) {
    selectedItemId.value = nextItem.id;
    selectedSurface.value = "task";
  }

  reviewMessage.value = "";
  mobileDetailOpen.value = false;
});

function selectItem(itemId: string) {
  selectedItemId.value = itemId;
  selectedSurface.value = "task";
  reviewMessage.value = "";
  expandedFindingId.value = null;
  mobileDetailOpen.value = true;

  const attention = allItems.value.find((item) => item.id === itemId)?.attention;
  if (attention && !attention.viewed_at) emit("viewed", attention.id);
}

function openSelectedAttention() {
  if (selectedItem.value?.attention) emit("open-destination", selectedItem.value.attention);
}

function resolveSelectedAttention() {
  if (selectedItem.value?.attention) emit("resolved", selectedItem.value.attention.id);
}

function selectMeeting() {
  const firstMeeting = upcomingMeetings.value[0];
  if (!firstMeeting) return;
  selectedMeetingId.value = firstMeeting.id;
  selectedSurface.value = "meeting";
  mobileDetailOpen.value = true;
}

watch(() => props.focusRequest?.nonce, () => {
  const meetingId = props.focusRequest?.meetingId;
  if (!meetingId || !props.meetings.some((meeting) => meeting.id === meetingId)) return;
  selectedMeetingId.value = meetingId;
  selectedSurface.value = "meeting";
  mobileDetailOpen.value = true;
}, { immediate: true });

function acceptWork() {
  reviewMessage.value = "Work accepted. Katra can continue the workflow.";
}

function requestChanges() {
  reviewMessage.value = feedback.value.trim()
    ? "Feedback sent to the agent. This item remains in Needs decision."
    : "Add feedback before requesting changes.";
}

function connectMeetingPresence(meeting: CommunicationMeeting) {
  meetingRoomRealtime?.stop();
  meetingRoomPresence.value = [];
  meetingRoomRealtime = startMeetingRoomRealtime({
    meetingId: meeting.id,
    onPresence(users) {
      meetingRoomPresence.value = users;
      meetingRaisedParticipantIds.value = retainPresentRaisedParticipantIds(
        meetingRaisedParticipantIds.value,
        new Set(users.map((user) => user.id)),
      );
    },
    onStateChange(event) {
      if (!activeRoomMeeting.value || event.meeting_id !== activeRoomMeeting.value.id) return;
      const updated = { ...activeRoomMeeting.value, status: event.status };
      activeRoomMeeting.value = updated;
      emit("meeting-updated", updated);
      if (event.status === "completed") {
        showMeetingRoomNotice("The organizer ended this meeting.");
        void leaveMeeting();
      }
      if (event.status === "cancelled") {
        showMeetingRoomNotice("This meeting was cancelled.");
        void leaveMeeting();
      }
    },
    onParticipantAccessChange(event) {
      const meeting = activeRoomMeeting.value;
      if (!meeting || event.meeting_id !== meeting.id) return;
      const affected = meeting.participants.find((participant) => participant.participant_id === event.participant_id);

      if (event.operation === "removed") {
        const updated = {
          ...meeting,
          participants: meeting.participants.filter((participant) => participant.participant_id !== event.participant_id),
        };
        activeRoomMeeting.value = updated;
        if (affected) meetingRoomPresence.value = meetingRoomPresence.value.filter((participant) => participant.id !== affected.id);
        emit("meeting-updated", updated);
        if (affected?.id === props.currentUser.id) {
          void meetingMedia.disconnect();
          showMeetingRoomNotice("The organizer removed you from this meeting.");
          void leaveMeeting();
        }
        else void connectMeetingMedia(meeting.id);
        return;
      }

      void getMeeting(meeting.id).then((updated) => {
        activeRoomMeeting.value = updated;
        emit("meeting-updated", updated);
      }).catch(() => undefined);
    },
    onOutcomeChange(event) {
      if (event.meeting_id === activeRoomMeeting.value?.id) void loadMeetingOutcomes();
    },
    onMessageChange(event) {
      if (event.meeting_id !== activeRoomMeeting.value?.id) return;
      if (!meetingChatMessages.value.some((message) => message.id === event.message_id) && meetingRoomPanel.value !== "chat") {
        meetingUnreadChat.value += 1;
      }
      void loadMeetingChat(activeRoomMeeting.value);
    },
    onMessageReactionChange(event) {
      if (event.meeting_id === activeRoomMeeting.value?.id) void loadMeetingChat(activeRoomMeeting.value);
    },
    onRoomReaction: showIncomingMeetingReaction,
    onError() {
      showMeetingRoomNotice("Live presence is reconnecting. Your meeting access is still active.");
    },
  });
}

function applyMeetingOutcomes(outcomes: MeetingOutcome[]): void {
  meetingOutcomes.value = [...outcomes].sort((left, right) => left.sequence - right.sequence);
  if (!activeRoomMeeting.value) return;
  const updated = { ...activeRoomMeeting.value, outcomes: meetingOutcomes.value };
  activeRoomMeeting.value = updated;
  emit("meeting-updated", updated);
}

async function loadMeetingOutcomes(): Promise<void> {
  const meeting = activeRoomMeeting.value;
  if (!meeting) return;

  try {
    applyMeetingOutcomes(await getMeetingOutcomes(meeting.id));
  } catch {
    showMeetingRoomNotice("Meeting outcomes are reconnecting.");
  }
}

async function addMeetingOutcome(): Promise<void> {
  const meeting = activeRoomMeeting.value;
  const body = meetingOutcomeBody.value.trim();
  if (!meeting || meeting.status !== "live" || !body || meetingOutcomeSaving.value) return;
  meetingOutcomeSaving.value = true;

  try {
    const created = await createMeetingOutcome(meeting.id, {
      kind: meetingOutcomeKind.value,
      body,
      assignee_user_id: meetingOutcomeKind.value === "action" ? meetingOutcomeAssigneeId.value : null,
    });
    applyMeetingOutcomes([...meetingOutcomes.value.filter((outcome) => outcome.id !== created.id), created]);
    meetingOutcomeBody.value = "";
    showMeetingRoomNotice(meetingOutcomeKind.value === "action" ? "Action added to the assignee’s Inbox." : "Meeting outcome saved.");
  } catch (error) {
    showMeetingRoomNotice(readableMeetingError(error, "The meeting outcome could not be saved."));
  } finally {
    meetingOutcomeSaving.value = false;
  }
}

function applyMeetingChat(messages: MeetingMessage[]): void {
  meetingChatMessages.value = [...messages].sort((left, right) => left.sequence - right.sequence);
}

async function loadMeetingChat(meeting: CommunicationMeeting | null = activeRoomMeeting.value): Promise<void> {
  if (!meeting) return;

  try {
    applyMeetingChat((await getMeetingMessages(meeting.id)).data);
  } catch {
    showMeetingRoomNotice("Meeting chat is reconnecting.");
  }
}

async function sendMeetingChat(): Promise<void> {
  const meeting = activeRoomMeeting.value;
  const body = meetingChatDraft.value.trim();
  if (!meeting || meeting.status !== "live" || !body || meetingChatSaving.value) return;
  meetingChatSaving.value = true;

  try {
    const created = await createMeetingMessage(meeting.id, body);
    applyMeetingChat([...meetingChatMessages.value.filter((message) => message.id !== created.id), created]);
    meetingChatDraft.value = "";
  } catch (error) {
    showMeetingRoomNotice(readableMeetingError(error, "The meeting message could not be sent."));
  } finally {
    meetingChatSaving.value = false;
  }
}

async function toggleMeetingChatReaction(message: MeetingMessage, kind: MeetingMessageReaction["kind"]): Promise<void> {
  const meeting = activeRoomMeeting.value;
  if (!meeting || meeting.status !== "live") return;
  const reacted = message.reactions.find((reaction) => reaction.kind === kind)?.reacted_by_current_user ?? false;

  try {
    const updated = await setMeetingMessageReaction(meeting.id, message.id, kind, !reacted);
    applyMeetingChat(meetingChatMessages.value.map((candidate) => candidate.id === updated.id ? updated : candidate));
  } catch (error) {
    showMeetingRoomNotice(readableMeetingError(error, "The meeting reaction could not be changed."));
  }
}

function showIncomingMeetingReaction(event: MeetingRoomReactionEvent): void {
  const meeting = activeRoomMeeting.value;
  if (!meeting || event.meeting_id !== meeting.id) return;
  const actor = meeting.participants.find((participant) => participant.id === event.actor_user_id)?.name ?? "A participant";
  const labels: Record<MeetingRoomReactionKind, string> = {
    approve: `${actor} reacted with approval`,
    support: `${actor} showed support`,
    celebrate: `${actor} celebrated`,
    "raise-hand": `${actor} raised a hand`,
    "lower-hand": `${actor} lowered a hand`,
  };
  meetingRaisedParticipantIds.value = updateRaisedParticipantIds(
    meetingRaisedParticipantIds.value,
    event.actor_user_id,
    event.kind,
  );
  if (event.actor_user_id === props.currentUser.id && (event.kind === "raise-hand" || event.kind === "lower-hand")) {
    meetingHandRaised.value = event.kind === "raise-hand";
  }
  meetingReactionEvent.value = { kind: event.kind, label: labels[event.kind] };
  window.setTimeout(() => {
    if (meetingReactionEvent.value?.label === labels[event.kind]) meetingReactionEvent.value = null;
  }, 2200);
}

async function joinMeeting() {
  const selectedMeeting = featuredMeeting.value;
  if (!selectedMeeting || meetingRoomConnecting.value) return;
  selectedMeetingId.value = selectedMeeting.id;

  if (meetingJoined.value && activeRoomMeeting.value?.id === selectedMeeting.id) {
    meetingRoomOpen.value = true;
    return;
  }

  meetingRoomConnecting.value = true;

  try {
    let meeting = selectedMeeting;
    if (meeting.status === "scheduled") {
      if (meeting.organizer.id !== props.currentUser.id) {
        calendarExportMessage.value = "This room will open when the organizer starts the meeting.";
        return;
      }
      meeting = await updateMeetingRoom(meeting.id, "start");
      emit("meeting-updated", meeting);
    }

    if (meeting.status !== "live") {
      calendarExportMessage.value = "This meeting is no longer available to join.";
      return;
    }

    const entered = await updateMeetingRoom(meeting.id, "join");
    activeRoomMeeting.value = entered;
    meetingOutcomes.value = entered.outcomes;
    applyMeetingChat([]);
    meetingJoined.value = true;
    meetingRoomOpen.value = true;
    meetingMobilePanelOpen.value = false;
    selectedSurface.value = "meeting";
    mobileDetailOpen.value = true;
    calendarExportMessage.value = "";
    emit("meeting-updated", entered);
    await connectMeetingMedia(entered.id);
    connectMeetingPresence(entered);
    void loadMeetingOutcomes();
    void loadMeetingChat(entered);
  } catch (error) {
    calendarExportMessage.value = readableMeetingError(error, "The meeting room is unavailable. Please try again.");
  } finally {
    meetingRoomConnecting.value = false;
  }
}

async function connectMeetingMedia(meetingId: string): Promise<void> {
  try {
    await meetingMedia.connect(await getMeetingMediaCredential(meetingId));
    if (meetingMedia.failure.value) {
      showMeetingRoomNotice("Connected without camera or microphone. Use the controls to retry after checking browser permissions.");
    }
  } catch (error) {
    showMeetingRoomNotice(readableMeetingError(error, "Audio and video could not connect. Check browser permissions and try again."));
  }
}

function minimizeMeetingRoom() {
  meetingMobilePanelOpen.value = false;
  meetingRoomOpen.value = false;
}

async function leaveMeeting() {
  const meeting = activeRoomMeeting.value;
  meetingRoomRealtime?.stop();
  meetingRoomRealtime = null;
  meetingRoomPresence.value = [];
  meetingRaisedParticipantIds.value = new Set();
  await meetingMedia.disconnect();
  if (meeting) await updateMeetingRoom(meeting.id, "leave").catch(() => undefined);
  clearMeetingRecordingTimer();
  meetingRoomOpen.value = false;
  meetingJoined.value = false;
  activeRoomMeeting.value = null;
  meetingRecordingPromptOpen.value = false;
  meetingRecordingActive.value = false;
  meetingRecordingSaved.value = false;
  meetingRecordingSeconds.value = 0;
  meetingInviteOpen.value = false;
  meetingMobilePanelOpen.value = false;
  meetingInvitedPeople.value = [];
  meetingGuestEmails.value = [];
  meetingShareFocused.value = false;
  meetingReactionMenuOpen.value = false;
  meetingReactionEvent.value = null;
  meetingHandRaised.value = false;
}

async function cancelFeaturedMeeting() {
  const meeting = featuredMeeting.value;
  if (!meeting || meeting.organizer.id !== props.currentUser.id || meetingCancellationPending.value) return;
  meetingCancellationPending.value = true;

  try {
    const cancelled = await updateMeetingRoom(meeting.id, "cancel");
    emit("meeting-updated", cancelled);
    calendarExportMessage.value = "Meeting cancelled.";
  } catch (error) {
    calendarExportMessage.value = readableMeetingError(error, "The meeting could not be cancelled.");
  } finally {
    meetingCancellationPending.value = false;
  }
}

function openMeetingPanel(panel: MeetingPanel) {
  meetingRoomPanel.value = panel;
  meetingMobilePanelOpen.value = true;
  if (panel !== "people") meetingInviteOpen.value = false;
  if (panel === "chat") meetingUnreadChat.value = 0;
}

function toggleMeetingMobilePanel() {
  meetingMobilePanelOpen.value = !meetingMobilePanelOpen.value;
  meetingReactionMenuOpen.value = false;
  meetingRecordingPromptOpen.value = false;
  if (!meetingMobilePanelOpen.value) meetingInviteOpen.value = false;
}

function closeMeetingMobilePanel() {
  meetingMobilePanelOpen.value = false;
  meetingInviteOpen.value = false;
}

function toggleMeetingInviteSelection(id: string) {
  meetingInviteSelections.value = meetingInviteSelections.value.includes(id)
    ? meetingInviteSelections.value.filter((candidate) => candidate !== id)
    : [...meetingInviteSelections.value, id];
}

async function sendMeetingInvitations() {
  const meeting = roomMeeting.value;
  if (!meeting || meetingInvitationsSending.value) return;

  if (!currentUserOrganizesRoomMeeting.value) {
    showMeetingRoomNotice("Only the meeting organizer can invite people.");
    return;
  }

  if (!meetingInviteSelections.value.length && !meetingGuestEmails.value.length) {
    showMeetingRoomNotice("Choose at least one person to invite.");
    return;
  }

  meetingInvitationsSending.value = true;

  try {
    const selected = meetingInviteCandidates.value.filter((candidate) => meetingInviteSelections.value.includes(candidate.id));
    const emailCount = meetingGuestEmails.value.length;
    let updated = meeting;
    if (emailCount) updated = await addMeetingGuestInvitations(updated.id, meetingGuestEmails.value);
    if (selected.length) updated = await addMeetingParticipants(updated.id, selected.map((candidate) => candidate.id));
    activeRoomMeeting.value = updated;
    meetingInvitedPeople.value = meetingInvitedPeople.value.filter((name) => !updated.participants.some((participant) => participant.name === name));
    meetingInviteSelections.value = [];
    meetingGuestEmails.value = [];
    meetingInviteOpen.value = false;
    emit("meeting-updated", updated);
    const total = selected.length + emailCount;
    showMeetingRoomNotice(`${total} meeting invitation${total === 1 ? "" : "s"} ready. Email delivery is queued.`);
  } catch (error) {
    showMeetingRoomNotice(readableMeetingError(error));
  } finally {
    meetingInvitationsSending.value = false;
  }
}

function meetingEmailInvitationLabel(status: CommunicationMeeting["guest_invitations"][number]["status"]): string {
  return {
    pending: "Pending delivery",
    queued: "Email queued",
    sent: "Email sent · awaiting response",
    failed: "Delivery failed · resend available",
    admitted: "Joined by email invitation",
    removed: "Removed · invitation still available",
    revoked: "Invitation revoked",
  }[status];
}

async function removeRoomMeetingParticipant(participant: {
  id: string;
  name: string;
  participantId?: string;
  canRemove?: boolean;
}, blockReentry: boolean): Promise<void> {
  const meeting = roomMeeting.value;
  if (!meeting || !participant.participantId || !participant.canRemove || meetingRemovingParticipantId.value) return;
  meetingRemovingParticipantId.value = participant.participantId;

  try {
    const updated = await removeMeetingParticipant(meeting.id, participant.participantId, blockReentry);
    activeRoomMeeting.value = updated;
    meetingRoomPresence.value = meetingRoomPresence.value.filter((present) => present.id !== participant.id);
    emit("meeting-updated", updated);
    showMeetingRoomNotice(blockReentry ? `${participant.name} was removed and their email invitation was blocked.` : `${participant.name} was removed from the meeting.`);
  } catch (error) {
    showMeetingRoomNotice(readableMeetingError(error, "The participant could not be removed."));
  } finally {
    meetingRemovingParticipantId.value = "";
  }
}

async function manageMeetingEmailInvitation(invitationId: string, command: "resend" | "revoke"): Promise<void> {
  const meeting = roomMeeting.value;
  if (!meeting || meetingInvitationsSending.value) return;
  meetingInvitationsSending.value = true;
  try {
    const updated = await updateMeetingGuestInvitation(meeting.id, invitationId, command);
    activeRoomMeeting.value = updated;
    emit("meeting-updated", updated);
    showMeetingRoomNotice(command === "resend" ? "A fresh email invitation is queued." : "Email invitation revoked.");
  } catch (error) {
    showMeetingRoomNotice(readableMeetingError(error));
  } finally {
    meetingInvitationsSending.value = false;
  }
}

function addMeetingGuestEmail() {
  const email = meetingGuestEmail.value.trim().toLowerCase();
  if (!email.includes("@") || meetingGuestEmails.value.includes(email)) return;
  meetingGuestEmails.value = [...meetingGuestEmails.value, email];
  meetingGuestEmail.value = "";
}

async function exportMeetingCalendar() {
  const meeting = featuredMeeting.value;
  if (!meeting || calendarExportPending.value) return;

  calendarExportPending.value = true;
  calendarExportMessage.value = "";

  try {
    const calendar = await getMeetingCalendar(meeting.id);
    const url = URL.createObjectURL(calendar);
    const anchor = document.createElement("a");
    anchor.href = url;
    anchor.download = `${meeting.title.toLowerCase().replace(/[^a-z0-9]+/g, "-").replace(/^-|-$/g, "") || "katra-meeting"}.ics`;
    anchor.click();
    window.setTimeout(() => URL.revokeObjectURL(url), 1_000);
    calendarExportMessage.value = "Calendar event downloaded.";
  } catch {
    calendarExportMessage.value = "Calendar export is unavailable. Please try again.";
  } finally {
    calendarExportPending.value = false;
  }
}

async function copyMeetingInvitation(kind: "link" | "details") {
  const meeting = roomMeeting.value;
  const link = meeting?.guest_link_url;
  if (!link) {
    showMeetingRoomNotice("Create a new guest link before copying it.");
    return;
  }
  const details = `${meeting?.title ?? "Katra meeting"}\n${meeting?.organization.name ?? "Katra"} · Katra room\n${link}`;
  await navigator.clipboard?.writeText(kind === "link" ? link : details).catch(() => undefined);
  showMeetingRoomNotice(kind === "link" ? "Meeting link copied." : "Meeting details copied.");
}

async function manageMeetingGuestLink(command: "revoke" | "regenerate"): Promise<void> {
  const meeting = roomMeeting.value;
  if (!meeting || !currentUserOrganizesRoomMeeting.value || meetingGuestLinkUpdating.value) return;
  meetingGuestLinkUpdating.value = true;

  try {
    const updated = await updateMeetingGuestLink(meeting.id, command);
    activeRoomMeeting.value = updated;
    emit("meeting-updated", updated);
    showMeetingRoomNotice(command === "revoke" ? "Guest link revoked. Admitted guests stay connected." : "A new guest link is ready.");
  } catch (error) {
    showMeetingRoomNotice(readableMeetingError(error, "The guest link could not be changed."));
  } finally {
    meetingGuestLinkUpdating.value = false;
  }
}

function clearMeetingRecordingTimer() {
  if (meetingRecordingTimer !== null) {
    window.clearInterval(meetingRecordingTimer);
    meetingRecordingTimer = null;
  }
}

function openMeetingRecordingPrompt() {
  meetingReactionMenuOpen.value = false;
  meetingRecordingPromptOpen.value = true;
}

function startMeetingRecording() {
  meetingRecordingPromptOpen.value = false;
  showMeetingRoomNotice("Recording is not available in this release yet.");
}

function stopMeetingRecording() {
  clearMeetingRecordingTimer();
  meetingRecordingActive.value = false;
  meetingRecordingSaved.value = true;
  meetingRecordingPromptOpen.value = false;
  meetingShareFocused.value = false;
  meetingRoomPanel.value = "notes";
  showMeetingRoomNotice("Recording saved. Transcript and action items are processing.");
}

function toggleMeetingRecording() {
  meetingRecordingPromptOpen.value = false;
  showMeetingRoomNotice("Recording is not available in this release yet.");
}

async function toggleMeetingScreenShare() {
  meetingRecordingPromptOpen.value = false;
  try {
    await meetingMedia.setScreenShareEnabled(!meetingMedia.screenShareEnabled.value);
    meetingShareFocused.value = false;
    showMeetingRoomNotice(meetingMedia.screenShareEnabled.value ? "You are sharing your screen." : "Screen sharing stopped.");
  } catch {
    showMeetingRoomNotice("Screen sharing could not be changed. Check browser permissions and try again.");
  }
}

async function toggleMeetingMicrophone(): Promise<void> {
  try {
    await meetingMedia.setMicrophoneEnabled(!meetingMicOn.value);
  } catch {
    showMeetingRoomNotice("The microphone could not be changed. Check browser permissions and try again.");
  }
}

async function toggleMeetingCamera(): Promise<void> {
  try {
    await meetingMedia.setCameraEnabled(!meetingCameraOn.value);
  } catch {
    showMeetingRoomNotice("The camera could not be changed. Check browser permissions and try again.");
  }
}

async function selectMeetingMicrophone(deviceId: string): Promise<void> {
  try {
    await meetingMedia.selectAudioInput(deviceId);
    showMeetingRoomNotice("Microphone changed.");
  } catch {
    showMeetingRoomNotice("That microphone is unavailable. Choose another device.");
  }
}

async function selectMeetingCamera(deviceId: string): Promise<void> {
  try {
    await meetingMedia.selectVideoInput(deviceId);
    showMeetingRoomNotice("Camera changed.");
  } catch {
    showMeetingRoomNotice("That camera is unavailable. Choose another device.");
  }
}

function toggleMeetingShareFocus() {
  meetingShareFocused.value = !meetingShareFocused.value;
  meetingReactionMenuOpen.value = false;
}

function clampMeetingPanelWidth(width: number) {
  const bodyWidth = meetingRoomBodyElement.value?.getBoundingClientRect().width ?? 1024;
  const maximum = Math.min(520, Math.max(320, bodyWidth - 500));
  return Math.round(Math.min(maximum, Math.max(260, width)));
}

function resizeMeetingPanel(event: PointerEvent) {
  if (!meetingPanelResizing.value) return;
  const bounds = meetingRoomBodyElement.value?.getBoundingClientRect();
  if (!bounds) return;
  meetingPanelWidth.value = clampMeetingPanelWidth(bounds.right - event.clientX - 10);
}

function startMeetingPanelResize(event: PointerEvent) {
  if (event.pointerType === "mouse" && event.button !== 0) return;
  event.preventDefault();
  meetingPanelResizeHandle = event.currentTarget as HTMLElement;
  meetingPanelResizePointerId = event.pointerId;
  meetingPanelResizeHandle.setPointerCapture(event.pointerId);
  meetingPanelResizing.value = true;
  window.addEventListener("pointermove", resizeMeetingPanel);
  window.addEventListener("pointerup", stopMeetingPanelResize);
  window.addEventListener("pointercancel", stopMeetingPanelResize);
  resizeMeetingPanel(event);
}

function stopMeetingPanelResize() {
  if (!meetingPanelResizing.value) return;
  if (
    meetingPanelResizeHandle &&
    meetingPanelResizePointerId !== null &&
    meetingPanelResizeHandle.hasPointerCapture(meetingPanelResizePointerId)
  ) {
    meetingPanelResizeHandle.releasePointerCapture(meetingPanelResizePointerId);
  }
  meetingPanelResizing.value = false;
  meetingPanelResizeHandle = null;
  meetingPanelResizePointerId = null;
  window.removeEventListener("pointermove", resizeMeetingPanel);
  window.removeEventListener("pointerup", stopMeetingPanelResize);
  window.removeEventListener("pointercancel", stopMeetingPanelResize);
}

function resizeMeetingPanelWithKeyboard(event: KeyboardEvent) {
  if (!["ArrowLeft", "ArrowRight", "Home", "End"].includes(event.key)) return;
  event.preventDefault();
  if (event.key === "Home") meetingPanelWidth.value = clampMeetingPanelWidth(260);
  else if (event.key === "End") meetingPanelWidth.value = clampMeetingPanelWidth(520);
  else meetingPanelWidth.value = clampMeetingPanelWidth(
    meetingPanelWidth.value + (event.key === "ArrowLeft" ? 20 : -20),
  );
}

function meetingReactionIcon(kind: MeetingRoomReactionKind | MeetingMessageReaction["kind"]) {
  if (kind === "support") return Heart;
  if (kind === "celebrate") return PartyPopper;
  if (kind === "raise-hand" || kind === "lower-hand") return Hand;
  return ThumbsUp;
}

async function sendMeetingReaction(kind: Exclude<MeetingRoomReactionKind, "lower-hand">) {
  meetingReactionMenuOpen.value = false;
  const meeting = activeRoomMeeting.value;
  if (!meeting) return;
  let command: MeetingRoomReactionKind = kind;
  if (kind === "raise-hand") {
    meetingHandRaised.value = !meetingHandRaised.value;
    command = meetingHandRaised.value ? "raise-hand" : "lower-hand";
    meetingRaisedParticipantIds.value = updateRaisedParticipantIds(
      meetingRaisedParticipantIds.value,
      props.currentUser.id,
      command,
    );
  }
  await sendMeetingRoomReaction(meeting.id, command).catch(() => {
    if (kind === "raise-hand") {
      meetingHandRaised.value = !meetingHandRaised.value;
      meetingRaisedParticipantIds.value = updateRaisedParticipantIds(
        meetingRaisedParticipantIds.value,
        props.currentUser.id,
        meetingHandRaised.value ? "raise-hand" : "lower-hand",
      );
    }
    showMeetingRoomNotice("The room reaction could not be sent.");
  });
}

function showMeetingRoomNotice(message: string) {
  meetingRoomNotice.value = message;
  window.setTimeout(() => {
    if (meetingRoomNotice.value === message) meetingRoomNotice.value = "";
  }, 2200);
}

function handleMeetingRoomKeydown(event: KeyboardEvent) {
  if (event.key !== "Escape" || !meetingRoomOpen.value) return;
  if (meetingInviteOpen.value) {
    meetingInviteOpen.value = false;
    return;
  }
  if (meetingRecordingPromptOpen.value) {
    meetingRecordingPromptOpen.value = false;
    return;
  }
  if (meetingReactionMenuOpen.value) {
    meetingReactionMenuOpen.value = false;
    return;
  }
  if (meetingMobilePanelOpen.value) {
    closeMeetingMobilePanel();
    return;
  }
  if (meetingShareFocused.value) {
    meetingShareFocused.value = false;
    return;
  }
  minimizeMeetingRoom();
}

function findingIcon(severity: FindingSeverity) {
  if (severity === "high") {
    return ShieldAlert;
  }

  if (severity === "medium") {
    return CircleAlert;
  }

  return Info;
}

async function openOrganizationMenu() {
  closeQueueViewMenu();
  closeRefineMenu();
  organizationMenuOpen.value = true;
  await nextTick();
  organizationOptionsElement.value
    ?.querySelector<HTMLButtonElement>('[aria-selected="true"]')
    ?.focus();
}

function closeOrganizationMenu(returnFocus = false) {
  organizationMenuOpen.value = false;

  if (returnFocus) {
    organizationCombobox.value?.querySelector<HTMLButtonElement>(".organization-filter-trigger")?.focus();
  }
}

function selectOrganization(organizationId: string) {
  organizationFilter.value = organizationId;
  closeOrganizationMenu(true);
}

function handleOrganizationTriggerKeydown(event: KeyboardEvent) {
  if (["ArrowDown", "Enter", " "].includes(event.key)) {
    event.preventDefault();
    void openOrganizationMenu();
  }
}

function handleOrganizationOptionsKeydown(event: KeyboardEvent) {
  if (event.key === "Escape") {
    event.preventDefault();
    closeOrganizationMenu(true);
    return;
  }

  if (!["ArrowDown", "ArrowUp", "Home", "End"].includes(event.key)) {
    return;
  }

  const options = Array.from(
    organizationOptionsElement.value?.querySelectorAll<HTMLButtonElement>(".organization-option") ?? [],
  );

  if (!options.length) {
    return;
  }

  event.preventDefault();
  const currentIndex = Math.max(0, options.indexOf(document.activeElement as HTMLButtonElement));
  let nextIndex = currentIndex;

  if (event.key === "ArrowDown" || event.key === "ArrowUp") {
    const direction = event.key === "ArrowDown" ? 1 : -1;
    nextIndex = (currentIndex + direction + options.length) % options.length;
  } else {
    nextIndex = event.key === "Home" ? 0 : options.length - 1;
  }

  options[nextIndex]?.focus();
}

function queueViewIsSelected(view: QueueView) {
  if (view === "all") {
    return selectedStates.value.length === tabs.length && selectedPriorities.value.length === 2;
  }

  return selectedStates.value.length === 1 && selectedStates.value[0] === view && selectedPriorities.value.length === 2;
}

function closeQueueViewMenu(returnFocus = false) {
  queueViewMenuOpen.value = false;

  if (returnFocus) {
    queueViewControl.value?.querySelector<HTMLButtonElement>(".inbox-view-selector")?.focus();
  }
}

function toggleQueueViewMenu() {
  closeOrganizationMenu();
  closeRefineMenu();
  queueViewMenuOpen.value = !queueViewMenuOpen.value;
}

function selectQueueView(view: QueueView) {
  selectedPriorities.value = ["high", "normal"];
  selectedStates.value = view === "all" ? tabs.map((tab) => tab.id) : [view];
  closeQueueViewMenu(true);
}

function closeRefineMenu(returnFocus = false) {
  refineMenuOpen.value = false;

  if (returnFocus) {
    refineControl.value?.querySelector<HTMLButtonElement>(".inbox-refine-trigger")?.focus();
  }
}

function toggleRefineMenu() {
  closeOrganizationMenu();
  closeQueueViewMenu();
  refineMenuOpen.value = !refineMenuOpen.value;
}

function toggleStateFilter(state: InboxState) {
  selectedStates.value = selectedStates.value.includes(state)
    ? selectedStates.value.filter((selectedState) => selectedState !== state)
    : [...selectedStates.value, state];
}

function togglePriorityFilter(priority: Priority) {
  selectedPriorities.value = selectedPriorities.value.includes(priority)
    ? selectedPriorities.value.filter((selectedPriority) => selectedPriority !== priority)
    : [...selectedPriorities.value, priority];
}

function resetQueueFilters() {
  selectedStates.value = tabs.map((tab) => tab.id);
  selectedPriorities.value = ["high", "normal"];
}

function handleQueueControlKeydown(event: KeyboardEvent, menu: "view" | "refine") {
  if (event.key !== "Escape") {
    return;
  }

  event.preventDefault();

  if (menu === "view") {
    closeQueueViewMenu(true);
  } else {
    closeRefineMenu(true);
  }
}

function handleDocumentPointerDown(event: PointerEvent) {
  if (organizationMenuOpen.value && !organizationCombobox.value?.contains(event.target as Node)) {
    closeOrganizationMenu();
  }

  if (queueViewMenuOpen.value && !queueViewControl.value?.contains(event.target as Node)) {
    closeQueueViewMenu();
  }

  if (refineMenuOpen.value && !refineControl.value?.contains(event.target as Node)) {
    closeRefineMenu();
  }
}

function clampQueueWidth(clientX: number) {
  const bounds = inboxPageElement.value?.getBoundingClientRect();

  if (!bounds) {
    return;
  }

  const queueMinimum = Math.max(Math.min(280, bounds.width * 0.45), bounds.width * 0.22);
  const detailMinimum = Math.min(380, bounds.width * 0.48);
  const queueMaximum = Math.min(bounds.width - detailMinimum, bounds.width * 0.7);
  const nextWidth = Math.min(queueMaximum, Math.max(queueMinimum, clientX - bounds.left));
  queueWidthPercent.value = (nextWidth / bounds.width) * 100;
}

function syncInboxQueueWidthToBounds() {
  const bounds = inboxPageElement.value?.getBoundingClientRect();
  if (!bounds) return;
  clampQueueWidth(bounds.left + bounds.width * queueWidthPercent.value / 100);
}

function resizeInboxPanes(event: PointerEvent) {
  if (paneResizing.value) {
    clampQueueWidth(event.clientX);
  }
}

function startInboxPaneResize(event: PointerEvent) {
  if (window.matchMedia("(max-width: 900px)").matches || (event.pointerType === "mouse" && event.button !== 0)) {
    return;
  }

  event.preventDefault();
  paneResizeHandle = event.currentTarget as HTMLElement;
  paneResizePointerId = event.pointerId;
  paneResizeHandle.setPointerCapture(event.pointerId);
  paneResizing.value = true;
  window.addEventListener("pointermove", resizeInboxPanes);
  window.addEventListener("pointerup", stopInboxPaneResize);
  window.addEventListener("pointercancel", stopInboxPaneResize);
  resizeInboxPanes(event);
}

function stopInboxPaneResize() {
  if (!paneResizing.value) {
    return;
  }

  if (paneResizeHandle && paneResizePointerId !== null && paneResizeHandle.hasPointerCapture(paneResizePointerId)) {
    paneResizeHandle.releasePointerCapture(paneResizePointerId);
  }

  paneResizing.value = false;
  paneResizeHandle = null;
  paneResizePointerId = null;
  window.removeEventListener("pointermove", resizeInboxPanes);
  window.removeEventListener("pointerup", stopInboxPaneResize);
  window.removeEventListener("pointercancel", stopInboxPaneResize);
  window.removeEventListener("pointermove", resizeMeetingPanel);
  window.removeEventListener("pointerup", stopMeetingPanelResize);
  window.removeEventListener("pointercancel", stopMeetingPanelResize);
}

function resizeInboxPanesWithKeyboard(event: KeyboardEvent) {
  if (!["ArrowLeft", "ArrowRight", "Home", "End"].includes(event.key)) {
    return;
  }

  event.preventDefault();

  if (event.key === "Home") {
    const bounds = inboxPageElement.value?.getBoundingClientRect();

    if (bounds) {
      clampQueueWidth(bounds.left + bounds.width * 0.22);
    }
    return;
  }

  if (event.key === "End") {
    const bounds = inboxPageElement.value?.getBoundingClientRect();

    if (bounds) {
      clampQueueWidth(bounds.left + bounds.width * 0.7);
    }
    return;
  }

  const bounds = inboxPageElement.value?.getBoundingClientRect();

  if (bounds) {
    clampQueueWidth(
      bounds.left + bounds.width * (queueWidthPercent.value + (event.key === "ArrowRight" ? 2 : -2)) / 100,
    );
  }
}

function toggleFinding(findingId: string) {
  expandedFindingId.value = expandedFindingId.value === findingId ? null : findingId;
}

function approveFinding(finding: Finding) {
  findingDecisions.value[finding.id] = "approved";
  findingMessages.value[finding.id] = "Finding approved for the final review.";
}

function requestFindingChanges(finding: Finding) {
  if (!findingFeedback.value[finding.id]?.trim()) {
    findingMessages.value[finding.id] = "Add feedback before requesting changes.";
    return;
  }

  findingDecisions.value[finding.id] = "changes-requested";
  findingMessages.value[finding.id] = "Feedback sent to the agent for this finding.";
}

function toggleSpeechInput() {
  speechListening.value = !speechListening.value;

  if (!speechListening.value) {
    const transcript = "Please verify the authorization tests cover cross-organization access.";
    feedback.value = feedback.value.trim() ? `${feedback.value.trim()} ${transcript}` : transcript;
    reviewMessage.value = "Voice note transcribed into feedback.";
  }
}

function clampFeedbackEditorHeight(height: number) {
  return Math.min(240, Math.max(60, height));
}

function resizeFeedbackEditor(event: PointerEvent) {
  if (!feedbackEditorResizing.value) {
    return;
  }

  feedbackEditorHeight.value = clampFeedbackEditorHeight(
    feedbackResizeStartHeight + event.clientY - feedbackResizeStartY,
  );
}

function startFeedbackEditorResize(event: PointerEvent) {
  if (event.pointerType === "mouse" && event.button !== 0) {
    return;
  }

  event.preventDefault();
  feedbackResizeHandle = event.currentTarget as HTMLElement;
  feedbackResizePointerId = event.pointerId;
  feedbackResizeStartY = event.clientY;
  feedbackResizeStartHeight = feedbackEditorHeight.value;
  feedbackResizeHandle.setPointerCapture(event.pointerId);
  feedbackEditorResizing.value = true;
  window.addEventListener("pointermove", resizeFeedbackEditor);
  window.addEventListener("pointerup", stopFeedbackEditorResize);
  window.addEventListener("pointercancel", stopFeedbackEditorResize);
}

function stopFeedbackEditorResize() {
  if (!feedbackEditorResizing.value) {
    return;
  }

  if (
    feedbackResizeHandle &&
    feedbackResizePointerId !== null &&
    feedbackResizeHandle.hasPointerCapture(feedbackResizePointerId)
  ) {
    feedbackResizeHandle.releasePointerCapture(feedbackResizePointerId);
  }

  feedbackEditorResizing.value = false;
  feedbackResizeHandle = null;
  feedbackResizePointerId = null;
  window.removeEventListener("pointermove", resizeFeedbackEditor);
  window.removeEventListener("pointerup", stopFeedbackEditorResize);
  window.removeEventListener("pointercancel", stopFeedbackEditorResize);
}

function resizeFeedbackEditorWithKeyboard(event: KeyboardEvent) {
  if (!["ArrowUp", "ArrowDown", "Home", "End"].includes(event.key)) {
    return;
  }

  event.preventDefault();

  if (event.key === "Home") {
    feedbackEditorHeight.value = 60;
    return;
  }

  if (event.key === "End") {
    feedbackEditorHeight.value = 240;
    return;
  }

  feedbackEditorHeight.value = clampFeedbackEditorHeight(
    feedbackEditorHeight.value + (event.key === "ArrowDown" ? 12 : -12),
  );
}

function findingFeedbackHeight(findingId: string) {
  return findingFeedbackHeights.value[findingId] ?? 54;
}

function clampFindingFeedbackHeight(height: number) {
  return Math.min(180, Math.max(54, height));
}

function resizeFindingFeedback(event: PointerEvent) {
  const findingId = findingFeedbackResizingId.value;

  if (!findingId) {
    return;
  }

  findingFeedbackHeights.value[findingId] = clampFindingFeedbackHeight(
    findingFeedbackResizeStartHeight + event.clientY - findingFeedbackResizeStartY,
  );
}

function startFindingFeedbackResize(event: PointerEvent, findingId: string) {
  if (event.pointerType === "mouse" && event.button !== 0) {
    return;
  }

  event.preventDefault();
  findingFeedbackResizeHandle = event.currentTarget as HTMLElement;
  findingFeedbackResizePointerId = event.pointerId;
  findingFeedbackResizeStartY = event.clientY;
  findingFeedbackResizeStartHeight = findingFeedbackHeight(findingId);
  findingFeedbackResizeHandle.setPointerCapture(event.pointerId);
  findingFeedbackResizingId.value = findingId;
  window.addEventListener("pointermove", resizeFindingFeedback);
  window.addEventListener("pointerup", stopFindingFeedbackResize);
  window.addEventListener("pointercancel", stopFindingFeedbackResize);
}

function stopFindingFeedbackResize() {
  if (!findingFeedbackResizingId.value) {
    return;
  }

  if (
    findingFeedbackResizeHandle &&
    findingFeedbackResizePointerId !== null &&
    findingFeedbackResizeHandle.hasPointerCapture(findingFeedbackResizePointerId)
  ) {
    findingFeedbackResizeHandle.releasePointerCapture(findingFeedbackResizePointerId);
  }

  findingFeedbackResizingId.value = null;
  findingFeedbackResizeHandle = null;
  findingFeedbackResizePointerId = null;
  window.removeEventListener("pointermove", resizeFindingFeedback);
  window.removeEventListener("pointerup", stopFindingFeedbackResize);
  window.removeEventListener("pointercancel", stopFindingFeedbackResize);
}

function resizeFindingFeedbackWithKeyboard(event: KeyboardEvent, findingId: string) {
  if (!["ArrowUp", "ArrowDown", "Home", "End"].includes(event.key)) {
    return;
  }

  event.preventDefault();

  if (event.key === "Home") {
    findingFeedbackHeights.value[findingId] = 54;
    return;
  }

  if (event.key === "End") {
    findingFeedbackHeights.value[findingId] = 180;
    return;
  }

  findingFeedbackHeights.value[findingId] = clampFindingFeedbackHeight(
    findingFeedbackHeight(findingId) + (event.key === "ArrowDown" ? 12 : -12),
  );
}

onMounted(() => {
  document.addEventListener("pointerdown", handleDocumentPointerDown);
  window.addEventListener("keydown", handleMeetingRoomKeydown);
  window.addEventListener("resize", syncInboxQueueWidthToBounds);
  nextTick(syncInboxQueueWidthToBounds);
});

onBeforeUnmount(() => {
  clearMeetingRecordingTimer();
  meetingRoomRealtime?.stop();
  meetingRoomRealtime = null;
  void meetingMedia.disconnect();
  document.removeEventListener("pointerdown", handleDocumentPointerDown);
  window.removeEventListener("keydown", handleMeetingRoomKeydown);
  window.removeEventListener("resize", syncInboxQueueWidthToBounds);
  window.removeEventListener("pointermove", resizeInboxPanes);
  window.removeEventListener("pointerup", stopInboxPaneResize);
  window.removeEventListener("pointercancel", stopInboxPaneResize);
  window.removeEventListener("pointermove", resizeFeedbackEditor);
  window.removeEventListener("pointerup", stopFeedbackEditorResize);
  window.removeEventListener("pointercancel", stopFeedbackEditorResize);
  window.removeEventListener("pointermove", resizeFindingFeedback);
  window.removeEventListener("pointerup", stopFindingFeedbackResize);
  window.removeEventListener("pointercancel", stopFindingFeedbackResize);
});

watch(meetingRoomOpen, (isOpen) => {
  if (!isOpen) return;
  nextTick(() => {
    meetingPanelWidth.value = clampMeetingPanelWidth(meetingPanelWidth.value);
  });
});
</script>

<template>
  <section
    ref="inboxPageElement"
    class="inbox-page"
    :class="{
      'inbox-page--detail-open': mobileDetailOpen,
      'inbox-page--resizing': paneResizing,
    }"
    :style="inboxPageStyle"
    aria-label="Inbox"
  >
    <section class="inbox-queue" aria-label="Attention queue">
      <header class="inbox-queue-header">
        <div class="inbox-title-row">
          <div class="inbox-title-copy">
            <h1>Inbox</h1>
            <span v-if="attentionItems.length">{{ attentionItems.length }}</span>
          </div>

          <div ref="organizationCombobox" class="organization-filter" :class="{ 'is-open': organizationMenuOpen }">
            <button
              class="organization-filter-trigger"
              type="button"
              role="combobox"
              aria-label="Filter by organization"
              aria-haspopup="listbox"
              aria-controls="organization-filter-options"
              :aria-expanded="organizationMenuOpen"
              @click="organizationMenuOpen ? closeOrganizationMenu() : openOrganizationMenu()"
              @keydown="handleOrganizationTriggerKeydown"
            >
              <span>{{ selectedOrganizationLabel }}</span>
              <ChevronDown
                class="organization-filter-chevron"
                :class="{ 'is-open': organizationMenuOpen }"
                :size="15"
                :stroke-width="1.8"
                aria-hidden="true"
              />
            </button>

            <div v-if="organizationMenuOpen" class="organization-filter-popover">
              <div
                id="organization-filter-options"
                ref="organizationOptionsElement"
                class="organization-options"
                role="listbox"
                aria-label="Organizations"
                @keydown="handleOrganizationOptionsKeydown"
              >
                <button
                  v-for="organization in organizationOptions"
                  :key="organization.id"
                  class="organization-option"
                  type="button"
                  role="option"
                  :aria-selected="organizationFilter === organization.id"
                  @click="selectOrganization(organization.id)"
                >
                  <span>{{ organization.label }}</span>
                  <Check
                    v-if="organizationFilter === organization.id"
                    :size="15"
                    :stroke-width="1.9"
                    aria-hidden="true"
                  />
                </button>
              </div>
            </div>
          </div>
        </div>

        <div class="upcoming-heading">
          <strong>Upcoming meetings</strong>
          <button type="button" aria-label="View all upcoming meetings" :disabled="upcomingMeetings.length === 0" @click="selectMeeting">
            <CalendarDays :size="15" :stroke-width="1.8" aria-hidden="true" />
            <span>{{ meetingStatus === "ready" ? `${upcomingMeetings.length} upcoming` : "Loading…" }}</span>
          </button>
        </div>

        <div v-if="featuredMeeting" class="upcoming-meeting" :class="{ 'is-selected': selectedSurface === 'meeting' }">
          <button class="upcoming-meeting-main" type="button" @click="selectMeeting">
            <span class="meeting-icon-shell">
              <CalendarDays :size="21" :stroke-width="1.7" aria-hidden="true" />
            </span>
            <span class="meeting-copy">
              <strong>{{ featuredMeeting.title }}</strong>
              <span>{{ meetingTimeLabel(featuredMeeting.starts_at, featuredMeeting.status) }} · {{ featuredMeeting.participants.length }} people</span>
              <span class="meeting-participants" :aria-label="`${featuredMeeting.participants.map((participant) => participant.name).join(', ')} attending`">
                <img v-for="participant in featuredMeeting.participants.slice(0, 4)" :key="participant.id" :src="meetingAvatar(participant.name)" :alt="participant.name" />
              </span>
            </span>
          </button>
          <button class="meeting-join" type="button" :disabled="meetingRoomConnecting" @click="joinMeeting">
            <Video :size="15" :stroke-width="1.8" aria-hidden="true" />
            <span>{{ meetingJoinLabel }}</span>
          </button>
        </div>
      </header>

      <div class="inbox-view-controls">
        <div
          ref="queueViewControl"
          class="inbox-view-control"
          :class="{ 'is-open': queueViewMenuOpen }"
          @keydown="handleQueueControlKeydown($event, 'view')"
        >
          <button
            class="inbox-view-selector"
            type="button"
            aria-haspopup="menu"
            :aria-expanded="queueViewMenuOpen"
            @click="toggleQueueViewMenu"
          >
            <ListFilter :size="16" :stroke-width="1.8" aria-hidden="true" />
            <span class="inbox-view-label">{{ queueViewLabel }}</span>
            <span class="inbox-view-count">{{ visibleItems.length }}</span>
            <ChevronDown
              class="inbox-control-chevron"
              :class="{ 'is-open': queueViewMenuOpen }"
              :size="15"
              :stroke-width="1.8"
              aria-hidden="true"
            />
          </button>

          <div v-if="queueViewMenuOpen" class="inbox-view-menu" role="menu" aria-label="Task views">
            <button
              type="button"
              role="menuitemradio"
              :aria-checked="queueViewIsSelected('all')"
              @click="selectQueueView('all')"
            >
              <span>All open tasks</span>
              <span class="inbox-menu-count">{{ openTaskCount }}</span>
              <Check v-if="queueViewIsSelected('all')" :size="15" :stroke-width="1.9" aria-hidden="true" />
            </button>
            <button
              v-for="tab in tabs"
              :key="tab.id"
              type="button"
              role="menuitemradio"
              :aria-checked="queueViewIsSelected(tab.id)"
              @click="selectQueueView(tab.id)"
            >
              <span>{{ tab.label }}</span>
              <span class="inbox-menu-count">{{ stateCounts[tab.id] }}</span>
              <Check v-if="queueViewIsSelected(tab.id)" :size="15" :stroke-width="1.9" aria-hidden="true" />
            </button>
          </div>
        </div>

        <div
          ref="refineControl"
          class="inbox-refine-control"
          :class="{ 'is-open': refineMenuOpen }"
          @keydown="handleQueueControlKeydown($event, 'refine')"
        >
          <button
            class="inbox-refine-trigger"
            :class="{ 'has-filters': activeRefineCount > 0 }"
            type="button"
            aria-haspopup="dialog"
            :aria-expanded="refineMenuOpen"
            @click="toggleRefineMenu"
          >
            <SlidersHorizontal :size="16" :stroke-width="1.8" aria-hidden="true" />
            <span>Refine</span>
            <span v-if="activeRefineCount" class="inbox-refine-count">{{ activeRefineCount }}</span>
            <ChevronDown
              class="inbox-control-chevron"
              :class="{ 'is-open': refineMenuOpen }"
              :size="15"
              :stroke-width="1.8"
              aria-hidden="true"
            />
          </button>

          <div v-if="refineMenuOpen" class="inbox-refine-popover" role="dialog" aria-label="Refine open tasks">
            <header>
              <strong>Refine queue</strong>
              <button type="button" :disabled="activeRefineCount === 0" @click="resetQueueFilters">Reset</button>
            </header>

            <section aria-labelledby="refine-status-heading">
              <h3 id="refine-status-heading">Status</h3>
              <button
                v-for="tab in tabs"
                :key="tab.id"
                type="button"
                :aria-pressed="selectedStates.includes(tab.id)"
                @click="toggleStateFilter(tab.id)"
              >
                <span>{{ tab.label }}</span>
                <span class="inbox-refine-option-meta">
                  {{ stateCounts[tab.id] }}
                  <Check v-if="selectedStates.includes(tab.id)" :size="14" :stroke-width="1.9" aria-hidden="true" />
                </span>
              </button>
            </section>

            <section aria-labelledby="refine-priority-heading">
              <h3 id="refine-priority-heading">Priority</h3>
              <button
                v-for="priority in priorities"
                :key="priority.id"
                type="button"
                :aria-pressed="selectedPriorities.includes(priority.id)"
                @click="togglePriorityFilter(priority.id)"
              >
                <span>{{ priority.label }}</span>
                <Check v-if="selectedPriorities.includes(priority.id)" :size="14" :stroke-width="1.9" aria-hidden="true" />
              </button>
            </section>
          </div>
        </div>
      </div>

      <div class="queue-order-note">
        <span>Ordered globally by: <strong>Priority, then oldest</strong></span>
        <span class="queue-order-help" title="Organization is context only and never affects rank">
          <Info :size="14" :stroke-width="1.8" aria-hidden="true" />
          <span class="sr-only">Organization is context only and never affects rank</span>
        </span>
      </div>

      <div v-if="visibleItems.length" class="inbox-item-list">
        <button
          v-for="item in visibleItems"
          :key="item.id"
          class="inbox-item"
          :class="{ 'inbox-item--selected': selectedSurface === 'task' && selectedItem.id === item.id }"
          type="button"
          @click="selectItem(item.id)"
        >
          <img class="inbox-item-avatar" :src="item.avatar" :alt="`${item.owner} avatar`" />
          <span class="inbox-item-body">
            <span class="inbox-item-title">{{ item.title }}</span>
            <span class="inbox-item-path">{{ item.organization }} <span>›</span> {{ item.product }} <span>›</span> {{ item.project }}</span>
            <span class="inbox-item-owner">{{ item.owner }} · {{ item.role }}</span>
          </span>
          <span class="inbox-item-meta">
            <span class="priority-label" :class="`priority-label--${item.priority}`">{{ item.priority }}</span>
            <span>{{ item.queuedLabel }}</span>
          </span>
        </button>
      </div>

      <div v-else-if="attentionStatus === 'loading'" class="inbox-empty" role="status">
        <Clock3 :size="24" :stroke-width="1.7" aria-hidden="true" />
        <strong>Loading your attention queue</strong>
        <span>Checking for mentions and continuation requests.</span>
      </div>

      <div v-else-if="attentionStatus === 'unavailable'" class="inbox-empty" role="alert">
        <CircleAlert :size="24" :stroke-width="1.7" aria-hidden="true" />
        <strong>Live attention is unavailable</strong>
        <span>Your attention queue will return when Katra reconnects.</span>
      </div>

      <div v-else class="inbox-empty">
        <Check :size="24" :stroke-width="1.7" aria-hidden="true" />
        <strong>Nothing waiting here</strong>
        <span>Try another state or organization.</span>
      </div>
    </section>

    <div
      class="inbox-pane-resize-handle"
      role="separator"
      tabindex="0"
      aria-label="Resize Inbox queue"
      aria-orientation="vertical"
      :aria-valuemin="22"
      :aria-valuemax="70"
      :aria-valuenow="Math.round(queueWidthPercent)"
      @pointerdown="startInboxPaneResize"
      @keydown="resizeInboxPanesWithKeyboard"
    ><span aria-hidden="true" /></div>

    <section class="inbox-detail" aria-live="polite">
      <button class="inbox-detail-back" type="button" @click="mobileDetailOpen = false">
        <ArrowLeft :size="18" :stroke-width="1.8" aria-hidden="true" />
        <span>Inbox</span>
      </button>

      <template v-if="selectedSurface === 'meeting' && featuredMeeting">
        <header class="detail-header meeting-detail-header">
          <span class="detail-avatar meeting-detail-icon">
            <CalendarDays :size="28" :stroke-width="1.7" aria-hidden="true" />
          </span>
          <div class="detail-heading-copy">
            <h2>{{ featuredMeeting.title }}</h2>
            <p>{{ featuredMeeting.organization.name }} <span>›</span> Katra</p>
            <div class="detail-meta-row">
              <span><Clock3 :size="15" :stroke-width="1.8" aria-hidden="true" /> {{ meetingTimeLabel(featuredMeeting.starts_at, featuredMeeting.status) }}</span>
              <span><UsersRound :size="15" :stroke-width="1.8" aria-hidden="true" /> {{ featuredMeeting.participants.length }} people</span>
              <span><Video :size="15" :stroke-width="1.8" aria-hidden="true" /> Katra room</span>
            </div>
          </div>
          <div class="meeting-detail-actions">
            <button v-if="featuredMeeting" class="detail-secondary-action" type="button" :disabled="calendarExportPending" @click="exportMeetingCalendar">
              <CalendarPlus :size="16" :stroke-width="1.8" aria-hidden="true" />
              {{ calendarExportPending ? "Preparing…" : "Add to calendar" }}
            </button>
            <button v-if="featuredMeeting?.status === 'scheduled' && currentUserOrganizesFeaturedMeeting" class="detail-secondary-action meeting-cancel-action" type="button" :disabled="meetingCancellationPending" @click="cancelFeaturedMeeting">
              <X :size="16" :stroke-width="1.8" aria-hidden="true" />
              {{ meetingCancellationPending ? "Cancelling…" : "Cancel meeting" }}
            </button>
            <button v-if="featuredMeeting?.status === 'scheduled' || featuredMeeting?.status === 'live'" class="detail-primary-action" type="button" :disabled="meetingRoomConnecting" @click="joinMeeting">
              <Video :size="16" :stroke-width="1.8" aria-hidden="true" />
              {{ meetingJoinLabel }}
            </button>
          </div>
        </header>

        <div class="meeting-detail-content">
          <p v-if="calendarExportMessage" class="meeting-calendar-status" role="status">{{ calendarExportMessage }}</p>
          <section class="detail-copy-section">
            <h3>Meeting brief</h3>
            <p>{{ featuredMeeting.desired_outcome ?? "No desired outcome was provided." }}</p>
          </section>
          <section class="meeting-agenda">
            <h3>Agenda</h3>
            <ol v-if="featuredMeeting?.agenda_items.length">
              <li v-for="item in featuredMeeting.agenda_items" :key="item.position"><span>{{ item.position }}</span><div><strong>{{ item.title }}</strong><small>{{ item.duration_minutes }} min<span v-if="item.owner"> · {{ item.owner.name }}</span></small></div></li>
            </ol>
            <ol v-else>
              <li><span>1</span><div><strong>Attention queue rules</strong><small>Priority first, FIFO within priority</small></div></li>
              <li><span>2</span><div><strong>Review workbench</strong><small>Feedback, acceptance, and change requests</small></div></li>
              <li><span>3</span><div><strong>Meeting workflow</strong><small>Preparation, notes, decisions, and follow-up</small></div></li>
            </ol>
          </section>
          <section class="meeting-attendees">
            <h3>Attendees</h3>
            <div v-if="featuredMeeting">
              <span v-for="participant in featuredMeeting.participants" :key="participant.id"><img :src="meetingAvatar(participant.name)" :alt="participant.name" /> {{ participant.name }}</span>
            </div>
          </section>
          <section v-if="featuredMeeting" class="meeting-detail-outcomes">
            <h3>Meeting outcomes</h3>
            <ol v-if="featuredMeeting.outcomes.length" class="meeting-outcome-list">
              <li v-for="outcome in featuredMeeting.outcomes" :key="outcome.id" :class="`meeting-outcome meeting-outcome--${outcome.kind}`"><span>{{ outcome.kind === "decision" ? "Decision" : outcome.kind === "action" ? "Action" : "Note" }}</span><p><strong>{{ outcome.body }}</strong><small>{{ outcome.author.name }}<template v-if="outcome.assignee"> · {{ outcome.assignee.name }} · {{ outcome.completed_at ? "Complete" : "Open" }}</template></small></p></li>
            </ol>
            <p v-else>No outcomes were recorded for this meeting.</p>
          </section>
          <section v-if="featuredMeeting" class="meeting-detail-chat">
            <h3>Meeting chat</h3>
            <ol v-if="meetingChatMessages.length">
              <li v-for="message in meetingChatMessages" :key="message.id">
                <img :src="meetingAvatar(message.author.name)" alt="" />
                <div class="meeting-detail-chat-copy"><strong>{{ message.author.name }}</strong><MarkdownMessage :body="message.body" /><small>{{ new Date(message.created_at).toLocaleString() }}</small></div>
              </li>
            </ol>
            <p v-else>No chat messages were recorded for this meeting.</p>
          </section>
        </div>
      </template>

      <template v-else-if="selectedItem">
        <header class="detail-header">
          <img class="detail-avatar" :src="selectedItem.avatar" :alt="`${selectedItem.owner} avatar`" />
          <div class="detail-heading-copy">
            <h2>{{ selectedItem.title }}</h2>
            <p>{{ selectedItem.organization }} <span>›</span> {{ selectedItem.product }} <span>›</span> {{ selectedItem.project }}</p>
            <div class="detail-meta-row">
              <span :class="`detail-priority detail-priority--${selectedItem.priority}`">
                <ShieldCheck :size="15" :stroke-width="1.8" aria-hidden="true" />
                {{ selectedItem.priority }} priority
              </span>
              <span>{{ selectedItem.role }}</span>
              <span><Clock3 :size="15" :stroke-width="1.8" aria-hidden="true" /> {{ selectedItem.queuedLabel }}</span>
            </div>
          </div>
          <span class="detail-state-label">{{ tabs.find((tab) => tab.id === selectedItem.state)?.label }}</span>
        </header>

        <div class="detail-scroll-region">
          <template v-if="selectedItem.attention">
            <section class="detail-copy-section">
              <h3>Conversation context</h3>
              <p>{{ selectedItem.context }}</p>
            </section>

            <section class="detail-copy-section">
              <h3>Why this needs attention</h3>
              <p>{{ selectedItem.summary }}</p>
            </section>

            <section class="attention-actions" aria-label="Attention actions">
              <button class="detail-primary-action" type="button" @click="openSelectedAttention">
                <MessageSquareText :size="16" :stroke-width="1.8" aria-hidden="true" />
                {{ selectedItem.attention.kind === "meeting-action" ? "Open meeting" : selectedItem.attention.kind === "message-attention-request" ? "Open message" : "Open conversation" }}
              </button>
              <button class="attention-complete-action" type="button" @click="resolveSelectedAttention">
                <Check :size="16" :stroke-width="1.8" aria-hidden="true" />
                Mark complete
              </button>
            </section>
          </template>

          <template v-else>
          <section class="detail-copy-section">
            <h3>Task context</h3>
            <p>{{ selectedItem.context }}</p>
          </section>

          <section class="detail-copy-section">
            <h3>Agent summary</h3>
            <p>{{ selectedItem.summary }}</p>
          </section>

          <section class="findings-section">
            <header>
              <h3>Findings ({{ selectedItem.findings.length }})</h3>
              <button type="button" @click="reviewMessage = 'Full report opened in the review workspace.'">
                <span>View full report</span>
                <ExternalLink :size="14" :stroke-width="1.8" aria-hidden="true" />
              </button>
            </header>
            <div class="findings-list">
              <article
                v-for="finding in selectedItem.findings"
                :key="finding.id"
                class="finding-item"
                :class="{
                  'finding-item--expanded': expandedFindingId === finding.id,
                  'finding-item--approved': findingDecisions[finding.id] === 'approved',
                  'finding-item--changes': findingDecisions[finding.id] === 'changes-requested',
                }"
              >
                <button
                  class="finding-row"
                  type="button"
                  :aria-expanded="expandedFindingId === finding.id"
                  :aria-controls="`finding-details-${finding.id}`"
                  @click="toggleFinding(finding.id)"
                >
                  <component
                    :is="findingIcon(finding.severity)"
                    class="finding-icon"
                    :class="`finding-icon--${finding.severity}`"
                    :size="18"
                    :stroke-width="1.8"
                    aria-hidden="true"
                  />
                  <span class="finding-copy">
                    <strong>{{ finding.title }}</strong>
                    <span>{{ finding.location }}</span>
                  </span>
                  <span class="finding-severity" :class="`finding-severity--${finding.severity}`">{{ finding.severity }}</span>
                  <span v-if="finding.delta" class="finding-delta">{{ finding.delta }}</span>
                  <ChevronDown
                    class="finding-expand-caret"
                    :class="{ 'is-expanded': expandedFindingId === finding.id }"
                    :size="16"
                    :stroke-width="1.8"
                    aria-hidden="true"
                  />
                </button>

                <div
                  v-if="expandedFindingId === finding.id"
                  :id="`finding-details-${finding.id}`"
                  class="finding-details"
                >
                  <div class="finding-detail-grid">
                    <section>
                      <h4>What Sentinel found</h4>
                      <p>{{ finding.detail ?? `The agent identified this ${finding.severity}-severity issue while reviewing ${finding.location}.` }}</p>
                    </section>
                    <section>
                      <h4>Proposed resolution</h4>
                      <p>{{ finding.recommendation ?? "Review the supplied change, confirm the intended behavior, and add evidence that the issue cannot regress." }}</p>
                    </section>
                  </div>

                  <label
                    class="finding-feedback-field"
                    :class="{ 'finding-feedback-field--resizing': findingFeedbackResizingId === finding.id }"
                  >
                    <span>Feedback for this finding</span>
                    <textarea
                      v-model="findingFeedback[finding.id]"
                      :style="{ '--finding-feedback-height': `${findingFeedbackHeight(finding.id)}px` }"
                      :aria-label="`Feedback for ${finding.title}`"
                      placeholder="Ask a question or describe the change you need…"
                    />
                    <div
                      class="textarea-resize-handle finding-feedback-resize-handle"
                      role="separator"
                      tabindex="0"
                      :aria-label="`Resize feedback for ${finding.title}`"
                      aria-orientation="horizontal"
                      :aria-valuemin="54"
                      :aria-valuemax="180"
                      :aria-valuenow="findingFeedbackHeight(finding.id)"
                      @pointerdown="startFindingFeedbackResize($event, finding.id)"
                      @keydown="resizeFindingFeedbackWithKeyboard($event, finding.id)"
                    ><span aria-hidden="true" /></div>
                  </label>

                  <div class="finding-review-actions">
                    <p v-if="findingMessages[finding.id]" class="finding-review-message">
                      {{ findingMessages[finding.id] }}
                    </p>
                    <span class="finding-review-buttons">
                      <button
                        class="finding-request-button"
                        :class="{ 'is-active': findingDecisions[finding.id] === 'changes-requested' }"
                        type="button"
                        @click="requestFindingChanges(finding)"
                      >
                        <MessageSquareWarning :size="14" :stroke-width="1.8" aria-hidden="true" />
                        Request changes
                      </button>
                      <button
                        class="finding-approve-button"
                        :class="{ 'is-active': findingDecisions[finding.id] === 'approved' }"
                        type="button"
                        @click="approveFinding(finding)"
                      >
                        <Check :size="15" :stroke-width="1.9" aria-hidden="true" />
                        {{ findingDecisions[finding.id] === "approved" ? "Approved" : "Approve finding" }}
                      </button>
                    </span>
                  </div>
                </div>
              </article>
            </div>
          </section>

          <section class="feedback-section">
            <h3>Provide feedback</h3>
            <div
              class="feedback-composer"
              :class="{ 'feedback-composer--resizing': feedbackEditorResizing }"
              :style="feedbackEditorStyle"
            >
              <textarea
                v-model="feedback"
                aria-label="Feedback for the agent"
                placeholder="Share your feedback, request changes, or ask a question…"
              />
              <div
                class="textarea-resize-handle feedback-editor-resize-handle"
                role="separator"
                tabindex="0"
                aria-label="Resize feedback editor"
                aria-orientation="horizontal"
                :aria-valuemin="60"
                :aria-valuemax="240"
                :aria-valuenow="feedbackEditorHeight"
                @pointerdown="startFeedbackEditorResize"
                @keydown="resizeFeedbackEditorWithKeyboard"
              ><span aria-hidden="true" /></div>
              <div class="feedback-toolbar">
                <span class="feedback-tools">
                  <AtSign :size="16" :stroke-width="1.8" aria-hidden="true" />
                  <Smile :size="16" :stroke-width="1.8" aria-hidden="true" />
                  <Paperclip :size="16" :stroke-width="1.8" aria-hidden="true" />
                  <Code2 :size="16" :stroke-width="1.8" aria-hidden="true" />
                  <Link2 :size="16" :stroke-width="1.8" aria-hidden="true" />
                  <button
                    class="feedback-mic-button"
                    :class="{ 'is-listening': speechListening }"
                    type="button"
                    :aria-label="speechListening ? 'Stop voice input' : 'Start voice input'"
                    :aria-pressed="speechListening"
                    @click="toggleSpeechInput"
                  >
                    <Mic :size="16" :stroke-width="1.8" aria-hidden="true" />
                  </button>
                  <span v-if="speechListening" class="feedback-listening-state">Listening…</span>
                </span>
                <span class="feedback-actions">
                  <button class="feedback-secondary" type="button" @click="requestChanges">
                    <MessageSquareWarning :size="15" :stroke-width="1.8" aria-hidden="true" />
                    Request changes
                  </button>
                  <button class="feedback-primary" type="button" @click="acceptWork">
                    <Check :size="16" :stroke-width="1.9" aria-hidden="true" />
                    Accept work
                  </button>
                </span>
              </div>
            </div>
            <p v-if="reviewMessage" class="review-message">{{ reviewMessage }}</p>
          </section>
          </template>
        </div>
      </template>

      <div v-else class="inbox-empty">
        <Check :size="24" :stroke-width="1.7" aria-hidden="true" />
        <strong>No attention item selected</strong>
        <span>New mentions and requests will appear in the queue.</span>
      </div>
    </section>

    <button v-if="meetingJoined && !meetingRoomOpen" class="meeting-room-return" type="button" @click="meetingRoomOpen = true">
      <Video :size="16" aria-hidden="true" /> Return to {{ roomMeeting?.title ?? "meeting" }}
    </button>

    <Transition name="meeting-room">
      <section v-if="meetingJoined" v-show="meetingRoomOpen" class="meeting-room-window" role="dialog" aria-modal="true" aria-labelledby="meeting-room-title">
        <header class="meeting-room-header">
          <div>
            <span class="meeting-live-indicator"><i /> {{ roomMeeting?.status === "completed" ? "Ended" : "Live" }}</span>
            <span v-if="meetingRecordingActive" class="meeting-recording-indicator" role="status"><Circle :size="9" fill="currentColor" aria-hidden="true" /> Recording {{ meetingRecordingTime }}</span>
            <div><h2 id="meeting-room-title">{{ roomMeeting?.title ?? "Katra meeting" }}</h2><p>{{ roomMeeting?.organization.name ?? "Katra" }} · Katra room · {{ roomMeetingParticipants.length }} present</p></div>
          </div>
          <div><button class="meeting-header-record-button" type="button" :class="{ 'is-recording': meetingRecordingActive }" :aria-pressed="meetingRecordingActive" :aria-label="meetingRecordingActive ? 'Stop meeting recording' : 'Record meeting'" @click="toggleMeetingRecording"><Square v-if="meetingRecordingActive" :size="13" fill="currentColor" aria-hidden="true" /><Circle v-else :size="15" aria-hidden="true" /><span>{{ meetingRecordingActive ? meetingRecordingTime : 'Record' }}</span></button><span class="meeting-room-time" aria-label="Meeting elapsed time">{{ meetingElapsedTime }}</span><button type="button" aria-label="Minimize meeting" title="Minimize meeting" @click="minimizeMeetingRoom"><Minimize2 :size="17" :stroke-width="1.8" aria-hidden="true" /></button></div>
          <div v-if="meetingRecordingPromptOpen" class="meeting-recording-popover meeting-recording-popover--header" role="dialog" aria-labelledby="meeting-recording-title">
            <header><span><Circle :size="11" fill="currentColor" aria-hidden="true" /></span><div><strong id="meeting-recording-title">Start recording?</strong><small>Everyone in the room will be notified.</small></div></header>
            <p>Capture audio, video, chat, notes, reactions, and anything shared on screen.</p>
            <label><input v-model="meetingRecordingTranscript" type="checkbox" /><span><strong>Create transcript</strong><small>Searchable text with speaker names</small></span></label>
            <label><input v-model="meetingRecordingActionItems" type="checkbox" /><span><strong>Find action items</strong><small>Draft follow-up work from the transcript</small></span></label>
            <footer><button type="button" @click="meetingRecordingPromptOpen = false">Cancel</button><button type="button" @click="startMeetingRecording"><Circle :size="11" fill="currentColor" aria-hidden="true" /> Start recording</button></footer>
          </div>
        </header>

        <div
          ref="meetingRoomBodyElement"
          class="meeting-room-body"
          :class="{
            'meeting-room-body--focus': meetingShareFocused,
            'meeting-room-body--resizing': meetingPanelResizing,
            'meeting-room-body--mobile-panel-open': meetingMobilePanelOpen,
          }"
          :style="meetingRoomBodyStyle"
        >
          <div class="meeting-audio-sinks" aria-hidden="true"><MeetingParticipantAudio v-for="participant in roomMeetingParticipants" :key="`audio-${participant.id}`" :media="meetingMediaFor(participant)" /></div>
          <section
            class="meeting-stage"
            :class="{
              'meeting-stage--share': meetingScreenShareActive,
              'meeting-stage--share-focused': meetingShareFocused,
            }"
            :aria-label="meetingScreenShareActive ? 'Shared screen and meeting participants' : 'Meeting participants'"
          >
            <template v-if="meetingScreenShareActive">
              <article class="meeting-shared-screen">
                <header>
                  <span><MonitorUp :size="15" aria-hidden="true" /> {{ sharingRoomParticipant?.name ?? "Participant" }} is sharing</span>
                  <strong>Shared screen</strong>
                </header>
                <MeetingParticipantMedia v-if="activeMeetingScreenShare" :media="activeMeetingScreenShare.media" source="screen-share" />
                <div class="meeting-share-actions">
                  <button type="button" :aria-label="meetingShareFocused ? 'Exit shared screen focus' : 'Focus shared screen'" @click="toggleMeetingShareFocus">
                    <Minimize2 v-if="meetingShareFocused" :size="16" aria-hidden="true" />
                    <Maximize2 v-else :size="16" aria-hidden="true" />
                    <span>{{ meetingShareFocused ? 'Exit focus' : 'Focus share' }}</span>
                  </button>
                  <button v-if="meetingMedia.screenShareEnabled.value" type="button" aria-label="Stop screen sharing" @click="toggleMeetingScreenShare"><MonitorUp :size="16" aria-hidden="true" /><span>Stop sharing</span></button>
                </div>
              </article>
              <div v-if="!meetingShareFocused" class="meeting-share-participants" aria-label="Meeting participant filmstrip">
                <article v-for="participant in roomMeetingParticipants" :key="participant.id"><img :src="participant.avatar" alt="" /><span><strong>{{ participant.name }}</strong><small>{{ participant.id === currentUser.id ? "You" : meetingMediaFor(participant)?.isSpeaking ? "Speaking" : meetingParticipantMicrophoneMuted(participant) ? "Muted" : "Listening" }}</small></span><div v-if="meetingParticipantMicrophoneMuted(participant) || meetingParticipantHasRaisedHand(participant)" class="meeting-filmstrip-states"><MicOff v-if="meetingParticipantMicrophoneMuted(participant)" :size="12" aria-label="Microphone muted" /><Hand v-if="meetingParticipantHasRaisedHand(participant)" :size="12" aria-label="Hand raised" /></div></article>
              </div>
            </template>
            <template v-else>
              <article v-for="(participant, index) in roomMeetingParticipants" :key="participant.id" class="meeting-participant-tile" :class="{ 'meeting-participant-tile--primary': index === 0, 'meeting-participant-tile--speaker': meetingMediaFor(participant)?.isSpeaking }">
                <span v-if="meetingMediaFor(participant)?.isSpeaking" class="meeting-speaker-state"><i /> Speaking</span>
                <span v-if="meetingParticipantHasRaisedHand(participant)" class="meeting-hand-state"><Hand :size="13" aria-hidden="true" /> Hand raised</span>
                <span v-if="meetingParticipantMicrophoneMuted(participant)" class="meeting-mute-state"><MicOff :size="13" aria-hidden="true" /> Muted</span>
                <MeetingParticipantMedia :media="meetingMediaFor(participant)" :video-enabled="meetingParticipantHasVisibleCamera(participant)" />
                <img :src="participant.avatar" :alt="participant.name" />
                <div class="meeting-participant-identity" :class="{ 'has-camera': meetingParticipantHasVisibleCamera(participant) }"><strong>{{ participant.name }} <em v-if="participant.id === currentUser.id">You</em></strong><small>{{ participant.role }}</small></div>
              </article>
            </template>
            <Transition name="meeting-reaction">
              <div v-if="meetingReactionEvent" class="meeting-stage-reaction" role="status"><component :is="meetingReactionIcon(meetingReactionEvent.kind)" :size="20" aria-hidden="true" /><span>{{ meetingReactionEvent.label }}</span></div>
            </Transition>
          </section>

          <div
            v-if="!meetingShareFocused"
            class="meeting-panel-resize-handle"
            role="separator"
            aria-label="Resize meeting sidebar"
            aria-orientation="vertical"
            aria-valuemin="260"
            aria-valuemax="520"
            :aria-valuenow="meetingPanelWidth"
            tabindex="0"
            @pointerdown="startMeetingPanelResize"
            @keydown="resizeMeetingPanelWithKeyboard"
          ><span /></div>

          <aside v-if="!meetingShareFocused" id="inbox-meeting-focus-sheet" class="meeting-room-panel" :class="{ 'meeting-room-panel--mobile-open': meetingMobilePanelOpen }" aria-label="Meeting collaboration panel">
            <div class="meeting-mobile-panel-handle"><span aria-hidden="true" /><button type="button" aria-label="Collapse meeting tools" @click="closeMeetingMobilePanel"><ChevronDown :size="20" aria-hidden="true" /></button></div>
            <header>
              <button type="button" :class="{ 'is-active': meetingRoomPanel === 'notes' }" @click="openMeetingPanel('notes')"><ListFilter :size="14" aria-hidden="true" /> Notes</button>
              <button type="button" :class="{ 'is-active': meetingRoomPanel === 'chat' }" @click="openMeetingPanel('chat')"><MessageSquareText :size="14" aria-hidden="true" /> Chat <span v-if="meetingUnreadChat">{{ meetingUnreadChat }}</span></button>
              <button type="button" :class="{ 'is-active': meetingRoomPanel === 'people' }" @click="openMeetingPanel('people')"><UsersRound :size="14" aria-hidden="true" /> People <span>{{ roomMeetingParticipants.length }}</span></button>
            </header>

            <div v-if="meetingRoomPanel === 'notes'" class="meeting-room-notes">
              <article v-if="meetingRecordingSaved" class="meeting-recording-summary">
                <header><span><Circle :size="9" fill="currentColor" aria-hidden="true" /> Recording saved</span><time>{{ meetingRecordingTime }}</time></header>
                <p>The meeting archive now includes audio, video, chat, notes, and shared content.</p>
                <ul>
                  <li><FileText :size="14" aria-hidden="true" /><span><strong>Transcript</strong><small>{{ meetingRecordingTranscript ? 'Processing automatically' : 'Not requested' }}</small></span></li>
                  <li><ListChecks :size="14" aria-hidden="true" /><span><strong>Action items</strong><small>{{ meetingRecordingActionItems ? 'Generating from the transcript' : 'Not requested' }}</small></span></li>
                </ul>
                <button type="button" @click="showMeetingRoomNotice('The saved recording is ready in the meeting archive.')"><Play :size="14" aria-hidden="true" /> Open recording</button>
              </article>
              <section><h3>Agenda</h3><ol><li class="is-current"><span>1</span><p><strong>Attention queue rules</strong><small>Priority first, FIFO within priority</small></p></li><li><span>2</span><p><strong>Review workbench</strong><small>Feedback and approval behavior</small></p></li><li><span>3</span><p><strong>Meeting workflow</strong><small>Decisions and follow-up tasks</small></p></li></ol></section>
              <section class="meeting-outcomes" aria-labelledby="inbox-meeting-outcomes-title">
                <header><div><h3 id="inbox-meeting-outcomes-title">Meeting outcomes</h3><small>{{ meetingOutcomes.length ? `${meetingOutcomes.length} saved` : "Shared with meeting participants" }}</small></div></header>
                <ol v-if="meetingOutcomes.length" class="meeting-outcome-list">
                  <li v-for="outcome in meetingOutcomes" :key="outcome.id" :class="`meeting-outcome meeting-outcome--${outcome.kind}`"><span>{{ outcome.kind === "decision" ? "Decision" : outcome.kind === "action" ? "Action" : "Note" }}</span><p><strong>{{ outcome.body }}</strong><small>{{ outcome.author.name }} · {{ new Date(outcome.created_at).toLocaleTimeString([], { hour: "numeric", minute: "2-digit" }) }}<template v-if="outcome.assignee"> · {{ outcome.assignee.name }}<template v-if="outcome.completed_at"> · Complete</template></template></small></p></li>
                </ol>
                <p v-else class="meeting-outcome-empty">No durable outcomes yet. Add the first note, decision, or assigned action.</p>
                <form class="meeting-outcome-composer" @submit.prevent="addMeetingOutcome">
                  <div class="meeting-outcome-kind" aria-label="Outcome kind"><button v-for="kind in meetingOutcomeKinds" :key="kind" type="button" :class="{ 'is-active': meetingOutcomeKind === kind }" :aria-pressed="meetingOutcomeKind === kind" @click="meetingOutcomeKind = kind">{{ kind === "action" ? "Action" : kind === "decision" ? "Decision" : "Note" }}</button></div>
                  <textarea v-model="meetingOutcomeBody" maxlength="2000" :placeholder="meetingOutcomeKind === 'action' ? 'Describe the follow-up…' : meetingOutcomeKind === 'decision' ? 'Record the decision…' : 'Add a shared note…'" aria-label="Meeting outcome" />
                  <footer><label v-if="meetingOutcomeKind === 'action'"><span>Assign to</span><span class="meeting-outcome-assignee"><select v-model="meetingOutcomeAssigneeId" aria-label="Assign action item"><option v-for="participant in meetingOutcomeAssignees" :key="participant.id" :value="participant.id">{{ participant.name }}</option></select><ChevronDown :size="14" aria-hidden="true" /></span></label><button type="submit" :disabled="meetingOutcomeSaving || !meetingOutcomeBody.trim() || (meetingOutcomeKind === 'action' && !meetingOutcomeAssigneeId)">{{ meetingOutcomeSaving ? "Saving…" : "Add outcome" }}</button></footer>
                </form>
              </section>
            </div>

            <div v-else-if="meetingRoomPanel === 'chat'" class="meeting-room-chat">
              <div class="meeting-chat-messages" role="log" aria-label="Meeting chat messages" aria-live="polite">
                <p v-if="!meetingChatMessages.length" class="meeting-chat-empty">No messages yet. Start the meeting conversation.</p>
                <article v-for="message in meetingChatMessages" :key="message.id" :class="{ 'is-self': message.author.id === currentUser.id }">
                  <img :src="meetingAvatar(message.author.name)" alt="" />
                  <div>
                    <header><strong>{{ message.author.name }}</strong><time>{{ new Date(message.created_at).toLocaleTimeString([], { hour: 'numeric', minute: '2-digit' }) }}</time></header>
                    <MarkdownMessage :body="message.body" />
                    <footer>
                      <button
                        v-for="reaction in message.reactions"
                        :key="reaction.kind"
                        type="button"
                        :class="{ 'is-active': reaction.reacted_by_current_user }"
                        :aria-label="`${reaction.reacted_by_current_user ? 'Remove' : 'Add'} ${reaction.kind} reaction on ${message.author.name}'s message`"
                        @click="toggleMeetingChatReaction(message, reaction.kind)"
                      ><component :is="meetingReactionIcon(reaction.kind)" :size="12" aria-hidden="true" /> {{ reaction.count }}</button>
                      <button v-if="!message.reactions.some((reaction) => reaction.kind === 'approve')" type="button" :aria-label="`Approve ${message.author.name}'s message`" @click="toggleMeetingChatReaction(message, 'approve')"><ThumbsUp :size="12" aria-hidden="true" /></button>
                      <button v-if="!message.reactions.some((reaction) => reaction.kind === 'support')" type="button" :aria-label="`Support ${message.author.name}'s message`" @click="toggleMeetingChatReaction(message, 'support')"><Heart :size="12" aria-hidden="true" /></button>
                    </footer>
                  </div>
                </article>
              </div>
              <form class="meeting-chat-composer" @submit.prevent="sendMeetingChat">
                <textarea v-model="meetingChatDraft" maxlength="4000" aria-label="Message meeting chat" placeholder="Message everyone…" @keydown.enter.exact.prevent="sendMeetingChat" />
                <button type="submit" :disabled="meetingChatSaving || !meetingChatDraft.trim()" aria-label="Send meeting message"><MessageSquareText :size="16" aria-hidden="true" /></button>
              </form>
            </div>

            <div v-else class="meeting-room-people-wrap">
              <button v-if="currentUserOrganizesRoomMeeting && roomMeeting?.status === 'live'" class="meeting-invite-trigger" type="button" :aria-expanded="meetingInviteOpen" @click="meetingInviteOpen = !meetingInviteOpen"><UserPlus :size="15" aria-hidden="true" /> Invite people</button>
              <section v-if="meetingInviteOpen" class="meeting-invite-panel" aria-label="Invite people to the meeting">
                <header><div><strong>Invite people</strong><small>They can join this meeting immediately.</small></div><button type="button" aria-label="Close invitations" @click="meetingInviteOpen = false"><X :size="14" aria-hidden="true" /></button></header>
                <label class="meeting-invite-search"><Search :size="14" aria-hidden="true" /><input v-model="meetingInviteSearch" type="search" placeholder="Search people…" aria-label="Search meeting invitees" /></label>
                <label v-for="candidate in filteredMeetingInviteCandidates" :key="candidate.id">
                  <input type="checkbox" :checked="meetingInviteSelections.includes(candidate.id)" @change="toggleMeetingInviteSelection(candidate.id)" />
                  <img :src="candidate.avatar" alt="" />
                  <span><strong>{{ candidate.name }}</strong><small>{{ candidate.role }}</small></span>
                </label>
                <p v-if="meetingInviteCandidatesLoading" class="meeting-invite-empty">Loading people…</p>
                <p v-else-if="!filteredMeetingInviteCandidates.length" class="meeting-invite-empty">No people match that search.</p>
                <div class="meeting-invite-email-entry"><MailPlus :size="14" aria-hidden="true" /><input v-model="meetingGuestEmail" type="email" placeholder="Invite guest by email…" aria-label="Guest email address" @keydown.enter.prevent="addMeetingGuestEmail" /><button type="button" :disabled="!meetingGuestEmail.includes('@')" @click="addMeetingGuestEmail">Add</button></div>
                <ul v-if="meetingGuestEmails.length" class="meeting-invite-email-list"><li v-for="email in meetingGuestEmails" :key="email"><span>{{ email }}</span><button type="button" :aria-label="`Remove ${email}`" @click="meetingGuestEmails = meetingGuestEmails.filter((item) => item !== email)"><X :size="11" aria-hidden="true" /></button></li></ul>
                <div class="meeting-invite-copy-actions"><button type="button" :disabled="!roomMeeting?.guest_link_url" @click="copyMeetingInvitation('link')"><Link2 :size="13" aria-hidden="true" /> Copy link</button><button type="button" :disabled="!roomMeeting?.guest_link_url" @click="copyMeetingInvitation('details')"><Copy :size="13" aria-hidden="true" /> Copy details</button><button type="button" :disabled="meetingGuestLinkUpdating" @click="manageMeetingGuestLink(roomMeeting?.guest_link_url ? 'revoke' : 'regenerate')"><Link2 :size="13" aria-hidden="true" /> {{ meetingGuestLinkUpdating ? "Updating…" : roomMeeting?.guest_link_url ? "Revoke link" : "Create new link" }}</button></div>
                <button type="button" :disabled="meetingInvitationsSending || (!meetingInviteSelections.length && !meetingGuestEmails.length)" @click="sendMeetingInvitations"><UserPlus :size="14" aria-hidden="true" /> {{ meetingInvitationsSending ? "Sending…" : "Send invitations" }}</button>
              </section>
              <ul class="meeting-room-people">
                <li v-for="(participant, index) in roomMeetingPeople" :key="participant.id"><img :src="participant.avatar" alt="" /><p><strong>{{ participant.name }} <span v-if="participant.id === currentUser.id">You</span></strong><small>{{ participant.role }}{{ index === 0 && participant.isPresent ? " · speaking" : "" }}</small></p><span v-if="currentUserOrganizesRoomMeeting && participant.canRemove" class="meeting-participant-actions"><button type="button" :disabled="Boolean(meetingRemovingParticipantId)" :aria-label="`Remove ${participant.name} from this meeting`" @click="removeRoomMeetingParticipant(participant, false)"><UserMinus :size="12" aria-hidden="true" /> {{ meetingRemovingParticipantId === participant.participantId ? 'Removing…' : 'Remove' }}</button><button v-if="participant.canBlockReentry" type="button" :disabled="Boolean(meetingRemovingParticipantId)" :aria-label="`Remove ${participant.name} and block their email invitation`" @click="removeRoomMeetingParticipant(participant, true)">Remove &amp; block</button></span><template v-else-if="participant.isPresent"><MicOff v-if="meetingParticipantMicrophoneMuted(participant)" :size="14" aria-label="Microphone muted" /><Mic v-else :size="14" aria-label="Microphone on" /></template><Clock3 v-else :size="14" aria-label="Not in room" /></li>
                <li v-for="name in meetingInvitedPeople" :key="name" class="is-invited"><img :src="meetingInviteCandidates.find((candidate) => candidate.name === name)?.avatar ?? '/brand/icon.svg'" alt="" /><p><strong>{{ name }}</strong><small>Invitation sent · awaiting response</small></p><Clock3 :size="14" aria-label="Invitation pending" /></li>
                <li v-for="invitation in meetingEmailInvitations" :key="invitation.id" class="is-invited"><span class="meeting-email-avatar"><MailPlus :size="14" aria-hidden="true" /></span><p><strong>{{ invitation.email }}</strong><small>{{ meetingEmailInvitationLabel(invitation.status) }}</small></p><span v-if="currentUserOrganizesRoomMeeting" class="meeting-email-actions"><button type="button" :disabled="meetingInvitationsSending" @click="manageMeetingEmailInvitation(invitation.id, 'resend')">Resend</button><button v-if="invitation.status !== 'revoked'" type="button" :disabled="meetingInvitationsSending" @click="manageMeetingEmailInvitation(invitation.id, 'revoke')">Revoke</button></span><Clock3 v-else :size="14" aria-label="Invitation pending" /></li>
              </ul>
            </div>
          </aside>
        </div>

        <footer class="meeting-room-controls">
          <div class="meeting-room-control-group">
            <MeetingDeviceControl kind="microphone" :enabled="meetingMicOn" :devices="meetingMedia.audioInputDevices.value" :selected-device-id="meetingMedia.selectedAudioInputId.value" @toggle="toggleMeetingMicrophone" @select="selectMeetingMicrophone" />
            <MeetingDeviceControl kind="camera" :enabled="meetingCameraOn" :devices="meetingMedia.videoInputDevices.value" :selected-device-id="meetingMedia.selectedVideoInputId.value" @toggle="toggleMeetingCamera" @select="selectMeetingCamera" />
            <button type="button" class="meeting-desktop-call-control meeting-share-control" :class="{ 'is-active': meetingScreenShareActive }" :aria-pressed="meetingMedia.screenShareEnabled.value" :aria-label="meetingMedia.screenShareEnabled.value ? 'Stop screen sharing' : 'Start screen sharing'" @click="toggleMeetingScreenShare"><MonitorUp :size="18" aria-hidden="true" /><span>{{ meetingMedia.screenShareEnabled.value ? 'Sharing' : 'Share' }}</span></button>
            <button type="button" class="meeting-desktop-call-control meeting-reaction-control" :class="{ 'is-active': meetingReactionMenuOpen }" aria-haspopup="menu" :aria-expanded="meetingReactionMenuOpen" @click="meetingRecordingPromptOpen = false; meetingReactionMenuOpen = !meetingReactionMenuOpen"><Smile :size="18" aria-hidden="true" /><span>React</span></button>
            <button type="button" class="meeting-desktop-call-control" :class="{ 'is-active': meetingRoomPanel === 'chat' }" @click="openMeetingPanel('chat')"><MessageSquareText :size="18" aria-hidden="true" /><span>Chat</span><b v-if="meetingUnreadChat">{{ meetingUnreadChat }}</b></button>
            <button type="button" class="meeting-desktop-call-control" :class="{ 'is-active': meetingRoomPanel === 'people' }" @click="openMeetingPanel('people')"><UsersRound :size="18" aria-hidden="true" /><span>People</span></button>
            <button type="button" class="meeting-mobile-tools-button" :class="{ 'is-active': meetingMobilePanelOpen }" :aria-expanded="meetingMobilePanelOpen" aria-controls="inbox-meeting-focus-sheet" aria-label="Meeting tools" @click="toggleMeetingMobilePanel"><Ellipsis :size="20" aria-hidden="true" /><b v-if="meetingUnreadChat">{{ meetingUnreadChat }}</b><span>Tools</span></button>
            <button type="button" class="meeting-leave-button" aria-label="Leave meeting" @click="leaveMeeting"><PhoneOff :size="18" aria-hidden="true" /><span>Leave</span></button>
          </div>
          <div v-if="meetingReactionMenuOpen" class="meeting-reaction-tray" role="menu" aria-label="Meeting reactions">
            <button type="button" role="menuitem" aria-label="Send approval reaction" @click="sendMeetingReaction('approve')"><ThumbsUp :size="17" aria-hidden="true" /><span>Approve</span></button>
            <button type="button" role="menuitem" aria-label="Send support reaction" @click="sendMeetingReaction('support')"><Heart :size="17" aria-hidden="true" /><span>Support</span></button>
            <button type="button" role="menuitem" aria-label="Send celebration reaction" @click="sendMeetingReaction('celebrate')"><PartyPopper :size="17" aria-hidden="true" /><span>Celebrate</span></button>
            <button type="button" role="menuitem" :aria-pressed="meetingHandRaised" @click="sendMeetingReaction('raise-hand')"><Hand :size="17" aria-hidden="true" /><span>{{ meetingHandRaised ? 'Lower hand' : 'Raise hand' }}</span></button>
          </div>
          <p v-if="meetingRoomNotice" role="status">{{ meetingRoomNotice }}</p>
        </footer>
      </section>
    </Transition>
  </section>
</template>
