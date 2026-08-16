<script setup lang="ts">
import {
  Crown,
  LoaderCircle,
  LogOut,
  Search,
  ShieldCheck,
  ShieldOff,
  Trash2,
  UserPlus,
  UsersRound,
  X,
} from "@lucide/vue";
import { computed, nextTick, onBeforeUnmount, onMounted, ref, watch } from "vue";
import {
  CommunicationRequestError,
  addChannelMember,
  demoteChannelOwner,
  getChannelMemberCandidates,
  getChannelMembers,
  getChannel,
  leaveChannel,
  promoteChannelOwner,
  removeChannelMember,
  type ChannelMember,
  type CommunicationChannel,
  type MentionableUser,
} from "../../api/communication";

const props = defineProps<{
  channel: CommunicationChannel;
}>();

const emit = defineEmits<{
  close: [];
  left: [];
  "channel-updated": [channel: CommunicationChannel];
}>();

const members = ref<ChannelMember[]>([]);
const candidates = ref<MentionableUser[]>([]);
const loading = ref(true);
const candidatesLoading = ref(false);
const error = ref("");
const searchQuery = ref("");
const activeCandidateIndex = ref(0);
const pendingUserId = ref<string | null>(null);
const confirmingRemovalId = ref<string | null>(null);
const confirmingLeave = ref(false);
const leaving = ref(false);
const searchInput = ref<HTMLInputElement | null>(null);
let membersAbortController: AbortController | null = null;
let candidatesAbortController: AbortController | null = null;
let searchTimer: number | null = null;

const ownerCount = computed(() => members.value.filter((member) => member.role === "owner").length);
const currentMember = computed(() => members.value.find((member) => member.is_current_user) ?? null);
const finalOwner = computed(() => currentMember.value?.role === "owner" && ownerCount.value === 1);

function initials(name: string): string {
  return name
    .split(/\s+/)
    .filter(Boolean)
    .slice(0, 2)
    .map((part) => part[0]?.toUpperCase() ?? "")
    .join("");
}

function errorMessage(caught: unknown): string {
  return caught instanceof CommunicationRequestError
    ? caught.message
    : "Katra Server could not update this Channel. Please try again.";
}

async function loadMembers(): Promise<void> {
  membersAbortController?.abort();
  membersAbortController = new AbortController();
  loading.value = true;
  error.value = "";

  try {
    members.value = await getChannelMembers(props.channel.id, membersAbortController.signal);
  } catch (caught) {
    if (!(caught instanceof DOMException && caught.name === "AbortError")) {
      error.value = errorMessage(caught);
    }
  } finally {
    loading.value = false;
  }
}

async function loadCandidates(): Promise<void> {
  if (!props.channel.permissions.can_manage_members) return;

  candidatesAbortController?.abort();
  candidatesAbortController = new AbortController();
  candidatesLoading.value = true;

  try {
    candidates.value = await getChannelMemberCandidates(
      props.channel.id,
      searchQuery.value,
      candidatesAbortController.signal,
    );
    activeCandidateIndex.value = 0;
  } catch (caught) {
    if (!(caught instanceof DOMException && caught.name === "AbortError")) {
      error.value = errorMessage(caught);
    }
  } finally {
    candidatesLoading.value = false;
  }
}

async function addMember(candidate: MentionableUser): Promise<void> {
  pendingUserId.value = candidate.id;
  error.value = "";

  try {
    await addChannelMember(props.channel.id, candidate.id);
    searchQuery.value = "";
    await Promise.all([loadMembers(), loadCandidates()]);
    await nextTick();
    searchInput.value?.focus();
  } catch (caught) {
    error.value = errorMessage(caught);
  } finally {
    pendingUserId.value = null;
  }
}

async function promote(member: ChannelMember): Promise<void> {
  pendingUserId.value = member.id;
  error.value = "";

  try {
    const updated = await promoteChannelOwner(props.channel.id, member.id);
    members.value = members.value.map((candidate) => candidate.id === updated.id ? updated : candidate);
  } catch (caught) {
    error.value = errorMessage(caught);
  } finally {
    pendingUserId.value = null;
  }
}

