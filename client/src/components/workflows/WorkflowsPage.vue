<script setup lang="ts">
import {
  Archive,
  Check,
  ChevronDown,
  CircleCheckBig,
  CircleDashed,
  ClipboardList,
  ExternalLink,
  FileCode2,
  GitBranch,
  ListFilter,
  MoreVertical,
  Pause,
  PencilLine,
  Play,
  Plus,
  ScrollText,
  ShieldCheck,
  Square,
  StickyNote,
  Workflow,
  X,
} from "@lucide/vue";
import { computed, onBeforeUnmount, onMounted, ref } from "vue";
import WorkflowEditor from "./WorkflowEditor.vue";
import { isFiniteNumber, useUiPreference } from "../../composables/useUiPreference";

type RunStatus = "attention" | "waiting" | "running" | "paused" | "cancelled";
type AgentKey = "katra" | "atlas" | "artisan" | "vector" | "sentinel" | "envoy" | "system" | "human";
type MenuKind = "scope" | "status" | "actions" | null;
type ViewKind = "runs" | "definitions";
type EditorIntent = "create" | "edit";

type WorkflowRun = {
  id: string;
  name: string;
  organization: string;
  product: string;
  project: string;
  stage: string;
  stageIndex: number;
  agent: AgentKey;
  elapsed: string;
  status: RunStatus;
  startedAt: string;
  repository: string;
  worktree: string;
  branch: string;
  environment: string;
  lastSynced: string;
  base: string;
  nextSync: string;
  handoffAt: string;
  handoffNote: string;
};

type WorkflowDefinition = {
  id: string;
  name: string;
  purpose: string;
  trigger: string;
  scope: string;
  stages: number;
  activeRuns: number;
  lastRun: string;
};

const agentDirectory: Record<AgentKey, { name: string; role: string; avatar?: string }> = {
  katra: { name: "Katra", role: "Platform Coordinator", avatar: "/avatars/katra.png" },
  atlas: { name: "Atlas", role: "Documentation Agent", avatar: "/avatars/atlas.png" },
  artisan: { name: "Artisan", role: "Engineering Agent", avatar: "/avatars/artisan.png" },
  vector: { name: "Vector", role: "Platform Agent", avatar: "/avatars/vector.png" },
  sentinel: { name: "Sentinel", role: "Security Agent", avatar: "/avatars/sentinel.png" },
  envoy: { name: "Envoy", role: "Sales Assistant", avatar: "/avatars/envoy.png" },
  system: { name: "Katra Server", role: "Workspace service" },
  human: { name: "Human approval", role: "Approval gate" },
};

const stageDirectory: { label: string; owner: AgentKey; time: string; duration: string }[] = [
  { label: "Request intake", owner: "katra", time: "10:19 AM", duration: "1m 02s" },
  { label: "Plan", owner: "atlas", time: "10:20 AM", duration: "3m 18s" },
  { label: "Workspace prepared", owner: "system", time: "10:23 AM", duration: "1m 11s" },
  { label: "Implementation", owner: "artisan", time: "10:24 AM", duration: "12m 05s" },
  { label: "Platform checks", owner: "vector", time: "10:36 AM", duration: "4m 09s" },
  { label: "Security review", owner: "sentinel", time: "10:40 AM", duration: "In progress" },
  { label: "Human approval", owner: "human", time: "Pending", duration: "—" },
];

