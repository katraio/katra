<script setup lang="ts">
import { LoaderCircle, MessageCirclePlus, Plus, Search, UserRound, X } from "@lucide/vue";
import { computed, nextTick, onBeforeUnmount, onMounted, ref, watch } from "vue";
import type { AuthUser } from "../../api/auth";
import {
  CommunicationRequestError,
  createDirectMessage,
  getDirectMessageCandidates,
  type CommunicationDirectMessage,
  type CommunicationOrganization,
  type DirectMessageCandidate,
} from "../../api/communication";
import KatraSelect, { type KatraSelectOption } from "../ui/KatraSelect.vue";

const props = defineProps<{
  organizations: CommunicationOrganization[];
  currentUser: AuthUser;
}>();

const emit = defineEmits<{
  close: [];
  created: [directMessage: CommunicationDirectMessage];
}>();

const selectedOrganizationId = ref(
  props.organizations.find((organization) => organization.kind === "operating")?.id
    ?? props.organizations[0]?.id
    ?? "",
);
const selectedCandidates = ref<DirectMessageCandidate[]>([]);
const candidates = ref<DirectMessageCandidate[]>([]);
const searchQuery = ref("");
const activeCandidateIndex = ref(0);
const loading = ref(false);
const creating = ref(false);
const error = ref("");
const dialog = ref<HTMLElement | null>(null);
const searchInput = ref<HTMLInputElement | null>(null);
let searchTimer: number | null = null;
let candidatesAbortController: AbortController | null = null;

const organizationOptions = computed<KatraSelectOption[]>(() => props.organizations.map((organization) => ({
  value: organization.id,
  label: organization.name,
  description: organization.kind === "operating" ? "Internal organization" : "Client organization",
})));
const selectedOrganization = computed(() =>
  props.organizations.find((organization) => organization.id === selectedOrganizationId.value) ?? null,
);
const selectedIds = computed(() => new Set(selectedCandidates.value.map((candidate) => candidate.id)));
const visibleCandidates = computed(() => candidates.value.filter((candidate) => !selectedIds.value.has(candidate.id)));
const hasRequiredClient = computed(() => selectedCandidates.value.some((candidate) => candidate.kind === "client"));
const canCreate = computed(() => selectedCandidates.value.length > 0
  && (selectedOrganization.value?.kind !== "client" || hasRequiredClient.value));

function initials(name: string): string {
  return name
    .split(/\s+/)
    .filter(Boolean)
    .slice(0, 2)
    .map((part) => part[0]?.toUpperCase() ?? "")
    .join("");
}

function errorMessage(caught: unknown): string {
  if (caught instanceof CommunicationRequestError) {
    return Object.values(caught.fields).flat()[0] ?? caught.message;
  }

  return "Katra Server could not start this Direct Message. Please try again.";
}

async function loadCandidates(): Promise<void> {
  if (!selectedOrganizationId.value) return;

  candidatesAbortController?.abort();
  candidatesAbortController = new AbortController();
  loading.value = true;
  error.value = "";

  try {
    candidates.value = await getDirectMessageCandidates(
      selectedOrganizationId.value,
      searchQuery.value,
      candidatesAbortController.signal,
    );
    activeCandidateIndex.value = 0;
  } catch (caught) {
    if (!(caught instanceof DOMException && caught.name === "AbortError")) {
      error.value = errorMessage(caught);
    }
  } finally {
    loading.value = false;
  }
}

function selectCandidate(candidate: DirectMessageCandidate): void {
  if (selectedIds.value.has(candidate.id)) return;
  selectedCandidates.value = [...selectedCandidates.value, candidate];
  searchQuery.value = "";
  activeCandidateIndex.value = 0;
  nextTick(() => searchInput.value?.focus());
}

function removeCandidate(candidateId: string): void {
  selectedCandidates.value = selectedCandidates.value.filter((candidate) => candidate.id !== candidateId);
  nextTick(() => searchInput.value?.focus());
}

function moveCandidate(delta: number): void {
  if (visibleCandidates.value.length === 0) return;
  activeCandidateIndex.value = (
    activeCandidateIndex.value + delta + visibleCandidates.value.length
  ) % visibleCandidates.value.length;
}

function selectActiveCandidate(): void {
  const candidate = visibleCandidates.value[activeCandidateIndex.value];
  if (candidate) selectCandidate(candidate);
}

function handleSearchBackspace(): void {
  if (searchQuery.value || selectedCandidates.value.length === 0) return;
  selectedCandidates.value = selectedCandidates.value.slice(0, -1);
}

