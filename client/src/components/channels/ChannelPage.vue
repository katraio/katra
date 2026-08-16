<script setup lang="ts">
import {
  AtSign,
  Bell,
  Bot,
  CalendarClock,
  Check,
  ChevronDown,
  Code2,
  Ellipsis,
  Hash,
  Headphones,
  Heart,
  Laugh,
  Link2,
  LockKeyhole,
  MessageSquare,
  Mic,
  Paperclip,
  SendHorizontal,
  Smile,
  ThumbsUp,
  UsersRound,
  X,
  type IconNode,
} from "@lucide/vue";
import { computed, nextTick, onBeforeUnmount, onMounted, ref, watch, type Component } from "vue";
import { isFiniteNumber, useUiPreference } from "../../composables/useUiPreference";
import HuddleMeeting from "../meetings/HuddleMeeting.vue";
import MeetingScheduleDialog, { type MeetingParticipant } from "../meetings/MeetingScheduleDialog.vue";
import MarkdownMessage from "../messages/MarkdownMessage.vue";

type Reaction = {
  id: string;
  label: string;
  icon: Component;
  count: number;
};

type ThreadReply = {
  id: string;
  author: string;
  role?: string;
  avatar: string;
  time: string;
  body: string;
};

type ChannelMessage = {
  id: string;
  author: string;
  role?: string;
  avatar: string;
  time: string;
  body: string;
  reactions?: Reaction[];
  replies?: ThreadReply[];
  update?: {
    eyebrow: string;
    title: string;
    detail: string;
    status: string;
  };
};

type ChannelDefinition = {
  id: string;
  name: string;
  private: boolean;
  topic: string;
  memberCount: number;
  members: Array<{ name: string; avatar: string; status: string }>;
  messages: ChannelMessage[];
};

const props = defineProps<{
  channelId: string;
}>();

const userAvatar = "/brand/icon.svg";

const sharedReactions = {
  approval: { id: "approval", label: "Approve", icon: ThumbsUp, count: 4 },
  love: { id: "love", label: "Love", icon: Heart, count: 2 },
  laugh: { id: "laugh", label: "Funny", icon: Laugh, count: 1 },
  done: { id: "done", label: "Done", icon: Check, count: 3 },
};

