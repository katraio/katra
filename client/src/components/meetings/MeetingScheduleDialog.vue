<script setup lang="ts">
import { ArrowDown, ArrowUp, CalendarClock, Check, ClipboardList, Copy, Link2, MailPlus, Plus, Repeat2, Search, Trash2, UsersRound, X } from "@lucide/vue";
import { computed, onBeforeUnmount, onMounted, ref } from "vue";
import {
  CommunicationRequestError,
  createMeeting,
  getMeetingCandidates,
  type CommunicationMeeting,
} from "../../api/communication";
import KatraSelect, { type KatraSelectOption } from "../ui/KatraSelect.vue";

export type MeetingParticipant = {
  id?: string;
  name: string;
  avatar: string;
  role?: string;
  kind?: "internal" | "client";
  participantId?: string;
  meetingKind?: "user" | "guest";
  admissionSource?: "copied-link" | "email-invitation" | null;
  canRemove?: boolean;
  canBlockReentry?: boolean;
};

const props = defineProps<{
  defaultTitle: string;
  audienceLabel: string;
  participants: MeetingParticipant[];
  organizationId?: string;
}>();

const emit = defineEmits<{
  close: [];
  scheduled: [message: string, meeting?: CommunicationMeeting];
  "meeting-created": [meeting: CommunicationMeeting];
}>();

const title = ref(props.defaultTitle);
const scheduleType = ref<"once" | "recurring">("once");
const tomorrow = new Date(Date.now() + 24 * 60 * 60 * 1000);
const date = ref([
  tomorrow.getFullYear(),
  String(tomorrow.getMonth() + 1).padStart(2, "0"),
  String(tomorrow.getDate()).padStart(2, "0"),
].join("-"));
const time = ref("10:00");
const duration = ref("30");
const repeat = ref("weekly");
const participantKey = (participant: MeetingParticipant) => participant.id ?? participant.name;
const selectedInvitees = ref(props.participants.map(participantKey));
const inviteSearch = ref("");
const guestEmail = ref("");
const guestEmails = ref<string[]>([]);
const copiedMessage = ref("");
const requestError = ref("");
const submitting = ref(false);
const serverParticipants = ref<MeetingParticipant[]>([]);
const createdMeeting = ref<CommunicationMeeting | null>(null);
const agendaGoal = ref("Align on the decision and leave with clear owners and next steps.");
const agendaItems = ref([
  { id: 1, title: "Context and desired outcome", owner: "morgan", duration: "5" },
  { id: 2, title: "Review options and make a decision", owner: "Katra", duration: "20" },
]);
let nextAgendaId = 3;

const durationOptions: KatraSelectOption[] = [
  { value: "15", label: "15 minutes" },
  { value: "30", label: "30 minutes" },
  { value: "45", label: "45 minutes" },
  { value: "60", label: "1 hour" },
];
const repeatOptions: KatraSelectOption[] = [
  { value: "daily", label: "Daily" },
  { value: "weekly", label: "Weekly" },
  { value: "every two weeks", label: "Every two weeks" },
  { value: "monthly", label: "Monthly" },
];
const agendaDurationOptions: KatraSelectOption[] = ["5", "10", "15", "20", "30"].map((value) => ({ value, label: value }));
const candidateAbortController = new AbortController();
const availableParticipants = computed(() => {
  const participants = new Map<string, MeetingParticipant>();
  [...props.participants, ...serverParticipants.value].forEach((participant) => {
    participants.set(participantKey(participant), participant);
  });
  return [...participants.values()];
});
const participantOptions = computed<KatraSelectOption[]>(() => availableParticipants.value.map((participant) => ({ value: participantKey(participant), label: participant.name })));

const filteredParticipants = computed(() => {
  const query = inviteSearch.value.trim().toLowerCase();
  if (!query) return availableParticipants.value;
  return availableParticipants.value.filter((participant) => `${participant.name} ${participant.role ?? ""}`.toLowerCase().includes(query));
});

const invitationSummary = computed(() => {
  const total = selectedInvitees.value.length + guestEmails.value.length;
  if (selectedInvitees.value.length === availableParticipants.value.length && !guestEmails.value.length) return `Everyone in ${props.audienceLabel}`;
  if (!total) return "No invitees selected";
  return `${total} invitee${total === 1 ? "" : "s"}`;
});

function toggleInvitee(key: string) {
  selectedInvitees.value = selectedInvitees.value.includes(key)
    ? selectedInvitees.value.filter((invitee) => invitee !== key)
    : [...selectedInvitees.value, key];
}