async function submit(): Promise<void> {
  if (!canCreate.value || creating.value || !selectedOrganizationId.value) return;

  creating.value = true;
  error.value = "";

  try {
    const directMessage = await createDirectMessage(
      selectedOrganizationId.value,
      selectedCandidates.value.map((candidate) => candidate.id),
    );
    emit("created", directMessage);
  } catch (caught) {
    error.value = errorMessage(caught);
  } finally {
    creating.value = false;
  }
}

function handleKeydown(event: KeyboardEvent): void {
  if (event.key === "Escape") {
    event.preventDefault();
    emit("close");
    return;
  }

  if (event.key !== "Tab" || !dialog.value) return;

  const focusable = Array.from(dialog.value.querySelectorAll<HTMLElement>(
    "button:not([disabled]), input:not([disabled]), [tabindex]:not([tabindex='-1'])",
  ));
  const first = focusable[0];
  const last = focusable.at(-1);

  if (event.shiftKey && document.activeElement === first) {
    event.preventDefault();
    last?.focus();
  } else if (!event.shiftKey && document.activeElement === last) {
    event.preventDefault();
    first?.focus();
  }
}

watch(selectedOrganizationId, () => {
  selectedCandidates.value = [];
  searchQuery.value = "";
  void loadCandidates();
  nextTick(() => searchInput.value?.focus());
});

watch(searchQuery, () => {
  if (searchTimer !== null) window.clearTimeout(searchTimer);
  searchTimer = window.setTimeout(() => void loadCandidates(), 180);
});

watch(visibleCandidates, (available) => {
  activeCandidateIndex.value = Math.min(activeCandidateIndex.value, Math.max(0, available.length - 1));
});

onMounted(() => {
  void loadCandidates();
  void nextTick(() => searchInput.value?.focus());
});

onBeforeUnmount(() => {
  candidatesAbortController?.abort();
  if (searchTimer !== null) window.clearTimeout(searchTimer);
});
</script>

<template>
  <Teleport to="body">
    <div class="dm-create-overlay" @mousedown.self="$emit('close')" @keydown="handleKeydown">
      <section ref="dialog" class="dm-create-dialog" role="dialog" aria-modal="true" aria-labelledby="dm-create-title">
        <header>
          <span class="dm-create-heading-icon" aria-hidden="true">
            <MessageCirclePlus :size="19" :stroke-width="1.8" />
          </span>
          <div>
            <h2 id="dm-create-title">New Direct Message</h2>
            <p>Only the people in this conversation can see it.</p>
          </div>
          <button type="button" aria-label="Close new Direct Message" @click="$emit('close')">
            <X :size="18" aria-hidden="true" />
          </button>
        </header>

        <p v-if="error" class="dm-create-error" role="alert">{{ error }}</p>

        <form @submit.prevent="submit">
          <label class="dm-create-scope">
            <span>Conversation scope</span>
            <KatraSelect
              v-model="selectedOrganizationId"
              :options="organizationOptions"
              label="Direct Message organization"
            />
          </label>

          <p class="dm-create-boundary">
            <template v-if="selectedOrganization?.kind === 'client'">
              Select at least one {{ selectedOrganization.name }} client. Internal teammates with access may also join.
            </template>
            <template v-else>
              This conversation stays inside {{ selectedOrganization?.name ?? 'the internal organization' }}.
            </template>
          </p>

          <section class="dm-create-selected" aria-label="Selected participants">
            <h3>Participants</h3>
            <div class="dm-create-chips">
              <span class="dm-create-chip dm-create-chip--current">
                <UserRound :size="13" aria-hidden="true" />
                {{ currentUser.name }} · You
              </span>
              <span v-for="candidate in selectedCandidates" :key="candidate.id" class="dm-create-chip">
                {{ candidate.name }}
                <button type="button" :aria-label="`Remove ${candidate.name}`" @click="removeCandidate(candidate.id)">
                  <X :size="12" aria-hidden="true" />
                </button>
              </span>
            </div>
          </section>

          <label class="dm-create-search-label" for="dm-create-search">Add people</label>
          <div class="dm-create-search">
            <Search :size="15" aria-hidden="true" />
            <input
              id="dm-create-search"
              ref="searchInput"
              v-model="searchQuery"
              type="search"
              autocomplete="off"
              placeholder="Search people"
              role="combobox"
              aria-autocomplete="list"
              aria-controls="dm-create-candidates"
              :aria-expanded="visibleCandidates.length > 0"
              :aria-activedescendant="visibleCandidates[activeCandidateIndex] ? `dm-candidate-${visibleCandidates[activeCandidateIndex].id}` : undefined"
              @keydown.down.prevent="moveCandidate(1)"
              @keydown.up.prevent="moveCandidate(-1)"
              @keydown.enter.prevent="selectActiveCandidate"
              @keydown.backspace="handleSearchBackspace"
            />
            <LoaderCircle v-if="loading" class="dm-create-spinner" :size="15" aria-label="Searching" />
          </div>

          <div id="dm-create-candidates" class="dm-create-candidates" role="listbox" aria-label="Available participants">
            <button
              v-for="(candidate, index) in visibleCandidates"
              :id="`dm-candidate-${candidate.id}`"
              :key="candidate.id"
              type="button"
              role="option"
              :aria-selected="index === activeCandidateIndex"
              :class="{ 'is-active': index === activeCandidateIndex }"
              @mouseenter="activeCandidateIndex = index"
              @click="selectCandidate(candidate)"
            >
              <span class="dm-create-avatar">{{ initials(candidate.name) }}</span>
              <span>
                <strong>{{ candidate.name }}</strong>
                <small>{{ candidate.kind === 'internal' ? 'Internal team' : `${selectedOrganization?.name ?? 'Client'} client` }}</small>
              </span>
              <Plus :size="15" aria-hidden="true" />
            </button>
            <p v-if="!loading && visibleCandidates.length === 0">
              {{ searchQuery ? "No matching people." : "No other people are available in this scope." }}
            </p>
          </div>

          <footer>
            <p v-if="selectedOrganization?.kind === 'client' && !hasRequiredClient">
              Choose at least one client to continue.
            </p>
            <p v-else>{{ selectedCandidates.length }} selected, plus you</p>
            <button type="submit" :disabled="!canCreate || creating">
              <LoaderCircle v-if="creating" class="dm-create-spinner" :size="15" aria-hidden="true" />
              <MessageCirclePlus v-else :size="15" aria-hidden="true" />
              Start conversation
            </button>
          </footer>
        </form>
      </section>
    </div>
  </Teleport>