const channels: Record<string, ChannelDefinition> = {
  general: {
    id: "general",
    name: "general",
    private: false,
    topic: "Company-wide coordination, decisions, and day-to-day context",
    memberCount: 7,
    members: [
      { name: "morgan", avatar: userAvatar, status: "online" },
      { name: "Katra", avatar: "/avatars/katra.png", status: "online" },
      { name: "Artisan", avatar: "/avatars/artisan.png", status: "online" },
      { name: "Atlas", avatar: "/avatars/atlas.png", status: "online" },
      { name: "Envoy", avatar: "/avatars/envoy.png", status: "online" },
    ],
    messages: [
      {
        id: "general-1",
        author: "morgan",
        avatar: userAvatar,
        time: "9:14 AM",
        body: "The Workflows page is finally landing where I wanted it. Let’s keep the same density and calm interaction model as we move into channels.",
        reactions: [{ ...sharedReactions.approval }, { ...sharedReactions.love }],
        replies: [
          {
            id: "general-1-reply-1",
            author: "Atlas",
            role: "Documentation Agent",
            avatar: "/avatars/atlas.png",
            time: "9:21 AM",
            body: "I captured the current shell and workflow conventions so the collaboration surfaces can build on them without drifting.",
          },
          {
            id: "general-1-reply-2",
            author: "Katra",
            role: "Coordinator",
            avatar: "/avatars/katra.png",
            time: "9:24 AM",
            body: "I’ll route channel decisions back into the relevant project and workflow context when they become actionable.",
          },
        ],
      },
      {
        id: "general-2",
        author: "Katra",
        role: "Coordinator",
        avatar: "/avatars/katra.png",
        time: "9:32 AM",
        body: "Three items need attention today: the client security review, the Northstar Goods scope question, and the documentation plan. They remain ordered globally by priority and age in Inbox.",
        update: {
          eyebrow: "Attention summary",
          title: "3 decisions waiting",
          detail: "1 security review · 1 scope clarification · 1 documentation approval",
          status: "Open Inbox",
        },
        reactions: [{ ...sharedReactions.done, count: 2 }],
      },
      {
        id: "general-3",
        author: "Envoy",
        role: "Sales Agent",
        avatar: "/avatars/envoy.png",
        time: "10:06 AM",
        body: "I prepared the internal discovery brief for FinServ. No customer contact was made. The sales team can review the evidence and decide whether to advance it.",
        reactions: [{ ...sharedReactions.approval, count: 2 }],
        replies: [
          {
            id: "general-3-reply-1",
            author: "morgan",
            avatar: userAvatar,
            time: "10:10 AM",
            body: "Perfect. Keep all external communication with the human sales team.",
          },
        ],
      },
      {
        id: "general-4",
        author: "Sentinel",
        role: "Security Agent",
        avatar: "/avatars/sentinel.png",
        time: "10:24 AM",
        body: "The review handoff now separates every finding, its evidence, proposed resolution, and approval state. Feedback stays attached to the specific finding it changes.",
        reactions: [{ ...sharedReactions.done, count: 3 }],
      },
      {
        id: "general-5",
        author: "Atlas",
        role: "Documentation Agent",
        avatar: "/avatars/atlas.png",
        time: "10:42 AM",
        body: "I’m updating the product notes from the approved interface decisions only. The exploratory ideas remain clearly marked so they don’t accidentally become architecture commitments.",
        reactions: [{ ...sharedReactions.approval, count: 3 }],
        replies: [
          {
            id: "general-5-reply-1",
            author: "morgan",
            avatar: userAvatar,
            time: "10:47 AM",
            body: "Exactly. The interface should help us discover the system without pretending every mock interaction is final.",
          },
        ],
      },
      {
        id: "general-6",
        author: "morgan",
        avatar: userAvatar,
        time: "11:03 AM",
        body: "Once the channel experience feels right, direct messages should reuse the same conversation language but stay focused on one agent and its current context.",
        reactions: [{ ...sharedReactions.love, count: 2 }],
      },
      {
        id: "general-7",
        author: "Artisan",
        role: "Engineering Agent",
        avatar: "/avatars/artisan.png",
        time: "11:18 AM",
        body: "The channel composer is now isolated from the conversation scroll region, so long histories won’t push the profile or workspace controls below the viewport.",
        reactions: [{ ...sharedReactions.done, count: 2 }],
      },
      {
        id: "general-8",
        author: "Vector",
        role: "Platform Agent",
        avatar: "/avatars/vector.png",
        time: "11:31 AM",
        body: "I checked the responsive path as well. On smaller screens, navigation remains a drawer and an active thread becomes the focused conversation layer.",
        reactions: [{ ...sharedReactions.approval, count: 2 }],
      },
      {
        id: "general-9",
        author: "Katra",
        role: "Coordinator",
        avatar: "/avatars/katra.png",
        time: "11:46 AM",
        body: "Channel search, member visibility, message reactions, thread replies, and voice-input state are available in the mock. Nothing is persisted or sent outside the client.",
        reactions: [{ ...sharedReactions.done, count: 4 }],
      },
      {
        id: "general-10",
        author: "morgan",
        avatar: userAvatar,
        time: "12:02 PM",
        body: "This is the right direction. Keep it clean enough for everyday conversation, but let work context appear inline when it actually helps the team make a decision.",
        reactions: [{ ...sharedReactions.love, count: 3 }, { ...sharedReactions.approval, count: 4 }],
      },
    ],
  },
  announcements: {
    id: "announcements",
    name: "announcements",
    private: false,
    topic: "Company updates, releases, and decisions everyone should see",
    memberCount: 7,
    members: [
      { name: "morgan", avatar: userAvatar, status: "online" },
      { name: "Katra", avatar: "/avatars/katra.png", status: "online" },
      { name: "Atlas", avatar: "/avatars/atlas.png", status: "online" },
      { name: "Sentinel", avatar: "/avatars/sentinel.png", status: "online" },
    ],
    messages: [
      {
        id: "announcement-1",
        author: "morgan",
        avatar: userAvatar,
        time: "Yesterday at 4:42 PM",
        body: "The first Katra client prototype now has complete Inbox, Projects, Agents, and Workflows surfaces. We’re moving into collaboration next, starting with channels.",
        reactions: [{ ...sharedReactions.love, count: 5 }, { ...sharedReactions.approval, count: 6 }],
      },
      {
        id: "announcement-2",
        author: "Katra",
        role: "Coordinator",
        avatar: "/avatars/katra.png",
        time: "8:30 AM",
        body: "The active workflow queue now exposes each handoff, workspace state, assigned agent, and human approval gate in one operational view.",
        update: {
          eyebrow: "Product update",
          title: "Workflow control is ready for review",
          detail: "Active runs · Reusable definitions · Human approval gates",
          status: "View workflows",
        },
        reactions: [{ ...sharedReactions.done, count: 4 }],
      },
    ],
  },
  engineering: {
    id: "engineering",
    name: "engineering",
    private: true,
    topic: "Implementation decisions, code review, and delivery coordination",
    memberCount: 5,
    members: [
      { name: "morgan", avatar: userAvatar, status: "online" },
      { name: "Artisan", avatar: "/avatars/artisan.png", status: "online" },
      { name: "Sentinel", avatar: "/avatars/sentinel.png", status: "online" },
      { name: "Vector", avatar: "/avatars/vector.png", status: "away" },
      { name: "Katra", avatar: "/avatars/katra.png", status: "online" },
    ],
    messages: [
      {
        id: "engineering-1",
        author: "Artisan",
        role: "Engineering Agent",
        avatar: "/avatars/artisan.png",
        time: "8:52 AM",
        body: "The client shell now keeps each operational surface isolated from the sidebar implementation. That gives us room to move to a dual-rail layout later without rewriting feature pages.",
        reactions: [{ ...sharedReactions.done, count: 3 }],
        replies: [
          {
            id: "engineering-1-reply-1",
            author: "Vector",
            role: "Platform Agent",
            avatar: "/avatars/vector.png",
            time: "8:58 AM",
            body: "That also keeps Tauri packaging and the future web deployment path independent from feature navigation.",
          },
        ],
      },
      {
        id: "engineering-2",
        author: "Sentinel",
        role: "Security Agent",
        avatar: "/avatars/sentinel.png",
        time: "9:40 AM",
        body: "I completed the current API review. Five findings are ready for individual approval or feedback in Inbox; no work has been accepted automatically.",
        update: {
          eyebrow: "Security handoff",
          title: "5 findings ready for review",
          detail: "2 high · 2 medium · 1 low",
          status: "Review findings",
        },
        reactions: [{ ...sharedReactions.approval, count: 2 }],
      },
      {
        id: "engineering-3",
        author: "morgan",
        avatar: userAvatar,
        time: "10:18 AM",
        body: "Good. Keep the approval boundary visible. I want to understand what changed and provide feedback before anything becomes accepted guidance.",
      },
    ],
  },
  ideas: {
    id: "ideas",
    name: "ideas",
    private: true,
    topic: "Early product thinking, experiments, and intentionally unfinished concepts",
    memberCount: 6,
    members: [
      { name: "morgan", avatar: userAvatar, status: "online" },
      { name: "Katra", avatar: "/avatars/katra.png", status: "online" },
      { name: "Atlas", avatar: "/avatars/atlas.png", status: "online" },
      { name: "Vector", avatar: "/avatars/vector.png", status: "away" },
    ],
    messages: [
      {
        id: "ideas-1",
        author: "morgan",
        avatar: userAvatar,
        time: "Yesterday at 2:14 PM",
        body: "I want memory to remain replaceable. If a better system arrives later, the agent profile and workspace shouldn’t need to know which memory implementation sits behind the interface.",
        reactions: [{ ...sharedReactions.approval, count: 4 }],
        replies: [
          {
            id: "ideas-1-reply-1",
            author: "Atlas",
            role: "Documentation Agent",
            avatar: "/avatars/atlas.png",
            time: "2:22 PM",
            body: "That points toward a capability contract: retrieve context, write candidate knowledge, review provenance, and retire superseded knowledge.",
          },
          {
            id: "ideas-1-reply-2",
            author: "Katra",
            role: "Coordinator",
            avatar: "/avatars/katra.png",
            time: "2:27 PM",
            body: "The server can own that contract while harness profiles select the active provider.",
          },
        ],
      },
      {
        id: "ideas-2",
        author: "Vector",
        role: "Platform Agent",
        avatar: "/avatars/vector.png",
        time: "9:02 AM",
        body: "A workspace snapshot could become the portable handoff unit between agents: repository state, environment metadata, task context, and explicit approvals without coupling the workflow to one runner.",
        reactions: [{ ...sharedReactions.love, count: 3 }],
      },
      {
        id: "ideas-3",
        author: "morgan",
        avatar: userAvatar,
        time: "9:18 AM",
        body: "Worth exploring, but let’s keep it as a product hypothesis until the workflow pages show exactly what data a handoff needs.",
        reactions: [{ ...sharedReactions.done, count: 2 }],
      },
    ],
  },
};

const channelAliases: Record<string, string> = {
  "favorite-general": "general",
  "channel-general": "general",
  "channel-announcements": "announcements",
  "channel-engineering": "engineering",
  "channel-ideas": "ideas",
};

const activeChannelKey = computed(() => channelAliases[props.channelId] ?? "general");
const channel = computed(() => channels[activeChannelKey.value] ?? channels.general);
const customMessages = ref<Record<string, ChannelMessage[]>>({});
const customReplies = ref<Record<string, ThreadReply[]>>({});
const draft = ref("");
const threadDraft = ref("");
const memberMenuOpen = ref(false);
const moreMenuOpen = ref(false);
const huddleMenuOpen = ref(false);
const huddleOpen = ref(false);
const meetingSchedulerOpen = ref(false);
const meetingScheduleNotice = ref("");
const selectedThreadId = ref<string | null>(null);
const voiceListening = ref(false);
const selectedReactions = ref<Record<string, boolean>>({});
const messageList = ref<HTMLElement | null>(null);
const threadList = ref<HTMLElement | null>(null);
const memberMenu = ref<HTMLElement | null>(null);
const moreMenu = ref<HTMLElement | null>(null);
const huddleMenu = ref<HTMLElement | null>(null);
const channelPageElement = ref<HTMLElement | null>(null);
const threadWidth = useUiPreference(
  "channel-thread-width",
  390,
  (value): value is number => isFiniteNumber(value) && value >= 280 && value <= 780,
);
const threadMaximumWidth = ref(780);
const threadResizing = ref(false);