function readableError(error: unknown): string {
  if (error instanceof CommunicationRequestError) {
    return Object.values(error.fields).flat()[0] ?? error.message;
  }
  return "Katra Server could not schedule the meeting. Your form remains intact.";
}

async function scheduleMeeting() {
  if (!title.value.trim() || (!selectedInvitees.value.length && !guestEmails.value.length)) return;

  if (props.organizationId) {
    if (scheduleType.value === "recurring") {
      requestError.value = "Recurring meetings remain a later slice. Choose One time to schedule this meeting now.";
      return;
    }

    submitting.value = true;
    requestError.value = "";

    try {
      const participantsByKey = new Map(availableParticipants.value.map((participant) => [participantKey(participant), participant]));
      const meeting = await createMeeting(props.organizationId, {
        title: title.value.trim(),
        starts_at: new Date(`${date.value}T${time.value}:00`).toISOString(),
        duration_minutes: Number(duration.value),
        desired_outcome: agendaGoal.value.trim(),
        participant_ids: selectedInvitees.value
          .map((key) => participantsByKey.get(key)?.id)
          .filter((id): id is string => Boolean(id)),
        guest_emails: guestEmails.value,
        agenda_items: agendaItems.value.map((item) => ({
          title: item.title.trim(),
          owner_user_id: participantsByKey.get(item.owner)?.id ?? null,
          duration_minutes: Number(item.duration),
        })),
      });
      createdMeeting.value = meeting;
      emit("meeting-created", meeting);
      emit("scheduled", `${meeting.title} scheduled successfully.`, meeting);
      return;
    } catch (error) {
      requestError.value = readableError(error);
      return;
    } finally {
      submitting.value = false;
    }
  }

  const cadence = scheduleType.value === "recurring" ? `, repeating ${repeat.value}` : "";
  const emailNote = guestEmails.value.length ? ` Email invitations will be sent to ${guestEmails.value.length} external guest${guestEmails.value.length === 1 ? "" : "s"}.` : "";
  const agendaNote = agendaItems.value.length ? ` ${agendaItems.value.length} agenda item${agendaItems.value.length === 1 ? "" : "s"} included.` : "";
  emit("scheduled", `${title.value.trim()} scheduled for ${date.value} at ${time.value}${cadence}.${agendaNote}${emailNote}`);
}

function addAgendaItem() {
  agendaItems.value = [
    ...agendaItems.value,
    { id: nextAgendaId++, title: "", owner: selectedInvitees.value[0] ?? "morgan", duration: "5" },
  ];
}

function removeAgendaItem(id: number) {
  agendaItems.value = agendaItems.value.filter((item) => item.id !== id);
}

function moveAgendaItem(index: number, direction: -1 | 1) {
  const nextIndex = index + direction;
  if (nextIndex < 0 || nextIndex >= agendaItems.value.length) return;
  const reordered = [...agendaItems.value];
  const [item] = reordered.splice(index, 1);
  reordered.splice(nextIndex, 0, item);
  agendaItems.value = reordered;
}

function addGuestEmail() {
  const email = guestEmail.value.trim().toLowerCase();
  if (!email.includes("@") || guestEmails.value.includes(email)) return;
  guestEmails.value = [...guestEmails.value, email];
  guestEmail.value = "";
}

async function copyInvitation(kind: "link" | "details") {
  const meetingLink = createdMeeting.value?.guest_link_url ?? "https://meet.katra.local/architecture-review";

  if (props.organizationId && !createdMeeting.value) {
    copiedMessage.value = "Schedule this meeting to create its secure guest link.";
    return;
  }
  const cadence = scheduleType.value === "recurring" ? `Repeats: ${repeat.value}\n` : "";
  const agenda = agendaItems.value.length
    ? `\nAgenda\n${agendaItems.value.map((item, index) => `${index + 1}. ${item.title || "Untitled item"} · ${item.duration} min · ${item.owner}`).join("\n")}\nOutcome: ${agendaGoal.value.trim()}`
    : "";
  const details = `${title.value.trim()}\n${date.value} at ${time.value} · ${duration.value} minutes\n${cadence}${meetingLink}${agenda}`;
  await navigator.clipboard?.writeText(kind === "link" ? meetingLink : details).catch(() => undefined);
  copiedMessage.value = kind === "link" ? "Meeting link copied." : "Meeting details copied.";
}