const runs = ref<WorkflowRun[]>([
  {
    id: "run-client-security",
    name: "Software Delivery",
    organization: "DevOption",
    product: "Katra",
    project: "Client",
    stage: "Security review",
    stageIndex: 5,
    agent: "sentinel",
    elapsed: "26m 14s",
    status: "attention",
    startedAt: "Aug 6, 2026 10:19 AM",
    repository: "katra/client",
    worktree: "worktrees/run-01K7M9W6",
    branch: "feature/kc-512-security-hardening",
    environment: "Staging",
    lastSynced: "Aug 6, 2026 10:40 AM (1m ago)",
    base: "origin/main (a1b2c3d)",
    nextSync: "Automatic in 1m 42s",
    handoffAt: "Aug 6, 2026 10:40 AM",
    handoffNote: "Completed static analysis and dependency scan. Found 2 medium-severity issues in auth/session handling. No high-severity issues detected. Preparing detailed findings for review.",
  },
  {
    id: "run-onboarding-api",
    name: "Security Review",
    organization: "DevOption",
    product: "Platform",
    project: "API",
    stage: "Human approval",
    stageIndex: 6,
    agent: "human",
    elapsed: "18m 46s",
    status: "waiting",
    startedAt: "Aug 6, 2026 10:26 AM",
    repository: "platform/onboarding-api",
    worktree: "worktrees/run-01K7MA1F",
    branch: "security/onboarding-api-review",
    environment: "Review",
    lastSynced: "Aug 6, 2026 10:43 AM",
    base: "origin/main (c29b8a4)",
    nextSync: "On approval",
    handoffAt: "Aug 6, 2026 10:43 AM",
    handoffNote: "Security review is complete. The run is waiting for a human release decision.",
  },
  {
    id: "run-api-guide",
    name: "Documentation Update",
    organization: "DevOption",
    product: "Platform",
    project: "Docs",
    stage: "Implementation",
    stageIndex: 3,
    agent: "artisan",
    elapsed: "14m 07s",
    status: "running",
    startedAt: "Aug 6, 2026 10:31 AM",
    repository: "platform/docs",
    worktree: "worktrees/run-01K7MA6P",
    branch: "docs/api-guide",
    environment: "Preview",
    lastSynced: "Aug 6, 2026 10:42 AM",
    base: "origin/main (77fd014)",
    nextSync: "Automatic in 2m",
    handoffAt: "Aug 6, 2026 10:34 AM",
    handoffNote: "Atlas prepared the documentation plan and handed the implementation changes to Artisan.",
  },
  {
    id: "run-finserv",
    name: "Sales Discovery Prep",
    organization: "DevOption",
    product: "Sales",
    project: "FinServ",
    stage: "Plan",
    stageIndex: 1,
    agent: "atlas",
    elapsed: "11m 52s",
    status: "running",
    startedAt: "Aug 6, 2026 10:33 AM",
    repository: "sales/briefs",
    worktree: "worktrees/run-01K7MA9S",
    branch: "briefs/finserv-discovery",
    environment: "Internal",
    lastSynced: "Aug 6, 2026 10:36 AM",
    base: "origin/main (b823cd0)",
    nextSync: "On demand",
    handoffAt: "Aug 6, 2026 10:34 AM",
    handoffNote: "Envoy gathered the source material. Atlas is structuring the internal discovery brief for the sales team.",
  },
  {
    id: "run-server",
    name: "Software Delivery",
    organization: "DevOption",
    product: "Katra",
    project: "Server",
    stage: "Platform checks",
    stageIndex: 4,
    agent: "vector",
    elapsed: "9m 31s",
    status: "running",
    startedAt: "Aug 6, 2026 10:36 AM",
    repository: "katra/server",
    worktree: "worktrees/run-01K7MAC3",
    branch: "feature/workspace-reaper",
    environment: "Staging",
    lastSynced: "Aug 6, 2026 10:42 AM",
    base: "origin/main (ef16aa1)",
    nextSync: "Automatic in 38s",
    handoffAt: "Aug 6, 2026 10:39 AM",
    handoffNote: "Implementation passed focused tests. Vector is validating the container build and staging deployment.",
  },
  {
    id: "run-auth",
    name: "Security Review",
    organization: "DevOption",
    product: "Platform",
    project: "Auth",
    stage: "Workspace prepared",
    stageIndex: 2,
    agent: "system",
    elapsed: "8m 03s",
    status: "running",
    startedAt: "Aug 6, 2026 10:37 AM",
    repository: "platform/auth",
    worktree: "worktrees/run-01K7MADF",
    branch: "review/auth-hardening",
    environment: "Isolated",
    lastSynced: "Aug 6, 2026 10:40 AM",
    base: "origin/main (92b603e)",
    nextSync: "Automatic",
    handoffAt: "Aug 6, 2026 10:39 AM",
    handoffNote: "The isolated workspace is ready and the review context is being attached.",
  },
  {
    id: "run-sdk",
    name: "Documentation Update",
    organization: "DevOption",
    product: "Platform",
    project: "SDK",
    stage: "Plan",
    stageIndex: 1,
    agent: "atlas",
    elapsed: "7m 12s",
    status: "running",
    startedAt: "Aug 6, 2026 10:38 AM",
    repository: "platform/sdk",
    worktree: "worktrees/run-01K7MAF7",
    branch: "docs/sdk-reference",
    environment: "Documentation",
    lastSynced: "Aug 6, 2026 10:40 AM",
    base: "origin/main (645bd11)",
    nextSync: "Automatic",
    handoffAt: "Aug 6, 2026 10:39 AM",
    handoffNote: "Atlas is mapping the SDK surface and the documentation gaps.",
  },
  {
    id: "run-erp",
    name: "Software Delivery",
    organization: "DevOption",
    product: "Integrations",
    project: "ERP",
    stage: "Implementation",
    stageIndex: 3,
    agent: "artisan",
    elapsed: "6m 44s",
    status: "running",
    startedAt: "Aug 6, 2026 10:39 AM",
    repository: "integrations/erp",
    worktree: "worktrees/run-01K7MAGB",
    branch: "feature/erp-sync",
    environment: "Staging",
    lastSynced: "Aug 6, 2026 10:42 AM",
    base: "origin/main (f2ba5d6)",
    nextSync: "Automatic in 1m",
    handoffAt: "Aug 6, 2026 10:41 AM",
    handoffNote: "Artisan is implementing the approved ERP synchronization plan.",
  },
  {
    id: "run-retail",
    name: "Sales Discovery Prep",
    organization: "DevOption",
    product: "Sales",
    project: "Retail",
    stage: "Request intake",
    stageIndex: 0,
    agent: "katra",
    elapsed: "5m 30s",
    status: "running",
    startedAt: "Aug 6, 2026 10:40 AM",
    repository: "sales/briefs",
    worktree: "worktrees/run-01K7MAH9",
    branch: "briefs/retail",
    environment: "Internal",
    lastSynced: "Aug 6, 2026 10:40 AM",
    base: "origin/main (b823cd0)",
    nextSync: "On demand",
    handoffAt: "Aug 6, 2026 10:40 AM",
    handoffNote: "Katra is classifying the internal request before routing it to Envoy.",
  },
  {
    id: "run-mobile",
    name: "Software Delivery",
    organization: "DevOption",
    product: "Mobile",
    project: "App",
    stage: "Plan",
    stageIndex: 1,
    agent: "atlas",
    elapsed: "4m 18s",
    status: "running",
    startedAt: "Aug 6, 2026 10:41 AM",
    repository: "katra/mobile",
    worktree: "worktrees/run-01K7MAJ2",
    branch: "plan/mobile-navigation",
    environment: "Planning",
    lastSynced: "Aug 6, 2026 10:42 AM",
    base: "origin/main (aef9057)",
    nextSync: "Automatic",
    handoffAt: "Aug 6, 2026 10:42 AM",
    handoffNote: "Atlas is preparing the mobile navigation plan and acceptance evidence.",
  },
  {
    id: "run-data",
    name: "Security Review",
    organization: "DevOption",
    product: "Data",
    project: "Pipeline",
    stage: "Implementation",
    stageIndex: 3,
    agent: "artisan",
    elapsed: "3m 47s",
    status: "running",
    startedAt: "Aug 6, 2026 10:42 AM",
    repository: "data/pipeline",
    worktree: "worktrees/run-01K7MAKQ",
    branch: "fix/pipeline-secrets",
    environment: "Isolated",
    lastSynced: "Aug 6, 2026 10:43 AM",
    base: "origin/main (0ca2ba8)",
    nextSync: "Automatic in 2m",
    handoffAt: "Aug 6, 2026 10:43 AM",
    handoffNote: "Artisan is remediating the validated pipeline secret-handling issue.",
  },
  {
    id: "run-changelog",
    name: "Documentation Update",
    organization: "DevOption",
    product: "Platform",
    project: "Docs",
    stage: "Request intake",
    stageIndex: 0,
    agent: "katra",
    elapsed: "2m 16s",
    status: "running",
    startedAt: "Aug 6, 2026 10:43 AM",
    repository: "platform/docs",
    worktree: "worktrees/run-01K7MAM8",
    branch: "docs/changelog",
    environment: "Documentation",
    lastSynced: "Aug 6, 2026 10:43 AM",
    base: "origin/main (77fd014)",
    nextSync: "Automatic",
    handoffAt: "Aug 6, 2026 10:43 AM",
    handoffNote: "Katra is attaching release evidence before routing the changelog update.",
  },
]);

const definitions = ref<WorkflowDefinition[]>([
  { id: "customer-request-review", name: "Customer Request Review", purpose: "Review incoming work, decide what moves forward, and confirm the outcome.", trigger: "Manual", scope: "All work", stages: 6, activeRuns: 0, lastRun: "Never" },
  { id: "software-delivery", name: "Software Delivery", purpose: "Move approved work from intake through validated release.", trigger: "Manual / Ready for work", scope: "Projects", stages: 7, activeRuns: 5, lastRun: "1m ago" },
  { id: "security-review", name: "Security Review", purpose: "Review changes, route remediation, and require validation.", trigger: "Manual / Repository event", scope: "Repositories", stages: 5, activeRuns: 3, lastRun: "3m ago" },
  { id: "documentation-update", name: "Documentation Update", purpose: "Turn implementation evidence into durable project knowledge.", trigger: "Release / Decision", scope: "Projects", stages: 4, activeRuns: 3, lastRun: "5m ago" },
  { id: "sales-discovery", name: "Sales Discovery Prep", purpose: "Prepare internal research and briefs for the human sales team.", trigger: "Manual / Meeting", scope: "Sales", stages: 4, activeRuns: 2, lastRun: "11m ago" },
]);

const root = ref<HTMLElement | null>(null);
const runWorkbench = ref<HTMLElement | null>(null);
const activeView = ref<ViewKind>("runs");
const selectedRunId = ref("run-client-security");
const selectedDefinitionId = ref("customer-request-review");
const editorOpen = ref(false);
const editorInitialName = ref("Customer Request Review");
const editorIntent = ref<EditorIntent>("edit");
const scopeFilter = ref("all");
const statusFilter = ref("all");
const openMenu = ref<MenuKind>(null);
const findingsOpen = ref(false);
const noteEditorOpen = ref(false);
const noteDraft = ref("");
const runNotes = ref<{ id: number; text: string; createdAt: string }[]>([]);
const notice = ref("");
let noticeTimer: number | undefined;

const defaultRunQueuePercent = 37.5;
const runQueuePercent = useUiPreference(
  "workflow-run-queue-percent",
  defaultRunQueuePercent,
  (value): value is number => isFiniteNumber(value) && value >= 28 && value <= 62,
);
const isResizingRunQueue = ref(false);
let runQueueResizePointerId: number | undefined;
let runQueueResizeHandle: HTMLElement | null = null;

const scopeOptions = [
  { id: "all", label: "All scopes" },
  { id: "DevOption", label: "DevOption" },
  { id: "Katra", label: "Katra" },
  { id: "Platform", label: "Platform" },
  { id: "Sales", label: "Sales" },
];