const participantDirectory: MeetingParticipant[] = [
  { name: "morgan", role: "DevOption", avatar: userAvatar },
  { name: "Katra", role: "Coordinator", avatar: "/avatars/katra.png" },
  { name: "Artisan", role: "Engineering Agent", avatar: "/avatars/artisan.png" },
  { name: "Atlas", role: "Documentation Agent", avatar: "/avatars/atlas.png" },
  { name: "Envoy", role: "Sales Assistant", avatar: "/avatars/envoy.png" },
  { name: "Sentinel", role: "Security Agent", avatar: "/avatars/sentinel.png" },
  { name: "Vector", role: "Platform Agent", avatar: "/avatars/vector.png" },
];

const huddleParticipants = computed(() => {
  const channelNames = new Set(channel.value.members.map((member) => member.name));
  const ordered = [
    ...channel.value.members.map((member) => participantDirectory.find((participant) => participant.name === member.name) ?? { name: member.name, avatar: member.avatar, role: "Channel member" }),
    ...participantDirectory.filter((participant) => !channelNames.has(participant.name)),
  ];
  return ordered.slice(0, channel.value.memberCount);
});

const THREAD_MINIMUM_WIDTH = 280;
let threadResizeHandle: HTMLElement | null = null;
let threadResizePointerId: number | null = null;

const channelPageStyle = computed(() => ({
  "--channel-thread-width": `${threadWidth.value}px`,
}));

const allMessages = computed(() => [
  ...channel.value.messages,
  ...(customMessages.value[channel.value.id] ?? []),
]);

const selectedThreadMessage = computed(() =>
  allMessages.value.find((message) => message.id === selectedThreadId.value) ?? null,
);

const threadReplies = computed(() => {
  const message = selectedThreadMessage.value;
  if (!message) {
    return [];
  }

  return [...(message.replies ?? []), ...(customReplies.value[message.id] ?? [])];
});

function toggleMemberMenu() {
  memberMenuOpen.value = !memberMenuOpen.value;
  moreMenuOpen.value = false;
}

function toggleMoreMenu() {
  moreMenuOpen.value = !moreMenuOpen.value;
  memberMenuOpen.value = false;
}

function startChannelHuddle() {
  memberMenuOpen.value = false;
  moreMenuOpen.value = false;
  huddleMenuOpen.value = false;
  huddleOpen.value = true;
}

function openMeetingScheduler() {
  huddleMenuOpen.value = false;
  meetingSchedulerOpen.value = true;
}

function handleMeetingScheduled(message: string) {
  meetingSchedulerOpen.value = false;
  meetingScheduleNotice.value = message;
  window.setTimeout(() => { if (meetingScheduleNotice.value === message) meetingScheduleNotice.value = ""; }, 3200);
}

function openThread(message: ChannelMessage) {
  selectedThreadId.value = message.id;
  nextTick(() => {
    syncThreadWidthToBounds();
    threadList.value?.scrollTo({ top: threadList.value.scrollHeight });
  });
}

function closeThread() {
  selectedThreadId.value = null;
  threadDraft.value = "";
}

function threadWidthBounds() {
  const bounds = channelPageElement.value?.getBoundingClientRect();

  if (!bounds) {
    return null;
  }

  const mainMinimum = bounds.width <= 1100 ? 420 : 460;
  const maximum = Math.max(
    THREAD_MINIMUM_WIDTH,
    Math.min(bounds.width - mainMinimum, bounds.width * 0.62),
  );

  threadMaximumWidth.value = Math.round(maximum);
  return { bounds, maximum };
}

function setThreadWidth(width: number) {
  const limits = threadWidthBounds();

  if (!limits) {
    return;
  }

  threadWidth.value = Math.round(
    Math.min(limits.maximum, Math.max(THREAD_MINIMUM_WIDTH, width)),
  );
}

function syncThreadWidthToBounds() {
  if (window.matchMedia("(max-width: 900px)").matches) {
    return;
  }

  setThreadWidth(threadWidth.value);
}

function resizeThreadPane(event: PointerEvent) {
  if (!threadResizing.value) {
    return;
  }

  const limits = threadWidthBounds();

  if (limits) {
    setThreadWidth(limits.bounds.right - event.clientX);
  }
}

function startThreadPaneResize(event: PointerEvent) {
  if (window.matchMedia("(max-width: 900px)").matches || (event.pointerType === "mouse" && event.button !== 0)) {
    return;
  }

  event.preventDefault();
  threadResizeHandle = event.currentTarget as HTMLElement;
  threadResizePointerId = event.pointerId;
  threadResizeHandle.setPointerCapture(event.pointerId);
  threadResizing.value = true;
  window.addEventListener("pointermove", resizeThreadPane);
  window.addEventListener("pointerup", stopThreadPaneResize);
  window.addEventListener("pointercancel", stopThreadPaneResize);
  resizeThreadPane(event);
}

function stopThreadPaneResize() {
  if (!threadResizing.value) {
    return;
  }

  if (threadResizeHandle && threadResizePointerId !== null && threadResizeHandle.hasPointerCapture(threadResizePointerId)) {
    threadResizeHandle.releasePointerCapture(threadResizePointerId);
  }

  threadResizing.value = false;
  threadResizeHandle = null;
  threadResizePointerId = null;
  window.removeEventListener("pointermove", resizeThreadPane);
  window.removeEventListener("pointerup", stopThreadPaneResize);
  window.removeEventListener("pointercancel", stopThreadPaneResize);
}

function resizeThreadPaneWithKeyboard(event: KeyboardEvent) {
  if (!["ArrowLeft", "ArrowRight", "Home", "End"].includes(event.key)) {
    return;
  }

  event.preventDefault();

  if (event.key === "Home") {
    setThreadWidth(THREAD_MINIMUM_WIDTH);
    return;
  }

  if (event.key === "End") {
    setThreadWidth(threadMaximumWidth.value);
    return;
  }

  setThreadWidth(threadWidth.value + (event.key === "ArrowLeft" ? 20 : -20));
}

function reactionCount(message: ChannelMessage, reaction: Reaction) {
  return reaction.count + (selectedReactions.value[`${message.id}:${reaction.id}`] ? 1 : 0);
}

function toggleReaction(message: ChannelMessage, reaction: Reaction) {
  const key = `${message.id}:${reaction.id}`;
  selectedReactions.value[key] = !selectedReactions.value[key];
}

function addQuickReaction(message: ChannelMessage) {
  const reaction = message.reactions?.find((item) => item.id === "approval");
  if (reaction) {
    toggleReaction(message, reaction);
    return;
  }

  message.reactions = [{ ...sharedReactions.approval, count: 0 }];
  toggleReaction(message, message.reactions[0]);
}

function sendMessage() {
  const body = draft.value.trim();
  if (!body) {
    return;
  }

  const newMessage: ChannelMessage = {
    id: `${channel.value.id}-${Date.now()}`,
    author: "morgan",
    avatar: userAvatar,
    time: "Just now",
    body,
  };

  customMessages.value[channel.value.id] = [
    ...(customMessages.value[channel.value.id] ?? []),
    newMessage,
  ];
  draft.value = "";
  nextTick(() => messageList.value?.scrollTo({ top: messageList.value.scrollHeight, behavior: "smooth" }));
}