function avatarFor(name: string): string {
  const key = name.toLowerCase().split(/\s+/)[0];
  return ["artisan", "atlas", "envoy", "katra", "sentinel", "vector"].includes(key)
    ? `/avatars/${key}.png`
    : "/brand/icon.svg";
}

async function loadParticipants() {
  if (!props.organizationId) return;

  try {
    const candidates = await getMeetingCandidates(props.organizationId, "", candidateAbortController.signal);
    serverParticipants.value = candidates.map((candidate) => ({
      id: candidate.id,
      name: candidate.name,
      avatar: avatarFor(candidate.name),
      role: candidate.kind === "client" ? "Client participant" : "Organization member",
      kind: candidate.kind,
    }));
    const participantByName = new Map(
      availableParticipants.value.map((participant) => [participant.name.toLowerCase(), participantKey(participant)]),
    );
    const fallbackOwner = selectedInvitees.value[0] ?? "";
    agendaItems.value = agendaItems.value.map((item) => ({
      ...item,
      owner: availableParticipants.value.some((participant) => participantKey(participant) === item.owner)
        ? item.owner
        : participantByName.get(item.owner.toLowerCase()) ?? fallbackOwner,
    }));
  } catch (error) {
    if (error instanceof DOMException && error.name === "AbortError") return;
    requestError.value = readableError(error);
  }
}

onMounted(() => void loadParticipants());
onBeforeUnmount(() => candidateAbortController.abort());
</script>