</template>

<style scoped>
.dm-create-overlay { position: fixed; z-index: 100; inset: 0; display: grid; place-items: center; padding: 24px; background: rgb(10 14 20 / 66%); backdrop-filter: blur(5px); }
.dm-create-dialog { display: flex; width: min(580px, 100%); max-height: min(760px, calc(100vh - 48px)); flex-direction: column; overflow: hidden; border-radius: 14px; background: #303947; color: #d8dee9; box-shadow: 0 22px 70px rgb(5 8 13 / 48%); }
.dm-create-dialog > header { display: grid; min-height: 72px; grid-template-columns: 38px minmax(0, 1fr) 34px; align-items: center; gap: 11px; padding: 0 18px; border-bottom: 1px solid rgb(216 222 233 / 7%); }
.dm-create-heading-icon { display: grid; width: 38px; height: 38px; place-items: center; border-radius: 10px; background: #252d38; color: #c39bbc; }
.dm-create-dialog h2 { margin: 0; color: #eef1f6; font-size: 16px; }
.dm-create-dialog header p { margin: 3px 0 0; color: #8590a0; font-size: 11px; }
.dm-create-dialog header > button { display: grid; width: 34px; height: 34px; place-items: center; border: 0; border-radius: 8px; background: transparent; color: #9ca6b5; cursor: pointer; }
.dm-create-dialog header > button:hover, .dm-create-dialog header > button:focus-visible { outline: 0; background: rgb(216 222 233 / 7%); color: #eef1f6; }
.dm-create-error { margin: 12px 18px 0; padding: 9px 11px; border-radius: 8px; background: rgb(191 97 106 / 16%); color: #e6b2b8; font-size: 11px; }
.dm-create-dialog form { display: flex; min-height: 0; flex: 1; flex-direction: column; padding: 16px 18px 0; }
.dm-create-scope { display: grid; gap: 7px; color: #cfd5df; font-size: 11px; font-weight: 700; }
.dm-create-boundary { margin: 8px 0 13px; color: #8792a1; font-size: 10px; line-height: 1.45; }
.dm-create-selected h3, .dm-create-search-label { margin: 0 0 7px; color: #cfd5df; font-size: 11px; font-weight: 700; }
.dm-create-chips { display: flex; min-height: 34px; flex-wrap: wrap; gap: 6px; margin-bottom: 14px; }
.dm-create-chip { display: inline-flex; max-width: 100%; height: 30px; align-items: center; gap: 5px; padding: 0 6px 0 9px; border-radius: 999px; background: rgb(180 142 173 / 16%); color: #dec6da; font-size: 10px; font-weight: 680; }
.dm-create-chip--current { padding-right: 9px; background: #252d38; color: #b8c0cc; }
.dm-create-chip > button { display: grid; width: 20px; height: 20px; place-items: center; border: 0; border-radius: 50%; background: transparent; color: #a891a5; cursor: pointer; }
.dm-create-chip > button:hover, .dm-create-chip > button:focus-visible { outline: 0; background: rgb(255 255 255 / 8%); color: #f0dcea; }
.dm-create-search-label { display: block; }
.dm-create-search { position: relative; display: flex; height: 40px; flex: 0 0 40px; align-items: center; gap: 9px; padding: 0 11px; border-radius: 9px; background: #252d38; color: #7e8998; box-shadow: inset 0 0 0 1px rgb(216 222 233 / 8%); }
.dm-create-search:focus-within { color: #c7a2c1; box-shadow: inset 0 0 0 1px rgb(180 142 173 / 62%); }
.dm-create-search input { min-width: 0; flex: 1; border: 0; outline: 0; background: transparent; color: #edf0f4; font: inherit; font-size: 12px; }
.dm-create-search input::placeholder { color: #707b8b; }
.dm-create-spinner { animation: dm-create-spin 1s linear infinite; }
.dm-create-candidates { min-height: 110px; max-height: 260px; overflow-y: auto; margin-top: 6px; }
.dm-create-candidates > button { display: grid; width: 100%; min-height: 48px; grid-template-columns: 34px minmax(0, 1fr) 20px; align-items: center; gap: 9px; padding: 6px 8px; border: 0; border-radius: 9px; background: transparent; color: #dce2eb; font: inherit; text-align: left; cursor: pointer; }
.dm-create-candidates > button:hover, .dm-create-candidates > button:focus-visible, .dm-create-candidates > button.is-active { outline: 0; background: rgb(180 142 173 / 14%); }
.dm-create-avatar { display: grid; width: 34px; height: 34px; place-items: center; border-radius: 50%; background: #414b5b; color: #e6d2e2; font-size: 9px; font-weight: 750; }
.dm-create-candidates > button > span:nth-child(2) { display: grid; min-width: 0; gap: 2px; }
.dm-create-candidates strong { overflow: hidden; font-size: 12px; text-overflow: ellipsis; white-space: nowrap; }
.dm-create-candidates small { color: #8792a1; font-size: 9px; }
.dm-create-candidates > button > svg { color: #b48ead; }
.dm-create-candidates > p { margin: 0; padding: 24px 8px; color: #7f8a99; font-size: 11px; text-align: center; }
.dm-create-dialog footer { display: flex; min-height: 62px; align-items: center; justify-content: space-between; gap: 16px; margin-top: 8px; border-top: 1px solid rgb(216 222 233 / 7%); }
.dm-create-dialog footer p { margin: 0; color: #8490a0; font-size: 10px; }
.dm-create-dialog footer button { display: inline-flex; min-width: max-content; height: 34px; align-items: center; justify-content: center; gap: 6px; padding: 0 12px; border: 0; border-radius: 8px; background: #c39bbc; color: #221d25; font: inherit; font-size: 10px; font-weight: 780; cursor: pointer; }
.dm-create-dialog footer button:hover, .dm-create-dialog footer button:focus-visible { outline: 0; background: #d1abd0; box-shadow: 0 0 0 2px rgb(195 155 188 / 18%); }
.dm-create-dialog footer button:disabled { cursor: default; opacity: .42; }
@keyframes dm-create-spin { to { transform: rotate(360deg); } }
@media (max-width: 600px) {
  .dm-create-overlay { align-items: end; padding: 0; }
  .dm-create-dialog { width: 100%; max-height: calc(100dvh - 36px); border-radius: 16px 16px 0 0; }
  .dm-create-dialog form { padding-right: 14px; padding-left: 14px; }
  .dm-create-candidates { max-height: 34dvh; }
  .dm-create-dialog footer { align-items: stretch; flex-direction: column; gap: 8px; padding: 11px 0 14px; }
  .dm-create-dialog footer button { width: 100%; }
}
</style>