function sendThreadReply() {
  const body = threadDraft.value.trim();
  const message = selectedThreadMessage.value;
  if (!body || !message) {
    return;
  }

  const reply: ThreadReply = {
    id: `${message.id}-reply-${Date.now()}`,
    author: "morgan",
    avatar: userAvatar,
    time: "Just now",
    body,
  };

  customReplies.value[message.id] = [...(customReplies.value[message.id] ?? []), reply];
  threadDraft.value = "";
  nextTick(() => threadList.value?.scrollTo({ top: threadList.value.scrollHeight, behavior: "smooth" }));
}

function handleComposerKeydown(event: KeyboardEvent) {
  if (event.key === "Enter" && !event.shiftKey) {
    event.preventDefault();
    sendMessage();
  }
}

function handleThreadComposerKeydown(event: KeyboardEvent) {
  if (event.key === "Enter" && !event.shiftKey) {
    event.preventDefault();
    sendThreadReply();
  }
}

function toggleVoiceInput() {
  if (voiceListening.value) {
    voiceListening.value = false;
    draft.value = `${draft.value}${draft.value ? " " : ""}Let’s capture that as a follow-up for the current workflow.`;
    return;
  }

  voiceListening.value = true;
}

function handleDocumentPointerDown(event: PointerEvent) {
  const target = event.target as Node;
  if (memberMenuOpen.value && !memberMenu.value?.contains(target)) {
    memberMenuOpen.value = false;
  }
  if (moreMenuOpen.value && !moreMenu.value?.contains(target)) {
    moreMenuOpen.value = false;
  }
  if (huddleMenuOpen.value && !huddleMenu.value?.contains(target)) {
    huddleMenuOpen.value = false;
  }
}

function handleDocumentKeydown(event: KeyboardEvent) {
  if (event.key !== "Escape") {
    return;
  }

  if (meetingSchedulerOpen.value) {
    meetingSchedulerOpen.value = false;
    return;
  }

  if (moreMenuOpen.value || memberMenuOpen.value || huddleMenuOpen.value) {
    moreMenuOpen.value = false;
    memberMenuOpen.value = false;
    huddleMenuOpen.value = false;
    return;
  }

  if (selectedThreadId.value) {
    closeThread();
  }
}

watch(activeChannelKey, () => {
  memberMenuOpen.value = false;
  moreMenuOpen.value = false;
  huddleMenuOpen.value = false;
  huddleOpen.value = false;
  meetingSchedulerOpen.value = false;
  selectedThreadId.value = null;
  draft.value = "";
});

onMounted(() => {
  document.addEventListener("pointerdown", handleDocumentPointerDown);
  document.addEventListener("keydown", handleDocumentKeydown);
  window.addEventListener("resize", syncThreadWidthToBounds);
  nextTick(() => messageList.value?.scrollTo({ top: messageList.value.scrollHeight }));
});

onBeforeUnmount(() => {
  stopThreadPaneResize();
  document.removeEventListener("pointerdown", handleDocumentPointerDown);
  document.removeEventListener("keydown", handleDocumentKeydown);
  window.removeEventListener("resize", syncThreadWidthToBounds);
});
</script>