<template>
  <div class="meeting-scheduler-backdrop" @click.self="emit('close')">
    <section class="meeting-scheduler" role="dialog" aria-modal="true" aria-labelledby="meeting-scheduler-title">
      <header>
        <span><CalendarClock :size="18" aria-hidden="true" /></span>
        <div><h2 id="meeting-scheduler-title">Schedule a meeting</h2><p>{{ audienceLabel }}</p></div>
        <button type="button" aria-label="Close meeting scheduler" @click="emit('close')"><X :size="17" aria-hidden="true" /></button>
      </header>

      <form @submit.prevent="scheduleMeeting">
        <label class="meeting-scheduler-field"><span>Meeting name</span><input v-model="title" type="text" /></label>

        <fieldset class="meeting-schedule-type">
          <legend>Meeting type</legend>
          <button type="button" :class="{ 'is-active': scheduleType === 'once' }" :aria-pressed="scheduleType === 'once'" @click="scheduleType = 'once'"><CalendarClock :size="15" aria-hidden="true" /><span><strong>One time</strong><small>Meet once at the selected time</small></span></button>
          <button type="button" :class="{ 'is-active': scheduleType === 'recurring' }" :aria-pressed="scheduleType === 'recurring'" @click="scheduleType = 'recurring'"><Repeat2 :size="15" aria-hidden="true" /><span><strong>Recurring</strong><small>Repeat on a regular cadence</small></span></button>
        </fieldset>

        <div class="meeting-schedule-grid">
          <label><span>Date</span><input v-model="date" type="date" /></label>
          <label><span>Time</span><input v-model="time" type="time" /></label>
          <label><span>Duration</span><KatraSelect v-model="duration" :options="durationOptions" label="Meeting duration" /></label>
          <label v-if="scheduleType === 'recurring'"><span>Repeats</span><KatraSelect v-model="repeat" :options="repeatOptions" label="Meeting repeat cadence" /></label>
        </div>

        <fieldset class="meeting-scheduler-agenda">
          <legend><span><ClipboardList :size="15" aria-hidden="true" /> Agenda</span><small>{{ agendaItems.length }} item{{ agendaItems.length === 1 ? '' : 's' }}</small></legend>
          <label class="meeting-agenda-goal"><span>Desired outcome</span><textarea v-model="agendaGoal" rows="2" placeholder="What should be true when this meeting ends?"></textarea></label>
          <div class="meeting-agenda-items">
            <article v-for="(item, index) in agendaItems" :key="item.id" class="meeting-agenda-item">
              <span class="meeting-agenda-number">{{ index + 1 }}</span>
              <label class="meeting-agenda-topic"><span>Topic</span><input v-model="item.title" type="text" placeholder="Agenda item" /></label>
              <label><span>Owner</span><KatraSelect v-model="item.owner" :options="participantOptions" :label="`Owner for ${item.title || 'agenda item'}`" compact /></label>
              <label><span>Minutes</span><KatraSelect v-model="item.duration" :options="agendaDurationOptions" :label="`Minutes for ${item.title || 'agenda item'}`" compact /></label>
              <div class="meeting-agenda-actions">
                <button type="button" :disabled="index === 0" :aria-label="`Move ${item.title || 'agenda item'} up`" @click="moveAgendaItem(index, -1)"><ArrowUp :size="13" aria-hidden="true" /></button>
                <button type="button" :disabled="index === agendaItems.length - 1" :aria-label="`Move ${item.title || 'agenda item'} down`" @click="moveAgendaItem(index, 1)"><ArrowDown :size="13" aria-hidden="true" /></button>
                <button type="button" :aria-label="`Remove ${item.title || 'agenda item'}`" @click="removeAgendaItem(item.id)"><Trash2 :size="13" aria-hidden="true" /></button>
              </div>
            </article>
          </div>
          <button type="button" class="meeting-agenda-add" @click="addAgendaItem"><Plus :size="14" aria-hidden="true" /> Add agenda item</button>
        </fieldset>

        <fieldset class="meeting-scheduler-invites">
          <legend><span><UsersRound :size="15" aria-hidden="true" /> Invite people</span><small>{{ invitationSummary }}</small></legend>
          <label class="meeting-scheduler-search"><Search :size="14" aria-hidden="true" /><input v-model="inviteSearch" type="search" placeholder="Search people…" aria-label="Search meeting invitees" /></label>
          <label v-for="participant in filteredParticipants" :key="participantKey(participant)">
            <input type="checkbox" :checked="selectedInvitees.includes(participantKey(participant))" @change="toggleInvitee(participantKey(participant))" />
            <img :src="participant.avatar" alt="" />
            <span><strong>{{ participant.name }}</strong><small>{{ participant.role ?? 'Room participant' }}</small></span>
          </label>
          <p v-if="!filteredParticipants.length" class="meeting-scheduler-empty">No people match that search.</p>
        </fieldset>

        <section class="meeting-invitation-delivery" aria-label="Invitation delivery">
          <header><div><MailPlus :size="15" aria-hidden="true" /><span><strong>Invite by email</strong><small>Add guests or customers who are not in Katra</small></span></div></header>
          <div class="meeting-guest-email"><input v-model="guestEmail" type="email" placeholder="guest@example.com" aria-label="Guest email address" @keydown.enter.prevent="addGuestEmail" /><button type="button" :disabled="!guestEmail.includes('@')" @click="addGuestEmail">Add guest</button></div>
          <ul v-if="guestEmails.length" class="meeting-guest-emails"><li v-for="email in guestEmails" :key="email"><span>{{ email }}</span><button type="button" :aria-label="`Remove ${email}`" @click="guestEmails = guestEmails.filter((item) => item !== email)"><X :size="12" aria-hidden="true" /></button></li></ul>
          <div><button type="button" @click="copyInvitation('link')"><Link2 :size="14" aria-hidden="true" /> Copy meeting link</button><button type="button" @click="copyInvitation('details')"><Copy :size="14" aria-hidden="true" /> Copy details</button></div>
          <p v-if="copiedMessage" role="status">{{ copiedMessage }}</p>
        </section>

        <p v-if="requestError" class="meeting-scheduler-error" role="alert">{{ requestError }}</p>
        <footer>
          <button type="button" @click="emit('close')">Cancel</button>
          <button type="submit" :disabled="submitting || !title.trim() || (!selectedInvitees.length && !guestEmails.length)"><Check :size="15" aria-hidden="true" /> {{ submitting ? "Scheduling…" : "Schedule meeting" }}</button>
        </footer>
      </form>
    </section>
  </div>
</template>