async function demote(member: ChannelMember): Promise<void> {
  pendingUserId.value = member.id;
  error.value = "";

  try {
    const updated = await demoteChannelOwner(props.channel.id, member.id);
    members.value = members.value.map((candidate) => candidate.id === updated.id ? updated : candidate);
    if (member.is_current_user) emit("channel-updated", await getChannel(props.channel.id));
  } catch (caught) {
    error.value = errorMessage(caught);
  } finally {
    pendingUserId.value = null;
  }
}

async function remove(member: ChannelMember): Promise<void> {
  if (confirmingRemovalId.value !== member.id) {
    confirmingRemovalId.value = member.id;
    return;
  }

  pendingUserId.value = member.id;
  error.value = "";

  try {
    await removeChannelMember(props.channel.id, member.id);
    members.value = members.value.filter((candidate) => candidate.id !== member.id);
    confirmingRemovalId.value = null;
    await loadCandidates();
  } catch (caught) {
    error.value = errorMessage(caught);
  } finally {
    pendingUserId.value = null;
  }
}

async function leave(): Promise<void> {
  if (finalOwner.value) return;

  if (!confirmingLeave.value) {
    confirmingLeave.value = true;
    return;
  }

  leaving.value = true;
  error.value = "";

  try {
    await leaveChannel(props.channel.id);
    emit("left");
  } catch (caught) {
    error.value = errorMessage(caught);
    confirmingLeave.value = false;
  } finally {
    leaving.value = false;
  }
}

function moveCandidate(delta: number): void {
  if (candidates.value.length === 0) return;
  activeCandidateIndex.value = (
    activeCandidateIndex.value + delta + candidates.value.length
  ) % candidates.value.length;
}

function selectActiveCandidate(): void {
  const candidate = candidates.value[activeCandidateIndex.value];
  if (candidate) void addMember(candidate);
}

function handleEscape(): void {
  if (confirmingLeave.value) {
    confirmingLeave.value = false;
    return;
  }

  if (confirmingRemovalId.value) {
    confirmingRemovalId.value = null;
    return;
  }

  emit("close");
}

watch(searchQuery, () => {
  if (searchTimer !== null) window.clearTimeout(searchTimer);
  searchTimer = window.setTimeout(() => void loadCandidates(), 180);
});

onMounted(() => {
  void loadMembers();
  void loadCandidates();
  void nextTick(() => searchInput.value?.focus());
});

onBeforeUnmount(() => {
  membersAbortController?.abort();
  candidatesAbortController?.abort();
  if (searchTimer !== null) window.clearTimeout(searchTimer);
});
</script>

