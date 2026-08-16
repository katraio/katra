<script setup lang="ts">
import {
  AtSign,
  Bot,
  CalendarClock,
  Check,
  ChevronDown,
  Code2,
  Ellipsis,
  ExternalLink,
  Heart,
  Headphones,
  Info,
  Mic,
  Paperclip,
  SendHorizontal,
  Smile,
  ThumbsUp,
  UserRound,
  Workflow,
} from "@lucide/vue";
import { computed, nextTick, onMounted, ref, watch, type Component } from "vue";
import HuddleMeeting from "../meetings/HuddleMeeting.vue";
import MeetingScheduleDialog, { type MeetingParticipant } from "../meetings/MeetingScheduleDialog.vue";
import MarkdownMessage from "./MarkdownMessage.vue";

type Reaction = {
  id: string;
  label: string;
  icon: Component;
  count: number;
};

type DirectMessage = {
  id: string;
  author: string;
  time: string;
  body: string;
  reactions?: Reaction[];
};

type AgentDefinition = {
  id: string;
  name: string;
  role: string;
  avatar: string;
  status: "online" | "away";
  statusLabel: string;
  purpose: string;
  guardrail: string;
  currentWork: {
    title: string;
    path: string;
    stage: string;
    summary: string;
  };
  messages: DirectMessage[];
};

const props = defineProps<{
  destinationId: string;
}>();

const userAvatar = "/brand/icon.svg";

const reactions = {
  approval: { id: "approval", label: "Approve", icon: ThumbsUp, count: 2 },
  love: { id: "love", label: "Love", icon: Heart, count: 1 },
  done: { id: "done", label: "Done", icon: Check, count: 2 },
};