const statusOptions: { id: "all" | RunStatus; label: string }[] = [
  { id: "all", label: "All statuses" },
  { id: "attention", label: "Attention" },
  { id: "waiting", label: "Waiting" },
  { id: "running", label: "Running" },
  { id: "paused", label: "Paused" },
];

const scopeLabel = computed(() => scopeOptions.find((option) => option.id === scopeFilter.value)?.label ?? "All scopes");
const statusLabel = computed(() => statusOptions.find((option) => option.id === statusFilter.value)?.label ?? "All statuses");

const visibleRuns = computed(() => runs.value.filter((run) => {
  const matchesScope = scopeFilter.value === "all"
    || run.organization === scopeFilter.value
    || run.product === scopeFilter.value;
  const matchesStatus = statusFilter.value === "all" || run.status === statusFilter.value;
  return matchesScope && matchesStatus && run.status !== "cancelled";
}));

const selectedRun = computed(() => runs.value.find((run) => run.id === selectedRunId.value) ?? visibleRuns.value[0] ?? runs.value[0]);
const selectedAgent = computed(() => agentDirectory[selectedRun.value.agent]);
const selectedDefinition = computed(() => definitions.value.find((item) => item.id === selectedDefinitionId.value) ?? definitions.value[0]);
const selectedRunTitle = computed(() => selectedRun.value.id === "run-client-security"
  ? "Software Delivery · Katra Client"
  : `${selectedRun.value.name} · ${selectedRun.value.project}`);
const selectedRunPublicId = computed(() => selectedRun.value.id === "run-client-security"
  ? "run_01K7M9W6Y2F3TQJZ8P6D4H1"
  : selectedRun.value.id.replaceAll("-", "_"));

function setView(view: ViewKind) {
  activeView.value = view;
  openMenu.value = null;
}

function toggleMenu(menu: Exclude<MenuKind, null>) {
  openMenu.value = openMenu.value === menu ? null : menu;
}

function setScope(scope: string) {
  scopeFilter.value = scope;
  openMenu.value = null;
  ensureVisibleSelection();
}

function setStatus(status: "all" | RunStatus) {
  statusFilter.value = status;
  openMenu.value = null;
  ensureVisibleSelection();
}

function ensureVisibleSelection() {
  window.requestAnimationFrame(() => {
    if (!visibleRuns.value.some((run) => run.id === selectedRunId.value) && visibleRuns.value[0]) {
      selectedRunId.value = visibleRuns.value[0].id;
    }
  });
}

function selectRun(id: string) {
  selectedRunId.value = id;
  findingsOpen.value = false;
  noteEditorOpen.value = false;
}

function setRunQueuePercent(percent: number) {
  runQueuePercent.value = Math.min(62, Math.max(28, percent));
}

function updateRunQueueWidth(clientX: number) {
  const bounds = runWorkbench.value?.getBoundingClientRect();
  if (!bounds?.width) return;
  setRunQueuePercent(((clientX - bounds.left) / bounds.width) * 100);
}

function startRunQueueResize(event: PointerEvent) {
  if (window.matchMedia("(max-width: 900px)").matches) return;
  event.preventDefault();
  const handle = event.currentTarget as HTMLElement;
  handle.setPointerCapture(event.pointerId);
  runQueueResizePointerId = event.pointerId;
  runQueueResizeHandle = handle;
  isResizingRunQueue.value = true;
  updateRunQueueWidth(event.clientX);
}

function moveRunQueueResize(event: PointerEvent) {
  if (!isResizingRunQueue.value || event.pointerId !== runQueueResizePointerId) return;
  updateRunQueueWidth(event.clientX);
}

function finishRunQueueResize(event: PointerEvent) {
  if (!isResizingRunQueue.value || event.pointerId !== runQueueResizePointerId) return;
  if (runQueueResizeHandle?.hasPointerCapture(event.pointerId)) runQueueResizeHandle.releasePointerCapture(event.pointerId);
  runQueueResizePointerId = undefined;
  runQueueResizeHandle = null;
  isResizingRunQueue.value = false;
}

function resizeRunQueueWithKeyboard(event: KeyboardEvent) {
  const step = event.shiftKey ? 5 : 2;
  if (event.key === "ArrowLeft") setRunQueuePercent(runQueuePercent.value - step);
  else if (event.key === "ArrowRight") setRunQueuePercent(runQueuePercent.value + step);
  else if (event.key === "Home") setRunQueuePercent(28);
  else if (event.key === "End") setRunQueuePercent(62);
  else return;
  event.preventDefault();
}

function resetRunQueueWidth() {
  runQueuePercent.value = defaultRunQueuePercent;
}

function showNotice(message: string) {
  notice.value = message;
  window.clearTimeout(noticeTimer);
  noticeTimer = window.setTimeout(() => {
    notice.value = "";
  }, 2600);
}

function togglePause() {
  const run = selectedRun.value;
  run.status = run.status === "paused" ? "running" : "paused";
  showNotice(run.status === "paused" ? "Workflow run paused." : "Workflow run resumed.");
  openMenu.value = null;
}

function cancelRun() {
  selectedRun.value.status = "cancelled";
  showNotice("Workflow run cancelled.");
  openMenu.value = null;
  ensureVisibleSelection();
}

function openWorkflowEditor(name: string, intent: EditorIntent = "edit") {
  editorInitialName.value = name;
  editorIntent.value = intent;
  editorOpen.value = true;
}

function addNote() {
  const text = noteDraft.value.trim();
  if (!text) return;
  runNotes.value.unshift({ id: Date.now(), text, createdAt: "Just now" });
  noteDraft.value = "";
  noteEditorOpen.value = false;
  showNotice("Run note added.");
}

function handleDocumentPointerDown(event: PointerEvent) {
  const target = event.target as HTMLElement;
  if (!root.value?.contains(target)) return;
  if (!target.closest(".workflow-popover") && !target.closest("[data-menu-trigger]")) openMenu.value = null;
}

function handleEscape(event: KeyboardEvent) {
  if (event.key !== "Escape") return;
  openMenu.value = null;
  findingsOpen.value = false;
  noteEditorOpen.value = false;
}

onMounted(() => {
  document.addEventListener("pointerdown", handleDocumentPointerDown);
  document.addEventListener("keydown", handleEscape);
  window.addEventListener("pointermove", moveRunQueueResize);
  window.addEventListener("pointerup", finishRunQueueResize);
  window.addEventListener("pointercancel", finishRunQueueResize);
});

onBeforeUnmount(() => {
  document.removeEventListener("pointerdown", handleDocumentPointerDown);
  document.removeEventListener("keydown", handleEscape);
  window.removeEventListener("pointermove", moveRunQueueResize);
  window.removeEventListener("pointerup", finishRunQueueResize);
  window.removeEventListener("pointercancel", finishRunQueueResize);
  window.clearTimeout(noticeTimer);
});
</script>

