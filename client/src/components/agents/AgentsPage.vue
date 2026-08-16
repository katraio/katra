<script setup lang="ts">
import {
  Bot,
  BrainCircuit,
  Check,
  ChevronDown,
  Container,
  Cpu,
  Database,
  ExternalLink,
  GitBranch,
  HardDrive,
  MemoryStick,
  Plus,
  Search,
  Server,
  Settings,
  ShieldCheck,
  SlidersHorizontal,
  Sparkles,
  Workflow,
  Wrench,
  X,
} from "@lucide/vue";
import { computed, onBeforeUnmount, onMounted, ref, type Component } from "vue";
import KatraSelect, { type KatraSelectOption } from "../ui/KatraSelect.vue";

type AgentStatus = "online" | "away" | "offline";
type FilterKind = "status" | "workspace" | "runtime";

type AgentSkill = {
  name: string;
  detail: string;
  icon: Component;
};

type AgentProfile = {
  id: string;
  name: string;
  avatar: string;
  role: string;
  capabilities: string;
  status: AgentStatus;
  statusDetail: string;
  runtimeFamily: "openai" | "anthropic" | "google";
  runtime: string;
  version: string;
  organization: string;
  project: string;
  workspace: string;
  workspaceFilter: string;
  openWork: number;
  blocked: number;
  lastActivity: string;
  lastActivityAt: string;
  purpose: string;
  instructions: string[];
  moreInstructions: string[];
  contextWindow: string;
  temperature: string;
  tools: string;
  memory: string;
  skills: AgentSkill[];
  moreSkills: number;
  branch: string;
  environment: string;
  lastSynced: string;
  nextSync: string;
};