const agents: Record<string, AgentDefinition> = {
  katra: {
    id: "katra",
    name: "Katra",
    role: "Coordinator",
    avatar: "/avatars/katra.png",
    status: "online",
    statusLabel: "Coordinating active work",
    purpose: "Keeps attention, projects, agents, and workflows connected without taking approval away from the team.",
    guardrail: "Routes and summarizes work, but never accepts agent output or changes project guidance without human direction.",
    currentWork: {
      title: "Channel experience review",
      path: "DevOption › Katra › Client",
      stage: "Review",
      summary: "Collecting the interface decisions that should guide channels, direct messages, and the eventual collaboration model.",
    },
    messages: [
      {
        id: "katra-1",
        author: "morgan",
        time: "9:08 AM",
        body: "Keep an eye on the interface decisions as we work. I want the UX to guide the platform, but I do not want mock interactions quietly turning into architecture commitments.",
        reactions: [{ ...reactions.approval }],
      },
      {
        id: "katra-2",
        author: "Katra",
        time: "9:11 AM",
        body: "Understood. I’m separating approved interface conventions from open product hypotheses and routing each decision back to its project context.",
        reactions: [{ ...reactions.done }],
      },
      {
        id: "katra-3",
        author: "Katra",
        time: "11:48 AM",
        body: "The channel layout now has a stable conversation surface, explicit authorship treatment, resizable threads, and quieter scroll behavior. Direct messages can reuse that language while exposing the selected agent’s current assignment.",
      },
    ],
  },
  artisan: {
    id: "artisan",
    name: "Artisan",
    role: "Engineering Agent",
    avatar: "/avatars/artisan.png",
    status: "online",
    statusLabel: "Available",
    purpose: "Implements approved work inside prepared workspaces and explains technical decisions before handoff.",
    guardrail: "Does not merge, deploy, or broaden scope without the workflow’s explicit approval gate.",
    currentWork: {
      title: "Data export endpoint",
      path: "Northstar Goods › ERP › Laravel App",
      stage: "Implementation",
      summary: "Building the approved export path and preparing tests for the next security review.",
    },
    messages: [
      {
        id: "artisan-1",
        author: "Artisan",
        time: "8:46 AM",
        body: "The export endpoint is isolated behind the existing authorization policy. Before I finish the query path, I need your decision on whether archived records belong in the default export.",
      },
      {
        id: "artisan-2",
        author: "morgan",
        time: "8:53 AM",
        body: "Exclude archived records by default. Add an explicit option for authorized users who need the complete history.",
        reactions: [{ ...reactions.approval, count: 1 }],
      },
      {
        id: "artisan-3",
        author: "Artisan",
        time: "9:02 AM",
        body: "Got it. I updated the implementation plan and acceptance tests. I’ll hand the completed change to Sentinel before anything is presented for final approval.",
        reactions: [{ ...reactions.done, count: 1 }],
      },
    ],
  },
  atlas: {
    id: "atlas",
    name: "Atlas",
    role: "Documentation Agent",
    avatar: "/avatars/atlas.png",
    status: "online",
    statusLabel: "Updating product guidance",
    purpose: "Turns approved decisions into clear product, architecture, and operating documentation.",
    guardrail: "Exploratory ideas remain explicitly provisional until a human approves them as product or architecture guidance.",
    currentWork: {
      title: "Collaboration UX decision log",
      path: "DevOption › Katra › Client",
      stage: "Documentation",
      summary: "Capturing the approved shell, Inbox, workflow, channel, and direct-message conventions from this design pass.",
    },
    messages: [
      {
        id: "atlas-1",
        author: "Atlas",
        time: "10:18 AM",
        body: "I have the approved navigation, Inbox ordering, and workflow-control decisions captured. I’m keeping the collaboration notes separate until the channel and DM views settle.",
      },
      {
        id: "atlas-2",
        author: "morgan",
        time: "10:25 AM",
        body: "Perfect. Document the intention behind the interface, not every temporary mock detail.",
        reactions: [{ ...reactions.love }],
      },
      {
        id: "atlas-3",
        author: "Atlas",
        time: "10:31 AM",
        body: "Agreed. The notes will explain the stable interaction rules, the problems they solve, and which decisions are still deliberately open.",
      },
    ],
  },
  envoy: {
    id: "envoy",
    name: "Envoy",
    role: "Sales Agent",
    avatar: "/avatars/envoy.png",
    status: "online",
    statusLabel: "Preparing an internal brief",
    purpose: "Supports the human sales team with research, qualification, discovery preparation, and sales-to-delivery handoffs.",
    guardrail: "Internal assistant only. Envoy never contacts prospects, sends material, commits pricing, or advances a deal stage.",
    currentWork: {
      title: "FinServ discovery preparation",
      path: "DevOption › Sales › FinServ",
      stage: "Internal review",
      summary: "Organizing account evidence, likely operational pain, discovery questions, and the recommendation for the human sales team.",
    },
    messages: [
      {
        id: "envoy-1",
        author: "Envoy",
        time: "9:38 AM",
        body: "The FinServ brief is ready for internal review. I found evidence of a fragmented request process, unclear delivery ownership, and manual compliance reporting. No customer contact was made.",
      },
      {
        id: "envoy-2",
        author: "morgan",
        time: "9:45 AM",
        body: "Good. Lead with their operating problems and the questions we still need answered. Do not turn it into a packaged pitch.",
        reactions: [{ ...reactions.approval }],
      },
      {
        id: "envoy-3",
        author: "Envoy",
        time: "9:52 AM",
        body: "Updated. The brief now separates verified evidence, working hypotheses, and questions for the sales team to ask during discovery.",
        reactions: [{ ...reactions.done }],
      },
    ],
  },
  sentinel: {
    id: "sentinel",
    name: "Sentinel",
    role: "Security Agent",
    avatar: "/avatars/sentinel.png",
    status: "online",
    statusLabel: "Waiting for your review",
    purpose: "Reviews code, dependencies, and delivery changes, then presents evidence and proposed resolutions for individual approval.",
    guardrail: "Findings and patches remain advisory until a human accepts them or sends specific feedback.",
    currentWork: {
      title: "Katra client security findings",
      path: "DevOption › Katra › Client",
      stage: "Human approval",
      summary: "Five findings are waiting in Inbox with evidence, proposed resolutions, and finding-level approval controls.",
    },
    messages: [
      {
        id: "sentinel-1",
        author: "Sentinel",
        time: "10:14 AM",
        body: "The current review is complete. Two high, two medium, and one low finding remain. Each finding now carries its own evidence, resolution, feedback, and approval state.",
      },
      {
        id: "sentinel-2",
        author: "morgan",
        time: "10:20 AM",
        body: "That is the review model I want. Keep the findings independent so one disputed recommendation does not block the decisions I am ready to make.",
        reactions: [{ ...reactions.approval, count: 3 }],
      },
      {
        id: "sentinel-3",
        author: "Sentinel",
        time: "10:27 AM",
        body: "Confirmed. No findings or patches have been accepted automatically. The task remains at the human approval gate.",
      },
    ],
  },
  vector: {
    id: "vector",
    name: "Vector",
    role: "Platform Agent",
    avatar: "/avatars/vector.png",
    status: "away",
    statusLabel: "Running platform checks",
    purpose: "Prepares workspaces, verifies runtime health, and automates repeatable platform and delivery operations.",
    guardrail: "Environment changes remain constrained to the workflow’s prepared workspace and approved delivery boundary.",
    currentWork: {
      title: "Workspace readiness check",
      path: "DevOption › Katra › Server",
      stage: "Platform checks",
      summary: "Validating the repository worktree, environment dependencies, and handoff metadata before Artisan begins implementation.",
    },
    messages: [
      {
        id: "vector-1",
        author: "Vector",
        time: "8:12 AM",
        body: "The server workspace is prepared from the requested branch. Dependencies are healthy and the handoff manifest includes repository state, environment metadata, and the approval context.",
      },
      {
        id: "vector-2",
        author: "morgan",
        time: "8:18 AM",
        body: "Hold the workspace until the scope question is answered. I do not want implementation to begin with an ambiguous authorization boundary.",
      },
      {
        id: "vector-3",
        author: "Vector",
        time: "8:20 AM",
        body: "Workspace held. The environment will stay ready, but no agent will be nudged until Katra receives the approved scope.",
        reactions: [{ ...reactions.done }],
      },
    ],
  },
};

