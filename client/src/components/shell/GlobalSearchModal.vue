<script setup lang="ts">
import { Hash, Inbox, Search } from "@lucide/vue";
import { computed, nextTick, onBeforeUnmount, ref, watch, type Component } from "vue";
import {
  searchCommunications,
  type CommunicationSearchResult,
} from "../../api/communication";
import type { GlobalSearchSelection } from "./globalSearch";

type SearchResult = {
  id: string;
  destinationId: string;
  label: string;
  description: string;
  category: "Destination" | "Task" | "Project" | "Organization" | "Workflow" | "Channel" | "Agent" | "Message";
  surfaces: SearchSurface[];
  icon?: Component;
  avatar?: string;
  keywords: string;
  focus?: GlobalSearchSelection["focus"];
};

type SearchSurface = "inbox" | "channels";

const props = defineProps<{
  open: boolean;
  query: string;
  activeDestination: string;
  attentionCount: number;
}>();

const emit = defineEmits<{
  close: [];
  select: [selection: GlobalSearchSelection];
  "update:query": [query: string];
}>();

const quickAccessIds = ["inbox"];

const searchResults: SearchResult[] = [
  {
    id: "inbox",
    destinationId: "inbox",
    label: "Inbox",
    description: "Open your attention queue",
    category: "Destination",
    surfaces: ["inbox"],
    icon: Inbox,
    keywords: "attention tasks pending review approvals",
  },
];

const effectiveSearchResults = computed(() => searchResults.map((result) => result.id === "inbox"
  ? {
      ...result,
      description: props.attentionCount === 1
        ? "1 item needs your attention"
        : `${props.attentionCount} items need your attention`,
    }
  : result));

const modal = ref<HTMLElement | null>(null);
const searchInput = ref<HTMLInputElement | null>(null);
const activeIndex = ref(0);
const communicationResults = ref<CommunicationSearchResult[]>([]);
const communicationSearchStatus = ref<"idle" | "loading" | "ready" | "unavailable">("idle");
let communicationSearchTimer: number | null = null;
let communicationSearchAbortController: AbortController | null = null;

const currentConversationId = computed(() => {
  if (props.activeDestination.startsWith("channel-")) {
    return props.activeDestination.slice("channel-".length);
  }

  return props.activeDestination.startsWith("dm-")
    ? props.activeDestination.slice("dm-".length)
    : undefined;
});

const communicationSearchResults = computed<SearchResult[]>(() => communicationResults.value.map((result) => ({
  id: `message-${result.message_id}`,
  destinationId: `${result.conversation_type === "channel" ? "channel" : "dm"}-${result.conversation_id}`,
  label: result.conversation_type === "channel"
    ? `# ${result.conversation_label}`
    : result.conversation_label || "Direct message",
  description: `${result.author.name} · ${result.body}`,
  category: "Message",
  surfaces: ["channels"],
  icon: result.conversation_type === "channel" ? Hash : Inbox,
  keywords: `${result.body} ${result.author.name} ${result.conversation_label}`,
  focus: {
    conversationId: result.conversation_id,
    messageId: result.message_id,
    threadRootMessageId: result.thread_root_message_id,
  },
})));

function surfaceForDestination(destinationId: string): SearchSurface {
  if (destinationId.startsWith("channel-") || destinationId.startsWith("dm-")) return "channels";
  return "inbox";
}

const activeSurface = computed(() => surfaceForDestination(props.activeDestination));
const activeContextLabel = computed(() => {
  const exactResult = [...communicationSearchResults.value, ...effectiveSearchResults.value]
    .find((result) => result.destinationId === props.activeDestination);
  return exactResult?.label ?? "Inbox";
});

function resultScore(result: SearchResult, query: string) {
  const label = result.label.toLowerCase();
  const description = result.description.toLowerCase();
  const keywords = result.keywords.toLowerCase();
  let score = 0;

  if (result.destinationId === props.activeDestination) score += 300;
  if (result.surfaces.includes(activeSurface.value)) score += 120;
  if (label === query) score += 90;
  else if (label.startsWith(query)) score += 60;
  else if (label.includes(query)) score += 45;
  if (description.includes(query)) score += 20;
  if (keywords.includes(query)) score += 10;

  return score;
}

const visibleResults = computed(() => {
  const query = props.query.trim().toLowerCase();

  if (!query) {
    const activeResult = effectiveSearchResults.value.find((result) => result.destinationId === props.activeDestination);
    const quickAccess = quickAccessIds
      .map((id) => effectiveSearchResults.value.find((result) => result.id === id))
      .filter((result): result is SearchResult => Boolean(result));

    return activeResult
      ? [activeResult, ...quickAccess.filter((result) => result.id !== activeResult.id)]
      : quickAccess;
  }

  return [...communicationSearchResults.value, ...effectiveSearchResults.value]
    .map((result, index) => ({ result, index }))
    .filter(({ result }) =>
      `${result.label} ${result.description} ${result.category} ${result.keywords}`
        .toLowerCase()
        .includes(query),
    )
    .sort((left, right) =>
      resultScore(right.result, query) - resultScore(left.result, query) || left.index - right.index,
    )
    .map(({ result }) => result);
});

const activeResultId = computed(() => visibleResults.value[activeIndex.value]?.id);

watch(
  () => props.open,
  async (open) => {
    if (!open) {
      return;
    }

    activeIndex.value = 0;
    await nextTick();
    searchInput.value?.focus();
  },
);