const agents = ref<AgentProfile[]>([
  {
    id: "katra",
    name: "Katra",
    avatar: "/avatars/katra.png",
    role: "Platform Coordinator",
    capabilities: "Orchestration, Routing, Policy",
    status: "online",
    statusDetail: "Uptime 12h 34m",
    runtimeFamily: "openai",
    runtime: "GPT-5",
    version: "v1.8.2",
    organization: "DevOption",
    project: "Katra",
    workspace: "Core Operations",
    workspaceFilter: "core",
    openWork: 5,
    blocked: 1,
    lastActivity: "1m ago",
    lastActivityAt: "Aug 6, 2026 10:55 AM",
    purpose: "Coordinate projects, prepare workspaces, route work to specialist agents, and protect workflow policy.",
    instructions: ["Prioritize the global queue", "Prepare bounded workspace context", "Route work to the best specialist"],
    moreInstructions: ["Require human approval at decision gates", "Keep customers outside the execution plane"],
    contextWindow: "256K",
    temperature: "0.2",
    tools: "Workflow Engine, Project Graph",
    memory: "Katra Graph",
    skills: [
      { name: "Workflow routing", detail: "Plan & delegate", icon: Workflow },
      { name: "Policy control", detail: "Guardrail checks", icon: ShieldCheck },
      { name: "Workspace prep", detail: "Context assembly", icon: HardDrive },
    ],
    moreSkills: 8,
    branch: "main",
    environment: "Production",
    lastSynced: "1m ago",
    nextSync: "Automatic",
  },
  {
    id: "artisan",
    name: "Artisan",
    avatar: "/avatars/artisan.png",
    role: "Engineering Agent",
    capabilities: "APIs, Services, Data",
    status: "online",
    statusDetail: "Uptime 8h 12m",
    runtimeFamily: "anthropic",
    runtime: "Claude Opus 4",
    version: "v1.7.0",
    organization: "DevOption",
    project: "Katra / Server",
    workspace: "Server Modernization",
    workspaceFilter: "server",
    openWork: 3,
    blocked: 0,
    lastActivity: "2m ago",
    lastActivityAt: "Aug 6, 2026 10:54 AM",
    purpose: "Design, build, and maintain backend services, APIs, and data models with production-ready validation.",
    instructions: [
      "Prioritize Server modernization work",
      "Review open changes and unblock CI failures",
      "Enforce API standards and schema validation",
      "Escalate unsafe product assumptions",
    ],
    moreInstructions: ["Include focused tests with every change", "Keep migrations reversible"],
    contextWindow: "200K",
    temperature: "0.3",
    tools: "Code Interpreter, Web Search",
    memory: "Persistent",
    skills: [
      { name: "Git hosting", detail: "Repository access", icon: GitBranch },
      { name: "PostgreSQL", detail: "Read / Write", icon: Database },
      { name: "Redis", detail: "Read / Write", icon: HardDrive },
      { name: "Containers", detail: "Build & Deploy", icon: Container },
    ],
    moreSkills: 6,
    branch: "agent/server-modernization",
    environment: "Staging",
    lastSynced: "1m ago",
    nextSync: "Automatic",
  },
  {
    id: "atlas",
    name: "Atlas",
    avatar: "/avatars/atlas.png",
    role: "Documentation Agent",
    capabilities: "Architecture, Plans, Knowledge",
    status: "online",
    statusDetail: "Uptime 9h 48m",
    runtimeFamily: "openai",
    runtime: "GPT-5 mini",
    version: "v1.6.3",
    organization: "DevOption",
    project: "Katra / Client",
    workspace: "Client Documentation",
    workspaceFilter: "client",
    openWork: 2,
    blocked: 0,
    lastActivity: "5m ago",
    lastActivityAt: "Aug 6, 2026 10:51 AM",
    purpose: "Turn architecture, implementation evidence, and team decisions into durable plans and documentation.",
    instructions: ["Document decisions before implementation", "Keep current and planned behavior distinct", "Attach evidence to recommendations"],
    moreInstructions: ["Prefer clear language over jargon"],
    contextWindow: "128K",
    temperature: "0.2",
    tools: "Docs Library, Repository Search",
    memory: "Knowledge packs",
    skills: [
      { name: "Architecture docs", detail: "ADRs & PDRs", icon: BrainCircuit },
      { name: "Repository review", detail: "Source evidence", icon: GitBranch },
      { name: "Knowledge packs", detail: "Curated context", icon: MemoryStick },
    ],
    moreSkills: 5,
    branch: "docs/client-experience",
    environment: "Documentation",
    lastSynced: "5m ago",
    nextSync: "Automatic",
  },
  {
    id: "envoy",
    name: "Envoy",
    avatar: "/avatars/envoy.png",
    role: "Sales Assistant",
    capabilities: "Research, Briefs, Handoffs",
    status: "away",
    statusDetail: "Away since 9:32 AM",
    runtimeFamily: "google",
    runtime: "Gemini 2.5 Pro",
    version: "v1.6.1",
    organization: "DevOption",
    project: "Sales",
    workspace: "Discovery Preparation",
    workspaceFilter: "sales",
    openWork: 1,
    blocked: 0,
    lastActivity: "23m ago",
    lastActivityAt: "Aug 6, 2026 10:33 AM",
    purpose: "Assist the internal sales team with research, discovery preparation, follow-up drafts, and delivery handoffs.",
    instructions: ["Never contact customers directly", "Ground briefs in credible evidence", "Leave pricing and commitments to humans"],
    moreInstructions: ["Keep sales-to-delivery handoffs explicit"],
    contextWindow: "1M",
    temperature: "0.4",
    tools: "Research, CRM Search",
    memory: "Sales context",
    skills: [
      { name: "Lead research", detail: "Evidence gathering", icon: Search },
      { name: "Discovery briefs", detail: "Meeting preparation", icon: Sparkles },
      { name: "Handoffs", detail: "Sales to delivery", icon: Workflow },
    ],
    moreSkills: 4,
    branch: "sales/discovery-briefs",
    environment: "Internal",
    lastSynced: "23m ago",
    nextSync: "On demand",
  },
  {
    id: "sentinel",
    name: "Sentinel",
    avatar: "/avatars/sentinel.png",
    role: "Security Agent",
    capabilities: "Review, Monitoring, Compliance",
    status: "online",
    statusDetail: "Uptime 11h 2m",
    runtimeFamily: "anthropic",
    runtime: "Claude Opus 4",
    version: "v1.8.2",
    organization: "DevOption",
    project: "Katra / Client",
    workspace: "Security Review",
    workspaceFilter: "client",
    openWork: 4,
    blocked: 2,
    lastActivity: "3m ago",
    lastActivityAt: "Aug 6, 2026 10:53 AM",
    purpose: "Review code and system changes for exploitable risk, validate remediation, and protect operational boundaries.",
    instructions: ["Prioritize attack paths over checklists", "Show exact evidence for findings", "Require validation before closure"],
    moreInstructions: ["Keep sensitive evidence scoped"],
    contextWindow: "200K",
    temperature: "0.1",
    tools: "SAST, Dependency Scan, Secrets Scan",
    memory: "Security findings",
    skills: [
      { name: "Code review", detail: "SAST & manual", icon: ShieldCheck },
      { name: "Dependency audit", detail: "Supply chain", icon: Wrench },
      { name: "Validation", detail: "Fix verification", icon: Check },
    ],
    moreSkills: 7,
    branch: "review/client-security",
    environment: "Isolated",
    lastSynced: "3m ago",
    nextSync: "Automatic",
  },
  {
    id: "vector",
    name: "Vector",
    avatar: "/avatars/vector.png",
    role: "Platform Agent",
    capabilities: "CI/CD, Infrastructure, Automation",
    status: "online",
    statusDetail: "Uptime 10h 17m",
    runtimeFamily: "openai",
    runtime: "GPT-5",
    version: "v1.7.0",
    organization: "Northstar Goods",
    project: "ERP / Laravel App",
    workspace: "Release Automation",
    workspaceFilter: "erp",
    openWork: 3,
    blocked: 0,
    lastActivity: "7m ago",
    lastActivityAt: "Aug 6, 2026 10:49 AM",
    purpose: "Prepare environments, automate delivery, and keep platform work observable, repeatable, and recoverable.",
    instructions: ["Verify the intended revision before rollout", "Preserve rollback paths", "Report readiness with evidence"],
    moreInstructions: ["Escalate capacity risk before deployment"],
    contextWindow: "256K",
    temperature: "0.2",
    tools: "Shell, Cluster API, Git",
    memory: "Platform runbooks",
    skills: [
      { name: "CI/CD", detail: "Build & release", icon: Workflow },
      { name: "Infrastructure", detail: "Cluster control", icon: Server },
      { name: "Containers", detail: "Images & runtime", icon: Container },
    ],
    moreSkills: 8,
    branch: "release/erp-automation",
    environment: "Staging",
    lastSynced: "7m ago",
    nextSync: "Automatic",
  },
]);

const root = ref<HTMLElement | null>(null);
const statusFilter = ref<"all" | AgentStatus>("all");
const workspaceFilter = ref("all");
const runtimeFilter = ref<"all" | AgentProfile["runtimeFamily"]>("all");
const openFilter = ref<FilterKind | null>(null);
const expandedAgentId = ref<string | null>("artisan");
const expandedInstructionAgentId = ref<string | null>(null);
const createAgentOpen = ref(false);
const settingsAgent = ref<AgentProfile | null>(null);
const settingsName = ref("");
const settingsRole = ref("");
const settingsRuntime = ref("");
const settingsMemory = ref("");
const newAgentName = ref("");
const newAgentRole = ref("");
const newAgentRuntime = ref("GPT-5");
const agentRuntimeOptions: KatraSelectOption[] = [
  { value: "GPT-5", label: "GPT-5" },
  { value: "Claude Opus 4", label: "Claude Opus 4" },
  { value: "Gemini 2.5 Pro", label: "Gemini 2.5 Pro" },
];
const notice = ref("");
let noticeTimer: number | undefined;

const filterDefinitions = {
  status: [
    { id: "all", label: "All status" },
    { id: "online", label: "Online" },
    { id: "away", label: "Away" },
    { id: "offline", label: "Offline" },
  ],
  workspace: [
    { id: "all", label: "All workspaces" },
    { id: "core", label: "Core Operations" },
    { id: "server", label: "Server" },
    { id: "client", label: "Client" },
    { id: "sales", label: "Sales" },
    { id: "erp", label: "ERP" },
  ],
  runtime: [
    { id: "all", label: "All runtimes" },
    { id: "openai", label: "OpenAI" },
    { id: "anthropic", label: "Anthropic" },
    { id: "google", label: "Google" },
  ],
} as const;

