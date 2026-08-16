<script setup lang="ts">
import { CalendarClock, LockKeyhole, Video, UsersRound } from "@lucide/vue";
import { computed, onBeforeUnmount, onMounted, ref } from "vue";
import { useRoute, useRouter } from "vue-router";
import type { AuthUser } from "../../api/auth";
import { CommunicationRequestError, type CommunicationMeeting } from "../../api/communication";
import {
  admitGuestMeeting,
  admitEmailMeetingInvitation,
  getGuestMeeting,
  inspectGuestMeeting,
  inspectEmailMeetingInvitation,
  type GuestMeetingLobby,
} from "../../api/meetingGuests";
import type { MeetingParticipant } from "./MeetingScheduleDialog.vue";
import HuddleMeeting from "./HuddleMeeting.vue";

const route = useRoute();
const router = useRouter();
const isEmailInvitation = computed(() => route.name === "meeting-email-invitation");
const accessId = computed(() => String(isEmailInvitation.value ? route.params.invitationId ?? "" : route.params.meetingId ?? ""));
const storageKey = computed(() => `katra.meeting-guest.${isEmailInvitation.value ? "email" : "link"}.${accessId.value}`);
const lobby = ref<GuestMeetingLobby | null>(null);
const meeting = ref<CommunicationMeeting | null>(null);
const sessionToken = ref("");
const linkToken = ref("");
const displayName = ref("");
const participant = ref<{ id: string; name: string } | null>(null);
const inspecting = ref(true);
const admitting = ref(false);
const roomOpen = ref(false);
const errorMessage = ref("");
const accessEnded = ref(false);
const admissionKey = ref(crypto.randomUUID());
let scheduledPoll: number | null = null;

const currentUser = computed<AuthUser | undefined>(() => participant.value ? {
  id: participant.value.id,
  first_name: participant.value.name.split(/\s+/)[0] ?? participant.value.name,
  last_name: participant.value.name.split(/\s+/).slice(1).join(" "),
  name: participant.value.name,
  email: "",
  email_verified_at: null,
  is_global_administrator: false,
} : undefined);
const roomParticipants = computed<MeetingParticipant[]>(() => (meeting.value?.participants ?? []).map((person) => ({
  id: person.id,
  name: person.name,
  avatar: "/brand/icon.svg",
  role: person.kind === "guest" ? "Meeting guest" : person.id === meeting.value?.organizer.id ? "Meeting organizer" : "Meeting participant",
  participantId: person.participant_id,
  meetingKind: person.kind,
  admissionSource: person.admission_source,
  canRemove: person.can_remove,
  canBlockReentry: person.can_block_reentry,
})));
const startsLabel = computed(() => lobby.value
  ? new Intl.DateTimeFormat(undefined, { dateStyle: "medium", timeStyle: "short" }).format(new Date(lobby.value.starts_at))
  : "");
const canEnter = computed(() => lobby.value?.status === "live" && displayName.value.trim().length >= 2);

function readableError(error: unknown): string {
  if (error instanceof CommunicationRequestError) {
    return Object.values(error.fields).flat()[0] ?? error.message;
  }
  return "This meeting could not be reached. Please try again.";
}

function saveSession(): void {
  if (!sessionToken.value || !participant.value) return;
  sessionStorage.setItem(storageKey.value, JSON.stringify({
    token: sessionToken.value,
    participant: participant.value,
  }));
}

function clearSession(): void {
  sessionStorage.removeItem(storageKey.value);
  sessionToken.value = "";
  participant.value = null;
  meeting.value = null;
  roomOpen.value = false;
}

async function restoreSession(): Promise<boolean> {
  const stored = sessionStorage.getItem(storageKey.value);
  if (!stored) return false;

  try {
    const value = JSON.parse(stored) as { token?: unknown; participant?: { id?: unknown; name?: unknown } };
    if (typeof value.token !== "string" || typeof value.participant?.id !== "string" || typeof value.participant.name !== "string") {
      clearSession();
      return false;
    }
    const restoredMeeting = await getGuestMeeting(value.token);
    sessionToken.value = value.token;
    participant.value = { id: value.participant.id, name: value.participant.name };
    meeting.value = restoredMeeting;
    roomOpen.value = restoredMeeting.status === "live";
    return true;
  } catch {
    clearSession();
    return false;
  }
}

async function inspectLink(quiet = false): Promise<void> {
  if (!linkToken.value) return;
  if (!quiet) inspecting.value = true;

  try {
    lobby.value = await (isEmailInvitation.value
      ? inspectEmailMeetingInvitation(accessId.value, linkToken.value)
      : inspectGuestMeeting(accessId.value, linkToken.value));
    errorMessage.value = "";
  } catch (error) {
    if (!quiet) errorMessage.value = readableError(error);
  } finally {
    inspecting.value = false;
  }
}