watch(
  () => props.query,
  () => {
    activeIndex.value = 0;
  },
);

watch(
  [() => props.open, () => props.query],
  ([open, rawQuery]) => {
    if (communicationSearchTimer !== null) {
      window.clearTimeout(communicationSearchTimer);
      communicationSearchTimer = null;
    }
    communicationSearchAbortController?.abort();
    communicationSearchAbortController = null;

    const query = rawQuery.trim();

    if (!open || query.length < 2) {
      communicationResults.value = [];
      communicationSearchStatus.value = "idle";
      return;
    }

    communicationSearchStatus.value = "loading";
    communicationSearchTimer = window.setTimeout(async () => {
      communicationSearchTimer = null;
      const controller = new AbortController();
      communicationSearchAbortController = controller;

      try {
        communicationResults.value = await searchCommunications(query, {
          currentConversationId: currentConversationId.value,
          signal: controller.signal,
        });
        communicationSearchStatus.value = "ready";
      } catch (error) {
        if (error instanceof DOMException && error.name === "AbortError") {
          return;
        }

        communicationResults.value = [];
        communicationSearchStatus.value = "unavailable";
      } finally {
        if (communicationSearchAbortController === controller) {
          communicationSearchAbortController = null;
        }
      }
    }, 180);
  },
  { immediate: true },
);

onBeforeUnmount(() => {
  if (communicationSearchTimer !== null) {
    window.clearTimeout(communicationSearchTimer);
  }
  communicationSearchAbortController?.abort();
});

function close() {
  emit("close");
}

function select(result: SearchResult) {
  emit("select", {
    destinationId: result.destinationId,
    focus: result.focus,
  });
}

function handleInput(event: Event) {
  emit("update:query", (event.target as HTMLInputElement).value);
}

function handleKeydown(event: KeyboardEvent) {
  if (event.key === "Escape") {
    event.preventDefault();
    close();
    return;
  }

  if (event.key === "ArrowDown" || event.key === "ArrowUp") {
    event.preventDefault();

    if (!visibleResults.value.length) {
      return;
    }

    const direction = event.key === "ArrowDown" ? 1 : -1;
    activeIndex.value =
      (activeIndex.value + direction + visibleResults.value.length) % visibleResults.value.length;
    nextTick(() => {
      document
        .getElementById(`global-search-result-${activeIndex.value}`)
        ?.scrollIntoView({ block: "nearest" });
    });
    return;
  }

  if (event.key === "Enter" && activeResultId.value) {
    event.preventDefault();
    select(visibleResults.value[activeIndex.value]);
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
      class="global-search-backdrop"
      @pointerdown.self="close"
      @keydown="handleKeydown"
    >
      <section
        ref="modal"
        class="global-search-modal"
        role="dialog"
        aria-modal="true"
        aria-label="Search Katra"
      >
        <label class="global-search-input-shell">
          <Search :size="20" :stroke-width="1.8" aria-hidden="true" />
          <input
            ref="searchInput"
            :value="query"
            type="search"
            role="combobox"
            aria-label="Search Katra"
            aria-autocomplete="list"
            aria-controls="global-search-results"
            :aria-activedescendant="activeResultId ? `global-search-result-${activeIndex}` : undefined"
            :aria-expanded="true"
            placeholder="Search Katra"
            autocomplete="off"
            spellcheck="false"
            @input="handleInput"
          />
          <kbd>esc</kbd>
        </label>

        <div class="global-search-context">
          <span>{{ query.trim() ? "Search results" : "Quick access" }}</span>
          <span class="global-search-context-meta">
            <span>{{ activeContextLabel }} first</span>
            <span v-if="query.trim()">{{ visibleResults.length }} found</span>
          </span>
        </div>

        <div id="global-search-results" class="global-search-results" role="listbox">
          <button
            v-for="(result, index) in visibleResults"
            :id="`global-search-result-${index}`"
            :key="result.id"
            class="global-search-result"
            :class="{ 'global-search-result--active': activeIndex === index }"
            type="button"
            role="option"
            :aria-selected="activeIndex === index"
            @pointermove="activeIndex = index"
            @click="select(result)"
          >
            <span class="global-search-result-icon" :class="{ 'has-avatar': result.avatar }">
              <img v-if="result.avatar" :src="result.avatar" alt="" aria-hidden="true" />
              <component v-else :is="result.icon" :size="18" :stroke-width="1.8" aria-hidden="true" />
            </span>
            <span class="global-search-result-copy">
              <strong>{{ result.label }}</strong>
              <span>{{ result.description }}</span>
            </span>
            <span class="global-search-result-category">{{ result.category }}</span>
          </button>

          <p v-if="communicationSearchStatus === 'loading' && query.trim().length >= 2" class="global-search-empty">
            Searching authorized messages…
          </p>

          <p v-else-if="communicationSearchStatus === 'unavailable' && !visibleResults.length" class="global-search-empty">
            Message search is temporarily unavailable. Other Katra results remain available.
          </p>

          <p v-else-if="!visibleResults.length" class="global-search-empty">
            No matches. Try a channel, person, or message.
          </p>
        </div>

        <footer class="global-search-footer" aria-hidden="true">
          <span><kbd>↑</kbd><kbd>↓</kbd> Navigate</span>
          <span><kbd>↵</kbd> Open</span>
        </footer>
      </section>
    </div>
  </Teleport>
</template>