const statusLabel = computed(() => filterDefinitions.status.find((item) => item.id === statusFilter.value)?.label ?? "All status");
const workspaceLabel = computed(() => filterDefinitions.workspace.find((item) => item.id === workspaceFilter.value)?.label ?? "All workspaces");
const runtimeLabel = computed(() => filterDefinitions.runtime.find((item) => item.id === runtimeFilter.value)?.label ?? "All runtimes");

const visibleAgents = computed(() => {
  return agents.value.filter((agent) => {
    const matchesStatus = statusFilter.value === "all" || agent.status === statusFilter.value;
    const matchesWorkspace = workspaceFilter.value === "all" || agent.workspaceFilter === workspaceFilter.value;
    const matchesRuntime = runtimeFilter.value === "all" || agent.runtimeFamily === runtimeFilter.value;
    return matchesStatus && matchesWorkspace && matchesRuntime;
  });
});

const statusCounts = computed(() => ({
  online: agents.value.filter((agent) => agent.status === "online").length,
  away: agents.value.filter((agent) => agent.status === "away").length,
  offline: agents.value.filter((agent) => agent.status === "offline").length,
}));

function toggleFilter(filter: FilterKind) {
  openFilter.value = openFilter.value === filter ? null : filter;
}

function setStatusFilter(value: "all" | AgentStatus) {
  statusFilter.value = value;
  openFilter.value = null;
}

function setWorkspaceFilter(value: string) {
  workspaceFilter.value = value;
  openFilter.value = null;
}

function setRuntimeFilter(value: "all" | AgentProfile["runtimeFamily"]) {
  runtimeFilter.value = value;
  openFilter.value = null;
}

function toggleAgent(agentId: string) {
  expandedAgentId.value = expandedAgentId.value === agentId ? null : agentId;
}

function openSettings(agent: AgentProfile) {
  settingsAgent.value = agent;
  settingsName.value = agent.name;
  settingsRole.value = agent.role;
  settingsRuntime.value = agent.runtime;
  settingsMemory.value = agent.memory;
}

function saveSettings() {
  if (!settingsAgent.value) return;
  settingsAgent.value.name = settingsName.value.trim() || settingsAgent.value.name;
  settingsAgent.value.role = settingsRole.value.trim() || settingsAgent.value.role;
  settingsAgent.value.runtime = settingsRuntime.value.trim() || settingsAgent.value.runtime;
  settingsAgent.value.memory = settingsMemory.value.trim() || settingsAgent.value.memory;
  showNotice(`${settingsAgent.value.name} settings saved.`);
  settingsAgent.value = null;
}

function createAgent() {
  const name = newAgentName.value.trim();
  const role = newAgentRole.value.trim();
  if (!name || !role) return;
  const id = `${name.toLowerCase().replace(/[^a-z0-9]+/g, "-")}-${Date.now()}`;
  agents.value.push({
    id,
    name,
    avatar: "/brand/icon.svg",
    role,
    capabilities: "New profile",
    status: "offline",
    statusDetail: "Not started",
    runtimeFamily: "openai",
    runtime: newAgentRuntime.value,
    version: "v1.0.0",
    organization: "DevOption",
    project: "Unassigned",
    workspace: "No workspace",
    workspaceFilter: "core",
    openWork: 0,
    blocked: 0,
    lastActivity: "Never",
    lastActivityAt: "Not started",
    purpose: "New configurable agent profile. Add purpose and operating instructions before assigning work.",
    instructions: ["Await configuration and workspace assignment"],
    moreInstructions: [],
    contextWindow: "128K",
    temperature: "0.2",
    tools: "None attached",
    memory: "Not configured",
    skills: [],
    moreSkills: 0,
    branch: "—",
    environment: "Not started",
    lastSynced: "Never",
    nextSync: "Manual",
  });
  expandedAgentId.value = id;
  createAgentOpen.value = false;
  newAgentName.value = "";
  newAgentRole.value = "";
  showNotice(`${name} agent profile created.`);
}

function showNotice(message: string) {
  notice.value = message;
  window.clearTimeout(noticeTimer);
  noticeTimer = window.setTimeout(() => {
    notice.value = "";
  }, 2600);
}

function handleDocumentPointerDown(event: PointerEvent) {
  const target = event.target as Node;
  if (!root.value?.contains(target)) return;
  if (!(target as HTMLElement).closest(".agents-filter")) openFilter.value = null;
}

function handleEscape(event: KeyboardEvent) {
  if (event.key !== "Escape") return;
  openFilter.value = null;
  createAgentOpen.value = false;
  settingsAgent.value = null;
}

onMounted(() => {
  document.addEventListener("pointerdown", handleDocumentPointerDown);
  document.addEventListener("keydown", handleEscape);
});

onBeforeUnmount(() => {
  document.removeEventListener("pointerdown", handleDocumentPointerDown);
  document.removeEventListener("keydown", handleEscape);
  window.clearTimeout(noticeTimer);
});
</script>

