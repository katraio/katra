<script setup lang="ts">
import {
  ChevronDown, Circle, Clock3, Copy, Ellipsis, FileText, Hand, Heart, Link2, ListChecks, ListFilter, MailPlus,
  Maximize2, MessageSquareText, Mic, MicOff, Minimize2, MonitorUp, PartyPopper,
  PhoneOff, Play, Search, Smile, Square, ThumbsUp, UserPlus, UsersRound, Video, VideoOff, X,
  UserMinus,
} from "@lucide/vue";
import { computed, onBeforeUnmount, onMounted, ref } from "vue";
import type { AuthUser } from "../../api/auth";
import {
  CommunicationRequestError,
  addMeetingGuestInvitations,
  addMeetingParticipants,
  createMeetingMessage,
  createMeetingOutcome,
  getMeetingOutcomes,
  getMeetingMessages,
  getMeetingCandidates,
  getMeeting,
  getMeetingMediaCredential,
  removeMeetingParticipant,
  sendMeetingRoomReaction,
  setMeetingMessageReaction,
  updateMeetingGuestLink,
  updateMeetingGuestInvitation,
  updateMeetingRoom,
  type CommunicationMeeting,
  type MeetingMessage,
  type MeetingMessageReaction,
  type MeetingOutcome,
  type MeetingRoomReactionKind,
} from "../../api/communication";
import {
  createGuestMeetingMessage,
  createGuestMeetingOutcome,
  getGuestMeeting,
  getGuestMeetingMessages,
  getGuestMeetingMediaCredential,
  getGuestMeetingOutcomes,
  sendGuestMeetingRoomReaction,
  setGuestMeetingMessageReaction,
  updateGuestMeetingRoom,
} from "../../api/meetingGuests";
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
import type { MeetingParticipant } from "./MeetingScheduleDialog.vue";
import MarkdownMessage from "../messages/MarkdownMessage.vue";
import MeetingDeviceControl from "./MeetingDeviceControl.vue";
import MeetingParticipantAudio from "./MeetingParticipantAudio.vue";
import MeetingParticipantMedia from "./MeetingParticipantMedia.vue";

const props = defineProps<{
  title: string;
  subtitle: string;
  participants: MeetingParticipant[];
  organizationId?: string;
  meeting?: CommunicationMeeting | null;
  currentUser?: AuthUser;
  guestSessionToken?: string;
}>();

const emit = defineEmits<{
  minimize: [];
  leave: [];
  "meeting-updated": [meeting: CommunicationMeeting];
  "access-revoked": [];
}>();

const panel = ref<"notes" | "chat" | "people">("notes");
const mobilePanelOpen = ref(false);
const meetingMedia = useMeetingMedia();
const micOn = meetingMedia.microphoneEnabled;
const cameraOn = meetingMedia.cameraEnabled;
const cameraVisible = meetingMedia.cameraVisible;
const shareActive = computed(() => meetingMedia.activeScreenShare.value !== null);
const shareFocused = ref(false);
const reactionMenuOpen = ref(false);
const reactionEvent = ref<{ kind: MeetingRoomReactionKind; label: string } | null>(null);
const handRaised = ref(false);
const raisedParticipantIds = ref<ReadonlySet<string>>(new Set());
const notice = ref("");
const panelWidth = useUiPreference(
  "meeting-panel-width",
  300,
  (value): value is number => isFiniteNumber(value) && value >= 260 && value <= 520,
);
const panelResizing = ref(false);
const bodyElement = ref<HTMLElement | null>(null);
const recordingPromptOpen = ref(false);
const recordingActive = ref(false);
const recordingSaved = ref(false);
const recordingSeconds = ref(0);
const recordingTranscript = ref(true);
const recordingActions = ref(true);
const inviteOpen = ref(false);
const inviteSearch = ref("");
const guestEmail = ref("");
const guestEmails = ref<string[]>([]);
const invitedNames = ref<string[]>([]);
const invitedEmails = ref<string[]>([]);
const currentMeeting = ref<CommunicationMeeting | null>(props.meeting ?? null);
const meetingElapsedTime = useMeetingElapsedTime(() => currentMeeting.value?.started_at ?? null);
const outcomes = ref<MeetingOutcome[]>(props.meeting?.outcomes ?? []);
const outcomeKind = ref<MeetingOutcome["kind"]>("note");
const outcomeBody = ref("");
const outcomeAssigneeId = ref(props.currentUser?.id ?? "");
const outcomeSaving = ref(false);
const serverInviteDirectory = ref<MeetingParticipant[]>([]);
const inviteDirectoryLoading = ref(false);
const sendingInvitations = ref(false);
const guestLinkUpdating = ref(false);
const roomPresence = ref<MeetingPresence[]>([]);
const roomConnecting = ref(false);
const chatDraft = ref("");
const messages = ref<MeetingMessage[]>([]);
const chatSaving = ref(false);
const unreadChat = ref(0);
const removingParticipantId = ref("");

const prototypeInviteDirectory: MeetingParticipant[] = [
  { name: "Sentinel", role: "Security Agent", avatar: "/avatars/sentinel.png" },
  { name: "Vector", role: "Platform Agent", avatar: "/avatars/vector.png" },
  { name: "Morgan Lee", role: "DevOption", avatar: "/brand/icon.svg" },
];
const inviteDirectory = computed(() => props.organizationId && currentMeeting.value
  ? serverInviteDirectory.value
  : prototypeInviteDirectory);
const participantKey = (participant: MeetingParticipant) => participant.id ?? participant.name;
const meetingParticipantIds = computed(() => new Set(currentMeeting.value?.participants.map((participant) => participant.id) ?? []));
const availableInvitees = computed(() => inviteDirectory.value.filter((candidate) => {
  if (candidate.id && meetingParticipantIds.value.has(candidate.id)) return false;
  return !props.participants.some((participant) => participantKey(participant) === participantKey(candidate));
}));
const filteredInvitees = computed(() => {
  const query = inviteSearch.value.trim().toLowerCase();
  if (!query) return availableInvitees.value;
  return availableInvitees.value.filter((candidate) => `${candidate.name} ${candidate.role ?? ""}`.toLowerCase().includes(query));
});
const isOrganizer = computed(() => currentMeeting.value?.organizer.id === props.currentUser?.id);
const emailInvitations = computed<CommunicationMeeting["guest_invitations"]>(() => currentMeeting.value?.guest_invitations
  ?? invitedEmails.value.map((email, index) => ({
    id: `prototype-email-${index}`,
    email,
    url: null,
    expires_at: "",
    status: "sent",
    send_count: 1,
    last_queued_at: null,
    last_sent_at: null,
    last_failed_at: null,
    admitted_at: null,
    revoked_at: null,
  })));