async function enterMeeting(): Promise<void> {
  if (!canEnter.value || admitting.value || !linkToken.value) return;
  admitting.value = true;
  errorMessage.value = "";

  try {
    const admitted = await (isEmailInvitation.value ? admitEmailMeetingInvitation : admitGuestMeeting)(
      accessId.value,
      linkToken.value,
      displayName.value.trim(),
      admissionKey.value,
    );
    sessionToken.value = admitted.session_token;
    participant.value = admitted.participant;
    meeting.value = admitted.meeting;
    roomOpen.value = true;
    saveSession();
  } catch (error) {
    errorMessage.value = readableError(error);
  } finally {
    admitting.value = false;
  }
}

async function rejoinMeeting(): Promise<void> {
  if (!sessionToken.value) return;
  try {
    meeting.value = await getGuestMeeting(sessionToken.value);
    roomOpen.value = meeting.value.status === "live";
  } catch (error) {
    clearSession();
    errorMessage.value = readableError(error);
  }
}

function handleMeetingUpdated(updated: CommunicationMeeting): void {
  meeting.value = updated;
}

function handleAccessRevoked(): void {
  sessionStorage.removeItem(storageKey.value);
  sessionToken.value = "";
  participant.value = null;
  meeting.value = null;
  roomOpen.value = false;
  accessEnded.value = true;
  errorMessage.value = "The organizer ended your access to this meeting.";
}

async function returnToInvitation(): Promise<void> {
  admissionKey.value = crypto.randomUUID();
  accessEnded.value = false;
  await inspectLink();
}