<template>
  <section ref="root" class="agents-page" aria-labelledby="agents-title">
    <header class="agents-page-header">
      <div class="agents-heading">
        <h1 id="agents-title">Agents</h1>
        <p>Global operational roster. Monitor status, workload, and activity across all agents.</p>
      </div>

      <div class="agents-controls">
        <div class="agents-filter">
          <button type="button" class="agents-filter-trigger" :aria-expanded="openFilter === 'status'" @click="toggleFilter('status')">
            <span>{{ statusLabel }}</span>
            <ChevronDown :size="16" :stroke-width="1.8" aria-hidden="true" />
          </button>
          <div v-if="openFilter === 'status'" class="agents-filter-menu" role="menu">
            <button v-for="item in filterDefinitions.status" :key="item.id" type="button" role="menuitemradio" :aria-checked="statusFilter === item.id" @click="setStatusFilter(item.id)">
              <span>{{ item.label }}</span>
              <Check v-if="statusFilter === item.id" :size="15" aria-hidden="true" />
            </button>
          </div>
        </div>

        <div class="agents-filter">
          <button type="button" class="agents-filter-trigger" :aria-expanded="openFilter === 'workspace'" @click="toggleFilter('workspace')">
            <span>{{ workspaceLabel }}</span>
            <ChevronDown :size="16" :stroke-width="1.8" aria-hidden="true" />
          </button>
          <div v-if="openFilter === 'workspace'" class="agents-filter-menu" role="menu">
            <button v-for="item in filterDefinitions.workspace" :key="item.id" type="button" role="menuitemradio" :aria-checked="workspaceFilter === item.id" @click="setWorkspaceFilter(item.id)">
              <span>{{ item.label }}</span>
              <Check v-if="workspaceFilter === item.id" :size="15" aria-hidden="true" />
            </button>
          </div>
        </div>

        <div class="agents-filter agents-runtime-filter">
          <button type="button" class="agents-filter-trigger" :aria-expanded="openFilter === 'runtime'" @click="toggleFilter('runtime')">
            <span>{{ runtimeLabel }}</span>
            <ChevronDown :size="16" :stroke-width="1.8" aria-hidden="true" />
          </button>
          <div v-if="openFilter === 'runtime'" class="agents-filter-menu" role="menu">
            <button v-for="item in filterDefinitions.runtime" :key="item.id" type="button" role="menuitemradio" :aria-checked="runtimeFilter === item.id" @click="setRuntimeFilter(item.id)">
              <span>{{ item.label }}</span>
              <Check v-if="runtimeFilter === item.id" :size="15" aria-hidden="true" />
            </button>
          </div>
        </div>

        <button type="button" class="agents-create-button" @click="createAgentOpen = true">
          <Plus :size="18" :stroke-width="1.8" aria-hidden="true" />
          <span>Create agent</span>
        </button>
      </div>
    </header>

    <section class="agents-roster" aria-label="Agent roster">
      <div class="agents-column-headings" aria-hidden="true">
        <span>Agent / Profile</span>
        <span>Role &amp; Capabilities</span>
        <span>Availability / Runtime</span>
        <span>Assigned Project / Workspace</span>
        <span>Open Work</span>
        <span>Last Activity</span>
        <span />
      </div>

      <div class="agents-list">
        <article v-for="agent in visibleAgents" :key="agent.id" class="agent-row" :class="{ 'agent-row--expanded': expandedAgentId === agent.id }">
          <div class="agent-summary">
            <button type="button" class="agent-summary-toggle" :aria-expanded="expandedAgentId === agent.id" :aria-label="`${expandedAgentId === agent.id ? 'Collapse' : 'Expand'} ${agent.name} agent details`" @click="toggleAgent(agent.id)" />

            <div class="agent-identity">
              <span class="agent-avatar">
                <img :src="agent.avatar" :alt="`${agent.name} avatar`" />
                <i :class="`agent-presence agent-presence--${agent.status}`" />
              </span>
              <span>
                <strong>{{ agent.name }} <i class="agent-profile-mark" /></strong>
                <small v-if="expandedAgentId === agent.id">Expanded</small>
              </span>
            </div>

            <div class="agent-role">
              <strong>{{ agent.role }}</strong>
              <span>{{ agent.capabilities }}</span>
            </div>

            <div class="agent-runtime">
              <span><i :class="`agent-state-dot agent-state-dot--${agent.status}`" />{{ agent.status }}</span>
              <small>{{ agent.statusDetail }}</small>
              <span class="agent-model">{{ agent.runtime }}</span>
              <small>{{ agent.version }}</small>
            </div>

            <div class="agent-assignment">
              <strong>{{ agent.organization }} / {{ agent.project }}</strong>
              <span>{{ agent.workspace }}</span>
            </div>

            <div class="agent-open-work">
              <strong><i />{{ agent.openWork }} in progress</strong>
              <span :class="{ 'agent-blocked': agent.blocked > 0 }">{{ agent.blocked ? `${agent.blocked} blocked` : '— blocked' }}</span>
            </div>

            <div class="agent-activity">
              <strong>{{ agent.lastActivity }}</strong>
              <span>{{ agent.lastActivityAt }}</span>
            </div>

            <button type="button" class="agent-expand-button" :aria-label="`${expandedAgentId === agent.id ? 'Collapse' : 'Expand'} ${agent.name} agent details`" @click.stop="toggleAgent(agent.id)">
              <ChevronDown :size="17" :stroke-width="1.8" aria-hidden="true" :class="{ 'agent-expand-icon--open': expandedAgentId === agent.id }" />
            </button>
          </div>

          <div v-if="expandedAgentId === agent.id" class="agent-expanded-detail">
            <section class="agent-purpose-panel">
              <h2>Purpose</h2>
              <p>{{ agent.purpose }}</p>
              <h2>Current instructions</h2>
              <ul>
                <li v-for="instruction in agent.instructions" :key="instruction">{{ instruction }}</li>
                <li v-for="instruction in expandedInstructionAgentId === agent.id ? agent.moreInstructions : []" :key="instruction">{{ instruction }}</li>
              </ul>
              <button v-if="agent.moreInstructions.length" type="button" class="agent-show-more" @click="expandedInstructionAgentId = expandedInstructionAgentId === agent.id ? null : agent.id">
                <span>{{ expandedInstructionAgentId === agent.id ? "Show less" : "Show more" }}</span>
                <ChevronDown :size="14" aria-hidden="true" :class="{ 'agent-expand-icon--open': expandedInstructionAgentId === agent.id }" />
              </button>
            </section>

            <section class="agent-config-panel">
              <h2>Model / Runtime</h2>
              <div class="agent-config-primary">
                <strong>{{ agent.runtime }}</strong>
                <span>{{ agent.version }}</span>
              </div>
              <dl>
                <div><dt>Context window</dt><dd>{{ agent.contextWindow }}</dd></div>
                <div><dt>Temperature</dt><dd>{{ agent.temperature }}</dd></div>
                <div><dt>Tools</dt><dd>{{ agent.tools }}</dd></div>
                <div><dt>Memory</dt><dd>{{ agent.memory }}</dd></div>
              </dl>
            </section>

            <section class="agent-skills-panel">
              <h2>Skills / MCP tools</h2>
              <div class="agent-skill-list">
                <div v-for="skill in agent.skills" :key="skill.name" class="agent-skill-row">
                  <span><component :is="skill.icon" :size="17" :stroke-width="1.7" aria-hidden="true" /></span>
                  <span><strong>{{ skill.name }}</strong><small>{{ skill.detail }}</small></span>
                  <i />
                </div>
                <p v-if="agent.skills.length === 0">No skills attached yet.</p>
              </div>
              <button v-if="agent.moreSkills" type="button" class="agent-more-skills" @click="showNotice(`${agent.name} has ${agent.moreSkills} more attached skills.`)">+ {{ agent.moreSkills }} more tools</button>
            </section>

            <section class="agent-workspace-panel">
              <h2>Active workspace</h2>
              <div class="agent-workspace-title">
                <span><Server :size="18" :stroke-width="1.7" aria-hidden="true" /></span>
                <span><strong>{{ agent.organization }} / {{ agent.project }}</strong><small>{{ agent.workspace }}</small></span>
              </div>
              <dl>
                <div><dt>Branch</dt><dd>{{ agent.branch }}</dd></div>
                <div><dt>Environment</dt><dd>{{ agent.environment }}</dd></div>
                <div><dt>Last synced</dt><dd>{{ agent.lastSynced }}</dd></div>
                <div><dt>Next sync</dt><dd>{{ agent.nextSync }}</dd></div>
              </dl>
            </section>

            <div class="agent-expanded-actions">
              <button type="button" class="agent-open-button" @click="showNotice(`${agent.name} workbench opened.`)">
                <span>Open agent</span>
                <ExternalLink :size="17" :stroke-width="1.8" aria-hidden="true" />
              </button>
              <button type="button" class="agent-settings-button" @click="openSettings(agent)">
                <Settings :size="18" :stroke-width="1.8" aria-hidden="true" />
                <span>Agent settings</span>
              </button>
            </div>
          </div>
        </article>

        <div v-if="visibleAgents.length === 0" class="agents-empty-state">
          <Bot :size="28" :stroke-width="1.6" aria-hidden="true" />
          <strong>No agents match these filters</strong>
          <span>Try another status, workspace, or runtime.</span>
        </div>
      </div>
    </section>

    <footer class="agents-status-bar">
      <span>{{ agents.length }} agents</span>
      <div>
        <span><i class="agent-state-dot agent-state-dot--online" />Online <strong>{{ statusCounts.online }}</strong></span>
        <span><i class="agent-state-dot agent-state-dot--away" />Away <strong>{{ statusCounts.away }}</strong></span>
        <span><i class="agent-state-dot agent-state-dot--offline" />Offline <strong>{{ statusCounts.offline }}</strong></span>
      </div>
      <span class="agents-refresh">Refreshed 1m ago <Cpu :size="15" :stroke-width="1.7" aria-hidden="true" /> <i class="agent-state-dot agent-state-dot--online" /> Auto-refresh on</span>
    </footer>

    <Transition name="agents-notice">
      <p v-if="notice" class="agents-notice" role="status">{{ notice }}</p>
    </Transition>

    <div v-if="createAgentOpen" class="agents-modal-backdrop" role="presentation" @mousedown.self="createAgentOpen = false">
      <form class="agents-modal" aria-labelledby="create-agent-title" @submit.prevent="createAgent">
        <header>
          <div>
            <h2 id="create-agent-title">Create agent profile</h2>
            <p>Start with identity and runtime. Purpose, skills, memory, and workspace can be configured next.</p>
          </div>
          <button type="button" aria-label="Close create agent dialog" @click="createAgentOpen = false"><X :size="19" aria-hidden="true" /></button>
        </header>
        <label><span>Agent name</span><input v-model="newAgentName" autofocus placeholder="Agent name" /></label>
        <label><span>Role</span><input v-model="newAgentRole" placeholder="e.g. Quality Agent" /></label>
        <label>
          <span>Runtime</span>
          <KatraSelect v-model="newAgentRuntime" :options="agentRuntimeOptions" label="Agent runtime" large />
        </label>
        <footer>
          <button type="button" class="agents-modal-secondary" @click="createAgentOpen = false">Cancel</button>
          <button type="submit" class="agents-modal-primary" :disabled="!newAgentName.trim() || !newAgentRole.trim()">Create agent</button>
        </footer>
      </form>
    </div>

    <div v-if="settingsAgent" class="agents-modal-backdrop" role="presentation" @mousedown.self="settingsAgent = null">
      <form class="agents-modal" aria-labelledby="agent-settings-title" @submit.prevent="saveSettings">
        <header>
          <div>
            <h2 id="agent-settings-title">{{ settingsAgent.name }} settings</h2>
            <p>Update this profile’s identity and core runtime configuration.</p>
          </div>
          <button type="button" aria-label="Close agent settings" @click="settingsAgent = null"><X :size="19" aria-hidden="true" /></button>
        </header>
        <label><span>Name</span><input v-model="settingsName" /></label>
        <label><span>Role</span><input v-model="settingsRole" /></label>
        <label><span>Runtime</span><input v-model="settingsRuntime" /></label>
        <label><span>Memory system</span><input v-model="settingsMemory" /></label>
        <footer>
          <button type="button" class="agents-modal-secondary" @click="settingsAgent = null">Cancel</button>
          <button type="submit" class="agents-modal-primary">Save settings</button>
        </footer>
      </form>
    </div>
  </section>