<template>
  <Teleport to="body">
    <div class="channel-members-overlay" @mousedown.self="$emit('close')" @keydown.esc="handleEscape">
      <section
        class="channel-members-dialog"
        role="dialog"
        aria-modal="true"
        :aria-labelledby="`channel-members-title-${channel.id}`"
      >
        <header>
          <span class="channel-members-heading-icon" aria-hidden="true">
            <UsersRound :size="19" :stroke-width="1.8" />
          </span>
          <div>
            <h2 :id="`channel-members-title-${channel.id}`">{{ channel.name }} members</h2>
            <p>Private Channel access and ownership</p>
          </div>
          <button type="button" aria-label="Close Channel members" @click="$emit('close')">
            <X :size="18" aria-hidden="true" />
          </button>
        </header>

        <p v-if="error" class="channel-members-error" role="alert">{{ error }}</p>

        <section v-if="channel.permissions.can_manage_members" class="channel-member-add" aria-label="Add a member">
          <label :for="`channel-member-search-${channel.id}`">Add someone</label>
          <div class="channel-member-search">
            <Search :size="15" aria-hidden="true" />
            <input
              :id="`channel-member-search-${channel.id}`"
              ref="searchInput"
              v-model="searchQuery"
              type="search"
              autocomplete="off"
              placeholder="Search internal people"
              :aria-controls="`channel-member-candidates-${channel.id}`"
              :aria-activedescendant="candidates[activeCandidateIndex] ? `channel-candidate-${candidates[activeCandidateIndex].id}` : undefined"
              @keydown.down.prevent="moveCandidate(1)"
              @keydown.up.prevent="moveCandidate(-1)"
              @keydown.enter.prevent="selectActiveCandidate"
            />
            <LoaderCircle v-if="candidatesLoading" class="channel-members-spinner" :size="15" aria-label="Searching" />
          </div>
          <div :id="`channel-member-candidates-${channel.id}`" class="channel-member-candidates" role="listbox">
            <button
              v-for="(candidate, index) in candidates"
              :id="`channel-candidate-${candidate.id}`"
              :key="candidate.id"
              type="button"
              role="option"
              :aria-selected="index === activeCandidateIndex"
              :class="{ 'is-active': index === activeCandidateIndex }"
              :disabled="pendingUserId !== null"
              @mouseenter="activeCandidateIndex = index"
              @click="addMember(candidate)"
            >
              <span>{{ initials(candidate.name) }}</span>
              <strong>{{ candidate.name }}</strong>
              <UserPlus :size="15" aria-hidden="true" />
            </button>
            <p v-if="!candidatesLoading && candidates.length === 0">
              {{ searchQuery ? "No matching internal people." : "Everyone available is already here." }}
            </p>
          </div>
        </section>

        <section class="channel-member-roster" aria-label="Current members">
          <div class="channel-member-roster-heading">
            <h3>Current members</h3>
            <span>{{ members.length }}</span>
          </div>

          <div v-if="loading" class="channel-members-state">
            <LoaderCircle class="channel-members-spinner" :size="18" aria-hidden="true" />
            <span>Loading members…</span>
          </div>

          <div v-else class="channel-member-list">
            <article v-for="member in members" :key="member.id">
              <span class="channel-member-avatar">{{ initials(member.name) }}</span>
              <div>
                <strong>{{ member.name }}</strong>
                <span v-if="member.is_current_user">You</span>
              </div>
              <span v-if="member.role === 'owner'" class="channel-owner-label">
                <Crown :size="13" aria-hidden="true" /> Owner
              </span>
              <div v-if="channel.permissions.can_manage_members" class="channel-member-actions">
                <button
                  v-if="member.role === 'member' && !member.is_current_user"
                  type="button"
                  :disabled="pendingUserId !== null"
                  title="Make owner"
                  :aria-label="`Make ${member.name} an owner`"
                  @click="promote(member)"
                >
                  <ShieldCheck :size="15" aria-hidden="true" />
                </button>
                <button
                  v-if="member.role === 'owner' && ownerCount > 1"
                  type="button"
                  :disabled="pendingUserId !== null"
                  title="Remove owner status"
                  :aria-label="`Remove ${member.name}'s owner status`"
                  @click="demote(member)"
                >
                  <ShieldOff :size="15" aria-hidden="true" />
                </button>
                <button
                  v-if="!member.is_current_user && (member.role !== 'owner' || ownerCount > 1)"
                  type="button"
                  class="channel-member-remove"
                  :class="{ 'is-confirming': confirmingRemovalId === member.id }"
                  :disabled="pendingUserId !== null"
                  :aria-label="confirmingRemovalId === member.id ? `Confirm removing ${member.name}` : `Remove ${member.name}`"
                  @click="remove(member)"
                >
                  <Trash2 :size="15" aria-hidden="true" />
                  <span v-if="confirmingRemovalId === member.id">Remove?</span>
                </button>
              </div>
            </article>
          </div>
        </section>

        <footer v-if="currentMember" class="channel-members-footer">
          <p v-if="finalOwner">Assign another owner before leaving this Channel.</p>
          <p v-else>Leaving removes this private Channel from your workspace.</p>
          <button
            type="button"
            :class="{ 'is-confirming': confirmingLeave }"
            :disabled="finalOwner || leaving"
            @click="leave"
          >
            <LoaderCircle v-if="leaving" class="channel-members-spinner" :size="15" aria-hidden="true" />
            <LogOut v-else :size="15" aria-hidden="true" />
            {{ confirmingLeave ? "Confirm leave" : "Leave Channel" }}
          </button>
        </footer>
      </section>
    </div>
  </Teleport>
