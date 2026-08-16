<script setup lang="ts">
import { Hash, LockKeyhole, Plus, X } from "@lucide/vue";
import { computed, nextTick, ref, watch } from "vue";
import {
  CommunicationRequestError,
  createChannel,
  type CommunicationChannel,
} from "../../api/communication";

type ChannelFilter = "all" | "joined" | "archived";
type ChannelVisibility = "public" | "private";

const props = defineProps<{
  open: boolean;
  channels: CommunicationChannel[];
  operatingOrganizationId: string | null;
}>();

const emit = defineEmits<{
  close: [];
  created: [channel: CommunicationChannel];
  select: [channelId: string];
}>();

const filters: { id: ChannelFilter; label: string }[] = [
  { id: "all", label: "All channels" },
  { id: "joined", label: "Joined" },
  { id: "archived", label: "Archived" },
];

const activeFilter = ref<ChannelFilter>("all");
const mode = ref<"browse" | "create">("browse");
const channelName = ref("");
const visibility = ref<ChannelVisibility>("public");
const creating = ref(false);
const createError = ref("");
const closeButton = ref<HTMLButtonElement | null>(null);
const channelNameInput = ref<HTMLInputElement | null>(null);
const modal = ref<HTMLElement | null>(null);

const visibleChannels = computed(() => {
  return props.channels.filter((channel) =>
    (activeFilter.value === "all" && channel.archived_at === null) ||
    (activeFilter.value === "joined" && channel.membership !== null && channel.archived_at === null) ||
    (activeFilter.value === "archived" && channel.archived_at !== null),
  );
});

watch(
  () => props.open,
  async (open) => {
    if (!open) {
      return;
    }

    mode.value = "browse";
    channelName.value = "";
    visibility.value = "public";
    createError.value = "";
    await nextTick();
    closeButton.value?.focus();
  },
);

function openCreate() {
  mode.value = "create";
  createError.value = "";
  nextTick(() => channelNameInput.value?.focus());
}

function returnToBrowse() {
  mode.value = "browse";
  channelName.value = "";
  visibility.value = "public";
  createError.value = "";
}

async function submitCreate() {
  const name = channelName.value.trim();

  if (!name || !props.operatingOrganizationId || creating.value) {
    return;
  }

  creating.value = true;
  createError.value = "";

  try {
    const channel = await createChannel(props.operatingOrganizationId, {
      name,
      visibility: visibility.value,
    });
    emit("created", channel);
  } catch (error) {
    if (error instanceof CommunicationRequestError) {
      createError.value = Object.values(error.fields).flat()[0] ?? error.message;
    } else {
      createError.value = "Katra Server is unavailable. The channel was not created.";
    }
  } finally {
    creating.value = false;
  }
}

function close() {
  emit("close");
}

function handleKeydown(event: KeyboardEvent) {
  if (event.key === "Escape") {
    event.preventDefault();
    close();
    return;
  }

  if (event.key !== "Tab" || !modal.value) {
    return;
  }

  const focusable = Array.from(
    modal.value.querySelectorAll<HTMLElement>(
      "button:not([disabled]), input:not([disabled]), [tabindex]:not([tabindex='-1'])",
    ),
  );

  if (!focusable.length) {
    return;
  }

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
</script>

<template>
  <Teleport to="body">
    <div
      v-if="open"
      class="channel-browser-backdrop"
      @pointerdown.self="close"
      @keydown="handleKeydown"
    >
      <section
        ref="modal"
        class="channel-browser-modal"
        role="dialog"
        aria-modal="true"
        aria-labelledby="channel-browser-title"
      >
        <header class="channel-browser-header">
          <h2 id="channel-browser-title">{{ mode === "browse" ? "Browse channels" : "Create channel" }}</h2>
          <button ref="closeButton" class="channel-browser-close" type="button" aria-label="Close channel browser" @click="close">
            <X :size="19" :stroke-width="1.8" aria-hidden="true" />
          </button>
        </header>

        <button
          v-if="mode === 'browse' && operatingOrganizationId"
          class="channel-browser-create"
          type="button"
          @click="openCreate"
        >
          <span class="channel-browser-create-icon" aria-hidden="true">
            <Plus :size="18" :stroke-width="2" />
          </span>
          <span>Create a channel</span>
        </button>

        <div v-if="mode === 'browse'" class="channel-browser-tabs" role="tablist" aria-label="Channel filters">
          <button
            v-for="filter in filters"
            :key="filter.id"
            type="button"
            role="tab"
            :aria-selected="activeFilter === filter.id"
            @click="activeFilter = filter.id"
          >
            {{ filter.label }}
          </button>
        </div>

        <div v-if="mode === 'browse'" class="channel-browser-results">
          <div v-if="visibleChannels.length" class="channel-browser-list">
            <button
              v-for="channel in visibleChannels"
              :key="channel.id"
              type="button"
              @click="emit('select', channel.id)"
            >
              <span class="channel-browser-channel-name">
                <LockKeyhole v-if="channel.visibility === 'private'" :size="16" :stroke-width="1.8" aria-hidden="true" />
                <Hash v-else :size="16" :stroke-width="1.8" aria-hidden="true" />
                {{ channel.name }}
              </span>
              <span class="channel-browser-channel-meta">
                {{ channel.membership ? "Joined" : "Available" }} ·
                {{ channel.visibility === "client-team" ? "Client team" : channel.visibility }}
              </span>
            </button>
          </div>

          <p v-else class="channel-browser-empty">No channels match this view.</p>
        </div>

        <form v-else class="channel-create-form" @submit.prevent="submitCreate">
          <label class="channel-create-field">
            <span>Channel name</span>
            <input
              ref="channelNameInput"
              v-model="channelName"
              name="channel-name"
              type="text"
              maxlength="255"
              autocomplete="off"
              placeholder="e.g. Product planning"
              required
            />
            <small>Katra creates the channel address from this name.</small>
          </label>

          <fieldset class="channel-create-visibility">
            <legend>Who can find this channel?</legend>
            <label :class="{ 'is-selected': visibility === 'public' }">
              <input v-model="visibility" type="radio" name="visibility" value="public" />
              <Hash :size="19" :stroke-width="1.8" aria-hidden="true" />
              <span>
                <strong>Public</strong>
                <small>Any internal team member can find and join it.</small>
              </span>
            </label>
            <label :class="{ 'is-selected': visibility === 'private' }">
              <input v-model="visibility" type="radio" name="visibility" value="private" />
              <LockKeyhole :size="19" :stroke-width="1.8" aria-hidden="true" />
              <span>
                <strong>Private</strong>
                <small>Only invited internal team members can see it.</small>
              </span>
            </label>
          </fieldset>

          <p v-if="createError" class="channel-create-error" role="alert">{{ createError }}</p>

          <div class="channel-create-actions">
            <button type="button" :disabled="creating" @click="returnToBrowse">Cancel</button>
            <button type="submit" :disabled="creating || !channelName.trim()">
              {{ creating ? "Creating…" : "Create channel" }}
            </button>
          </div>
        </form>
      </section>
    </div>
  </Teleport>
</template>