</template>

<style scoped>
.agents-page {
  position: relative;
  display: flex;
  width: 100%;
  height: 100%;
  min-width: 0;
  min-height: 0;
  flex-direction: column;
  overflow: hidden;
  background: #303744;
  color: #dde2ea;
}

.agents-page-header {
  display: flex;
  min-width: 0;
  flex: 0 0 auto;
  align-items: flex-start;
  justify-content: space-between;
  gap: 28px;
  padding: 32px 30px 24px;
}

.agents-heading {
  width: min(520px, 38%);
  flex: 0 1 520px;
}

.agents-heading h1 {
  margin: 0;
  color: #f1f3f7;
  font-size: clamp(25px, 2.2vw, 32px);
  font-weight: 700;
  letter-spacing: -0.025em;
}

.agents-heading p {
  max-width: 520px;
  margin: 8px 0 0;
  color: #b8c0cc;
  font-size: 13px;
  line-height: 1.5;
}

.agents-controls {
  display: flex;
  min-width: 0;
  align-items: center;
  justify-content: flex-end;
  gap: 10px;
}

.agents-filter-trigger,
.agents-create-button {
  height: 42px;
  border-radius: 9px;
}

.agents-filter { position: relative; flex: 0 0 auto; }

.agents-filter-trigger {
  display: flex;
  min-width: 126px;
  align-items: center;
  justify-content: space-between;
  gap: 10px;
  padding: 0 13px;
  background: #2b323d;
  color: #c5ccd6;
  cursor: pointer;
}