const activeAgentKey = computed(() => props.destinationId.replace(/^dm-/, ""));
const agent = computed(() => agents[activeAgentKey.value] ?? agents.katra);
const customMessages = ref<Record<string, DirectMessage[]>>({});
const draft = ref("");
const contextOpen = ref(false);
const moreMenuOpen = ref(false);
const huddleMenuOpen = ref(false);
const huddleOpen = ref(false);
const meetingSchedulerOpen = ref(false);
const meetingScheduleNotice = ref("");
const voiceListening = ref(false);
const selectedReactions = ref<Record<string, boolean>>({});
const messageList = ref<HTMLElement | null>(null);

const allMessages = computed(() => [
  ...agent.value.messages,
  ...(customMessages.value[agent.value.id] ?? []),
]);

const huddleParticipants = computed<MeetingParticipant[]>(() => [
  { name: "morgan", role: "DevOption", avatar: userAvatar },
  { name: agent.value.name, role: agent.value.role, avatar: agent.value.avatar },
]);

function startDirectMeeting() {
  huddleMenuOpen.value = false;
  moreMenuOpen.value = false;
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

function reactionCount(message: DirectMessage, reaction: Reaction) {
  return reaction.count + (selectedReactions.value[`${message.id}:${reaction.id}`] ? 1 : 0);
}

function toggleReaction(message: DirectMessage, reaction: Reaction) {
  const key = `${message.id}:${reaction.id}`;
  selectedReactions.value[key] = !selectedReactions.value[key];
}

function addQuickReaction(message: DirectMessage) {
  const existing = message.reactions?.find((reaction) => reaction.id === "approval");

  if (existing) {
    toggleReaction(message, existing);
    return;
  }

  message.reactions = [{ ...reactions.approval, count: 0 }];
  toggleReaction(message, message.reactions[0]);
}

function sendMessage() {
  const body = draft.value.trim();

  if (!body) {
    return;
  }

  const message: DirectMessage = {
    id: `${agent.value.id}-${Date.now()}`,
    author: "morgan",
    time: "Just now",
    body,
  };

  customMessages.value[agent.value.id] = [
    ...(customMessages.value[agent.value.id] ?? []),
    message,
  ];
  draft.value = "";
  nextTick(() => messageList.value?.scrollTo({ top: messageList.value.scrollHeight, behavior: "smooth" }));
}

function handleComposerKeydown(event: KeyboardEvent) {
  if (event.key === "Enter" && !event.shiftKey) {
    event.preventDefault();
    sendMessage();
  }
}

function toggleVoiceInput() {
  if (voiceListening.value) {
    voiceListening.value = false;
    draft.value = `${draft.value}${draft.value ? " " : ""}Let’s keep that attached to the current assignment.`;
    return;
  }

  voiceListening.value = true;
}

watch(activeAgentKey, () => {
  draft.value = "";
  contextOpen.value = false;
  moreMenuOpen.value = false;
  huddleMenuOpen.value = false;
  huddleOpen.value = false;
  meetingSchedulerOpen.value = false;
  voiceListening.value = false;
  nextTick(() => messageList.value?.scrollTo({ top: messageList.value.scrollHeight }));
});

onMounted(() => {
  nextTick(() => messageList.value?.scrollTo({ top: messageList.value.scrollHeight }));
});
</script>

<template>
  <section class="dm-page" :aria-label="`Direct message with ${agent.name}`">
    <header class="dm-header">
      <div class="dm-identity">
        <span class="dm-identity-avatar">
          <img :src="agent.avatar" :alt="`${agent.name} avatar`" />
          <span :class="`is-${agent.status}`" />
        </span>
        <div>
          <div class="dm-name-row">
            <h1>{{ agent.name }}</h1>
            <span class="dm-agent-label">
              <Bot :size="11" :stroke-width="2" aria-hidden="true" />
              Agent
            </span>
          </div>
          <p>{{ agent.role }} <span>·</span> {{ agent.statusLabel }}</p>
        </div>
      </div>

      <div class="dm-header-actions">
        <div class="dm-huddle-shell">
          <button type="button" aria-label="Start a meeting with this conversation" @click="startDirectMeeting"><Headphones :size="18" aria-hidden="true" /></button>
          <button type="button" aria-label="Meeting options" aria-haspopup="menu" :aria-expanded="huddleMenuOpen" @click="huddleMenuOpen = !huddleMenuOpen"><ChevronDown :size="14" aria-hidden="true" /></button>
          <div v-if="huddleMenuOpen" class="dm-more-menu dm-huddle-menu" role="menu">
            <button type="button" role="menuitem" @click="startDirectMeeting"><Headphones :size="16" aria-hidden="true" /><span><strong>Start meeting now</strong><small>Invite {{ agent.name }} immediately</small></span></button>
            <button type="button" role="menuitem" @click="openMeetingScheduler"><CalendarClock :size="16" aria-hidden="true" /><span><strong>Schedule meeting</strong><small>One time or recurring</small></span></button>
          </div>
        </div>
        <button type="button" aria-label="View agent context" :aria-expanded="contextOpen" @click="contextOpen = !contextOpen">
          <Info :size="18" :stroke-width="1.8" aria-hidden="true" />
        </button>
        <div class="dm-more-shell">
          <button type="button" aria-label="Conversation options" :aria-expanded="moreMenuOpen" @click="moreMenuOpen = !moreMenuOpen">
            <Ellipsis :size="19" :stroke-width="1.9" aria-hidden="true" />
          </button>
          <div v-if="moreMenuOpen" class="dm-more-menu" role="menu">
            <button type="button" role="menuitem" @click="contextOpen = true; moreMenuOpen = false">
              <UserRound :size="16" :stroke-width="1.8" aria-hidden="true" />
              View agent context
            </button>
          </div>
        </div>
      </div>
    </header>

    <section class="dm-context" :class="{ 'dm-context--open': contextOpen }" aria-label="Current agent context">
      <button class="dm-context-trigger" type="button" :aria-expanded="contextOpen" @click="contextOpen = !contextOpen">
        <span class="dm-context-icon">
          <Workflow :size="18" :stroke-width="1.8" aria-hidden="true" />
        </span>
        <span class="dm-context-copy">
          <small>Current focus</small>
          <strong>{{ agent.currentWork.title }}</strong>
          <span>{{ agent.currentWork.path }}</span>
        </span>
        <span class="dm-context-stage">{{ agent.currentWork.stage }}</span>
        <ChevronDown :class="{ 'is-open': contextOpen }" :size="17" :stroke-width="1.8" aria-hidden="true" />
      </button>

      <div v-if="contextOpen" class="dm-context-details">
        <div>
          <small>Assignment</small>
          <p>{{ agent.currentWork.summary }}</p>
        </div>
        <div>
          <small>Operating boundary</small>
          <p>{{ agent.guardrail }}</p>
        </div>
        <div class="dm-context-purpose">
          <small>Agent purpose</small>
          <p>{{ agent.purpose }}</p>
        </div>
        <div class="dm-context-actions">
          <button type="button">
            Open task
            <ExternalLink :size="14" :stroke-width="1.8" aria-hidden="true" />
          </button>
          <button type="button">
            Open agent
            <ExternalLink :size="14" :stroke-width="1.8" aria-hidden="true" />
          </button>
        </div>
      </div>
    </section>

    <div ref="messageList" class="dm-message-list" aria-live="polite">
      <div class="dm-date-marker"><span>Today</span></div>

      <article
        v-for="message in allMessages"
        :key="message.id"
        class="dm-message"
        :class="{ 'dm-message--self': message.author === 'morgan' }"
      >
        <img
          class="dm-message-avatar"
          :src="message.author === 'morgan' ? userAvatar : agent.avatar"
          :alt="`${message.author} avatar`"
        />
        <div class="dm-message-content">
          <header>
            <strong>{{ message.author }}</strong>
            <span v-if="message.author === 'morgan'" class="dm-self-label">You</span>
            <span v-else class="dm-role-label">{{ agent.role }}</span>
            <time>{{ message.time }}</time>
          </header>
          <MarkdownMessage :body="message.body" />
          <div v-if="message.reactions?.length" class="dm-reactions">
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
        <div class="dm-message-actions" aria-label="Message actions">
          <button type="button" aria-label="Add reaction" @click="addQuickReaction(message)">
            <Smile :size="16" :stroke-width="1.8" aria-hidden="true" />
          </button>
          <button type="button" aria-label="More message actions">
            <Ellipsis :size="17" :stroke-width="1.9" aria-hidden="true" />
          </button>
        </div>
      </article>
    </div>

    <form class="dm-composer" @submit.prevent="sendMessage">
      <textarea
        v-model="draft"
        rows="2"
        :placeholder="`Message ${agent.name}`"
        :aria-label="`Message ${agent.name}`"
        @keydown="handleComposerKeydown"
      />
      <div class="dm-composer-toolbar">
        <div class="dm-composer-tools">
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
          <span v-if="voiceListening" class="dm-listening-label">Listening…</span>
        </div>
        <button class="dm-send-button" type="submit" :disabled="!draft.trim()" aria-label="Send message">
          <SendHorizontal :size="17" :stroke-width="1.9" aria-hidden="true" />
        </button>
      </div>
    </form>

    <p v-if="meetingScheduleNotice" class="dm-meeting-notice" role="status">{{ meetingScheduleNotice }}</p>
    <MeetingScheduleDialog v-if="meetingSchedulerOpen" :default-title="`Meeting with ${agent.name}`" :audience-label="`Direct message with ${agent.name}`" :participants="huddleParticipants" @close="meetingSchedulerOpen = false" @scheduled="handleMeetingScheduled" />
    <HuddleMeeting v-if="huddleOpen" :title="`Meeting with ${agent.name}`" :subtitle="`Direct message with ${agent.name}`" :participants="huddleParticipants" @minimize="huddleOpen = false" @leave="huddleOpen = false" />
  </section>
</template>

<style scoped>
.dm-page {
  position: relative;
  display: flex;
  width: 100%;
  height: 100%;
  min-width: 0;
  min-height: 0;
  flex-direction: column;
  overflow: hidden;
  background: #303744;
  color: #dce1e9;
}

.dm-header {
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

.dm-identity,
.dm-name-row,
.dm-header-actions,
.dm-composer-tools,
.dm-reactions,
.dm-message-content > header {
  display: flex;
  align-items: center;
}

.dm-identity {
  min-width: 0;
  gap: 12px;
}

.dm-identity-avatar {
  position: relative;
  width: 43px;
  height: 43px;
  flex: 0 0 43px;
}

.dm-identity-avatar img {
  width: 100%;
  height: 100%;
  border-radius: 50%;
  object-fit: cover;
}

.dm-identity-avatar > span {
  position: absolute;
  right: -1px;
  bottom: 0;
  width: 11px;
  height: 11px;
  border: 2px solid #303744;
  border-radius: 50%;
}

.dm-identity-avatar > span.is-online { background: #55c997; }
.dm-identity-avatar > span.is-away { background: #d2ae62; }
.dm-identity > div { min-width: 0; }
.dm-name-row { min-width: 0; gap: 8px; }

.dm-name-row h1 {
  margin: 0;
  overflow: hidden;
  color: #eef1f5;
  font-size: 17px;
  font-weight: 720;
  text-overflow: ellipsis;
  white-space: nowrap;
}

.dm-agent-label,
.dm-role-label,
.dm-self-label {
  display: inline-flex;
  align-items: center;
  gap: 4px;
  padding: 3px 7px;
  border-radius: 999px;
  background: rgb(180 142 173 / 13%);
  color: #c29cbe;
  font-size: 8px;
  font-weight: 700;
}

.dm-identity p {
  margin: 5px 0 0;
  overflow: hidden;
  color: #8791a0;
  font-size: 10px;
  text-overflow: ellipsis;
  white-space: nowrap;
}

.dm-identity p span { color: #5f6978; }
.dm-header-actions { flex: 0 0 auto; gap: 7px; }
.dm-more-shell { position: relative; }
.dm-huddle-shell { position: relative; display: flex; align-items: stretch; border-radius: 9px; background: #343b47; }

.dm-huddle-shell > button {
  display: grid;
  width: 34px;
  height: 34px;
  place-items: center;
  border-radius: 9px 0 0 9px;
  background: transparent;
  color: #96a0af;
  cursor: pointer;
}

.dm-huddle-shell > button:nth-child(2) {
  width: 24px;
  border-radius: 0 9px 9px 0;
}

.dm-huddle-shell:hover,
.dm-huddle-shell:focus-within { background: #3c4452; }
.dm-huddle-shell > button:hover,
.dm-huddle-shell > button:focus-visible { outline: 0; color: #eef1f5; }

.dm-more-menu.dm-huddle-menu { width: 250px; }
.dm-huddle-menu button { height: auto; min-height: 50px; }
.dm-huddle-menu button > span { display: grid; gap: 3px; }
.dm-huddle-menu strong { color: #e8ebf0; font-size: 10px; }
.dm-huddle-menu small { color: #8792a0; font-size: 8px; }

.dm-meeting-notice {
  position: absolute;
  z-index: 75;
  top: 76px;
  right: 18px;
  max-width: 350px;
  margin: 0;
  padding: 10px 12px;
  border-radius: 8px;
  background: #46505d;
  color: #e6eaf0;
  box-shadow: 0 12px 28px rgb(5 8 12 / 28%);
  font-size: 9px;
}

.dm-header-actions > button,
.dm-more-shell > button {
  display: grid;
  width: 34px;
  height: 34px;
  place-items: center;
  border-radius: 9px;
  background: #343b47;
  color: #96a0af;
  cursor: pointer;
  transition: background 180ms ease, color 180ms ease;
}

.dm-header-actions > button:hover,
.dm-header-actions > button:focus-visible,
.dm-more-shell > button:hover,
.dm-more-shell > button:focus-visible {
  outline: 0;
  background: #3c4452;
  color: #e0e4eb;
}

.dm-more-menu {
  position: absolute;
  z-index: 30;
  top: calc(100% + 8px);
  right: 0;
  display: grid;
  width: 190px;
  gap: 3px;
  padding: 7px;
  border-radius: 11px;
  background: #39414e;
  box-shadow: 0 14px 34px rgb(8 11 16 / 22%);
}

.dm-more-menu button {
  display: flex;
  min-height: 36px;
  align-items: center;
  gap: 9px;
  padding: 0 10px;
  border-radius: 8px;
  background: transparent;
  color: #c6cdd7;
  text-align: left;
  cursor: pointer;
}

.dm-more-menu button:hover,
.dm-more-menu button:focus-visible { outline: 0; background: #454e5d; }

.dm-context {
  position: relative;
  z-index: 4;
  flex: 0 0 auto;
  margin: 0 16px;
  border-radius: 12px;
  background: #2b323d;
}

.dm-context-trigger {
  display: grid;
  width: 100%;
  min-height: 56px;
  grid-template-columns: 34px minmax(0, 1fr) auto 22px;
  align-items: center;
  gap: 11px;
  padding: 8px 12px;
  border-radius: 12px;
  background: transparent;
  color: #aab3c0;
  text-align: left;
  cursor: pointer;
}

.dm-context-trigger:hover,
.dm-context-trigger:focus-visible { outline: 0; background: #323a46; }

.dm-context-icon {
  display: grid;
  width: 34px;
  height: 34px;
  place-items: center;
  border-radius: 9px;
  background: rgb(180 142 173 / 14%);
  color: #c29dbe;
}

.dm-context-copy {
  display: grid;
  min-width: 0;
  grid-template-columns: auto minmax(0, 1fr);
  gap: 3px 10px;
}

.dm-context-copy small {
  grid-column: 1 / -1;
  color: #7d8796;
  font-size: 8px;
  font-weight: 700;
  letter-spacing: 0.08em;
  text-transform: uppercase;
}

.dm-context-copy strong {
  overflow: hidden;
  color: #dce1e8;
  font-size: 11px;
  text-overflow: ellipsis;
  white-space: nowrap;
}

.dm-context-copy > span {
  overflow: hidden;
  color: #7f8998;
  font-size: 9px;
  text-overflow: ellipsis;
  white-space: nowrap;
}

.dm-context-stage {
  padding: 5px 8px;
  border-radius: 999px;
  background: rgb(180 142 173 / 12%);
  color: #c49fbe;
  font-size: 8px;
  font-weight: 700;
  white-space: nowrap;
}

.dm-context-trigger > svg { color: #7e8897; transition: transform 180ms ease; }
.dm-context-trigger > svg.is-open { transform: rotate(180deg); }

.dm-context-details {
  display: grid;
  grid-template-columns: repeat(3, minmax(0, 1fr)) auto;
  gap: 18px;
  padding: 2px 16px 16px 57px;
}

.dm-context-details > div { min-width: 0; }
.dm-context-details small { color: #7f8998; font-size: 8px; font-weight: 700; letter-spacing: .07em; text-transform: uppercase; }
.dm-context-details p { margin: 6px 0 0; color: #aab4c1; font-size: 10px; line-height: 1.5; }
.dm-context-actions { display: flex; align-items: end; gap: 7px; }

.dm-context-actions button {
  display: flex;
  min-height: 32px;
  align-items: center;
  gap: 7px;
  padding: 0 10px;
  border-radius: 8px;
  background: #37404c;
  color: #c4ccd6;
  font-size: 9px;
  cursor: pointer;
}

.dm-context-actions button:hover,
.dm-context-actions button:focus-visible { outline: 0; background: #414a58; color: #e1e5eb; }

.dm-message-list {
  flex: 1;
  min-height: 0;
  overflow-y: auto;
  padding: 18px 24px 22px;
}

.dm-date-marker {
  display: flex;
  align-items: center;
  justify-content: center;
  margin: 0 0 18px;
}

.dm-date-marker span {
  padding: 5px 10px;
  border-radius: 999px;
  background: #353c48;
  color: #7f8998;
  font-size: 8px;
}

.dm-message {
  position: relative;
  display: grid;
  grid-template-columns: 40px minmax(0, 1fr);
  gap: 12px;
  margin: 0 -10px;
  padding: 11px 10px 13px;
  border-radius: 10px;
  transition: background 160ms ease;
}

.dm-message + .dm-message { margin-top: 12px; }
.dm-message:hover,
.dm-message:focus-within { background: rgb(42 48 59 / 50%); }

.dm-message--self { background: linear-gradient(90deg, rgb(180 142 173 / 10%), rgb(180 142 173 / 3%) 58%, transparent 92%); }
.dm-message--self:hover,
.dm-message--self:focus-within { background: linear-gradient(90deg, rgb(180 142 173 / 15%), rgb(180 142 173 / 5%) 58%, transparent 92%); }

.dm-message-avatar {
  width: 40px;
  height: 40px;
  border-radius: 50%;
  object-fit: cover;
}

.dm-message--self > .dm-message-avatar { box-shadow: 0 0 0 2px rgb(192 151 187 / 42%); }
.dm-message-content { min-width: 0; padding-top: 1px; }
.dm-message-content > header { min-width: 0; gap: 8px; }
.dm-message-content > header strong { color: #e0e5ec; font-size: 12px; font-weight: 720; }
.dm-message-content time { color: #707b8b; font-size: 9px; }

.dm-role-label { color: #b997b6; }
.dm-self-label { background: rgb(180 142 173 / 16%); color: #c9a5c5; }

.dm-message-content > p {
  max-width: 920px;
  margin: 6px 0 0;
  color: #b6beca;
  font-size: 12px;
  line-height: 1.55;
}

.dm-reactions { flex-wrap: wrap; gap: 6px; margin-top: 8px; }
.dm-reactions button {
  display: flex;
  height: 27px;
  align-items: center;
  gap: 5px;
  padding: 0 8px;
  border-radius: 999px;
  background: #343b47;
  color: #909aa9;
  cursor: pointer;
}

.dm-reactions button:hover,
.dm-reactions button:focus-visible,
.dm-reactions button.is-active { outline: 0; background: rgb(180 142 173 / 18%); color: #d0a8cd; }

.dm-message-actions {
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

.dm-message:hover .dm-message-actions,
.dm-message:focus-within .dm-message-actions { opacity: 1; pointer-events: auto; transform: translateY(0); }

.dm-message-actions button,
.dm-composer-tools button {
  display: grid;
  width: 30px;
  height: 30px;
  place-items: center;
  border-radius: 7px;
  background: transparent;
  color: #8993a2;
  cursor: pointer;
}

.dm-message-actions button:hover,
.dm-message-actions button:focus-visible,
.dm-composer-tools button:hover,
.dm-composer-tools button:focus-visible,
.dm-composer-tools button.is-listening { outline: 0; background: #414956; color: #d5dbe4; }

.dm-composer {
  display: flex;
  min-height: 108px;
  flex: 0 0 auto;
  flex-direction: column;
  margin: 0 18px 16px;
  border-radius: 12px;
  background: #252c36;
}

.dm-composer textarea {
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

.dm-composer textarea::placeholder { color: #727d8d; }

.dm-composer-toolbar {
  display: flex;
  min-height: 38px;
  align-items: center;
  justify-content: space-between;
  gap: 10px;
  padding: 0 9px 8px;
}

.dm-composer-tools { gap: 2px; }
.dm-listening-label { margin-left: 4px; color: #c49fbe; font-size: 9px; font-weight: 650; }

.dm-send-button {
  display: grid;
  width: 32px;
  height: 32px;
  place-items: center;
  border-radius: 8px;
  background: #b48ead;
  color: #20252d;
  cursor: pointer;
}

.dm-send-button:disabled { background: #343c48; color: #697484; cursor: default; opacity: .75; }
.dm-send-button:not(:disabled):hover,
.dm-send-button:not(:disabled):focus-visible { outline: 0; background: #c69fc0; }

@media (max-width: 1100px) {
  .dm-context-details { grid-template-columns: repeat(2, minmax(0, 1fr)); }
  .dm-context-actions { align-items: center; }
}

@media (max-width: 900px) {
  .dm-header { min-height: 68px; flex-basis: 68px; padding: 0 14px 0 58px; }
  .dm-identity-avatar { width: 38px; height: 38px; flex-basis: 38px; }
  .dm-context { margin: 0 12px; }
}

@media (max-width: 680px) {
  .dm-agent-label,
  .dm-identity p,
  .dm-context-stage { display: none; }
  .dm-context-trigger { grid-template-columns: 34px minmax(0, 1fr) 20px; }
  .dm-context-details { grid-template-columns: 1fr; padding-left: 16px; }
  .dm-context-purpose { display: none; }
  .dm-message-list { padding-right: 14px; padding-left: 14px; }
  .dm-message { grid-template-columns: 35px minmax(0, 1fr); gap: 10px; }
  .dm-message-avatar { width: 35px; height: 35px; }
  .dm-message-actions { display: none; }
  .dm-composer { margin-right: 12px; margin-bottom: 12px; margin-left: 12px; }
  .dm-composer-tools button:nth-child(4) { display: none; }
}
</style>