<template>
  <section
    ref="channelPageElement"
    class="channel-page"
    :class="{
      'channel-page--thread-open': selectedThreadMessage,
      'channel-page--thread-resizing': threadResizing,
    }"
    :style="channelPageStyle"
  >
    <section class="channel-main" :aria-label="`${channel.name} channel`">
      <header class="channel-page-header">
        <div class="channel-identity">
          <component
            :is="channel.private ? LockKeyhole : Hash"
            :size="21"
            :stroke-width="1.8"
            aria-hidden="true"
          />
          <div>
            <div class="channel-name-row">
              <h1>{{ channel.name }}</h1>
              <span v-if="channel.private" class="channel-private-label">Private</span>
            </div>
            <p>{{ channel.topic }}</p>
          </div>
        </div>

        <div class="channel-header-actions">
          <div ref="memberMenu" class="channel-header-popover-shell">
            <button
              class="channel-member-button"
              type="button"
              aria-label="View channel members"
              aria-haspopup="dialog"
              :aria-expanded="memberMenuOpen"
              @click.stop="toggleMemberMenu"
            >
              <span class="channel-member-stack" aria-hidden="true">
                <img v-for="member in channel.members.slice(0, 3)" :key="member.name" :src="member.avatar" alt="" />
              </span>
              <span>{{ channel.memberCount }}</span>
            </button>

            <section v-if="memberMenuOpen" class="channel-members-popover" aria-label="Channel members">
              <header>
                <div>
                  <strong>{{ channel.memberCount }} members</strong>
                  <span>#{{ channel.name }}</span>
                </div>
                <button type="button" aria-label="Close members" @click="memberMenuOpen = false">
                  <X :size="16" :stroke-width="1.8" aria-hidden="true" />
                </button>
              </header>
              <div class="channel-member-list">
                <button v-for="member in channel.members" :key="member.name" type="button">
                  <span class="channel-member-avatar">
                    <img :src="member.avatar" alt="" />
                    <span :class="`is-${member.status}`" />
                  </span>
                  <span>{{ member.name }}</span>
                </button>
              </div>
            </section>
          </div>

          <div ref="huddleMenu" class="channel-huddle-shell">
            <button class="channel-header-button channel-huddle-button" type="button" aria-label="Start a meeting with everyone in this channel" @click="startChannelHuddle"><Headphones :size="18" :stroke-width="1.8" aria-hidden="true" /></button>
            <button class="channel-huddle-menu-button" type="button" aria-label="Meeting options" aria-haspopup="menu" :aria-expanded="huddleMenuOpen" @click.stop="huddleMenuOpen = !huddleMenuOpen"><ChevronDown :size="14" aria-hidden="true" /></button>
            <div v-if="huddleMenuOpen" class="channel-page-menu channel-huddle-menu" role="menu">
              <button type="button" role="menuitem" @click="startChannelHuddle"><Headphones :size="16" aria-hidden="true" /><span><strong>Start meeting now</strong><small>Invite all {{ channel.memberCount }} channel participants</small></span></button>
              <button type="button" role="menuitem" @click="openMeetingScheduler"><CalendarClock :size="16" aria-hidden="true" /><span><strong>Schedule meeting</strong><small>One time or recurring</small></span></button>
            </div>
          </div>

          <div ref="moreMenu" class="channel-header-popover-shell">
            <button
              class="channel-header-button"
              type="button"
              aria-label="Channel options"
              aria-haspopup="menu"
              :aria-expanded="moreMenuOpen"
              @click.stop="toggleMoreMenu"
            >
              <Ellipsis :size="19" :stroke-width="1.9" aria-hidden="true" />
            </button>
            <div v-if="moreMenuOpen" class="channel-page-menu" role="menu">
              <button type="button" role="menuitem" @click="moreMenuOpen = false">
                <Bell :size="16" :stroke-width="1.8" aria-hidden="true" />
                Notification settings
              </button>
              <button type="button" role="menuitem" @click="moreMenuOpen = false">
                <Link2 :size="16" :stroke-width="1.8" aria-hidden="true" />
                Copy channel link
              </button>
            </div>
          </div>
        </div>
      </header>

      <div ref="messageList" class="channel-message-list" aria-live="polite">
        <div class="channel-date-marker"><span>Today</span></div>

        <article
          v-for="message in allMessages"
          :key="message.id"
          class="channel-message"
          :class="{ 'channel-message--self': message.author === 'morgan' }"
        >
          <img class="channel-message-avatar" :src="message.avatar" :alt="`${message.author} avatar`" />
          <div class="channel-message-content">
            <header>
              <strong>{{ message.author }}</strong>
              <span v-if="message.author === 'morgan'" class="channel-self-label">You</span>
              <span v-if="message.role" class="channel-agent-label">
                <Bot :size="11" :stroke-width="2" aria-hidden="true" />
                {{ message.role }}
              </span>
              <time>{{ message.time }}</time>
            </header>
            <MarkdownMessage :body="message.body" />

            <button v-if="message.update" class="channel-work-update" type="button">
              <span class="channel-work-update-icon">
                <MessageSquare :size="18" :stroke-width="1.8" aria-hidden="true" />
              </span>
              <span>
                <small>{{ message.update.eyebrow }}</small>
                <strong>{{ message.update.title }}</strong>
                <span>{{ message.update.detail }}</span>
              </span>
              <em>{{ message.update.status }}</em>
            </button>

            <button
              v-if="(message.replies?.length ?? 0) + (customReplies[message.id]?.length ?? 0)"
              class="channel-thread-summary"
              type="button"
              @click="openThread(message)"
            >
              <span class="channel-thread-avatars" aria-hidden="true">
                <img
                  v-for="reply in [...(message.replies ?? []), ...(customReplies[message.id] ?? [])].slice(0, 3)"
                  :key="reply.id"
                  :src="reply.avatar"
                  alt=""
                />
              </span>
              <strong>{{ (message.replies?.length ?? 0) + (customReplies[message.id]?.length ?? 0) }} replies</strong>
              <span>Latest reply {{ [...(message.replies ?? []), ...(customReplies[message.id] ?? [])].at(-1)?.time }}</span>
            </button>

            <div v-if="message.reactions?.length" class="channel-reactions">
              <button
                v-for="reaction in message.reactions"
                :key="reaction.id"
                type="button"
                :class="{ 'is-active': selectedReactions[`${message.id}:${reaction.id}`] }"
                :aria-label="`${reaction.label}, ${reactionCount(message, reaction)}`"
                @click="toggleReaction(message, reaction)"
              >
                <component :is="reaction.icon" :size="14" :stroke-width="1.9" aria-hidden="true" />
                <span>{{ reactionCount(message, reaction) }}</span>
              </button>
            </div>
          </div>

          <div class="channel-message-actions" aria-label="Message actions">
            <button type="button" aria-label="Add reaction" @click="addQuickReaction(message)">
              <Smile :size="16" :stroke-width="1.8" aria-hidden="true" />
            </button>
            <button type="button" aria-label="Reply in thread" @click="openThread(message)">
              <MessageSquare :size="16" :stroke-width="1.8" aria-hidden="true" />
            </button>
            <button type="button" aria-label="More message actions">
              <Ellipsis :size="17" :stroke-width="1.9" aria-hidden="true" />
            </button>
          </div>
        </article>
      </div>

      <form class="channel-composer" @submit.prevent="sendMessage">
        <textarea
          v-model="draft"
          rows="2"
          :placeholder="`Message #${channel.name}`"
          :aria-label="`Message ${channel.name}`"
          @keydown="handleComposerKeydown"
        />
        <div class="channel-composer-toolbar">
          <div class="channel-composer-tools">
            <button type="button" aria-label="Add attachment">
              <Paperclip :size="17" :stroke-width="1.8" aria-hidden="true" />
            </button>
            <button type="button" aria-label="Mention someone">
              <AtSign :size="17" :stroke-width="1.8" aria-hidden="true" />
            </button>
            <button type="button" aria-label="Add reaction">
              <Smile :size="17" :stroke-width="1.8" aria-hidden="true" />
            </button>
            <button type="button" aria-label="Insert code">
              <Code2 :size="17" :stroke-width="1.8" aria-hidden="true" />
            </button>
            <button
              type="button"
              :class="{ 'is-listening': voiceListening }"
              :aria-label="voiceListening ? 'Stop voice input' : 'Start voice input'"
              @click="toggleVoiceInput"
            >
              <Mic :size="17" :stroke-width="1.8" aria-hidden="true" />
            </button>
            <span v-if="voiceListening" class="channel-listening-label">Listening…</span>
          </div>
          <button class="channel-send-button" type="submit" :disabled="!draft.trim()" aria-label="Send message">
            <SendHorizontal :size="17" :stroke-width="1.9" aria-hidden="true" />
          </button>
        </div>
      </form>
    </section>

    <div
      v-if="selectedThreadMessage"
      class="channel-thread-resize-handle"
      role="separator"
      tabindex="0"
      aria-label="Resize thread panel"
      aria-orientation="vertical"
      :aria-valuemin="THREAD_MINIMUM_WIDTH"
      :aria-valuemax="threadMaximumWidth"
      :aria-valuenow="threadWidth"
      @pointerdown="startThreadPaneResize"
      @keydown="resizeThreadPaneWithKeyboard"
    ><span aria-hidden="true" /></div>

    <aside v-if="selectedThreadMessage" class="channel-thread" aria-label="Thread">
      <header class="channel-thread-header">
        <div>
          <strong>Thread</strong>
          <span>#{{ channel.name }}</span>
        </div>
        <button type="button" aria-label="Close thread" @click="closeThread">
          <X :size="18" :stroke-width="1.8" aria-hidden="true" />
        </button>
      </header>

      <div ref="threadList" class="channel-thread-list">
        <article
          class="channel-thread-root"
          :class="{ 'channel-thread-root--self': selectedThreadMessage.author === 'morgan' }"
        >
          <img :src="selectedThreadMessage.avatar" :alt="`${selectedThreadMessage.author} avatar`" />
          <div>
            <header>
              <strong>{{ selectedThreadMessage.author }}</strong>
              <span v-if="selectedThreadMessage.author === 'morgan'" class="channel-self-label">You</span>
              <time>{{ selectedThreadMessage.time }}</time>
            </header>
            <p>{{ selectedThreadMessage.body }}</p>
          </div>
        </article>

        <div class="channel-thread-count">
          <span>{{ threadReplies.length }} {{ threadReplies.length === 1 ? "reply" : "replies" }}</span>
        </div>

        <article
          v-for="reply in threadReplies"
          :key="reply.id"
          class="channel-thread-reply"
          :class="{ 'channel-thread-reply--self': reply.author === 'morgan' }"
        >
          <img :src="reply.avatar" :alt="`${reply.author} avatar`" />
          <div>
            <header>
              <strong>{{ reply.author }}</strong>
              <span v-if="reply.author === 'morgan'" class="channel-self-label">You</span>
              <span v-if="reply.role">{{ reply.role }}</span>
              <time>{{ reply.time }}</time>
            </header>
            <MarkdownMessage :body="reply.body" />
          </div>
        </article>
      </div>

      <form class="channel-thread-composer" @submit.prevent="sendThreadReply">
        <textarea
          v-model="threadDraft"
          rows="2"
          placeholder="Reply…"
          aria-label="Reply in thread"
          @keydown="handleThreadComposerKeydown"
        />
        <div>
          <span>Replying to {{ selectedThreadMessage.author }}</span>
          <button type="submit" :disabled="!threadDraft.trim()" aria-label="Send reply">
            <SendHorizontal :size="16" :stroke-width="1.9" aria-hidden="true" />
          </button>
        </div>
      </form>
    </aside>

    <p v-if="meetingScheduleNotice" class="channel-meeting-notice" role="status">{{ meetingScheduleNotice }}</p>
    <MeetingScheduleDialog v-if="meetingSchedulerOpen" :default-title="`${channel.name} meeting`" :audience-label="`#${channel.name}`" :participants="huddleParticipants" @close="meetingSchedulerOpen = false" @scheduled="handleMeetingScheduled" />
    <HuddleMeeting v-if="huddleOpen" :title="`#${channel.name} meeting`" :subtitle="`Everyone in #${channel.name}`" :participants="huddleParticipants" @minimize="huddleOpen = false" @leave="huddleOpen = false" />
  </section>