<template>
  <WorkflowEditor v-if="editorOpen" :initial-name="editorInitialName" :intent="editorIntent" @back="editorOpen = false" />

  <section v-else ref="root" class="workflows-page" aria-labelledby="workflows-title">
    <header class="workflows-header">
      <div class="workflows-heading">
        <h1 id="workflows-title">Workflows</h1>
        <p>Monitor runs, handoffs, workspace state, and approval gates.</p>
      </div>

      <div class="workflow-view-switch" aria-label="Workflow view">
        <button type="button" :class="{ 'is-active': activeView === 'runs' }" @click="setView('runs')">Active runs</button>
        <button type="button" :class="{ 'is-active': activeView === 'definitions' }" @click="setView('definitions')">Definitions</button>
      </div>

      <div class="workflows-header-actions">
        <div class="workflow-filter">
          <button data-menu-trigger type="button" class="workflow-filter-trigger" :aria-expanded="openMenu === 'scope'" @click="toggleMenu('scope')">
            <span>{{ scopeLabel }}</span>
            <ChevronDown :size="15" :stroke-width="1.8" aria-hidden="true" />
          </button>
          <div v-if="openMenu === 'scope'" class="workflow-popover workflow-filter-menu" role="menu">
            <button v-for="option in scopeOptions" :key="option.id" type="button" role="menuitemradio" :aria-checked="scopeFilter === option.id" @click="setScope(option.id)">
              <span>{{ option.label }}</span>
              <Check v-if="scopeFilter === option.id" :size="15" aria-hidden="true" />
            </button>
          </div>
        </div>

        <div class="workflow-filter">
          <button data-menu-trigger type="button" class="workflow-filter-trigger" :aria-expanded="openMenu === 'status'" @click="toggleMenu('status')">
            <span>{{ statusLabel }}</span>
            <ChevronDown :size="15" :stroke-width="1.8" aria-hidden="true" />
          </button>
          <div v-if="openMenu === 'status'" class="workflow-popover workflow-filter-menu" role="menu">
            <button v-for="option in statusOptions" :key="option.id" type="button" role="menuitemradio" :aria-checked="statusFilter === option.id" @click="setStatus(option.id)">
              <span>{{ option.label }}</span>
              <Check v-if="statusFilter === option.id" :size="15" aria-hidden="true" />
            </button>
          </div>
        </div>

        <button type="button" class="workflow-create-button" @click="openWorkflowEditor('Untitled workflow', 'create')">
          <Plus :size="18" :stroke-width="1.8" aria-hidden="true" />
          <span>Create workflow</span>
        </button>
      </div>
    </header>

    <div
      v-if="activeView === 'runs'"
      ref="runWorkbench"
      class="workflow-run-workbench"
      :class="{ 'is-resizing-run-queue': isResizingRunQueue }"
      :style="{ '--run-queue-percent': `${runQueuePercent}%` }"
    >
      <section class="run-queue" aria-label="Active workflow runs">
        <div class="run-queue-headings" aria-hidden="true">
          <span>Workflow / Context</span>
          <span>Stage &amp; Agent</span>
          <span>Elapsed</span>
          <span>Status</span>
        </div>

        <div class="run-list">
          <button
            v-for="run in visibleRuns"
            :key="run.id"
            type="button"
            class="run-row"
            :class="{ 'run-row--selected': selectedRun.id === run.id }"
            @click="selectRun(run.id)"
          >
            <span class="run-context">
              <strong>{{ run.name }} · {{ run.project }}</strong>
              <small>{{ run.organization }} / {{ run.product }} / {{ run.project }}</small>
            </span>
            <span class="run-stage">
              <strong>{{ run.stage }}</strong>
              <small>{{ agentDirectory[run.agent].name }}</small>
            </span>
            <span class="run-elapsed">{{ run.elapsed }}</span>
            <span class="run-status" :class="`run-status--${run.status}`">
              <i />{{ run.status === 'attention' ? 'Attention' : run.status === 'waiting' ? 'Waiting' : run.status === 'paused' ? 'Paused' : 'Running' }}
            </span>
          </button>

          <div v-if="visibleRuns.length === 0" class="run-empty-state">
            <ListFilter :size="26" :stroke-width="1.6" aria-hidden="true" />
            <strong>No active runs match these filters</strong>
            <span>Try another scope or status.</span>
          </div>
        </div>

        <footer class="run-queue-footer">
          <span>Showing 1–{{ visibleRuns.length }} of 27 active runs (oldest first)</span>
          <span><CircleDashed :size="14" :stroke-width="1.8" aria-hidden="true" /> Auto-refresh on <i /></span>
        </footer>
      </section>

      <div
        class="run-workbench-resize-handle"
        role="separator"
        aria-label="Resize active runs queue"
        aria-orientation="vertical"
        aria-valuemin="28"
        aria-valuemax="62"
        :aria-valuenow="Math.round(runQueuePercent)"
        tabindex="0"
        @dblclick="resetRunQueueWidth"
        @keydown="resizeRunQueueWithKeyboard"
        @pointerdown="startRunQueueResize"
      ><span aria-hidden="true" /></div>

      <section class="run-detail" aria-label="Selected workflow run">
        <header class="run-detail-header">
          <div class="run-detail-identity">
            <h2>{{ selectedRunTitle }}</h2>
            <button type="button" class="run-id" @click="showNotice('Run ID copied.')">{{ selectedRunPublicId }} <ClipboardList :size="15" :stroke-width="1.7" aria-hidden="true" /></button>
            <p><span>{{ selectedRun.organization }} / {{ selectedRun.product }} / {{ selectedRun.project }}</span><span>Started {{ selectedRun.startedAt }}</span></p>
          </div>

          <div class="run-detail-state">
            <strong><i :class="`state-dot--${selectedRun.status}`" />{{ selectedRun.status === 'attention' ? 'Attention' : selectedRun.status === 'waiting' ? 'Waiting' : selectedRun.status === 'paused' ? 'Paused' : 'Running' }}</strong>
            <span>{{ selectedRun.elapsed }} elapsed</span>
          </div>

          <div class="run-actions">
            <button data-menu-trigger type="button" class="run-actions-trigger" aria-label="Run actions" :aria-expanded="openMenu === 'actions'" @click="toggleMenu('actions')">
              <MoreVertical :size="19" :stroke-width="1.8" aria-hidden="true" />
            </button>
            <div v-if="openMenu === 'actions'" class="workflow-popover run-actions-menu" role="menu">
              <button type="button" role="menuitem" @click="togglePause">
                <Play v-if="selectedRun.status === 'paused'" :size="17" aria-hidden="true" />
                <Pause v-else :size="17" aria-hidden="true" />
                <span>{{ selectedRun.status === 'paused' ? 'Resume run' : 'Pause run' }}</span>
              </button>
              <button type="button" role="menuitem" class="is-danger" @click="cancelRun"><Square :size="16" aria-hidden="true" /><span>Cancel run</span></button>
              <div class="menu-separator" />
              <button type="button" role="menuitem" @click="showNotice('Run logs opened.'); openMenu = null"><ScrollText :size="17" aria-hidden="true" /><span>View run logs</span></button>
            </div>
          </div>
        </header>

        <div class="run-detail-scroll">
          <section class="run-timeline" aria-label="Workflow stage timeline">
            <ol>
              <li
                v-for="(stage, index) in stageDirectory"
                :key="stage.label"
                :class="{
                  'is-complete': index < selectedRun.stageIndex,
                  'is-current': index === selectedRun.stageIndex,
                  'is-future': index > selectedRun.stageIndex,
                }"
              >
                <div class="stage-marker">
                  <CircleCheckBig v-if="index < selectedRun.stageIndex" :size="20" :stroke-width="1.8" aria-hidden="true" />
                  <img v-else-if="index === selectedRun.stageIndex && agentDirectory[stage.owner].avatar" :src="agentDirectory[stage.owner].avatar" alt="" />
                  <ShieldCheck v-else-if="stage.owner === 'human'" :size="19" :stroke-width="1.7" aria-hidden="true" />
                  <Workflow v-else :size="18" :stroke-width="1.7" aria-hidden="true" />
                </div>
                <strong>{{ stage.label }}</strong>
                <span>by {{ agentDirectory[stage.owner].name }}</span>
                <time>{{ index < selectedRun.stageIndex ? stage.time : index === selectedRun.stageIndex ? stage.time : 'Pending' }}</time>
                <small>{{ index < selectedRun.stageIndex ? stage.duration : index === selectedRun.stageIndex ? (selectedRun.status === 'paused' ? 'Paused' : 'In progress') : '—' }}</small>
              </li>
            </ol>
          </section>

          <div class="run-detail-columns">
            <section class="workspace-detail" aria-labelledby="current-workspace-title">
              <h3 id="current-workspace-title">Current workspace</h3>
              <dl>
                <div><dt>Repository / Worktree</dt><dd>{{ selectedRun.repository }} / {{ selectedRun.worktree }}</dd></div>
                <div><dt>Branch</dt><dd>{{ selectedRun.branch }}</dd></div>
                <div><dt>Environment</dt><dd>{{ selectedRun.environment }}</dd></div>
                <div><dt>Last synced</dt><dd>{{ selectedRun.lastSynced }}</dd></div>
                <div><dt>Base</dt><dd>{{ selectedRun.base }}</dd></div>
                <div><dt>Next sync</dt><dd>{{ selectedRun.nextSync }}</dd></div>
              </dl>
              <div class="workspace-links">
                <button type="button" @click="showNotice('Workspace opened.')">Open workspace <ExternalLink :size="14" aria-hidden="true" /></button>
                <button type="button" @click="showNotice('Workspace changes opened.')">View changes <ExternalLink :size="14" aria-hidden="true" /></button>
              </div>
            </section>

            <section class="handoff-detail" aria-labelledby="current-handoff-title">
              <h3 id="current-handoff-title">Current handoff</h3>
              <div class="handoff-agent">
                <img v-if="selectedAgent.avatar" :src="selectedAgent.avatar" :alt="`${selectedAgent.name} avatar`" />
                <span v-else class="handoff-system-icon"><Workflow :size="20" aria-hidden="true" /></span>
                <p><strong>{{ selectedAgent.name }}</strong><span>{{ selectedAgent.role }}</span></p>
              </div>
              <p class="handoff-time">Latest note · {{ selectedRun.handoffAt }}</p>
              <p class="handoff-note">{{ selectedRun.handoffNote }}</p>
              <div class="handoff-actions">
                <button type="button" class="review-findings-button" @click="findingsOpen = true">
                  <span>{{ selectedRun.id === 'run-client-security' ? 'Review findings' : 'View handoff' }}</span>
                  <ExternalLink :size="15" :stroke-width="1.8" aria-hidden="true" />
                </button>
                <button type="button" class="view-full-handoff" @click="findingsOpen = true">View full handoff <ExternalLink :size="13" aria-hidden="true" /></button>
              </div>
            </section>
          </div>

          <section class="run-notes" aria-labelledby="run-notes-title">
            <header>
              <h3 id="run-notes-title">Run notes</h3>
              <button type="button" @click="noteEditorOpen = !noteEditorOpen"><StickyNote :size="15" aria-hidden="true" /> Add note</button>
            </header>
            <form v-if="noteEditorOpen" class="run-note-editor" @submit.prevent="addNote">
              <textarea v-model="noteDraft" autofocus placeholder="Add context for the team…" />
              <div><button type="button" @click="noteEditorOpen = false">Cancel</button><button type="submit" :disabled="!noteDraft.trim()">Save note</button></div>
            </form>
            <ul v-if="runNotes.length">
              <li v-for="note in runNotes" :key="note.id"><p>{{ note.text }}</p><span>morgan · {{ note.createdAt }}</span></li>
            </ul>
            <p v-else-if="!noteEditorOpen" class="run-notes-empty">No notes added yet.</p>
          </section>
        </div>
      </section>
    </div>

    <section v-else class="workflow-definitions" aria-label="Workflow definitions">
      <header class="definition-table-head" aria-hidden="true">
        <span>Workflow</span><span>Trigger</span><span>Scope</span><span>Stages</span><span>Active Runs</span><span>Last Run</span><span />
      </header>
      <article
        v-for="definition in definitions"
        :key="definition.id"
        class="definition-row"
        :class="{ 'definition-row--selected': selectedDefinition.id === definition.id }"
      >
        <button type="button" class="definition-row-main" @click="selectedDefinitionId = definition.id">
          <span><strong>{{ definition.name }}</strong><small>{{ definition.purpose }}</small></span>
          <span>{{ definition.trigger }}</span><span>{{ definition.scope }}</span><span>{{ definition.stages }}</span><span>{{ definition.activeRuns }}</span><span>{{ definition.lastRun }}</span>
          <ChevronDown :size="16" :class="{ 'is-open': selectedDefinition.id === definition.id }" aria-hidden="true" />
        </button>
        <div v-if="selectedDefinition.id === definition.id" class="definition-expanded">
          <div><h3>Execution policy</h3><p>Prepare a bounded workspace before agent work begins. Move forward only when the current stage satisfies its completion rule.</p></div>
          <div><h3>Approval gates</h3><p>Human approval is required before external communication, production release, pricing, or customer commitments.</p></div>
          <div class="definition-actions"><button type="button" @click="openWorkflowEditor(definition.name, 'edit')"><PencilLine :size="15" aria-hidden="true" /> Edit workflow</button><button type="button" @click="showNotice(`${definition.name} settings opened.`)">Workflow settings</button></div>
        </div>
      </article>
      <footer class="definitions-footer"><span>{{ definitions.length }} workflow definitions</span><span>Refreshed 1m ago <i /></span></footer>
    </section>

    <Transition name="workflow-notice">
      <p v-if="notice" class="workflow-notice" role="status">{{ notice }}</p>
    </Transition>

    <div v-if="findingsOpen" class="workflow-modal-backdrop" role="presentation" @mousedown.self="findingsOpen = false">
      <section class="workflow-modal findings-modal" role="dialog" aria-modal="true" aria-labelledby="findings-title">
        <header><div><h2 id="findings-title">{{ selectedRun.id === 'run-client-security' ? 'Sentinel findings' : 'Current handoff' }}</h2><p>{{ selectedRun.name }} · {{ selectedRun.project }}</p></div><button type="button" aria-label="Close findings" @click="findingsOpen = false"><X :size="19" aria-hidden="true" /></button></header>
        <div v-if="selectedRun.id === 'run-client-security'" class="findings-list">
          <article><ShieldCheck :size="19" aria-hidden="true" /><div><strong>Session rotation is not enforced after privilege changes</strong><p>The active session remains valid after the assigned role changes. Sentinel recommends rotating the session token.</p></div><span>Medium</span></article>
          <article><ShieldCheck :size="19" aria-hidden="true" /><div><strong>Cookie lifetime exceeds the approved policy</strong><p>The remember-me cookie is configured beyond the duration established for the client application.</p></div><span>Medium</span></article>
        </div>
        <p v-else class="handoff-modal-copy">{{ selectedRun.handoffNote }}</p>
        <footer><button type="button" @click="findingsOpen = false">Close</button><button type="button" @click="findingsOpen = false; showNotice('Review opened in Inbox.')">Open review</button></footer>
      </section>
    </div>
  </section>