.agents-filter:nth-of-type(3) .agents-filter-trigger { min-width: 148px; }
.agents-runtime-filter .agents-filter-trigger { min-width: 132px; }

.agents-filter-trigger:hover,
.agents-filter-trigger:focus-visible {
  outline: 0;
  background: #39414e;
  color: #edf0f4;
}

.agents-filter-menu {
  position: absolute;
  z-index: 35;
  top: calc(100% + 7px);
  right: 0;
  width: 210px;
  padding: 7px;
  border-radius: 11px;
  background: #353c49;
  box-shadow: 0 14px 34px rgb(12 15 21 / 24%);
}

.agents-filter-menu button {
  display: flex;
  width: 100%;
  height: 36px;
  align-items: center;
  justify-content: space-between;
  padding: 0 10px;
  border-radius: 7px;
  background: transparent;
  color: #cbd2dc;
  cursor: pointer;
}

.agents-filter-menu button:hover,
.agents-filter-menu button:focus-visible {
  outline: 0;
  background: #414957;
  color: #f0f2f5;
}

.agents-create-button {
  display: inline-flex;
  min-width: 148px;
  flex: 0 0 auto;
  align-items: center;
  justify-content: center;
  gap: 8px;
  padding: 0 16px;
  background: #c494c2;
  color: #242833;
  font-weight: 700;
  white-space: nowrap;
  cursor: pointer;
}