</template>

<style scoped>
.channel-page {
  position: relative;
  display: grid;
  width: 100%;
  height: 100%;
  min-width: 0;
  min-height: 0;
  grid-template-columns: minmax(0, 1fr);
  overflow: hidden;
  background: #303744;
  color: #dce1e9;
}

.channel-page--thread-open {
  grid-template-columns: minmax(460px, 1fr) 0 var(--channel-thread-width, 390px);
}

.channel-main,
.channel-thread {
  display: flex;
  min-width: 0;
  min-height: 0;
  flex-direction: column;
  overflow: hidden;
}

.channel-page-header {
  position: relative;
  z-index: 8;
  display: flex;
  min-height: 78px;
  flex: 0 0 78px;
  align-items: center;
  justify-content: space-between;
  gap: 18px;
  padding: 0 24px;
  background: #303744;
}

.channel-identity {
  display: flex;
  min-width: 0;
  align-items: center;
  gap: 12px;
}

.channel-identity > svg {
  flex: 0 0 auto;
  color: #b8c0cc;
}

.channel-identity > div {
  min-width: 0;
}

.channel-name-row {
  display: flex;
  min-width: 0;
  align-items: center;
  gap: 9px;
}

.channel-name-row h1 {
  margin: 0;
  overflow: hidden;
  color: #eef1f5;
  font-size: 17px;
  font-weight: 720;
  line-height: 1.2;
  text-overflow: ellipsis;
  white-space: nowrap;
}

.channel-private-label {
  padding: 4px 7px;
  border-radius: 999px;
  background: rgb(180 142 173 / 12%);
  color: #cda7cb;
  font-size: 9px;
  font-weight: 700;
  letter-spacing: 0.04em;
  text-transform: uppercase;
}

.channel-identity p {
  margin: 5px 0 0;
  overflow: hidden;
  color: #858f9f;
  font-size: 11px;
  line-height: 1.3;
  text-overflow: ellipsis;
  white-space: nowrap;
}

.channel-header-actions {
  display: flex;
  flex: 0 0 auto;
  align-items: center;
  gap: 7px;
}

.channel-huddle-shell {
  position: relative;
  display: flex;
  align-items: stretch;
  overflow: visible;
  border-radius: 9px;
  background: #343b47;
}

.channel-huddle-shell .channel-huddle-button {
  width: 34px;
  border-radius: 9px 0 0 9px;
  background: transparent;
}

.channel-huddle-menu-button {
  display: grid;
  width: 24px;
  height: 34px;
  place-items: center;
  border-radius: 0 9px 9px 0;
  background: transparent;
  color: #8f9aa9;
  cursor: pointer;
}

.channel-huddle-shell:hover,
.channel-huddle-shell:focus-within {
  background: #3c4452;
}

.channel-huddle-shell button:hover,
.channel-huddle-shell button:focus-visible {
  outline: 0;
  color: #edf0f5;
}

.channel-page-menu.channel-huddle-menu {
  width: 270px;
}

.channel-huddle-menu button {
  height: auto;
  min-height: 52px;
}

.channel-huddle-menu button > span {
  display: grid;
  gap: 3px;
}

.channel-huddle-menu strong {
  color: #e7ebf0;
  font-size: 10px;
}

.channel-huddle-menu small {
  color: #7f8a99;
  font-size: 8px;
}

.channel-meeting-notice {
  position: absolute;
  z-index: 75;
  top: 76px;
  right: 18px;
  max-width: 360px;
  margin: 0;
  padding: 10px 12px;
  border-radius: 8px;
  background: #46505d;
  color: #e6eaf0;
  box-shadow: 0 12px 28px rgb(5 8 12 / 28%);
  font-size: 9px;
}

.channel-header-button,
.channel-member-button,
.channel-members-popover header button,
.channel-thread-header button {
  display: grid;
  height: 34px;
  place-items: center;
  border-radius: 9px;
  background: #343b47;
  color: #96a0af;
  cursor: pointer;
  transition: background 180ms ease, color 180ms ease;
}

.channel-header-button {
  width: 34px;
}

.channel-header-button:hover,
.channel-header-button:focus-visible,
.channel-member-button:hover,
.channel-member-button:focus-visible,
.channel-members-popover header button:hover,
.channel-members-popover header button:focus-visible,
.channel-thread-header button:hover,
.channel-thread-header button:focus-visible {
  outline: 0;
  background: #3c4452;
  color: #e0e4eb;
}

.channel-member-button {
  display: flex;
  min-width: 77px;
  align-items: center;
  justify-content: center;
  gap: 7px;
  padding: 0 9px;
  font-size: 11px;
}

.channel-member-stack {
  display: flex;
  align-items: center;
}

.channel-member-stack img {
  width: 23px;
  height: 23px;
  margin-left: -7px;
  border: 2px solid #343b47;
  border-radius: 50%;
  object-fit: cover;
}

.channel-member-stack img:first-child {
  margin-left: 0;
}

.channel-header-popover-shell {
  position: relative;
}

.channel-members-popover,
.channel-page-menu {
  position: absolute;
  z-index: 40;
  top: calc(100% + 8px);
  right: 0;
  width: 254px;
  padding: 8px;
  border-radius: 14px;
  background: #2a313c;
  box-shadow: 0 15px 32px rgb(7 10 15 / 18%);
}

.channel-members-popover header {
  display: flex;
  align-items: center;
  justify-content: space-between;
  padding: 7px 7px 9px;
}

.channel-members-popover header > div {
  display: grid;
  gap: 4px;
}

.channel-members-popover header strong {
  color: #e6e9ef;
  font-size: 12px;
}

.channel-members-popover header span {
  color: #788393;
  font-size: 10px;
}

.channel-members-popover header button {
  width: 28px;
  height: 28px;
  background: transparent;
}

.channel-member-list {
  display: grid;
  gap: 2px;
}

.channel-member-list button,
.channel-page-menu button {
  display: flex;
  width: 100%;
  height: 38px;
  align-items: center;
  gap: 10px;
  padding: 0 9px;
  border-radius: 9px;
  background: transparent;
  color: #c9d0da;
  text-align: left;
  cursor: pointer;
}

.channel-member-list button:hover,
.channel-member-list button:focus-visible,
.channel-page-menu button:hover,
.channel-page-menu button:focus-visible {
  outline: 0;
  background: #373f4c;
  color: #f0f2f6;
}

.channel-member-avatar {
  position: relative;
  width: 26px;
  height: 26px;
  flex: 0 0 26px;
}

.channel-member-avatar img {
  width: 26px;
  height: 26px;
  border-radius: 50%;
  object-fit: cover;
}