<style scoped>
.meeting-scheduler-backdrop { position: absolute; z-index: 120; inset: 0; display: grid; place-items: center; padding: 28px; background: rgb(11 14 19 / 62%); backdrop-filter: blur(3px); }
.meeting-scheduler { display: grid; width: min(600px, 100%); max-height: min(760px, calc(100% - 18px)); grid-template-rows: auto minmax(0, 1fr); overflow: hidden; border-radius: 14px; background: #303743; color: #dfe4ea; box-shadow: 0 28px 72px rgb(3 5 8 / 48%); }
.meeting-scheduler > header { display: grid; grid-template-columns: 38px minmax(0, 1fr) 34px; align-items: center; gap: 11px; padding: 17px 18px 14px; background: #353d49; }
.meeting-scheduler > header > span { display: grid; width: 38px; height: 38px; place-items: center; border-radius: 10px; background: #514651; color: #dcbfd9; }
.meeting-scheduler h2, .meeting-scheduler p { margin: 0; }
.meeting-scheduler h2 { color: #f0f2f6; font-size: 14px; }
.meeting-scheduler p { margin-top: 3px; color: #8e99a7; font-size: 9px; }
.meeting-scheduler > header button { display: grid; width: 34px; height: 34px; place-items: center; border-radius: 8px; background: #414a57; color: #aab4c0; cursor: pointer; }
.meeting-scheduler > form { display: grid; min-height: 0; gap: 17px; overflow-y: auto; padding: 18px; }
.meeting-scheduler-field, .meeting-schedule-grid label { display: grid; gap: 7px; color: #aab4c0; font-size: 9px; font-weight: 650; }
.meeting-scheduler input[type="text"], .meeting-scheduler input[type="date"], .meeting-scheduler input[type="time"] { width: 100%; height: 38px; padding: 0 11px; border: 0; border-radius: 8px; outline: 0; background: #252c35; color: #e2e6ec; font: inherit; }
.meeting-schedule-type, .meeting-scheduler-agenda, .meeting-scheduler-invites { display: grid; gap: 7px; margin: 0; padding: 0; border: 0; }
.meeting-schedule-type { grid-template-columns: repeat(2, minmax(0, 1fr)); }
.meeting-schedule-type legend, .meeting-scheduler-agenda legend, .meeting-scheduler-invites legend { width: 100%; margin-bottom: 8px; color: #aab4c0; font-size: 9px; font-weight: 650; }
.meeting-schedule-type button { display: grid; min-height: 62px; grid-template-columns: 24px minmax(0, 1fr); align-items: center; gap: 7px; padding: 9px 11px; border-radius: 9px; background: #292f39; color: #929dab; text-align: left; cursor: pointer; }
.meeting-schedule-type button.is-active { background: #514651; color: #e5c9e2; box-shadow: inset 0 0 0 1px rgb(214 177 210 / 24%); }
.meeting-schedule-type button span, .meeting-scheduler-invites label > span { display: grid; gap: 3px; }
.meeting-schedule-type strong, .meeting-scheduler-invites strong { color: #e4e8ee; font-size: 9px; }
.meeting-schedule-type small, .meeting-scheduler-invites small { color: #8792a0; font-size: 8px; }
.meeting-schedule-grid { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 10px; }
.meeting-scheduler-agenda legend, .meeting-scheduler-invites legend { display: flex; align-items: center; justify-content: space-between; gap: 12px; }
.meeting-scheduler-agenda legend span, .meeting-scheduler-invites legend span { display: inline-flex; align-items: center; gap: 6px; }
.meeting-scheduler-agenda legend small { color: #8792a0; font-size: 8px; font-weight: 500; }
.meeting-agenda-goal { display: grid; gap: 6px; color: #aab4c0; font-size: 8px; font-weight: 650; }
.meeting-agenda-goal textarea { width: 100%; min-height: 48px; resize: vertical; padding: 9px 10px; border: 0; border-radius: 8px; outline: 0; background: #252c35; color: #e2e6ec; font: inherit; line-height: 1.45; }
.meeting-agenda-items { display: grid; gap: 6px; }
.meeting-agenda-item { display: grid; grid-template-columns: 22px minmax(150px, 1.6fr) minmax(90px, 1fr) 64px auto; align-items: end; gap: 7px; padding: 8px; border-radius: 9px; background: #292f39; }
.meeting-agenda-item label { display: grid; gap: 5px; color: #8792a0; font-size: 7px; font-weight: 650; }
.meeting-agenda-item input { width: 100%; height: 30px; min-width: 0; padding: 0 8px; border: 0; border-radius: 7px; outline: 0; background: #222933; color: #dfe4ea; font-size: 8px; }
.meeting-agenda-number { display: grid; width: 22px; height: 30px; place-items: center; border-radius: 7px; background: #514651; color: #e3c8e0; font-size: 8px; font-weight: 750; }
.meeting-agenda-actions { display: flex; gap: 3px; }
.meeting-agenda-actions button { display: grid; width: 28px; height: 30px; place-items: center; border-radius: 7px; background: #414a57; color: #aeb7c2; cursor: pointer; }
.meeting-agenda-actions button:last-child { color: #d8a3ad; }
.meeting-agenda-actions button:disabled { opacity: .35; cursor: not-allowed; }
.meeting-agenda-add { display: inline-flex; width: max-content; height: 31px; align-items: center; gap: 6px; padding: 0 10px; border-radius: 7px; background: #414a57; color: #cfb1cb; font-size: 8px; font-weight: 700; cursor: pointer; }
.meeting-scheduler-invites legend { display: flex; align-items: center; justify-content: space-between; gap: 12px; }
.meeting-scheduler-invites legend span { display: inline-flex; align-items: center; gap: 6px; }
.meeting-scheduler-invites label { display: grid; grid-template-columns: 16px 32px minmax(0, 1fr); align-items: center; gap: 9px; padding: 8px 9px; border-radius: 8px; background: #292f39; cursor: pointer; }
.meeting-scheduler-invites label.meeting-scheduler-search { grid-template-columns: 18px minmax(0, 1fr); background: #252c35; cursor: text; }
.meeting-scheduler-search > svg { color: #8792a0; }
.meeting-scheduler-search input[type="search"] { width: 100%; min-width: 0; height: 30px; padding: 0; border: 0; outline: 0; background: transparent; color: #dfe4ea; font-size: 9px; }
.meeting-scheduler-empty { margin: 0; padding: 10px; border-radius: 8px; background: #292f39; color: #8792a0; font-size: 8px; text-align: center; }
.meeting-scheduler-invites input[type="checkbox"] { width: 15px; height: 15px; margin: 0; accent-color: #c49ac0; }
.meeting-scheduler-invites img { width: 32px; height: 32px; border-radius: 50%; object-fit: cover; }
.meeting-invitation-delivery { display: grid; gap: 9px; padding: 11px; border-radius: 9px; background: #292f39; }
.meeting-invitation-delivery > header { display: flex; align-items: center; justify-content: space-between; gap: 12px; }
.meeting-invitation-delivery > header > div { display: flex; align-items: center; gap: 8px; color: #cbaac8; }
.meeting-invitation-delivery > header span { display: grid; gap: 3px; }
.meeting-invitation-delivery strong { color: #e4e8ee; font-size: 9px; }
.meeting-invitation-delivery small { color: #8792a0; font-size: 8px; }
.meeting-guest-email { display: grid; grid-template-columns: minmax(0, 1fr) auto; gap: 6px; }
.meeting-guest-email input { min-width: 0; height: 32px; padding: 0 9px; border: 0; border-radius: 7px; outline: 0; background: #222933; color: #dfe4ea; font-size: 8px; }
.meeting-invitation-delivery .meeting-guest-email button { height: 32px; }
.meeting-guest-emails { display: flex; flex-wrap: wrap; gap: 5px; margin: 0; padding: 0; list-style: none; }
.meeting-guest-emails li { display: inline-flex; align-items: center; gap: 5px; padding: 5px 6px 5px 8px; border-radius: 999px; background: #514651; color: #e0c4dd; font-size: 7px; }
.meeting-invitation-delivery .meeting-guest-emails button { width: 20px; height: 20px; padding: 0; justify-content: center; border-radius: 50%; }
.meeting-invitation-delivery > div { display: flex; flex-wrap: wrap; gap: 6px; }
.meeting-invitation-delivery button { display: inline-flex; height: 31px; align-items: center; gap: 6px; padding: 0 9px; border-radius: 7px; background: #414a57; color: #c9d0d9; font-size: 8px; cursor: pointer; }
.meeting-invitation-delivery > p { margin: 0; color: #a7d7b7; font-size: 8px; }
.meeting-scheduler-error { margin: 0; padding: 9px 10px; border-radius: 8px; background: rgb(191 97 106 / 16%); color: #e6b2b8; font-size: 9px; line-height: 1.45; }
.meeting-scheduler form > footer { display: flex; justify-content: flex-end; gap: 7px; padding-top: 2px; }
.meeting-scheduler form > footer button { display: inline-flex; min-width: 76px; height: 36px; align-items: center; justify-content: center; gap: 6px; padding: 0 12px; border-radius: 8px; background: #414a57; color: #c9d0d9; font-size: 9px; cursor: pointer; }
.meeting-scheduler form > footer button:last-child { background: #6b5669; color: #f0dbed; font-weight: 700; }
.meeting-scheduler form > footer button:disabled { opacity: .45; cursor: not-allowed; }
@media (max-width: 640px) { .meeting-scheduler-backdrop { padding: 10px; } .meeting-schedule-type, .meeting-schedule-grid { grid-template-columns: 1fr; } .meeting-agenda-item { grid-template-columns: 22px minmax(0, 1fr); } .meeting-agenda-topic { grid-column: 2; } .meeting-agenda-actions { grid-column: 2; } }
</style>