const displayParticipants = computed<MeetingParticipant[]>(() => {
  if (!roomPresence.value.length) return props.participants;

  return roomPresence.value
    .map((present) => {
      const details = currentMeeting.value?.participants.find((participant) => participant.id === present.id);
      return {
        id: present.id,
        name: present.name,
        avatar: avatarForName(present.name),
        role: present.id === currentMeeting.value?.organizer.id ? "Meeting organizer" : details?.kind === "guest" ? "Meeting guest" : "Meeting participant",
        participantId: details?.participant_id,
        meetingKind: details?.kind,
        admissionSource: details?.admission_source,
        canRemove: details?.can_remove,
        canBlockReentry: details?.can_block_reentry,
      };
    })
    .sort((left, right) => Number(right.id === currentMeeting.value?.organizer.id) - Number(left.id === currentMeeting.value?.organizer.id));
});
const presentParticipantIds = computed(() => new Set(roomPresence.value.map((participant) => participant.id)));
const peopleParticipants = computed<MeetingParticipant[]>(() => {
  const meeting = currentMeeting.value;
  if (!meeting) return displayParticipants.value;

  return meeting.participants
    .map((participant) => ({
      id: participant.id,
      name: participant.name,
      avatar: avatarForName(participant.name),
      role: participant.id === meeting.organizer.id
        ? "Meeting organizer"
        : participant.kind === "guest"
          ? presentParticipantIds.value.has(participant.id) ? "Meeting guest" : "Meeting guest · not in room"
          : presentParticipantIds.value.has(participant.id) ? "Meeting participant" : "Meeting participant · not in room",
      participantId: participant.participant_id,
      meetingKind: participant.kind,
      admissionSource: participant.admission_source,
      canRemove: participant.can_remove,
      canBlockReentry: participant.can_block_reentry,
    }))
    .sort((left, right) => Number(right.id === meeting.organizer.id) - Number(left.id === meeting.organizer.id));
});
const inviteSelections = ref<string[]>([]);
const bodyStyle = computed(() => ({ "--meeting-panel-width": `${panelWidth.value}px` }));
const recordingTime = computed(() => `${Math.floor(recordingSeconds.value / 60).toString().padStart(2, "0")}:${(recordingSeconds.value % 60).toString().padStart(2, "0")}`);
const outcomeAssignees = computed(() => currentMeeting.value?.participants ?? []);
const outcomeKinds = computed<MeetingOutcome["kind"][]>(() => props.guestSessionToken
  ? ["note", "decision"]
  : ["note", "decision", "action"]);
const activeScreenShare = meetingMedia.activeScreenShare;
const sharingParticipant = computed(() => {
  const identity = activeScreenShare.value?.identity;
  return displayParticipants.value.find((participant) => mediaIdentity(participant) === identity) ?? null;
});

let recordingTimer: number | null = null;
let resizeHandle: HTMLElement | null = null;
let resizePointerId: number | null = null;
let roomRealtime: MeetingRoomRealtimeController | null = null;
let roomClosed = false;
let rosterRefresh: Promise<void> | null = null;

function avatarForName(name: string): string {
  const key = name.toLowerCase().split(/\s+/)[0];
  return ["artisan", "atlas", "envoy", "katra", "sentinel", "vector"].includes(key)
    ? `/avatars/${key}.png`
    : "/brand/icon.svg";
}

function showNotice(message: string) {
  notice.value = message;
  window.setTimeout(() => { if (notice.value === message) notice.value = ""; }, 2200);
}

function mediaIdentity(participant: MeetingParticipant): string | null {
  return participant.participantId ? `mp_${participant.participantId.toLowerCase()}` : null;
}

function mediaFor(participant: MeetingParticipant) {
  return meetingMedia.forIdentity(mediaIdentity(participant));
}

function hasVisibleCamera(participant: MeetingParticipant): boolean {
  return Boolean(mediaFor(participant)?.camera)
    && (participant.id !== props.currentUser?.id || cameraVisible.value);
}

function participantMicrophoneMuted(participant: MeetingParticipant): boolean {
  return mediaFor(participant)?.microphoneMuted === true;
}

function participantHasRaisedHand(participant: MeetingParticipant): boolean {
  return Boolean(participant.id && raisedParticipantIds.value.has(participant.id));
}

function roomReactionIcon(kind: MeetingRoomReactionKind) {
  if (kind === "support") return Heart;
  if (kind === "celebrate") return PartyPopper;
  if (kind === "raise-hand" || kind === "lower-hand") return Hand;
  return ThumbsUp;
}

function applyOutcomes(updated: MeetingOutcome[]): void {
  outcomes.value = [...updated].sort((left, right) => left.sequence - right.sequence);
  if (!currentMeeting.value) return;
  currentMeeting.value = { ...currentMeeting.value, outcomes: outcomes.value };
  emit("meeting-updated", currentMeeting.value);
}

async function loadOutcomes(): Promise<void> {
  if (!currentMeeting.value) return;

  try {
    applyOutcomes(props.guestSessionToken
      ? await getGuestMeetingOutcomes(props.guestSessionToken)
      : await getMeetingOutcomes(currentMeeting.value.id));
  } catch {
    showNotice("Meeting outcomes are reconnecting.");
  }
}

async function addOutcome(): Promise<void> {
  if (!currentMeeting.value || currentMeeting.value.status !== "live" || outcomeSaving.value) return;
  const body = outcomeBody.value.trim();
  if (!body) return;
  outcomeSaving.value = true;

  try {
    const created = props.guestSessionToken
      ? await createGuestMeetingOutcome(props.guestSessionToken, { kind: outcomeKind.value, body })
      : await createMeetingOutcome(currentMeeting.value.id, {
          kind: outcomeKind.value,
          body,
          assignee_user_id: outcomeKind.value === "action" ? outcomeAssigneeId.value : null,
        });
    applyOutcomes([...outcomes.value.filter((outcome) => outcome.id !== created.id), created]);
    outcomeBody.value = "";
    showNotice(outcomeKind.value === "action" ? "Action added to the assignee’s Inbox." : `${outcomeKind.value === "decision" ? "Decision" : "Note"} saved.`);
  } catch (error) {
    showNotice(readableError(error, "The meeting outcome could not be saved."));
  } finally {
    outcomeSaving.value = false;
  }
}

function applyMessages(updated: MeetingMessage[]): void {
  messages.value = [...updated].sort((left, right) => left.sequence - right.sequence);
}

async function loadChat(): Promise<void> {
  if (!currentMeeting.value) return;

  try {
    applyMessages((props.guestSessionToken
      ? await getGuestMeetingMessages(props.guestSessionToken)
      : await getMeetingMessages(currentMeeting.value.id)).data);
  } catch {
    showNotice("Meeting chat is reconnecting.");
  }
}

async function sendChat(): Promise<void> {
  const meeting = currentMeeting.value;
  const body = chatDraft.value.trim();
  if (!meeting || meeting.status !== "live" || !body || chatSaving.value) return;
  chatSaving.value = true;

  try {
    const created = props.guestSessionToken
      ? await createGuestMeetingMessage(props.guestSessionToken, body)
      : await createMeetingMessage(meeting.id, body);
    applyMessages([...messages.value.filter((message) => message.id !== created.id), created]);
    chatDraft.value = "";
  } catch (error) {
    showNotice(readableError(error, "The meeting message could not be sent."));
  } finally {
    chatSaving.value = false;
  }
}

async function toggleChatReaction(message: MeetingMessage, kind: MeetingMessageReaction["kind"]): Promise<void> {
  const meeting = currentMeeting.value;
  if (!meeting || meeting.status !== "live") return;
  const reacted = message.reactions.find((reaction) => reaction.kind === kind)?.reacted_by_current_user ?? false;

  try {
    const updated = props.guestSessionToken
      ? await setGuestMeetingMessageReaction(props.guestSessionToken, message.id, kind, !reacted)
      : await setMeetingMessageReaction(meeting.id, message.id, kind, !reacted);
    applyMessages(messages.value.map((candidate) => candidate.id === updated.id ? updated : candidate));
  } catch (error) {
    showNotice(readableError(error, "The meeting reaction could not be changed."));
  }
}