</template>

<style scoped>
.workflows-page {
  position: relative;
  display: flex;
  width: 100%;
  height: 100%;
  min-width: 0;
  min-height: 0;
  flex-direction: column;
  overflow: hidden;
  background: #303744;
  color: #dce2ea;
}

.workflows-header {
  position: relative;
  z-index: 4;
  display: grid;
  grid-template-columns: minmax(270px, 1fr) auto minmax(430px, 1fr);
  min-width: 0;
  flex: 0 0 auto;
  align-items: center;
  gap: 22px;
  padding: 28px 30px 22px;
}

.workflows-heading h1 {
  margin: 0;
  color: #f0f2f6;
  font-size: 30px;
  font-weight: 750;
  letter-spacing: -0.035em;
}

.workflows-heading p {
  margin: 8px 0 0;
  color: #a2acba;
  font-size: 13px;
  line-height: 1.45;
}

.workflow-view-switch {
  display: grid;
  grid-template-columns: repeat(2, minmax(112px, 1fr));
  padding: 4px;
  border-radius: 10px;
  background: #272d37;
}

.workflow-view-switch button {
  min-height: 36px;
  padding: 0 18px;
  border-radius: 7px;
  background: transparent;
  color: #aab3c0;
  cursor: pointer;
}

.workflow-view-switch button:hover { color: #eef1f5; }
.workflow-view-switch button.is-active { background: #b991b6; color: #222731; font-weight: 700; }

.workflows-header-actions {
  display: flex;
  min-width: 0;
  align-items: center;
  justify-content: flex-end;
  gap: 10px;
}

.workflow-filter { position: relative; }
.workflow-filter-trigger {
  display: flex;
  min-width: 132px;
  height: 40px;
  align-items: center;
  justify-content: space-between;
  gap: 18px;
  padding: 0 13px;
  border-radius: 9px;
  background: #2c333e;
  color: #c5ccd7;
  cursor: pointer;
}
.workflow-filter-trigger:hover { background: #373e4b; color: #eef1f5; }

.workflow-create-button {
  display: inline-flex;
  height: 42px;
  align-items: center;
  gap: 9px;
  padding: 0 18px;
  border-radius: 9px;
  background: #c397c1;
  color: #252a33;
  font-weight: 750;
  cursor: pointer;
}
.workflow-create-button:hover { background: #d0a5ce; }

.workflow-popover {
  position: absolute;
  z-index: 20;
  min-width: 188px;
  padding: 6px;
  border-radius: 12px;
  background: #3a4250;
  box-shadow: 0 14px 35px rgb(9 12 17 / 22%);
}

.workflow-filter-menu { top: calc(100% + 7px); right: 0; }
.workflow-filter-menu button,
.run-actions-menu button {
  display: flex;
  width: 100%;
  min-height: 38px;
  align-items: center;
  justify-content: space-between;
  gap: 12px;
  padding: 0 11px;
  border-radius: 8px;
  background: transparent;
  color: #d5dbe4;
  cursor: pointer;
}
.workflow-filter-menu button:hover,
.workflow-filter-menu button:focus-visible,
.run-actions-menu button:hover,
.run-actions-menu button:focus-visible { background: #444d5d; outline: 0; }

.workflow-run-workbench {
  display: grid;
  grid-template-columns: clamp(320px, var(--run-queue-percent, 37.5%), calc(100% - 440px)) 0 minmax(0, 1fr);
  min-height: 0;
  flex: 1;
  overflow: hidden;
  background: #303744;
}

.run-queue {
  display: flex;
  min-width: 0;
  min-height: 0;
  flex-direction: column;
  overflow: hidden;
  border-radius: 0 12px 0 0;
  background: #2d343f;
}

.run-workbench-resize-handle {
  position: relative;
  z-index: 8;
  display: grid;
  width: 14px;
  height: 100%;
  justify-self: center;
  place-items: center;
  outline: 0;
  cursor: col-resize;
  touch-action: none;
}

.run-workbench-resize-handle > span {
  width: 2px;
  height: 54px;
  border-radius: 999px;
  background: #424b58;
  transition: height 140ms ease, background 140ms ease;
}

.run-workbench-resize-handle:hover > span,
.run-workbench-resize-handle:focus-visible > span,
.workflow-run-workbench.is-resizing-run-queue .run-workbench-resize-handle > span {
  height: 82px;
  background: #c49ac0;
}

.workflow-run-workbench.is-resizing-run-queue,
.workflow-run-workbench.is-resizing-run-queue * { cursor: col-resize !important; user-select: none; }

.run-queue-headings {
  display: grid;
  grid-template-columns: minmax(190px, 1.45fr) minmax(120px, .82fr) 65px 76px;
  flex: 0 0 auto;
  gap: 12px;
  padding: 16px 18px 12px;
  color: #9ba5b4;
  font-size: 10px;
  font-weight: 750;
  letter-spacing: .08em;
  text-transform: uppercase;
}

.run-list {
  min-height: 0;
  flex: 1;
  overflow-y: auto;
  overscroll-behavior: contain;
}

.run-row {
  position: relative;
  display: grid;
  width: calc(100% - 16px);
  min-height: 54px;
  grid-template-columns: minmax(190px, 1.45fr) minmax(120px, .82fr) 65px 76px;
  align-items: center;
  gap: 12px;
  padding: 8px 10px;
  margin: 2px 8px;
  border-radius: 8px;
  background: transparent;
  color: #d8dee7;
  text-align: left;
  cursor: pointer;
}

.run-row:hover { background: rgb(57 65 79 / 52%); }
.run-row--selected { background: #454351; box-shadow: 0 8px 22px rgb(11 14 19 / 9%); }

.run-context,
.run-stage { display: flex; min-width: 0; flex-direction: column; gap: 4px; }
.run-context strong,
.run-stage strong { overflow: hidden; color: #e9ecf1; font-size: 12px; font-weight: 680; line-height: 1.2; text-overflow: ellipsis; white-space: nowrap; }
.run-context small,
.run-stage small { overflow: hidden; color: #8e99a9; font-size: 10.5px; line-height: 1.2; text-overflow: ellipsis; white-space: nowrap; }
.run-elapsed { color: #adb6c3; font-size: 11px; }
.run-status { display: inline-flex; align-items: center; gap: 6px; color: #acb5c2; font-size: 10.5px; }
.run-status i,
.run-detail-state i,
.definitions-footer i { width: 7px; height: 7px; flex: 0 0 auto; border-radius: 999px; background: #71c396; }
.run-status--attention i,
.state-dot--attention { background: #efb95f !important; }
.run-status--waiting i,
.state-dot--waiting { background: #efb95f !important; }
.run-status--paused i,
.state-dot--paused { background: #a5aebb !important; }

.run-queue-footer {
  display: flex;
  min-height: 42px;
  flex: 0 0 auto;
  align-items: center;
  justify-content: space-between;
  gap: 16px;
  padding: 0 18px;
  border-radius: 9px 9px 0 0;
  background: #2c333e;
  color: #929dac;
  font-size: 10px;
}
.run-queue-footer > span:last-child { display: inline-flex; align-items: center; gap: 6px; white-space: nowrap; }
.run-queue-footer i { width: 7px; height: 7px; border-radius: 999px; background: #70c493; }

.run-detail {
  min-width: 0;
  min-height: 0;
  overflow: hidden;
  background: #303744;
}

.run-detail-header {
  position: relative;
  z-index: 3;
  display: grid;
  grid-template-columns: minmax(0, 1fr) auto auto;
  min-height: 108px;
  align-items: start;
  gap: 22px;
  padding: 22px 24px 18px;
}
.run-detail-identity h2 { margin: 0; color: #f1f3f6; font-size: 22px; font-weight: 720; letter-spacing: -.025em; }
.run-id { display: inline-flex; align-items: center; gap: 6px; margin-top: 8px; padding: 0; background: transparent; color: #9ba5b4; cursor: pointer; }
.run-id:hover { color: #d7dde5; }
.run-detail-identity p { display: flex; flex-wrap: wrap; gap: 18px; margin: 12px 0 0; color: #a4adba; font-size: 11px; }
.run-detail-state { display: flex; flex-direction: column; gap: 7px; padding-top: 2px; }
.run-detail-state strong { display: inline-flex; align-items: center; gap: 8px; color: #e2e6ec; font-size: 13px; }
.run-detail-state span { color: #9aa4b2; font-size: 11px; }
.run-actions { position: relative; }
.run-actions-trigger { display: grid; width: 38px; height: 38px; place-items: center; border-radius: 9px; background: #2d343f; color: #aab3c0; cursor: pointer; }
.run-actions-trigger:hover { background: #3a4250; color: #e7eaf0; }
.run-actions-menu { top: calc(100% + 7px); right: 0; min-width: 165px; }
.run-actions-menu button { justify-content: flex-start; }
.run-actions-menu .is-danger { color: #e6747d; }
.menu-separator { height: 1px; margin: 5px 8px; background: linear-gradient(90deg, transparent, rgb(222 229 239 / 8%), transparent); }

.run-detail-scroll { height: calc(100% - 108px); overflow-y: auto; overscroll-behavior: contain; }

.run-timeline { min-width: 760px; padding: 24px 20px 28px; margin: 0 22px 16px; border-radius: 12px; background: #2c333e; }
.run-timeline ol { display: grid; grid-template-columns: repeat(7, minmax(88px, 1fr)); margin: 0; padding: 0; list-style: none; }
.run-timeline li { position: relative; display: flex; min-width: 0; align-items: center; flex-direction: column; padding: 0 8px; text-align: center; }
.run-timeline li::before { position: absolute; z-index: 0; top: 20px; right: 50%; left: -50%; height: 2px; background: #5d6674; content: ""; }
.run-timeline li:first-child::before { display: none; }
.run-timeline li.is-complete::before,
.run-timeline li.is-current::before { background: #77b98f; }
.stage-marker { position: relative; z-index: 1; display: grid; width: 42px; height: 42px; place-items: center; overflow: hidden; border-radius: 999px; background: #39414d; color: #94a0b0; box-shadow: 0 0 0 1px #596372 inset; }
.stage-marker img { width: 100%; height: 100%; object-fit: cover; }
.is-complete .stage-marker { background: #6aaa81; color: #f2f6f3; box-shadow: 0 0 0 1px #a0d5b1 inset; }
.is-current .stage-marker { box-shadow: 0 0 0 2px #bd91b7, 0 0 0 6px rgb(189 145 183 / 12%); }
.run-timeline li > strong { margin-top: 12px; color: #e8ebf0; font-size: 11px; font-weight: 680; line-height: 1.3; }
.run-timeline li > span { margin-top: 5px; color: #b4bdc9; font-size: 10.5px; }
.run-timeline time { margin-top: 12px; color: #a3adba; font-size: 10px; }
.run-timeline small { margin-top: 6px; color: #939dac; font-size: 10px; }
.is-future > strong,
.is-future > span,
.is-future time,
.is-future small { color: #8993a2 !important; }

.run-detail-columns { display: grid; grid-template-columns: minmax(0, 1.08fr) minmax(280px, .92fr); min-height: 300px; gap: 12px; padding: 0 22px; }
.workspace-detail,
.handoff-detail { min-width: 0; padding: 22px; border-radius: 12px; background: #2c333e; }
.workspace-detail h3,
.handoff-detail h3,
.run-notes h3,
.definition-expanded h3 { margin: 0 0 20px; color: #aab4c2; font-size: 10px; font-weight: 760; letter-spacing: .09em; text-transform: uppercase; }
.workspace-detail dl { display: grid; gap: 12px; margin: 0; }
.workspace-detail dl > div { display: grid; grid-template-columns: 132px minmax(0, 1fr); gap: 18px; }
.workspace-detail dt { color: #aab3c0; font-size: 11px; }
.workspace-detail dd { min-width: 0; margin: 0; overflow: hidden; color: #cbd2dc; font-size: 11px; text-overflow: ellipsis; white-space: nowrap; }
.workspace-links { display: flex; gap: 18px; margin-top: 25px; }
.workspace-links button,
.view-full-handoff { display: inline-flex; align-items: center; gap: 7px; padding: 0; background: transparent; color: #c79ac2; cursor: pointer; }
.workspace-links button:hover,
.view-full-handoff:hover { color: #e0b8dc; }

.handoff-agent { display: flex; align-items: center; gap: 12px; }
.handoff-agent img,
.handoff-system-icon { display: grid; width: 46px; height: 46px; flex: 0 0 auto; place-items: center; border-radius: 999px; object-fit: cover; background: #3a4250; box-shadow: 0 0 0 1px #626d7c; }
.handoff-agent p { display: flex; min-width: 0; flex-direction: column; gap: 6px; margin: 0; }
.handoff-agent strong { color: #edf0f4; font-size: 13px; }
.handoff-agent span { color: #9da7b5; font-size: 11px; }
.handoff-time { margin: 18px 0 0; color: #9ca6b4; font-size: 10px; }
.handoff-note { max-width: 54ch; margin: 14px 0 20px; color: #b9c2ce; font-size: 11px; line-height: 1.55; }
.handoff-actions { display: flex; align-items: center; flex-wrap: wrap; gap: 12px 22px; }
.review-findings-button { display: inline-flex; min-width: 230px; height: 38px; align-items: center; justify-content: center; gap: 9px; border-radius: 8px; background: #c397c1; color: #282d36; font-weight: 720; cursor: pointer; }
.review-findings-button:hover { background: #d0a5ce; }

.run-notes { min-height: 145px; padding: 20px 22px; margin: 12px 22px 22px; border-radius: 12px; background: #2c333e; }
.run-notes > header { display: flex; align-items: center; justify-content: space-between; }
.run-notes > header h3 { margin: 0; }
.run-notes > header button { display: inline-flex; height: 34px; align-items: center; gap: 7px; padding: 0 12px; border-radius: 8px; background: #3a4250; color: #d7aed2; cursor: pointer; }
.run-notes > header button:hover { background: #444d5b; }
.run-notes-empty { margin: 22px 0 0; color: #8f99a7; font-size: 11px; }
.run-note-editor { margin-top: 17px; }
.run-note-editor textarea { width: 100%; min-height: 74px; resize: vertical; padding: 12px; border: 0; border-radius: 9px; outline: 0; background: #272e38; color: #dde2e9; font: 12px/1.45 Inter, sans-serif; }
.run-note-editor > div { display: flex; justify-content: flex-end; gap: 8px; margin-top: 9px; }
.run-note-editor button { height: 32px; padding: 0 12px; border-radius: 7px; background: #3a4250; cursor: pointer; }
.run-note-editor button[type="submit"] { background: #c397c1; color: #282d36; }
.run-note-editor button:disabled { opacity: .45; cursor: not-allowed; }
.run-notes ul { display: grid; gap: 8px; margin: 18px 0 0; padding: 0; list-style: none; }
.run-notes li { padding: 12px; border-radius: 8px; background: #2b323d; }
.run-notes li p { margin: 0; color: #cbd2dc; font-size: 12px; line-height: 1.45; }
.run-notes li span { display: block; margin-top: 7px; color: #8f99a7; font-size: 10px; }

.run-empty-state { display: grid; min-height: 250px; place-items: center; align-content: center; gap: 8px; color: #8f99a7; text-align: center; }
.run-empty-state strong { color: #cbd2dc; font-size: 13px; }
.run-empty-state span { font-size: 11px; }

.workflow-definitions { min-height: 0; flex: 1; overflow-y: auto; padding: 0 24px 18px; }
.definition-table-head,
.definition-row-main { display: grid; grid-template-columns: minmax(240px, 1.5fr) minmax(150px, .9fr) minmax(110px, .7fr) 70px 90px 90px 24px; align-items: center; gap: 18px; }
.definition-table-head { padding: 16px 16px 12px; color: #9ba5b4; font-size: 10px; font-weight: 750; letter-spacing: .08em; text-transform: uppercase; }
.definition-row { position: relative; }
.definition-row + .definition-row { margin-top: 2px; }
.definition-row-main { width: 100%; min-height: 76px; padding: 12px 16px; border-radius: 9px; background: transparent; color: #b9c2ce; text-align: left; cursor: pointer; }
.definition-row-main:hover,
.definition-row--selected .definition-row-main { background: rgb(58 66 80 / 55%); }
.definition-row-main > span:first-child { display: flex; min-width: 0; flex-direction: column; gap: 7px; }
.definition-row-main strong { color: #edf0f4; font-size: 13px; }
.definition-row-main small { overflow: hidden; color: #909aa9; font-size: 10.5px; text-overflow: ellipsis; white-space: nowrap; }
.definition-row-main > span:not(:first-child) { color: #b2bbc7; font-size: 11px; }
.definition-row-main svg { transition: transform 160ms ease; }
.definition-row-main svg.is-open { transform: rotate(180deg); }
.definition-expanded { display: grid; grid-template-columns: 1fr 1fr auto; gap: 28px; padding: 20px 18px 24px; margin-top: 2px; border-radius: 9px; background: rgb(44 51 62 / 72%); }
.definition-expanded h3 { margin-bottom: 10px; }
.definition-expanded p { max-width: 52ch; margin: 0; color: #aab4c1; font-size: 11px; line-height: 1.5; }
.definition-actions { display: flex; min-width: 170px; flex-direction: column; gap: 8px; }
.definition-actions button { display: inline-flex; height: 36px; align-items: center; justify-content: center; gap: 8px; border-radius: 8px; background: #3a4250; cursor: pointer; }
.definition-actions button:first-child { background: #c397c1; color: #282d36; }
.definitions-footer { display: flex; height: 42px; align-items: center; justify-content: space-between; padding: 0 16px; margin-top: 12px; border-radius: 9px; background: #2c333e; color: #929dac; font-size: 10px; }
.definitions-footer span:last-child { display: inline-flex; align-items: center; gap: 8px; }

.workflow-notice { position: absolute; z-index: 40; right: 24px; bottom: 20px; margin: 0; padding: 12px 15px; border-radius: 9px; background: #464f5f; color: #ecf0f5; box-shadow: 0 12px 30px rgb(9 12 17 / 24%); font-size: 12px; }
.workflow-notice-enter-active,
.workflow-notice-leave-active { transition: opacity 160ms ease, transform 160ms ease; }
.workflow-notice-enter-from,
.workflow-notice-leave-to { opacity: 0; transform: translateY(8px); }

.workflow-modal-backdrop { position: fixed; z-index: 60; display: grid; inset: 0; place-items: center; padding: 24px; background: rgb(13 16 21 / 66%); }
.workflow-modal { width: min(480px, 100%); padding: 22px; border-radius: 15px; background: #343c49; box-shadow: 0 22px 70px rgb(8 10 14 / 34%); }
.workflow-modal > header { display: flex; align-items: flex-start; justify-content: space-between; gap: 18px; margin-bottom: 22px; }
.workflow-modal h2 { margin: 0; color: #f0f2f6; font-size: 19px; }
.workflow-modal > header p { margin: 8px 0 0; color: #9ca6b4; font-size: 11px; line-height: 1.45; }
.workflow-modal > header > button { display: grid; width: 34px; height: 34px; flex: 0 0 auto; place-items: center; border-radius: 8px; background: transparent; color: #aab4c1; cursor: pointer; }
.workflow-modal > header > button:hover { background: #414a59; }
.workflow-modal > label { display: grid; gap: 8px; margin-top: 16px; color: #aeb7c4; font-size: 11px; font-weight: 650; }
.workflow-modal input,
.workflow-modal select { width: 100%; height: 42px; padding: 0 12px; border: 0; border-radius: 8px; outline: 0; background: #292f39; color: #e2e6ec; font: 12px Inter, sans-serif; }
.workflow-modal select { padding-right: 42px; appearance: none; }
.workflow-modal input:focus,
.workflow-modal select:focus { box-shadow: 0 0 0 2px #c49ac0; }
.workflow-modal > footer { display: flex; justify-content: flex-end; gap: 9px; margin-top: 24px; }
.workflow-modal > footer button { height: 38px; padding: 0 15px; border-radius: 8px; background: #414a59; cursor: pointer; }
.workflow-modal > footer button:last-child { background: #c397c1; color: #282d36; font-weight: 700; }
.workflow-modal > footer button:disabled { opacity: .45; cursor: not-allowed; }
.findings-modal { width: min(650px, 100%); }
.findings-list { display: grid; gap: 8px; }
.findings-list article { display: grid; grid-template-columns: auto 1fr auto; align-items: start; gap: 12px; padding: 14px; border-radius: 9px; background: #2b323d; }
.findings-list article > svg { color: #e6b25d; }
.findings-list strong { color: #e8ebf0; font-size: 12px; }
.findings-list p,
.handoff-modal-copy { margin: 7px 0 0; color: #aeb7c4; font-size: 11px; line-height: 1.5; }
.findings-list article > span { color: #e2ae59; font-size: 9px; font-weight: 750; letter-spacing: .08em; text-transform: uppercase; }

@media (max-width: 1180px) {
  .workflows-header { grid-template-columns: minmax(230px, 1fr) auto; }
  .workflow-view-switch { grid-column: 1 / -1; grid-row: 2; justify-self: start; }
  .workflows-header-actions { grid-column: 2; grid-row: 1; }
  .run-queue-headings,
  .run-row { grid-template-columns: minmax(180px, 1fr) minmax(110px, .75fr) 62px; }
  .run-queue-headings > :last-child,
  .run-row > :last-child { display: none; }
  .run-detail-columns { grid-template-columns: 1fr; }
  .run-detail-columns { gap: 10px; }
}

@media (max-width: 900px) {
  .workflows-header { grid-template-columns: 1fr; align-items: stretch; padding: 62px 20px 18px; }
  .workflows-header-actions { grid-column: 1; grid-row: 3; justify-content: flex-start; }
  .workflow-view-switch { width: 100%; }
  .workflow-run-workbench { display: flex; overflow-x: auto; scroll-snap-type: x mandatory; }
  .run-workbench-resize-handle { display: none; }
  .run-queue,
  .run-detail { width: 100%; min-width: 100%; scroll-snap-align: start; }
  .run-detail { box-shadow: none; }
  .definition-table-head { display: none; }
  .definition-row-main { grid-template-columns: minmax(0, 1fr) 90px 24px; }
  .definition-row-main > span:nth-child(2),
  .definition-row-main > span:nth-child(3),
  .definition-row-main > span:nth-child(4),
  .definition-row-main > span:nth-child(6) { display: none; }
  .definition-expanded { grid-template-columns: 1fr; }
}

@media (max-width: 620px) {
  .workflows-heading h1 { font-size: 25px; }
  .workflows-header-actions { display: grid; grid-template-columns: 1fr 1fr; }
  .workflow-filter-trigger { width: 100%; min-width: 0; }
  .workflow-create-button { grid-column: 1 / -1; justify-content: center; }
  .run-queue-headings,
  .run-row { grid-template-columns: minmax(0, 1fr) 88px; }
  .run-queue-headings > :nth-child(2),
  .run-queue-headings > :nth-child(4),
  .run-row > :nth-child(2),
  .run-row > :nth-child(4) { display: none; }
  .run-detail-header { grid-template-columns: minmax(0, 1fr) auto; padding: 18px; }
  .run-detail-state { display: none; }
  .run-detail-identity h2 { font-size: 19px; }
  .run-detail-identity p { gap: 8px; }
  .workspace-detail,
  .handoff-detail,
  .run-notes { padding: 19px; }
  .workspace-detail dl > div { grid-template-columns: 1fr; gap: 5px; }
  .workspace-detail dd { white-space: normal; }
  .handoff-actions { align-items: stretch; flex-direction: column; }
  .review-findings-button { width: 100%; min-width: 0; }
  .view-full-handoff { min-height: 32px; }
  .workflow-definitions { padding-inline: 12px; }
}
</style>