</template>

<style scoped>
.channel-members-overlay { position: fixed; z-index: 100; inset: 0; display: grid; place-items: center; padding: 24px; background: rgb(10 14 20 / 66%); backdrop-filter: blur(5px); }
.channel-members-dialog { display: flex; width: min(540px, 100%); max-height: min(760px, calc(100vh - 48px)); flex-direction: column; overflow: hidden; border-radius: 14px; background: #303947; color: #d8dee9; box-shadow: 0 22px 70px rgb(5 8 13 / 48%); }
.channel-members-dialog > header { display: grid; min-height: 72px; grid-template-columns: 38px minmax(0, 1fr) 34px; align-items: center; gap: 11px; padding: 0 18px; border-bottom: 1px solid rgb(216 222 233 / 7%); }
.channel-members-heading-icon { display: grid; width: 38px; height: 38px; place-items: center; border-radius: 10px; background: #252d38; color: #c39bbc; }
.channel-members-dialog h2, .channel-member-roster h3 { margin: 0; color: #eef1f6; }
.channel-members-dialog h2 { font-size: 16px; }
.channel-members-dialog header p { margin: 3px 0 0; color: #8590a0; font-size: 11px; }
.channel-members-dialog header > button { display: grid; width: 34px; height: 34px; place-items: center; border: 0; border-radius: 8px; background: transparent; color: #9ca6b5; cursor: pointer; }
.channel-members-dialog header > button:hover, .channel-members-dialog header > button:focus-visible { outline: 0; background: rgb(216 222 233 / 7%); color: #eef1f6; }
.channel-members-error { margin: 12px 18px 0; padding: 9px 11px; border-radius: 8px; background: rgb(191 97 106 / 16%); color: #e6b2b8; font-size: 11px; }
.channel-member-add { padding: 16px 18px 5px; }
.channel-member-add > label { display: block; margin-bottom: 7px; color: #cfd5df; font-size: 11px; font-weight: 700; }
.channel-member-search { position: relative; display: flex; height: 40px; align-items: center; gap: 9px; padding: 0 11px; border-radius: 9px; background: #252d38; color: #7e8998; box-shadow: inset 0 0 0 1px rgb(216 222 233 / 8%); }
.channel-member-search:focus-within { color: #c7a2c1; box-shadow: inset 0 0 0 1px rgb(180 142 173 / 62%); }
.channel-member-search input { min-width: 0; flex: 1; border: 0; outline: 0; background: transparent; color: #edf0f4; font: inherit; font-size: 12px; }
.channel-member-search input::placeholder { color: #707b8b; }
.channel-members-spinner { animation: channel-member-spin 1s linear infinite; }
.channel-member-candidates { max-height: 156px; overflow-y: auto; margin-top: 6px; }
.channel-member-candidates button { display: grid; width: 100%; min-height: 42px; grid-template-columns: 30px minmax(0, 1fr) 20px; align-items: center; gap: 9px; padding: 5px 8px; border: 0; border-radius: 8px; background: transparent; color: #dce2eb; font: inherit; text-align: left; cursor: pointer; }
.channel-member-candidates button:hover, .channel-member-candidates button:focus-visible, .channel-member-candidates button.is-active { outline: 0; background: rgb(180 142 173 / 14%); }
.channel-member-candidates button > span, .channel-member-avatar { display: grid; place-items: center; border-radius: 50%; background: #414b5b; color: #e6d2e2; font-size: 9px; font-weight: 750; }
.channel-member-candidates button > span { width: 30px; height: 30px; }
.channel-member-candidates button strong { overflow: hidden; font-size: 12px; text-overflow: ellipsis; white-space: nowrap; }
.channel-member-candidates button > svg { color: #b48ead; }
.channel-member-candidates > p { margin: 0; padding: 12px 8px; color: #7f8a99; font-size: 11px; text-align: center; }
.channel-member-roster { min-height: 0; padding: 12px 18px 18px; }
.channel-member-roster-heading { display: flex; align-items: center; justify-content: space-between; margin-bottom: 7px; }
.channel-member-roster h3 { font-size: 11px; }
.channel-member-roster-heading > span { display: grid; min-width: 22px; height: 20px; place-items: center; border-radius: 999px; background: #252d38; color: #aab4c2; font-size: 10px; }
.channel-members-state { display: flex; min-height: 120px; align-items: center; justify-content: center; gap: 8px; color: #8994a4; font-size: 11px; }
.channel-member-list { max-height: 330px; overflow-y: auto; }
.channel-member-list article { display: grid; min-height: 48px; grid-template-columns: 34px minmax(0, 1fr) auto auto; align-items: center; gap: 9px; padding: 5px 7px; border-radius: 9px; }
.channel-member-list article:hover { background: rgb(216 222 233 / 4%); }
.channel-member-avatar { width: 34px; height: 34px; }
.channel-member-list article > div:nth-child(2) { display: flex; min-width: 0; align-items: baseline; gap: 6px; }
.channel-member-list article strong { overflow: hidden; color: #e5e9ef; font-size: 12px; text-overflow: ellipsis; white-space: nowrap; }
.channel-member-list article > div:nth-child(2) span { color: #b48ead; font-size: 9px; font-weight: 700; text-transform: uppercase; }
.channel-owner-label { display: inline-flex; align-items: center; gap: 4px; color: #d5b774; font-size: 10px; font-weight: 700; }
.channel-member-actions { display: flex; align-items: center; gap: 4px; }
.channel-member-actions button { display: inline-flex; min-width: 30px; height: 30px; align-items: center; justify-content: center; gap: 5px; padding: 0 7px; border: 0; border-radius: 7px; background: transparent; color: #8f9aa9; font: inherit; font-size: 10px; font-weight: 700; cursor: pointer; }
.channel-member-actions button:hover, .channel-member-actions button:focus-visible { outline: 0; background: rgb(180 142 173 / 14%); color: #d9bdd5; }
.channel-member-actions .channel-member-remove:hover, .channel-member-actions .channel-member-remove:focus-visible, .channel-member-actions .channel-member-remove.is-confirming { background: rgb(191 97 106 / 16%); color: #e2a5ad; }
.channel-member-actions button:disabled { cursor: default; opacity: .45; }
.channel-members-footer { display: flex; min-height: 58px; align-items: center; justify-content: space-between; gap: 16px; padding: 10px 18px; border-top: 1px solid rgb(216 222 233 / 7%); }
.channel-members-footer p { margin: 0; color: #8490a0; font-size: 10px; }
.channel-members-footer button { display: inline-flex; min-width: max-content; height: 32px; align-items: center; justify-content: center; gap: 6px; padding: 0 10px; border: 0; border-radius: 8px; background: rgb(191 97 106 / 12%); color: #dca3aa; font: inherit; font-size: 10px; font-weight: 750; cursor: pointer; }
.channel-members-footer button:hover, .channel-members-footer button:focus-visible, .channel-members-footer button.is-confirming { outline: 0; background: rgb(191 97 106 / 22%); color: #efbbc1; }
.channel-members-footer button:disabled { cursor: default; opacity: .42; }
@keyframes channel-member-spin { to { transform: rotate(360deg); } }
@media (max-width: 600px) {
  .channel-members-overlay { align-items: end; padding: 0; }
  .channel-members-dialog { width: 100%; max-height: calc(100vh - 42px); border-radius: 16px 16px 0 0; }
  .channel-member-list article { grid-template-columns: 34px minmax(0, 1fr) auto; }
  .channel-member-actions { grid-column: 2 / -1; justify-content: flex-end; padding-bottom: 5px; }
  .channel-members-footer { align-items: flex-start; flex-direction: column; gap: 8px; }
  .channel-members-footer button { width: 100%; }
}
</style>