function showRoomReaction(event: MeetingRoomReactionEvent): void {
  if (event.meeting_id !== currentMeeting.value?.id) return;
  const actor = currentMeeting.value.participants.find((participant) => participant.id === event.actor_user_id)?.name ?? "A participant";
  const labels: Record<MeetingRoomReactionKind, string> = {
    approve: `${actor} approved`,
    support: `${actor} showed support`,
    celebrate: `${actor} celebrated`,
    "raise-hand": `${actor} raised a hand`,
    "lower-hand": `${actor} lowered a hand`,
  };
  raisedParticipantIds.value = updateRaisedParticipantIds(raisedParticipantIds.value, event.actor_user_id, event.kind);
  if (event.actor_user_id === props.currentUser?.id && (event.kind === "raise-hand" || event.kind === "lower-hand")) {
    handRaised.value = event.kind === "raise-hand";
  }
  reactionEvent.value = { kind: event.kind, label: labels[event.kind] };
  window.setTimeout(() => {
    if (reactionEvent.value?.label === labels[event.kind]) reactionEvent.value = null;
  }, 2200);
}

function clearRecordingTimer() {
  if (recordingTimer !== null) window.clearInterval(recordingTimer);
  recordingTimer = null;
}

function startRecording() {
  recordingPromptOpen.value = false;
  showNotice("Recording is not available in this release yet.");
}

function stopRecording() {
  clearRecordingTimer();
  recordingActive.value = false;
  recordingSaved.value = true;
  shareFocused.value = false;
  panel.value = "notes";
  showNotice("Recording saved. Transcript and action items are processing.");
}

function toggleRecording() {
  reactionMenuOpen.value = false;
  recordingPromptOpen.value = false;
  showNotice("Recording is not available in this release yet.");
}

async function toggleShare() {
  recordingPromptOpen.value = false;
  try {
    await meetingMedia.setScreenShareEnabled(!meetingMedia.screenShareEnabled.value);
    shareFocused.value = false;
    showNotice(meetingMedia.screenShareEnabled.value ? "You are sharing your screen." : "Screen sharing stopped.");
  } catch {
    showNotice("Screen sharing could not be changed. Check browser permissions and try again.");
  }
}

async function toggleMicrophone(): Promise<void> {
  try {
    await meetingMedia.setMicrophoneEnabled(!micOn.value);
  } catch {
    showNotice("The microphone could not be changed. Check browser permissions and try again.");
  }
}

async function toggleCamera(): Promise<void> {
  try {
    await meetingMedia.setCameraEnabled(!cameraOn.value);
  } catch {
    showNotice("The camera could not be changed. Check browser permissions and try again.");
  }
}

async function selectMicrophone(deviceId: string): Promise<void> {
  try {
    await meetingMedia.selectAudioInput(deviceId);
    showNotice("Microphone changed.");
  } catch {
    showNotice("That microphone is unavailable. Choose another device.");
  }
}

async function selectCamera(deviceId: string): Promise<void> {
  try {
    await meetingMedia.selectVideoInput(deviceId);
    showNotice("Camera changed.");
  } catch {
    showNotice("That camera is unavailable. Choose another device.");
  }
}

async function sendReaction(kind: Exclude<MeetingRoomReactionKind, "lower-hand">) {
  reactionMenuOpen.value = false;
  if (!currentMeeting.value) return;
  let command: MeetingRoomReactionKind = kind;
  if (kind === "raise-hand") {
    handRaised.value = !handRaised.value;
    command = handRaised.value ? "raise-hand" : "lower-hand";
    if (props.currentUser?.id) {
      raisedParticipantIds.value = updateRaisedParticipantIds(
        raisedParticipantIds.value,
        props.currentUser.id,
        command,
      );
    }
  }
  const request = props.guestSessionToken
    ? sendGuestMeetingRoomReaction(props.guestSessionToken, command)
    : sendMeetingRoomReaction(currentMeeting.value.id, command);
  await request.catch(() => {
    if (kind === "raise-hand") {
      handRaised.value = !handRaised.value;
      if (props.currentUser?.id) {
        raisedParticipantIds.value = updateRaisedParticipantIds(
          raisedParticipantIds.value,
          props.currentUser.id,
          handRaised.value ? "raise-hand" : "lower-hand",
        );
      }
    }
    showNotice("The room reaction could not be sent.");
  });
}

function openPanel(next: "notes" | "chat" | "people") {
  panel.value = next;
  mobilePanelOpen.value = true;
  if (next !== "people") inviteOpen.value = false;
  if (next === "chat") unreadChat.value = 0;
}

function toggleMobilePanel() {
  mobilePanelOpen.value = !mobilePanelOpen.value;
  reactionMenuOpen.value = false;
  recordingPromptOpen.value = false;
  if (!mobilePanelOpen.value) inviteOpen.value = false;
}

function closeMobilePanel() {
  mobilePanelOpen.value = false;
  inviteOpen.value = false;
}

function toggleInvite(candidate: MeetingParticipant) {
  const key = participantKey(candidate);
  inviteSelections.value = inviteSelections.value.includes(key)
    ? inviteSelections.value.filter((selected) => selected !== key)
    : [...inviteSelections.value, key];
}

function readableError(error: unknown, fallback = "Invitations are unavailable. Please try again."): string {
  if (error instanceof CommunicationRequestError) {
    return Object.values(error.fields).flat()[0] ?? error.message;
  }

  return fallback;
}

async function sendInvitations() {
  if (sendingInvitations.value) return;

  if (props.organizationId && currentMeeting.value) {
    const selectedPeople = availableInvitees.value.filter((candidate) => inviteSelections.value.includes(participantKey(candidate)));

    if (!selectedPeople.length && !guestEmails.value.length) {
      showNotice("Choose at least one person to invite.");
      return;
    }

    sendingInvitations.value = true;

    try {
      let updated = currentMeeting.value;
      const emailCount = guestEmails.value.length;
      if (guestEmails.value.length) {
        updated = await addMeetingGuestInvitations(updated.id, guestEmails.value);
      }
      if (selectedPeople.length) {
        updated = await addMeetingParticipants(
          updated.id,
          selectedPeople.flatMap((candidate) => candidate.id ? [candidate.id] : []),
        );
      }
      currentMeeting.value = updated;
      invitedNames.value = invitedNames.value.filter((name) => !updated.participants.some((participant) => participant.name === name));
      inviteSelections.value = [];
      guestEmails.value = [];
      inviteOpen.value = false;
      emit("meeting-updated", updated);
      const total = selectedPeople.length + emailCount;
      showNotice(`${total} invitation${total === 1 ? "" : "s"} ready. Email delivery is queued.`);
    } catch (error) {
      showNotice(readableError(error));
    } finally {
      sendingInvitations.value = false;
    }

    return;
  }

  const fresh = inviteSelections.value.filter((name) => !invitedNames.value.includes(name));
  const freshEmails = guestEmails.value.filter((email) => !invitedEmails.value.includes(email));
  invitedNames.value = [...invitedNames.value, ...fresh];
  invitedEmails.value = [...invitedEmails.value, ...freshEmails];
  inviteOpen.value = false;
  const total = fresh.length + freshEmails.length;
  showNotice(`${total || inviteSelections.value.length + guestEmails.value.length} invitation${(total || inviteSelections.value.length + guestEmails.value.length) === 1 ? "" : "s"} sent${freshEmails.length ? `, including ${freshEmails.length} by email` : ""}.`);
}