.channel-member-avatar > span {
  position: absolute;
  right: -1px;
  bottom: -1px;
  width: 8px;
  height: 8px;
  border: 2px solid #2a313c;
  border-radius: 50%;
  background: #54b991;
}

.channel-member-avatar > span.is-away {
  background: #c59d62;
}

.channel-page-menu {
  width: 210px;
}

.channel-date-marker {
  display: flex;
  justify-content: center;
  padding: 6px 0 16px;
}

.channel-date-marker span {
  padding: 6px 11px;
  border-radius: 999px;
  background: #353c48;
  color: #8893a2;
  font-size: 9px;
  font-weight: 650;
}

.channel-message-list {
  flex: 1;
  min-height: 0;
  overflow-y: auto;
  overscroll-behavior: contain;
  padding: 18px 24px 20px;
  scroll-behavior: smooth;
}

.channel-message {
  position: relative;
  display: grid;
  grid-template-columns: 40px minmax(0, 1fr);
  gap: 12px;
  margin: 0 -10px;
  padding: 10px 10px 13px;
  border-radius: 10px;
  transition: background 160ms ease;
}

.channel-message + .channel-message {
  margin-top: 8px;
}

.channel-message:hover,
.channel-message:focus-within {
  background: rgb(42 48 59 / 50%);
}

.channel-message-avatar {
  width: 40px;
  height: 40px;
  border-radius: 50%;
  object-fit: cover;
}

.channel-message-content {
  min-width: 0;
  padding-top: 1px;
}

.channel-message--self {
  background: linear-gradient(90deg, rgb(180 142 173 / 10%), rgb(180 142 173 / 3%) 58%, transparent 92%);
}

.channel-message--self:hover,
.channel-message--self:focus-within {
  background: linear-gradient(90deg, rgb(180 142 173 / 15%), rgb(180 142 173 / 5%) 58%, transparent 92%);
}

.channel-message--self > .channel-message-avatar,
.channel-thread-root--self > img,
.channel-thread-reply--self > img {
  box-shadow: 0 0 0 2px rgb(192 151 187 / 42%);
}

.channel-message-content > header,
.channel-thread-root header,
.channel-thread-reply header {
  display: flex;
  min-width: 0;
  align-items: center;
  gap: 8px;
}

.channel-message-content > header strong,
.channel-thread-root header strong,
.channel-thread-reply header strong {
  color: #e0e5ec;
  font-size: 12px;
  font-weight: 720;
}

.channel-message-content time,
.channel-thread-root time,
.channel-thread-reply time {
  color: #707b8b;
  font-size: 9px;
}

.channel-agent-label {
  display: flex;
  align-items: center;
  gap: 4px;
  padding: 3px 6px;
  border-radius: 999px;
  background: rgb(180 142 173 / 11%);
  color: #bb98b7;
  font-size: 8px;
  font-weight: 650;
}

.channel-self-label {
  padding: 3px 7px;
  border-radius: 999px;
  background: rgb(180 142 173 / 16%);
  color: #c9a5c5;
  font-size: 8px;
  font-weight: 700;
}

.channel-message-content > p,
.channel-thread-root p,
.channel-thread-reply p {
  max-width: 900px;
  margin: 6px 0 0;
  color: #b6beca;
  font-size: 12px;
  line-height: 1.55;
}

.channel-work-update {
  display: grid;
  width: min(620px, 100%);
  min-height: 74px;
  grid-template-columns: 36px minmax(0, 1fr) auto;
  align-items: center;
  gap: 11px;
  margin-top: 12px;
  padding: 10px 12px;
  border-radius: 11px;
  background: #292f3a;
  color: #bbc3ce;
  text-align: left;
  cursor: pointer;
  transition: background 180ms ease;
}

.channel-work-update:hover,
.channel-work-update:focus-visible {
  outline: 0;
  background: #333b47;
}

.channel-work-update-icon {
  display: grid;
  width: 36px;
  height: 36px;
  place-items: center;
  border-radius: 9px;
  background: rgb(180 142 173 / 14%);
  color: #c29cbe;
}

.channel-work-update > span:nth-child(2) {
  display: grid;
  min-width: 0;
  gap: 4px;
}

.channel-work-update small {
  color: #7d8796;
  font-size: 8px;
  font-weight: 700;
  letter-spacing: 0.08em;
  text-transform: uppercase;
}

.channel-work-update strong {
  color: #dce1e8;
  font-size: 11px;
}

.channel-work-update > span:nth-child(2) > span {
  overflow: hidden;
  color: #858f9f;
  font-size: 9px;
  text-overflow: ellipsis;
  white-space: nowrap;
}

.channel-work-update em {
  color: #c39fbe;
  font-size: 9px;
  font-style: normal;
  font-weight: 650;
  white-space: nowrap;
}

.channel-thread-summary {
  display: flex;
  width: fit-content;
  min-height: 30px;
  align-items: center;
  gap: 9px;
  margin-top: 9px;
  padding: 3px 8px 3px 3px;
  border-radius: 9px;
  background: transparent;
  color: #8b95a5;
  cursor: pointer;
  transition: background 160ms ease;
}

.channel-thread-summary:hover,
.channel-thread-summary:focus-visible {
  outline: 0;
  background: #343b47;
}

.channel-thread-summary strong {
  color: #b993b6;
  font-size: 10px;
}

.channel-thread-summary > span:last-child {
  font-size: 9px;
}

.channel-thread-avatars {
  display: flex;
  align-items: center;
}

.channel-thread-avatars img {
  width: 22px;
  height: 22px;
  margin-left: -5px;
  border: 2px solid #303744;
  border-radius: 50%;
  object-fit: cover;
}

.channel-thread-avatars img:first-child {
  margin-left: 0;
}

.channel-reactions {
  display: flex;
  flex-wrap: wrap;
  gap: 6px;
  margin-top: 8px;
}

.channel-reactions button {
  display: flex;
  height: 27px;
  align-items: center;
  gap: 5px;
  padding: 0 8px;
  border-radius: 999px;
  background: #343b47;
  color: #909aa9;
  cursor: pointer;
  transition: background 160ms ease, color 160ms ease;
}

.channel-reactions button:hover,
.channel-reactions button:focus-visible,
.channel-reactions button.is-active {
  outline: 0;
  background: rgb(180 142 173 / 18%);
  color: #d0a8cd;
}

.channel-message-actions {
  position: absolute;
  top: -8px;
  right: 10px;
  display: flex;
  padding: 4px;
  border-radius: 10px;
  background: #353d49;
  box-shadow: 0 8px 20px rgb(8 11 16 / 12%);
  opacity: 0;
  pointer-events: none;
  transform: translateY(3px);
  transition: opacity 150ms ease, transform 150ms ease;
}

.channel-message:hover .channel-message-actions,
.channel-message:focus-within .channel-message-actions {
  opacity: 1;
  pointer-events: auto;
  transform: translateY(0);
}

.channel-message-actions button,
.channel-composer-tools button {
  display: grid;
  width: 30px;
  height: 30px;
  place-items: center;
  border-radius: 7px;
  background: transparent;
  color: #8993a2;
  cursor: pointer;
}

.channel-message-actions button:hover,
.channel-message-actions button:focus-visible,
.channel-composer-tools button:hover,
.channel-composer-tools button:focus-visible,
.channel-composer-tools button.is-listening {
  outline: 0;
  background: rgb(180 142 173 / 13%);
  color: #d0a8cd;
}