.agents-create-button:hover,
.agents-create-button:focus-visible { outline: 0; background: #d2a5d0; }

.agents-roster {
  display: flex;
  min-width: 0;
  min-height: 0;
  flex: 1;
  flex-direction: column;
  padding: 0 20px;
}

.agents-column-headings,
.agent-summary {
  display: grid;
  grid-template-columns:
    minmax(190px, 1.15fr)
    minmax(180px, 1.02fr)
    minmax(190px, 1.05fr)
    minmax(220px, 1.16fr)
    minmax(130px, 0.72fr)
    minmax(150px, 0.78fr)
    38px;
  column-gap: 16px;
}

.agents-column-headings {
  min-height: 43px;
  flex: 0 0 43px;
  align-items: center;
  padding: 0 17px;
  color: #cbd1da;
  font-size: 11px;
  font-weight: 700;
}

.agents-list {
  min-height: 0;
  flex: 1;
  overflow-y: auto;
  padding: 0 5px 10px;
}

.agent-row { position: relative; }
.agent-row + .agent-row { margin-top: 2px; }

.agent-summary {
  position: relative;
  min-height: 72px;
  align-items: center;
  padding: 10px 12px;
  border-radius: 8px;
  color: #dbe0e8;
}

.agent-summary:hover { background: rgb(57 65 78 / 45%); }
.agent-row--expanded .agent-summary { background: rgb(60 67 81 / 38%); }

.agent-summary-toggle {
  position: absolute;
  z-index: 0;
  inset: 0;
  border-radius: inherit;
  background: transparent;
  cursor: pointer;
}

.agent-summary-toggle:focus-visible { outline-offset: -3px; }

.agent-summary > :not(.agent-summary-toggle) { position: relative; z-index: 1; pointer-events: none; }
.agent-summary > .agent-expand-button { pointer-events: auto; }

.agent-identity,
.agent-runtime,
.agent-workspace-title,
.agent-skill-row {
  display: flex;
  min-width: 0;
  align-items: center;
}

.agent-identity { gap: 11px; }

.agent-avatar {
  position: relative;
  display: block;
  width: 42px;
  height: 42px;
  flex: 0 0 42px;
}

.agent-avatar img { display: block; width: 100%; height: 100%; border-radius: 50%; object-fit: cover; }

.agent-presence {
  position: absolute;
  right: -1px;
  bottom: 0;
  width: 10px;
  height: 10px;
  border: 2px solid #303744;
  border-radius: 50%;
}

.agent-presence--online,
.agent-state-dot--online { background: #63d39a; }
.agent-presence--away,
.agent-state-dot--away { background: #e1b85f; }
.agent-presence--offline,
.agent-state-dot--offline { background: #7e8795; }

.agent-identity > span:last-child,
.agent-role,
.agent-assignment,
.agent-open-work,
.agent-activity {
  display: flex;
  min-width: 0;
  flex-direction: column;
  gap: 5px;
}

.agent-identity strong,
.agent-role strong,
.agent-assignment strong,
.agent-open-work strong,
.agent-activity strong {
  overflow: hidden;
  color: #edf0f4;
  font-size: 12px;
  font-weight: 700;
  text-overflow: ellipsis;
  white-space: nowrap;
}

.agent-identity small,
.agent-role span,
.agent-assignment span,
.agent-open-work span,
.agent-activity span,
.agent-runtime small {
  overflow: hidden;
  color: #929cab;
  font-size: 10px;
  line-height: 1.25;
  text-overflow: ellipsis;
  white-space: nowrap;
}

.agent-profile-mark {
  display: inline-block;
  width: 7px;
  height: 7px;
  margin-left: 5px;
  border-radius: 50%;
  background: #c494c2;
}

.agent-identity small {
  align-self: flex-start;
  padding: 3px 6px;
  border-radius: 4px;
  background: #454c59;
  color: #b7bfca;
}

.agent-runtime {
  display: grid;
  grid-template-columns: minmax(74px, 0.72fr) minmax(80px, 1fr);
  align-items: center;
  gap: 5px 12px;
}

.agent-runtime > span:first-child {
  display: flex;
  align-items: center;
  gap: 6px;
  color: #e1e5eb;
  font-size: 11px;
  text-transform: capitalize;
}

.agent-state-dot { display: inline-block; width: 7px; height: 7px; flex: 0 0 7px; border-radius: 50%; }
.agent-model { overflow: hidden; color: #aeb6c2; font-size: 10px; text-overflow: ellipsis; white-space: nowrap; }

.agent-open-work strong { display: flex; align-items: center; gap: 6px; }
.agent-open-work strong i { width: 7px; height: 7px; flex: 0 0 7px; border-radius: 50%; background: #efbd45; }
.agent-open-work .agent-blocked { color: #de7e88; }

.agent-expand-button {
  display: grid;
  width: 30px;
  height: 30px;
  place-items: center;
  border-radius: 8px;
  background: transparent;
  color: #a6afbc;
  cursor: pointer;
}

.agent-expand-button:hover,
.agent-expand-button:focus-visible { outline: 0; background: #414957; color: #eef1f5; }
.agent-expand-icon--open { transform: rotate(180deg); }

.agent-expanded-detail {
  display: grid;
  grid-template-columns: minmax(270px, 1.25fr) minmax(205px, 0.9fr) minmax(220px, 1fr) minmax(220px, 1fr) 190px;
  column-gap: 28px;
  margin: 2px 0 8px;
  padding: 18px 20px;
  border-radius: 9px;
  background: #323946;
}

.agent-expanded-detail > section {
  min-width: 0;
  padding: 0;
}

.agent-expanded-detail h2 {
  margin: 0 0 10px;
  color: #cdd3dc;
  font-size: 10px;
  font-weight: 750;
  letter-spacing: 0.04em;
  text-transform: uppercase;
}

.agent-purpose-panel p {
  margin: 0 0 15px;
  color: #b2bac6;
  font-size: 11px;
  line-height: 1.5;
}

.agent-purpose-panel ul { display: grid; gap: 6px; margin: 0; padding: 0 0 0 15px; color: #aeb6c2; font-size: 10px; line-height: 1.3; }

.agent-show-more,
.agent-more-skills {
  display: inline-flex;
  align-items: center;
  gap: 5px;
  margin-top: 10px;
  padding: 0;
  background: transparent;
  color: #aeb7c4;
  font-size: 10px;
  cursor: pointer;
}

.agent-show-more:hover,
.agent-show-more:focus-visible,
.agent-more-skills:hover,
.agent-more-skills:focus-visible { outline: 0; color: #e0b5df; }

.agent-config-primary { display: flex; align-items: center; justify-content: space-between; gap: 8px; margin-bottom: 12px; }
.agent-config-primary strong { color: #eef1f4; font-size: 12px; }
.agent-config-primary span { padding: 4px 6px; border-radius: 5px; background: #454c59; color: #c6a3c5; font-size: 9px; }

.agent-expanded-detail dl { display: grid; gap: 10px; margin: 0; }
.agent-expanded-detail dl div { display: flex; min-width: 0; justify-content: space-between; gap: 12px; }
.agent-expanded-detail dt { color: #949eac; font-size: 10px; }
.agent-expanded-detail dd { overflow: hidden; margin: 0; color: #d6dbe2; font-size: 10px; text-align: right; text-overflow: ellipsis; white-space: nowrap; }

.agent-skill-list { display: grid; gap: 7px; }
.agent-skill-row { gap: 8px; }
.agent-skill-row > span:first-child,
.agent-workspace-title > span:first-child {
  display: grid;
  width: 28px;
  height: 28px;
  flex: 0 0 28px;
  place-items: center;
  border-radius: 7px;
  background: #404856;
  color: #cf9fcc;
}
.agent-skill-row > span:nth-child(2),
.agent-workspace-title > span:nth-child(2) { display: flex; min-width: 0; flex: 1; flex-direction: column; gap: 3px; }
.agent-skill-row strong,
.agent-workspace-title strong { overflow: hidden; color: #e3e7ec; font-size: 10px; text-overflow: ellipsis; white-space: nowrap; }
.agent-skill-row small,
.agent-workspace-title small { overflow: hidden; color: #939dab; font-size: 9px; text-overflow: ellipsis; white-space: nowrap; }
.agent-skill-row > i { width: 7px; height: 7px; flex: 0 0 7px; border-radius: 50%; background: #63d39a; }
.agent-skill-list > p { margin: 8px 0; color: #929cab; font-size: 10px; }

.agent-workspace-title { gap: 9px; margin-bottom: 14px; }
.agent-workspace-title > span:first-child { background: #4a4254; }

.agent-expanded-actions { display: grid; align-content: start; gap: 9px; }
.agent-open-button,
.agent-settings-button {
  display: flex;
  width: 100%;
  height: 42px;
  align-items: center;
  justify-content: center;
  gap: 8px;
  border-radius: 8px;
  cursor: pointer;
}
.agent-open-button { background: #c494c2; color: #252934; font-weight: 700; }
.agent-settings-button { background: #3a414e; color: #cbd2db; }
.agent-open-button:hover,
.agent-open-button:focus-visible { outline: 0; background: #d2a5d0; }
.agent-settings-button:hover,
.agent-settings-button:focus-visible { outline: 0; background: #444c5a; color: #eef0f4; }

.agents-empty-state { display: grid; min-height: 220px; place-content: center; justify-items: center; gap: 8px; color: #8f99a8; text-align: center; }
.agents-empty-state strong { color: #cdd3db; }
.agents-empty-state span { font-size: 11px; }

.agents-status-bar {
  display: grid;
  min-height: 43px;
  flex: 0 0 43px;
  grid-template-columns: 1fr auto 1fr;
  align-items: center;
  margin: 0 20px 14px;
  padding: 0 16px;
  border-radius: 9px;
  background: #2b323d;
  color: #9ba5b3;
  font-size: 10px;
}

.agents-status-bar > div,
.agents-status-bar > div span,
.agents-refresh { display: flex; align-items: center; }
.agents-status-bar > div { gap: 18px; }
.agents-status-bar > div span { gap: 6px; }
.agents-status-bar strong { color: #d6dbe2; font-size: 10px; }
.agents-refresh { justify-self: end; gap: 8px; }

.agents-notice {
  position: absolute;
  z-index: 60;
  right: 20px;
  bottom: 67px;
  margin: 0;
  padding: 10px 13px;
  border-radius: 9px;
  background: #424957;
  color: #edf0f4;
  box-shadow: 0 12px 30px rgb(8 11 16 / 22%);
  font-size: 11px;
}

.agents-notice-enter-active,
.agents-notice-leave-active { transition: opacity 160ms ease, transform 160ms ease; }
.agents-notice-enter-from,
.agents-notice-leave-to { opacity: 0; transform: translateY(5px); }

.agents-modal-backdrop {
  position: fixed;
  z-index: 300;
  inset: 0;
  display: grid;
  padding: 20px;
  place-items: center;
  background: rgb(10 13 18 / 74%);
}

.agents-modal {
  display: grid;
  width: min(500px, calc(100vw - 32px));
  gap: 17px;
  padding: 20px;
  border-radius: 15px;
  background: #343b48;
  box-shadow: 0 24px 70px rgb(4 7 11 / 30%);
}

.agents-modal > header,
.agents-modal > footer { display: flex; align-items: center; justify-content: space-between; gap: 16px; }
.agents-modal h2 { margin: 0; color: #eef1f5; font-size: 18px; }
.agents-modal header p { margin: 6px 0 0; color: #9fa9b7; font-size: 11px; line-height: 1.45; }
.agents-modal header > button { display: grid; width: 34px; height: 34px; flex: 0 0 34px; place-items: center; border-radius: 9px; background: transparent; color: #aab3c0; cursor: pointer; }
.agents-modal header > button:hover,
.agents-modal header > button:focus-visible { outline: 0; background: #424a58; color: #f0f2f5; }
.agents-modal > label { display: grid; gap: 7px; }
.agents-modal label > span { color: #bbc3ce; font-size: 11px; font-weight: 650; }
.agents-modal input {
  width: 100%;
  height: 42px;
  padding: 0 11px;
  border: 0;
  outline: 0;
  border-radius: 9px;
  background: #29303b;
  color: #e4e8ee;
  font: 500 12px/1 Inter, ui-sans-serif, sans-serif;
}
.agents-modal input:focus { box-shadow: inset 0 0 0 1px rgb(209 166 211 / 48%); }
.agents-modal > footer { justify-content: flex-end; padding-top: 4px; }
.agents-modal-secondary,
.agents-modal-primary { height: 39px; padding: 0 14px; border-radius: 8px; cursor: pointer; }
.agents-modal-secondary { background: #414957; color: #cbd2dc; }
.agents-modal-primary { background: #c494c2; color: #252934; font-weight: 700; }
.agents-modal-primary:disabled { opacity: 0.45; cursor: default; }

@media (max-width: 1380px) {
  .agents-page-header { flex-direction: column; gap: 18px; padding-top: 26px; }
  .agents-heading { width: auto; flex-basis: auto; }
  .agents-controls { width: 100%; justify-content: flex-start; }
  .agents-column-headings,
  .agent-summary { grid-template-columns: minmax(180px, 1.2fr) minmax(170px, 1fr) minmax(180px, 1fr) minmax(210px, 1.1fr) minmax(120px, .72fr) 38px; }
  .agents-column-headings > span:nth-child(6),
  .agent-activity { display: none; }
  .agent-expanded-detail { grid-template-columns: 1.2fr .9fr 1fr 1fr; }
  .agent-expanded-actions { grid-column: 1 / -1; grid-template-columns: 1fr 1fr; padding: 14px 0 0; }
}

@media (max-width: 1050px) {
  .agents-page { padding-top: 52px; }
  .agents-page-header { padding: 16px 18px; }
  .agents-controls { display: grid; grid-template-columns: repeat(3, 1fr); }
  .agents-create-button { grid-column: 1 / -1; width: 100%; }
  .agents-filter,
  .agents-filter-trigger { width: 100%; min-width: 0 !important; }
  .agents-roster { padding: 0 10px; }
  .agents-column-headings { display: none; }
  .agent-summary { grid-template-columns: minmax(190px, 1.25fr) minmax(160px, 1fr) minmax(130px, .8fr) 38px; min-height: 82px; }
  .agent-runtime,
  .agent-assignment { display: none; }
  .agent-expanded-detail { grid-template-columns: 1fr 1fr; gap: 24px; }
  .agents-status-bar { grid-template-columns: auto 1fr; }
  .agents-status-bar > div { justify-self: end; }
  .agents-refresh { display: none; }
}

@media (max-width: 650px) {
  .agents-heading p { display: none; }
  .agents-controls { grid-template-columns: 1fr; }
  .agents-filter,
  .agents-create-button { grid-column: 1; }
  .agents-runtime-filter { display: none; }
  .agents-filter-menu { right: auto; left: 0; width: 100%; }
  .agent-summary { grid-template-columns: minmax(0, 1fr) minmax(110px, .7fr) 34px; column-gap: 10px; padding: 10px 8px; }
  .agent-role { display: none; }
  .agent-open-work { text-align: right; }
  .agent-open-work strong { justify-content: flex-end; }
  .agent-expanded-detail { grid-template-columns: 1fr; gap: 24px; padding: 18px; }
  .agent-expanded-actions { grid-template-columns: 1fr; }
  .agents-status-bar { grid-template-columns: 1fr; justify-items: center; gap: 5px; min-height: 58px; padding: 8px 12px; }
  .agents-status-bar > div { justify-self: center; gap: 11px; }
}
</style>