function emailInvitationLabel(status: CommunicationMeeting["guest_invitations"][number]["status"]): string {
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

async function removeParticipant(participant: MeetingParticipant, blockReentry: boolean): Promise<void> {
  const meeting = currentMeeting.value;
  if (!meeting || !participant.participantId || !participant.canRemove || removingParticipantId.value) return;
  removingParticipantId.value = participant.participantId;

  try {
    const updated = await removeMeetingParticipant(meeting.id, participant.participantId, blockReentry);
    currentMeeting.value = updated;
    roomPresence.value = roomPresence.value.filter((present) => present.id !== participant.id);
    emit("meeting-updated", updated);
    showNotice(blockReentry ? `${participant.name} was removed and their email invitation was blocked.` : `${participant.name} was removed from the meeting.`);
  } catch (error) {
    showNotice(readableError(error, "The participant could not be removed."));
  } finally {
    removingParticipantId.value = "";
  }
}

async function manageEmailInvitation(invitationId: string, command: "resend" | "revoke"): Promise<void> {
  if (!currentMeeting.value || sendingInvitations.value) return;
  sendingInvitations.value = true;
  try {
    const updated = await updateMeetingGuestInvitation(currentMeeting.value.id, invitationId, command);
    currentMeeting.value = updated;
    emit("meeting-updated", updated);
    showNotice(command === "resend" ? "A fresh email invitation is queued." : "Email invitation revoked.");
  } catch (error) {
    showNotice(readableError(error));
  } finally {
    sendingInvitations.value = false;
  }
}

function addGuestEmail() {
  const email = guestEmail.value.trim().toLowerCase();
  if (!email.includes("@") || guestEmails.value.includes(email)) return;
  guestEmails.value = [...guestEmails.value, email];
  guestEmail.value = "";
}

async function copyInvitation(kind: "link" | "details") {
  const link = currentMeeting.value?.guest_link_url;
  if (!link) {
    showNotice("Create a new guest link before copying it.");
    return;
  }
  const details = `${props.title}\n${props.subtitle}\n${link}`;
  await navigator.clipboard?.writeText(kind === "link" ? link : details).catch(() => undefined);
  showNotice(kind === "link" ? "Meeting link copied." : "Meeting details copied.");
}

async function manageGuestLink(command: "revoke" | "regenerate"): Promise<void> {
  const meeting = currentMeeting.value;
  if (!meeting || !isOrganizer.value || guestLinkUpdating.value) return;
  guestLinkUpdating.value = true;

  try {
    const updated = await updateMeetingGuestLink(meeting.id, command);
    currentMeeting.value = updated;
    emit("meeting-updated", updated);
    showNotice(command === "revoke" ? "Guest link revoked. Admitted guests stay connected." : "A new guest link is ready.");
  } catch (error) {
    showNotice(readableError(error, "The guest link could not be changed."));
  } finally {
    guestLinkUpdating.value = false;
  }
}

async function loadInviteDirectory() {
  if (!props.organizationId || !currentMeeting.value) return;
  inviteDirectoryLoading.value = true;

  try {
    const candidates = await getMeetingCandidates(props.organizationId);
    serverInviteDirectory.value = candidates.map((candidate) => ({
      id: candidate.id,
      name: candidate.name,
      avatar: candidate.name.toLowerCase().split(/\s+/)[0] && ["artisan", "atlas", "envoy", "katra", "sentinel", "vector"].includes(candidate.name.toLowerCase().split(/\s+/)[0])
        ? `/avatars/${candidate.name.toLowerCase().split(/\s+/)[0]}.png`
        : "/brand/icon.svg",
      role: candidate.kind === "client" ? "Client participant" : "DevOption",
      kind: candidate.kind,
    }));
  } catch (error) {
    showNotice(readableError(error));
  } finally {
    inviteDirectoryLoading.value = false;
  }
}

function applyRoomPresence(users: MeetingPresence[]): void {
  roomPresence.value = users;
  raisedParticipantIds.value = retainPresentRaisedParticipantIds(
    raisedParticipantIds.value,
    new Set(users.map((user) => user.id)),
  );
  const presentNames = new Set(users.map((user) => user.name));
  invitedNames.value = invitedNames.value.filter((name) => !presentNames.has(name));

  const meeting = currentMeeting.value;
  if (
    !meeting
    || rosterRefresh
    || !users.some((user) => !meeting.participants.some((participant) => participant.id === user.id))
  ) return;

  const request = props.guestSessionToken
    ? getGuestMeeting(props.guestSessionToken)
    : getMeeting(meeting.id);
  rosterRefresh = request
    .then((updated) => {
      if (currentMeeting.value?.id !== updated.id) return;
      currentMeeting.value = updated;
      emit("meeting-updated", updated);
    })
    .catch(() => undefined)
    .finally(() => { rosterRefresh = null; });
}

function closeTerminalRoom(): void {
  if (roomClosed) return;
  roomClosed = true;
  clearRecordingTimer();
  roomRealtime?.stop();
  roomRealtime = null;
  roomPresence.value = [];
  raisedParticipantIds.value = new Set();
  void meetingMedia.disconnect();
  emit("leave");
}

async function connectMeetingMedia(): Promise<void> {
  const meeting = currentMeeting.value;
  if (!meeting) return;

  try {
    const credential = props.guestSessionToken
      ? await getGuestMeetingMediaCredential(props.guestSessionToken)
      : await getMeetingMediaCredential(meeting.id);
    await meetingMedia.connect(credential);
    if (meetingMedia.failure.value) {
      showNotice("Connected without camera or microphone. Use the controls to retry after checking browser permissions.");
    }
  } catch (error) {
    showNotice(readableError(error, "Audio and video could not connect. Check browser permissions and try again."));
  }
}

async function connectRoom(): Promise<void> {
  if (!currentMeeting.value || !props.currentUser || roomConnecting.value) return;
  roomConnecting.value = true;

  try {
    const entered = props.guestSessionToken
      ? await updateGuestMeetingRoom(props.guestSessionToken, "join")
      : await updateMeetingRoom(currentMeeting.value.id, "join");
    currentMeeting.value = entered;
    emit("meeting-updated", entered);
    await connectMeetingMedia();
    roomRealtime = startMeetingRoomRealtime({
      meetingId: entered.id,
      authToken: props.guestSessionToken,
      onPresence: applyRoomPresence,
      onStateChange: (event) => {
        if (!currentMeeting.value || event.meeting_id !== currentMeeting.value.id) return;
        currentMeeting.value = { ...currentMeeting.value, status: event.status };
        emit("meeting-updated", currentMeeting.value);
        if (event.status === "completed" || event.status === "cancelled") {
          closeTerminalRoom();
        }
      },
      onParticipantAccessChange: (event) => {
        const meeting = currentMeeting.value;
        if (!meeting || event.meeting_id !== meeting.id) return;
        const affected = meeting.participants.find((participant) => participant.participant_id === event.participant_id);

        if (event.operation === "removed") {
          const updated = {
            ...meeting,
            participants: meeting.participants.filter((participant) => participant.participant_id !== event.participant_id),
          };
          currentMeeting.value = updated;
          if (affected) roomPresence.value = roomPresence.value.filter((participant) => participant.id !== affected.id);
          emit("meeting-updated", updated);
          if (affected?.id === props.currentUser?.id) {
            void meetingMedia.disconnect();
            emit("access-revoked");
            closeTerminalRoom();
          }
          else void connectMeetingMedia();
          return;
        }

        if (!props.guestSessionToken) {
          void getMeeting(meeting.id).then((updated) => {
            currentMeeting.value = updated;
            emit("meeting-updated", updated);
          }).catch(() => undefined);
        }
      },
      onOutcomeChange: (event) => {
        if (event.meeting_id === currentMeeting.value?.id) void loadOutcomes();
      },
      onMessageChange: (event) => {
        if (event.meeting_id !== currentMeeting.value?.id) return;
        if (!messages.value.some((message) => message.id === event.message_id) && panel.value !== "chat") unreadChat.value += 1;
        void loadChat();
      },
      onMessageReactionChange: (event) => {
        if (event.meeting_id === currentMeeting.value?.id) void loadChat();
      },
      onRoomReaction: showRoomReaction,
      onError: () => showNotice("Live participant presence is reconnecting."),
    });
  } catch (error) {
    showNotice(readableError(error, "The meeting room is unavailable. Please try again."));
  } finally {
    roomConnecting.value = false;
  }
}

function clampPanelWidth(width: number) {
  const bodyWidth = bodyElement.value?.getBoundingClientRect().width ?? 1024;
  return Math.round(Math.min(Math.min(520, Math.max(320, bodyWidth - 500)), Math.max(260, width)));
}

function resizePanel(event: PointerEvent) {
  if (!panelResizing.value) return;
  const bounds = bodyElement.value?.getBoundingClientRect();
  if (bounds) panelWidth.value = clampPanelWidth(bounds.right - event.clientX - 10);
}

function stopPanelResize() {
  if (!panelResizing.value) return;
  if (resizeHandle && resizePointerId !== null && resizeHandle.hasPointerCapture(resizePointerId)) resizeHandle.releasePointerCapture(resizePointerId);
  panelResizing.value = false;
  resizeHandle = null;
  resizePointerId = null;
  window.removeEventListener("pointermove", resizePanel);
  window.removeEventListener("pointerup", stopPanelResize);
  window.removeEventListener("pointercancel", stopPanelResize);
}

function startPanelResize(event: PointerEvent) {
  if (event.pointerType === "mouse" && event.button !== 0) return;
  event.preventDefault();
  resizeHandle = event.currentTarget as HTMLElement;
  resizePointerId = event.pointerId;
  resizeHandle.setPointerCapture(event.pointerId);
  panelResizing.value = true;
  window.addEventListener("pointermove", resizePanel);
  window.addEventListener("pointerup", stopPanelResize);
  window.addEventListener("pointercancel", stopPanelResize);
}

function resizePanelWithKeyboard(event: KeyboardEvent) {
  if (!["ArrowLeft", "ArrowRight", "Home", "End"].includes(event.key)) return;
  event.preventDefault();
  if (event.key === "Home") panelWidth.value = clampPanelWidth(260);
  else if (event.key === "End") panelWidth.value = clampPanelWidth(520);
  else panelWidth.value = clampPanelWidth(panelWidth.value + (event.key === "ArrowLeft" ? 20 : -20));
}

function handleKeydown(event: KeyboardEvent) {
  if (event.key !== "Escape") return;
  if (inviteOpen.value) inviteOpen.value = false;
  else if (recordingPromptOpen.value) recordingPromptOpen.value = false;
  else if (reactionMenuOpen.value) reactionMenuOpen.value = false;
  else if (mobilePanelOpen.value) closeMobilePanel();
  else if (shareFocused.value) shareFocused.value = false;
  else if (!props.guestSessionToken) emit("minimize");
}

async function leaveMeeting() {
  clearRecordingTimer();
  closeMobilePanel();
  const meeting = currentMeeting.value;
  roomRealtime?.stop();
  roomRealtime = null;
  roomPresence.value = [];
  raisedParticipantIds.value = new Set();
  await meetingMedia.disconnect();
  if (meeting) {
    const request = props.guestSessionToken
      ? updateGuestMeetingRoom(props.guestSessionToken, "leave")
      : updateMeetingRoom(meeting.id, "leave");
    await request.catch(() => undefined);
  }
  closeTerminalRoom();
}

onMounted(() => {
  panelWidth.value = clampPanelWidth(panelWidth.value);
  void loadInviteDirectory();
  void connectRoom();
  void loadOutcomes();
  void loadChat();
  window.addEventListener("keydown", handleKeydown);
});
onBeforeUnmount(() => { clearRecordingTimer(); stopPanelResize(); roomRealtime?.stop(); void meetingMedia.disconnect(); window.removeEventListener("keydown", handleKeydown); });
</script>

<template>
  <section class="meeting-room-window" role="dialog" aria-modal="true" :aria-label="title">
    <header class="meeting-room-header">
      <div><span class="meeting-live-indicator"><i /> {{ currentMeeting?.status === "completed" ? "Ended" : "Live" }}</span><span v-if="recordingActive" class="meeting-recording-indicator"><Circle :size="9" fill="currentColor" aria-hidden="true" /> Recording {{ recordingTime }}</span><div><h2>{{ title }}</h2><p>{{ subtitle }} · {{ displayParticipants.length }} present</p></div></div>
      <div><button v-if="!guestSessionToken" class="meeting-header-record-button" type="button" :class="{ 'is-recording': recordingActive }" :aria-pressed="recordingActive" :aria-label="recordingActive ? 'Stop meeting recording' : 'Record meeting'" @click="toggleRecording"><Square v-if="recordingActive" :size="13" fill="currentColor" aria-hidden="true" /><Circle v-else :size="15" aria-hidden="true" /><span>{{ recordingActive ? recordingTime : 'Record' }}</span></button><span class="meeting-room-time" aria-label="Meeting elapsed time">{{ meetingElapsedTime }}</span><button v-if="!guestSessionToken" type="button" aria-label="Minimize meeting" @click="emit('minimize')"><Minimize2 :size="17" aria-hidden="true" /></button></div>
      <div v-if="!guestSessionToken && recordingPromptOpen" class="meeting-recording-popover meeting-recording-popover--header" role="dialog" aria-label="Start meeting recording"><header><span><Circle :size="11" fill="currentColor" aria-hidden="true" /></span><div><strong>Start recording?</strong><small>Everyone in the room will be notified.</small></div></header><p>Capture audio, video, chat, notes, reactions, and shared content.</p><label><input v-model="recordingTranscript" type="checkbox" /><span><strong>Create transcript</strong><small>Searchable text with speaker names</small></span></label><label><input v-model="recordingActions" type="checkbox" /><span><strong>Find action items</strong><small>Draft follow-up work automatically</small></span></label><footer><button type="button" @click="recordingPromptOpen = false">Cancel</button><button type="button" @click="startRecording"><Circle :size="11" fill="currentColor" aria-hidden="true" /> Start recording</button></footer></div>
    </header>

    <div ref="bodyElement" class="meeting-room-body" :class="{ 'meeting-room-body--focus': shareFocused, 'meeting-room-body--resizing': panelResizing, 'meeting-room-body--mobile-panel-open': mobilePanelOpen }" :style="bodyStyle">
      <div class="meeting-audio-sinks" aria-hidden="true"><MeetingParticipantAudio v-for="participant in displayParticipants" :key="`audio-${participantKey(participant)}`" :media="mediaFor(participant)" /></div>
      <section class="meeting-stage huddle-meeting-stage" :class="{ 'meeting-stage--share': shareActive, 'meeting-stage--share-focused': shareFocused, 'huddle-meeting-stage--compact': displayParticipants.length <= 2 }" :aria-label="shareActive ? 'Shared screen and room participants' : 'Room participants'">
        <template v-if="shareActive">
          <article class="meeting-shared-screen"><header><span><MonitorUp :size="15" aria-hidden="true" /> {{ sharingParticipant?.name ?? 'Participant' }} is sharing</span><strong>Shared screen</strong></header><MeetingParticipantMedia v-if="activeScreenShare" :media="activeScreenShare.media" source="screen-share" /><div class="meeting-share-actions"><button type="button" :aria-label="shareFocused ? 'Exit shared screen focus' : 'Focus shared screen'" @click="shareFocused = !shareFocused"><Minimize2 v-if="shareFocused" :size="16" aria-hidden="true" /><Maximize2 v-else :size="16" aria-hidden="true" /><span>{{ shareFocused ? 'Exit focus' : 'Focus share' }}</span></button><button v-if="meetingMedia.screenShareEnabled.value" type="button" aria-label="Stop screen sharing" @click="toggleShare"><MonitorUp :size="16" aria-hidden="true" /><span>Stop sharing</span></button></div></article>
          <div v-if="!shareFocused" class="meeting-share-participants"><article v-for="participant in displayParticipants" :key="participantKey(participant)"><img :src="participant.avatar" alt="" /><span><strong>{{ participant.name }}</strong><small>{{ mediaFor(participant)?.isSpeaking ? 'Speaking' : participantMicrophoneMuted(participant) ? 'Muted' : 'Listening' }}</small></span><div v-if="participantMicrophoneMuted(participant) || participantHasRaisedHand(participant)" class="meeting-filmstrip-states"><MicOff v-if="participantMicrophoneMuted(participant)" :size="12" aria-label="Microphone muted" /><Hand v-if="participantHasRaisedHand(participant)" :size="12" aria-label="Hand raised" /></div></article></div>
        </template>
        <template v-else>
          <article v-for="(participant, index) in displayParticipants" :key="participantKey(participant)" class="meeting-participant-tile" :class="{ 'meeting-participant-tile--primary': index === 0, 'meeting-participant-tile--speaker': mediaFor(participant)?.isSpeaking }"><span v-if="mediaFor(participant)?.isSpeaking" class="meeting-speaker-state"><i /> Speaking</span><span v-if="participantHasRaisedHand(participant)" class="meeting-hand-state"><Hand :size="13" aria-hidden="true" /> Hand raised</span><span v-if="participantMicrophoneMuted(participant)" class="meeting-mute-state"><MicOff :size="13" aria-hidden="true" /> Muted</span><MeetingParticipantMedia :media="mediaFor(participant)" :video-enabled="hasVisibleCamera(participant)" /><img :src="participant.avatar" :alt="participant.name" /><div class="meeting-participant-identity" :class="{ 'has-camera': hasVisibleCamera(participant) }"><strong>{{ participant.name }} <em v-if="participant.id === currentUser?.id">You</em></strong><small>{{ participant.role ?? 'Room participant' }}</small></div></article>
        </template>
        <div v-if="reactionEvent" class="meeting-stage-reaction" role="status"><component :is="roomReactionIcon(reactionEvent.kind)" :size="18" aria-hidden="true" /> {{ reactionEvent.label }}</div>
      </section>

      <div v-if="!shareFocused" class="meeting-panel-resize-handle" role="separator" aria-label="Resize meeting sidebar" aria-orientation="vertical" aria-valuemin="260" aria-valuemax="520" :aria-valuenow="panelWidth" tabindex="0" @pointerdown="startPanelResize" @keydown="resizePanelWithKeyboard"><span /></div>

      <aside v-if="!shareFocused" id="meeting-focus-sheet" class="meeting-room-panel" :class="{ 'meeting-room-panel--mobile-open': mobilePanelOpen }" aria-label="Meeting collaboration panel">
        <div class="meeting-mobile-panel-handle"><span aria-hidden="true" /><button type="button" aria-label="Collapse meeting tools" @click="closeMobilePanel"><ChevronDown :size="20" aria-hidden="true" /></button></div>
        <header><button type="button" :class="{ 'is-active': panel === 'notes' }" @click="openPanel('notes')"><ListFilter :size="14" aria-hidden="true" /> Notes</button><button type="button" :class="{ 'is-active': panel === 'chat' }" @click="openPanel('chat')"><MessageSquareText :size="14" aria-hidden="true" /> Chat <span v-if="unreadChat">{{ unreadChat }}</span></button><button type="button" :class="{ 'is-active': panel === 'people' }" @click="openPanel('people')"><UsersRound :size="14" aria-hidden="true" /> People <span>{{ displayParticipants.length }}</span></button></header>
        <div v-if="panel === 'notes'" class="meeting-room-notes">
          <article v-if="recordingSaved" class="meeting-recording-summary"><header><span><Circle :size="9" fill="currentColor" aria-hidden="true" /> Recording saved</span><time>{{ recordingTime }}</time></header><p>The room archive includes audio, video, chat, notes, reactions, and shared content.</p><ul><li><FileText :size="14" aria-hidden="true" /><span><strong>Transcript</strong><small>{{ recordingTranscript ? 'Processing automatically' : 'Not requested' }}</small></span></li><li><ListChecks :size="14" aria-hidden="true" /><span><strong>Action items</strong><small>{{ recordingActions ? 'Generating from the transcript' : 'Not requested' }}</small></span></li></ul><button type="button" @click="showNotice('The recording is ready in the meeting archive.')"><Play :size="14" aria-hidden="true" /> Open recording</button></article>
          <section><h3>Huddle notes</h3><ol><li class="is-current"><span>1</span><p><strong>Current room context</strong><small>{{ subtitle }}</small></p></li><li><span>2</span><p><strong>Capture decisions</strong><small>Keep follow-up attached to normal Inbox work</small></p></li></ol></section>
          <section class="meeting-outcomes" aria-labelledby="meeting-outcomes-title">
            <header><div><h3 id="meeting-outcomes-title">Meeting outcomes</h3><small>{{ outcomes.length ? `${outcomes.length} saved` : "Shared with meeting participants" }}</small></div></header>
            <ol v-if="outcomes.length" class="meeting-outcome-list">
              <li v-for="outcome in outcomes" :key="outcome.id" :class="`meeting-outcome meeting-outcome--${outcome.kind}`">
                <span>{{ outcome.kind === "decision" ? "Decision" : outcome.kind === "action" ? "Action" : "Note" }}</span>
                <p><strong>{{ outcome.body }}</strong><small>{{ outcome.author.name }} · {{ new Date(outcome.created_at).toLocaleTimeString([], { hour: "numeric", minute: "2-digit" }) }}<template v-if="outcome.assignee"> · {{ outcome.assignee.name }}<template v-if="outcome.completed_at"> · Complete</template></template></small></p>
              </li>
            </ol>
            <p v-else class="meeting-outcome-empty">No durable outcomes yet. Add the first note, decision, or assigned action.</p>
            <form class="meeting-outcome-composer" @submit.prevent="addOutcome">
              <div class="meeting-outcome-kind" aria-label="Outcome kind">
                <button v-for="kind in outcomeKinds" :key="kind" type="button" :class="{ 'is-active': outcomeKind === kind }" :aria-pressed="outcomeKind === kind" @click="outcomeKind = kind">{{ kind === "action" ? "Action" : kind === "decision" ? "Decision" : "Note" }}</button>
              </div>
              <textarea v-model="outcomeBody" maxlength="2000" :placeholder="outcomeKind === 'action' ? 'Describe the follow-up…' : outcomeKind === 'decision' ? 'Record the decision…' : 'Add a shared note…'" aria-label="Meeting outcome" />
              <footer><label v-if="outcomeKind === 'action'"><span>Assign to</span><span class="meeting-outcome-assignee"><select v-model="outcomeAssigneeId" aria-label="Assign action item"><option v-for="participant in outcomeAssignees" :key="participant.id" :value="participant.id">{{ participant.name }}</option></select><ChevronDown :size="14" aria-hidden="true" /></span></label><button type="submit" :disabled="outcomeSaving || !outcomeBody.trim() || (outcomeKind === 'action' && !outcomeAssigneeId)">{{ outcomeSaving ? "Saving…" : "Add outcome" }}</button></footer>
            </form>
          </section>
        </div>
        <div v-else-if="panel === 'chat'" class="meeting-room-chat"><div class="meeting-chat-messages" role="log" aria-label="Meeting chat messages" aria-live="polite"><p v-if="!messages.length" class="meeting-chat-empty">No messages yet. Start the meeting conversation.</p><article v-for="message in messages" :key="message.id" :class="{ 'is-self': message.author.id === currentUser?.id }"><img :src="avatarForName(message.author.name)" alt="" /><div><header><strong>{{ message.author.name }}</strong><time>{{ new Date(message.created_at).toLocaleTimeString([], { hour: 'numeric', minute: '2-digit' }) }}</time></header><MarkdownMessage :body="message.body" /><footer><button v-for="reaction in message.reactions" :key="reaction.kind" type="button" :class="{ 'is-active': reaction.reacted_by_current_user }" :aria-label="`${reaction.reacted_by_current_user ? 'Remove' : 'Add'} ${reaction.kind} reaction on ${message.author.name}'s message`" @click="toggleChatReaction(message, reaction.kind)"><Heart v-if="reaction.kind === 'support'" :size="12" aria-hidden="true" /><ThumbsUp v-else :size="12" aria-hidden="true" /> {{ reaction.count }}</button><button v-if="!message.reactions.some((reaction) => reaction.kind === 'approve')" type="button" :aria-label="`Approve ${message.author.name}'s message`" @click="toggleChatReaction(message, 'approve')"><ThumbsUp :size="12" aria-hidden="true" /></button><button v-if="!message.reactions.some((reaction) => reaction.kind === 'support')" type="button" :aria-label="`Support ${message.author.name}'s message`" @click="toggleChatReaction(message, 'support')"><Heart :size="12" aria-hidden="true" /></button></footer></div></article></div><form class="meeting-chat-composer" @submit.prevent="sendChat"><textarea v-model="chatDraft" maxlength="4000" aria-label="Message meeting chat" placeholder="Message everyone…" @keydown.enter.exact.prevent="sendChat" /><button type="submit" :disabled="chatSaving || !chatDraft.trim()" aria-label="Send meeting message"><MessageSquareText :size="16" aria-hidden="true" /></button></form></div>
        <div v-else class="meeting-room-people-wrap">
          <button v-if="isOrganizer" class="meeting-invite-trigger" type="button" :aria-expanded="inviteOpen" @click="inviteOpen = !inviteOpen"><UserPlus :size="15" aria-hidden="true" /> Invite people</button>
          <section v-if="inviteOpen" class="meeting-invite-panel" aria-label="Invite people to the meeting">
            <header><div><strong>Invite people</strong><small>They can join immediately.</small></div><button type="button" aria-label="Close invitations" @click="inviteOpen = false"><X :size="14" aria-hidden="true" /></button></header>
            <label class="meeting-invite-search"><Search :size="14" aria-hidden="true" /><input v-model="inviteSearch" type="search" placeholder="Search people…" aria-label="Search meeting invitees" /></label>
            <label v-for="candidate in filteredInvitees" :key="participantKey(candidate)"><input type="checkbox" :checked="inviteSelections.includes(participantKey(candidate))" @change="toggleInvite(candidate)" /><img :src="candidate.avatar" alt="" /><span><strong>{{ candidate.name }}</strong><small>{{ candidate.role }}</small></span></label>
            <p v-if="inviteDirectoryLoading" class="meeting-invite-empty">Loading people…</p>
            <p v-else-if="!filteredInvitees.length" class="meeting-invite-empty">No people match that search.</p>
            <div class="meeting-invite-email-entry"><MailPlus :size="14" aria-hidden="true" /><input v-model="guestEmail" type="email" placeholder="Invite guest by email…" aria-label="Guest email address" @keydown.enter.prevent="addGuestEmail" /><button type="button" :disabled="!guestEmail.includes('@')" @click="addGuestEmail">Add</button></div>
            <ul v-if="guestEmails.length" class="meeting-invite-email-list"><li v-for="email in guestEmails" :key="email"><span>{{ email }}</span><button type="button" :aria-label="`Remove ${email}`" @click="guestEmails = guestEmails.filter((item) => item !== email)"><X :size="11" aria-hidden="true" /></button></li></ul>
            <div class="meeting-invite-copy-actions"><button type="button" :disabled="!currentMeeting?.guest_link_url" @click="copyInvitation('link')"><Link2 :size="13" aria-hidden="true" /> Copy link</button><button type="button" :disabled="!currentMeeting?.guest_link_url" @click="copyInvitation('details')"><Copy :size="13" aria-hidden="true" /> Copy details</button><button type="button" :disabled="guestLinkUpdating" @click="manageGuestLink(currentMeeting?.guest_link_url ? 'revoke' : 'regenerate')"><Link2 :size="13" aria-hidden="true" /> {{ guestLinkUpdating ? "Updating…" : currentMeeting?.guest_link_url ? "Revoke link" : "Create new link" }}</button></div>
            <button type="button" :disabled="sendingInvitations || (!inviteSelections.length && !guestEmails.length)" @click="sendInvitations"><UserPlus :size="14" aria-hidden="true" /> {{ sendingInvitations ? "Sending…" : "Send invitations" }}</button>
          </section>
          <ul class="meeting-room-people">
            <li v-for="(participant, index) in peopleParticipants" :key="participantKey(participant)"><img :src="participant.avatar" alt="" /><p><strong>{{ participant.name }} <span v-if="participant.id === currentUser?.id">You</span></strong><small>{{ participant.role ?? (index === 0 ? 'Speaking' : 'Room participant') }}</small></p><span v-if="isOrganizer && participant.canRemove" class="meeting-participant-actions"><button type="button" :disabled="Boolean(removingParticipantId)" :aria-label="`Remove ${participant.name} from this meeting`" @click="removeParticipant(participant, false)"><UserMinus :size="12" aria-hidden="true" /> {{ removingParticipantId === participant.participantId ? 'Removing…' : 'Remove' }}</button><button v-if="participant.canBlockReentry" type="button" :disabled="Boolean(removingParticipantId)" :aria-label="`Remove ${participant.name} and block their email invitation`" @click="removeParticipant(participant, true)">Remove &amp; block</button></span><template v-else-if="presentParticipantIds.has(participant.id ?? '')"><MicOff v-if="participantMicrophoneMuted(participant)" :size="14" aria-label="Microphone muted" /><Mic v-else :size="14" aria-label="Microphone on" /></template><Clock3 v-else :size="14" aria-label="Not in room" /></li>
            <li v-for="name in invitedNames" :key="name" class="is-invited"><img :src="availableInvitees.find((candidate) => candidate.name === name)?.avatar ?? '/brand/icon.svg'" alt="" /><p><strong>{{ name }}</strong><small>Invitation sent · awaiting response</small></p><Clock3 :size="14" aria-label="Invitation pending" /></li>
            <li v-for="invitation in emailInvitations" :key="invitation.id" class="is-invited"><span class="meeting-email-avatar"><MailPlus :size="14" aria-hidden="true" /></span><p><strong>{{ invitation.email }}</strong><small>{{ emailInvitationLabel(invitation.status) }}</small></p><span v-if="isOrganizer" class="meeting-email-actions"><button type="button" :disabled="sendingInvitations" @click="manageEmailInvitation(invitation.id, 'resend')">Resend</button><button v-if="invitation.status !== 'revoked'" type="button" :disabled="sendingInvitations" @click="manageEmailInvitation(invitation.id, 'revoke')">Revoke</button></span><Clock3 v-else :size="14" aria-label="Invitation pending" /></li>
          </ul>
        </div>
      </aside>
    </div>

    <footer class="meeting-room-controls"><div class="meeting-room-control-group"><MeetingDeviceControl kind="microphone" :enabled="micOn" :devices="meetingMedia.audioInputDevices.value" :selected-device-id="meetingMedia.selectedAudioInputId.value" @toggle="toggleMicrophone" @select="selectMicrophone" /><MeetingDeviceControl kind="camera" :enabled="cameraOn" :devices="meetingMedia.videoInputDevices.value" :selected-device-id="meetingMedia.selectedVideoInputId.value" @toggle="toggleCamera" @select="selectCamera" /><button type="button" class="meeting-desktop-call-control meeting-share-control" :class="{ 'is-active': shareActive }" :aria-pressed="meetingMedia.screenShareEnabled.value" :aria-label="meetingMedia.screenShareEnabled.value ? 'Stop screen sharing' : 'Start screen sharing'" @click="toggleShare"><MonitorUp :size="18" aria-hidden="true" /><span>{{ meetingMedia.screenShareEnabled.value ? 'Sharing' : 'Share' }}</span></button><button type="button" class="meeting-desktop-call-control meeting-reaction-control" :class="{ 'is-active': reactionMenuOpen }" aria-haspopup="menu" :aria-expanded="reactionMenuOpen" @click="recordingPromptOpen = false; reactionMenuOpen = !reactionMenuOpen"><Smile :size="18" aria-hidden="true" /><span>React</span></button><button type="button" class="meeting-desktop-call-control" :class="{ 'is-active': panel === 'chat' }" @click="openPanel('chat')"><MessageSquareText :size="18" aria-hidden="true" /><span>Chat</span></button><button type="button" class="meeting-desktop-call-control" :class="{ 'is-active': panel === 'people' }" @click="openPanel('people')"><UsersRound :size="18" aria-hidden="true" /><span>People</span></button><button type="button" class="meeting-mobile-tools-button" :class="{ 'is-active': mobilePanelOpen }" :aria-expanded="mobilePanelOpen" aria-controls="meeting-focus-sheet" aria-label="Meeting tools" @click="toggleMobilePanel"><Ellipsis :size="20" aria-hidden="true" /><b v-if="unreadChat">{{ unreadChat }}</b><span>Tools</span></button><button type="button" class="meeting-leave-button" aria-label="Leave meeting" @click="leaveMeeting"><PhoneOff :size="18" aria-hidden="true" /><span>Leave</span></button></div>
      <div v-if="reactionMenuOpen" class="meeting-reaction-tray" role="menu" aria-label="Meeting reactions"><button type="button" role="menuitem" @click="sendReaction('approve')"><ThumbsUp :size="17" aria-hidden="true" /><span>Approve</span></button><button type="button" role="menuitem" @click="sendReaction('support')"><Heart :size="17" aria-hidden="true" /><span>Support</span></button><button type="button" role="menuitem" @click="sendReaction('celebrate')"><PartyPopper :size="17" aria-hidden="true" /><span>Celebrate</span></button><button type="button" role="menuitem" :aria-pressed="handRaised" @click="sendReaction('raise-hand')"><Hand :size="17" aria-hidden="true" /><span>{{ handRaised ? "Lower hand" : "Raise hand" }}</span></button></div>
      <p v-if="notice" role="status">{{ notice }}</p>
    </footer>
  </section>
</template>

<style scoped>
.huddle-meeting-stage:not(.meeting-stage--share) { grid-template-columns: repeat(3, minmax(0, 1fr)); grid-template-rows: minmax(170px, 1.45fr) repeat(2, minmax(90px, 1fr)); }
.huddle-meeting-stage:not(.meeting-stage--share) .meeting-participant-tile--primary { grid-column: 1 / -1; }
.huddle-meeting-stage--compact:not(.meeting-stage--share) { grid-template-columns: repeat(2, minmax(0, 1fr)); grid-template-rows: repeat(2, minmax(0, 1fr)); }
.huddle-meeting-stage--compact:not(.meeting-stage--share) .meeting-participant-tile--primary { grid-column: 1 / -1; }
.meeting-stage--share .meeting-share-participants { overflow-x: auto; }
.meeting-stage--share .meeting-share-participants article { min-width: 92px; }
.meeting-end-button { display: inline-flex; align-items: center; gap: 7px; border: 0; border-radius: 8px; padding: 9px 13px; background: rgb(191 97 106 / 16%); color: #e6aeb4; font: inherit; font-size: 12px; font-weight: 700; cursor: pointer; }
.meeting-end-button:disabled { opacity: .6; cursor: default; }
.meeting-email-actions { display: inline-flex; gap: 5px; }
.meeting-email-actions button { border: 0; border-radius: 6px; padding: 5px 7px; background: #3c4451; color: #aeb8c5; font: inherit; font-size: 10px; cursor: pointer; }
.meeting-email-actions button:hover, .meeting-email-actions button:focus-visible { background: #514651; color: #e4c9e1; outline: 0; }
.meeting-email-actions button:disabled { opacity: .55; cursor: default; }
.meeting-participant-actions { display: inline-flex; align-items: center; gap: 4px; flex-wrap: wrap; justify-content: flex-end; }
.meeting-participant-actions button { display: inline-flex; align-items: center; gap: 4px; border: 1px solid rgb(191 97 106 / 24%); border-radius: 6px; padding: 5px 7px; background: rgb(191 97 106 / 10%); color: #d8a4aa; font: inherit; font-size: 10px; font-weight: 700; cursor: pointer; }
.meeting-participant-actions button:hover, .meeting-participant-actions button:focus-visible { background: rgb(191 97 106 / 18%); color: #f0c1c6; outline: 0; }
.meeting-participant-actions button:disabled { opacity: .55; cursor: default; }
@media (max-width: 820px), (max-width: 950px) and (max-height: 540px) {
  .huddle-meeting-stage:not(.meeting-stage--share),
  .huddle-meeting-stage--compact:not(.meeting-stage--share) {
    grid-template-columns: repeat(3, minmax(0, 1fr));
    grid-template-rows: minmax(0, 1fr) 104px;
  }
  .meeting-room-body--mobile-panel-open .huddle-meeting-stage:not(.meeting-stage--share),
  .meeting-room-body--mobile-panel-open .huddle-meeting-stage--compact:not(.meeting-stage--share) {
    grid-template-columns: minmax(0, 1fr);
    grid-template-rows: minmax(0, 1fr);
  }
  .meeting-room-controls > .meeting-end-button { display: none; }
}
@media (max-width: 950px) and (max-height: 540px) {
  .huddle-meeting-stage:not(.meeting-stage--share),
  .huddle-meeting-stage--compact:not(.meeting-stage--share) {
    grid-template-rows: minmax(0, 1fr) 84px;
  }
  .meeting-room-body--mobile-panel-open .huddle-meeting-stage:not(.meeting-stage--share),
  .meeting-room-body--mobile-panel-open .huddle-meeting-stage--compact:not(.meeting-stage--share) {
    grid-template-rows: minmax(0, 1fr);
  }
}
</style>