.channel-composer {
  position: relative;
  z-index: 6;
  display: flex;
  min-height: 96px;
  flex: 0 0 auto;
  flex-direction: column;
  margin: 0 24px 20px;
  border-radius: 13px;
  background: #272e38;
  box-shadow: 0 8px 22px rgb(8 11 16 / 7%);
}

.channel-composer textarea,
.channel-thread-composer textarea {
  width: 100%;
  min-height: 57px;
  resize: none;
  padding: 14px 15px 8px;
  border: 0;
  outline: 0;
  background: transparent;
  color: #d9dee6;
  font: 500 12px/1.5 Inter, ui-sans-serif, sans-serif;
}

.channel-composer textarea::placeholder,
.channel-thread-composer textarea::placeholder {
  color: #727d8d;
}

.channel-composer-toolbar {
  display: flex;
  min-height: 38px;
  align-items: center;
  justify-content: space-between;
  gap: 10px;
  padding: 0 9px 8px;
}

.channel-composer-tools {
  display: flex;
  align-items: center;
  gap: 2px;
}

.channel-listening-label {
  margin-left: 4px;
  color: #c49fbe;
  font-size: 9px;
  font-weight: 650;
}

.channel-send-button,
.channel-thread-composer button {
  display: grid;
  width: 32px;
  height: 32px;
  place-items: center;
  border-radius: 8px;
  background: #b48ead;
  color: #20252d;
  cursor: pointer;
  transition: background 160ms ease, opacity 160ms ease;
}

.channel-send-button:disabled,
.channel-thread-composer button:disabled {
  background: #343c48;
  color: #697484;
  cursor: default;
  opacity: 0.75;
}

.channel-send-button:not(:disabled):hover,
.channel-send-button:not(:disabled):focus-visible,
.channel-thread-composer button:not(:disabled):hover,
.channel-thread-composer button:not(:disabled):focus-visible {
  outline: 0;
  background: #c69fc0;
}

.channel-thread {
  background: #2c333f;
}

.channel-thread-resize-handle {
  position: relative;
  z-index: 22;
  display: grid;
  width: 8px;
  height: 100%;
  margin-left: -4px;
  place-items: center;
  outline: 0;
  background: transparent;
  cursor: col-resize;
  touch-action: none;
}

.channel-thread-resize-handle > span {
  width: 2px;
  height: 54px;
  border-radius: 999px;
  background: #424b58;
  transition: height 140ms ease, background 140ms ease;
}

.channel-thread-resize-handle:hover > span,
.channel-thread-resize-handle:focus-visible > span,
.channel-page--thread-resizing .channel-thread-resize-handle > span {
  height: 82px;
  background: #c49ac0;
}

.channel-page--thread-resizing,
.channel-page--thread-resizing * {
  cursor: col-resize !important;
  user-select: none !important;
}

.channel-thread-header {
  display: flex;
  min-height: 70px;
  flex: 0 0 70px;
  align-items: center;
  justify-content: space-between;
  gap: 12px;
  padding: 0 16px 0 19px;
}

.channel-thread-header > div {
  display: flex;
  align-items: baseline;
  gap: 8px;
}

.channel-thread-header strong {
  color: #e5e9ef;
  font-size: 14px;
}

.channel-thread-header span {
  color: #7d8796;
  font-size: 10px;
}

.channel-thread-header button {
  width: 31px;
  height: 31px;
  background: transparent;
}

.channel-thread-list {
  flex: 1;
  min-height: 0;
  overflow-y: auto;
  padding: 4px 18px 18px;
}

.channel-thread-root,
.channel-thread-reply {
  display: grid;
  grid-template-columns: 34px minmax(0, 1fr);
  gap: 10px;
  padding: 9px 0 13px;
}

.channel-thread-root--self,
.channel-thread-reply--self {
  margin: 0 -8px;
  padding-right: 8px;
  padding-left: 8px;
  border-radius: 9px;
  background: linear-gradient(90deg, rgb(180 142 173 / 11%), rgb(180 142 173 / 3%) 72%, transparent);
}

.channel-thread-root > img,
.channel-thread-reply > img {
  width: 34px;
  height: 34px;
  border-radius: 50%;
  object-fit: cover;
}

.channel-thread-root p,
.channel-thread-reply p {
  font-size: 11px;
}

.channel-thread-count {
  display: flex;
  align-items: center;
  gap: 10px;
  margin: 3px 0 5px 44px;
  color: #7c8797;
  font-size: 9px;
}

.channel-thread-reply header {
  flex-wrap: wrap;
  gap: 5px 7px;
}

.channel-thread-reply header > span {
  color: #977c96;
  font-size: 8px;
}

.channel-thread-composer {
  display: flex;
  min-height: 102px;
  flex: 0 0 auto;
  flex-direction: column;
  margin: 0 14px 15px;
  border-radius: 12px;
  background: #252c36;
}

.channel-thread-composer > div {
  display: flex;
  min-height: 38px;
  align-items: center;
  justify-content: space-between;
  gap: 10px;
  padding: 0 8px 8px 13px;
}

.channel-thread-composer > div > span {
  overflow: hidden;
  color: #737e8e;
  font-size: 9px;
  text-overflow: ellipsis;
  white-space: nowrap;
}

.channel-thread-composer button {
  width: 30px;
  height: 30px;
}

@media (max-width: 1100px) {
  .channel-page--thread-open {
    grid-template-columns: minmax(420px, 1fr) 0 var(--channel-thread-width, 330px);
  }

  .channel-identity p {
    max-width: 340px;
  }

  .channel-work-update {
    grid-template-columns: 36px minmax(0, 1fr);
  }

  .channel-work-update em {
    display: none;
  }
}

@media (max-width: 900px) {
  .channel-page {
    position: relative;
    display: block;
  }

  .channel-main {
    width: 100%;
    height: 100%;
  }

  .channel-page-header {
    min-height: 68px;
    flex-basis: 68px;
    padding: 0 14px 0 58px;
  }

  .channel-identity p,
  .channel-header-button:nth-of-type(2) {
    display: none;
  }

  .channel-thread {
    position: absolute;
    z-index: 20;
    inset: 0;
    width: 100%;
    height: 100%;
  }

  .channel-thread-resize-handle {
    display: none;
  }

  .channel-thread-header {
    min-height: 60px;
    flex-basis: 60px;
    padding-left: 58px;
  }
}

@media (max-width: 620px) {
  .channel-page-header {
    gap: 10px;
  }

  .channel-private-label,
  .channel-member-button > span:last-child,
  .channel-huddle-shell {
    display: none;
  }

  .channel-member-button {
    min-width: 56px;
    padding: 0 6px;
  }

  .channel-message-list {
    padding-right: 14px;
    padding-left: 14px;
  }

  .channel-message {
    grid-template-columns: 35px minmax(0, 1fr);
    gap: 10px;
  }

  .channel-message-avatar {
    width: 35px;
    height: 35px;
  }

  .channel-message-actions {
    display: none;
  }

  .channel-composer {
    margin-right: 12px;
    margin-bottom: 12px;
    margin-left: 12px;
  }

  .channel-composer-tools button:nth-child(4) {
    display: none;
  }

  .channel-work-update {
    min-height: 68px;
  }

  .channel-thread-summary > span:last-child {
    display: none;
  }
}
</style>