onMounted(async () => {
  const fragmentToken = new URLSearchParams(route.hash.replace(/^#/, "")).get("token") ?? "";
  linkToken.value = fragmentToken || (typeof route.query.token === "string" ? route.query.token : "");

  if (fragmentToken || typeof route.query.token === "string") {
    const query = { ...route.query };
    delete query.token;
    await router.replace({ path: route.path, query, hash: "" });
  }

  const restored = await restoreSession();
  if (restored) {
    inspecting.value = false;
    return;
  }

  if (!linkToken.value) {
    inspecting.value = false;
    errorMessage.value = "This meeting link is unavailable or has expired.";
    return;
  }

  await inspectLink();
  scheduledPoll = window.setInterval(() => {
    if (lobby.value?.status === "scheduled") void inspectLink(true);
  }, 5000);
});

onBeforeUnmount(() => {
  if (scheduledPoll !== null) window.clearInterval(scheduledPoll);
});
</script>

<template>
  <main class="guest-meeting-page">
    <HuddleMeeting
      v-if="roomOpen && meeting && currentUser"
      :title="meeting.title"
      :subtitle="`Guest of ${meeting.organization.name}`"
      :participants="roomParticipants"
      :meeting="meeting"
      :current-user="currentUser"
      :guest-session-token="sessionToken"
      @meeting-updated="handleMeetingUpdated"
      @access-revoked="handleAccessRevoked"
      @leave="roomOpen = false"
    />

    <section v-else class="guest-meeting-card" aria-labelledby="guest-meeting-title">
      <header class="guest-meeting-brand"><img src="/brand/icon.svg" alt="" /><span>Katra meetings</span></header>

      <div v-if="inspecting" class="guest-meeting-loading" role="status"><i /><p>Checking this meeting link…</p></div>

      <template v-else-if="meeting && participant">
        <span class="guest-meeting-icon"><Video :size="25" aria-hidden="true" /></span>
        <p class="guest-meeting-eyebrow">You left the room</p>
        <h1 id="guest-meeting-title">{{ meeting.title }}</h1>
        <p v-if="meeting.status === 'live'" class="guest-meeting-summary">Your guest session is still active in this browser tab.</p>
        <p v-else class="guest-meeting-summary">This meeting has ended.</p>
        <button v-if="meeting.status === 'live'" class="guest-meeting-primary" type="button" @click="rejoinMeeting"><Video :size="17" aria-hidden="true" /> Rejoin meeting</button>
      </template>

      <template v-else-if="accessEnded">
        <span class="guest-meeting-icon guest-meeting-icon--error"><LockKeyhole :size="25" aria-hidden="true" /></span>
        <p class="guest-meeting-eyebrow">Access ended</p>
        <h1 id="guest-meeting-title">You were removed from this meeting</h1>
        <p class="guest-meeting-summary">{{ errorMessage }}</p>
        <button v-if="linkToken" class="guest-meeting-primary" type="button" @click="returnToInvitation"><Video :size="17" aria-hidden="true" /> Return to invitation</button>
      </template>

      <template v-else-if="lobby">
        <span class="guest-meeting-icon"><UsersRound :size="25" aria-hidden="true" /></span>
        <p class="guest-meeting-eyebrow">{{ lobby.organization.name }}</p>
        <h1 id="guest-meeting-title">{{ lobby.title }}</h1>
        <p class="guest-meeting-summary">Hosted by {{ lobby.organizer.name }}</p>
        <dl><div><dt><CalendarClock :size="15" aria-hidden="true" /> Starts</dt><dd>{{ startsLabel }}</dd></div><div><dt>Length</dt><dd>{{ lobby.duration_minutes }} minutes</dd></div></dl>

        <form @submit.prevent="enterMeeting">
          <label for="guest-display-name">Your display name</label>
          <input id="guest-display-name" v-model="displayName" maxlength="80" autocomplete="name" placeholder="How others will see you" />
          <button class="guest-meeting-primary" type="submit" :disabled="!canEnter || admitting"><Video :size="17" aria-hidden="true" /> {{ admitting ? "Entering…" : lobby.status === "live" ? "Join meeting" : "Meeting hasn’t started" }}</button>
        </form>
        <p v-if="lobby.status === 'scheduled'" class="guest-meeting-waiting">This page will update when the organizer starts the meeting.</p>
      </template>

      <template v-else>
        <span class="guest-meeting-icon guest-meeting-icon--error"><LockKeyhole :size="25" aria-hidden="true" /></span>
        <p class="guest-meeting-eyebrow">Link unavailable</p>
        <h1 id="guest-meeting-title">We couldn’t open this meeting</h1>
        <p class="guest-meeting-summary">{{ errorMessage }}</p>
      </template>

      <p v-if="errorMessage && lobby" class="guest-meeting-error" role="alert">{{ errorMessage }}</p>
      <footer><LockKeyhole :size="13" aria-hidden="true" /> This guest session only grants access to this meeting.</footer>
    </section>
  </main>
</template>

<style scoped>
.guest-meeting-page { min-height: 100vh; display: grid; place-items: center; padding: 28px; background: #222730; color: #d8dee9; }
.guest-meeting-card { width: min(100%, 470px); border: 0; border-radius: 18px; padding: 28px; background: #303744; box-shadow: none; }
.guest-meeting-brand { display: flex; align-items: center; gap: 9px; margin-bottom: 34px; color: #c8cfda; font-size: 13px; font-weight: 750; letter-spacing: .02em; }
.guest-meeting-brand img { width: 25px; height: 25px; border-radius: 7px; }
.guest-meeting-icon { width: 48px; height: 48px; display: grid; place-items: center; border-radius: 14px; background: rgb(180 142 173 / 14%); color: #d1a6d3; }
.guest-meeting-icon--error { background: rgb(191 97 106 / 14%); color: #e2a5ad; }
.guest-meeting-eyebrow { margin: 22px 0 7px; color: #c9a5c5; font-size: 12px; font-weight: 800; letter-spacing: .08em; text-transform: uppercase; }
h1 { margin: 0; color: #eef1f5; font-size: clamp(25px, 5vw, 34px); line-height: 1.12; letter-spacing: -.025em; }
.guest-meeting-summary { margin: 11px 0 24px; color: #aeb7c4; line-height: 1.5; }
dl { display: grid; grid-template-columns: 1fr 1fr; gap: 10px; margin: 0 0 25px; }
dl div { padding: 12px; border: 0; border-radius: 10px; background: #2d333e; }
dt { display: flex; align-items: center; gap: 6px; color: #8b95a5; font-size: 11px; font-weight: 700; text-transform: uppercase; }
dd { margin: 6px 0 0; color: #d8dee9; font-size: 13px; font-weight: 650; }
form { display: grid; gap: 9px; }
label { color: #cfd5df; font-size: 12px; font-weight: 700; }
input { width: 100%; box-sizing: border-box; border: 1px solid transparent; border-radius: 10px; padding: 12px 13px; background: #252c36; color: #eef1f5; font: inherit; outline: none; }
input::placeholder { color: #707b8b; }
input:focus { border-color: #b48ead; box-shadow: 0 0 0 3px rgb(180 142 173 / 13%); }
.guest-meeting-primary { min-height: 43px; display: inline-flex; align-items: center; justify-content: center; gap: 8px; border: 0; border-radius: 10px; padding: 0 17px; margin-top: 5px; background: #b48ead; color: #222730; font: inherit; font-size: 13px; font-weight: 800; cursor: pointer; }
.guest-meeting-primary:hover:not(:disabled), .guest-meeting-primary:focus-visible { outline: 0; background: #c69fc0; }
.guest-meeting-primary:disabled { background: #3a4250; color: #7f8999; cursor: default; }
.guest-meeting-waiting, .guest-meeting-error { margin: 13px 0 0; color: #98a2b1; font-size: 12px; line-height: 1.45; }
.guest-meeting-error { color: #e2a5ad; }
.guest-meeting-loading { min-height: 230px; display: grid; place-items: center; align-content: center; gap: 14px; color: #aeb7c4; }
.guest-meeting-loading i { width: 28px; height: 28px; border: 3px solid rgb(180 142 173 / 18%); border-top-color: #d1a6d3; border-radius: 50%; animation: guest-spin .8s linear infinite; }
.guest-meeting-card > footer { display: flex; align-items: center; justify-content: center; gap: 6px; margin-top: 26px; color: #7f8999; font-size: 11px; }
@keyframes guest-spin { to { transform: rotate(360deg); } }
@media (max-width: 560px) { .guest-meeting-page { padding: 14px; } .guest-meeting-card { padding: 22px; } dl { grid-template-columns: 1fr; } }
</style>
