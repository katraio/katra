<script setup lang="ts">
import {
  Activity,
  Archive,
  Boxes,
  Check,
  CheckCircle2,
  ChevronDown,
  CircleDot,
  Code2,
  FileText,
  GitBranch,
  GitCommitHorizontal,
  GitPullRequest,
  History,
  Leaf,
  MoreHorizontal,
  PackageCheck,
  PanelsTopLeft,
  Play,
  Plus,
  Rocket,
  Search,
  Server,
  Settings,
  ShieldCheck,
  Tag,
  Workflow,
  X,
  XCircle,
} from "@lucide/vue";
import { computed, nextTick, onBeforeUnmount, onMounted, ref, watch, type Component } from "vue";
import { useUiPreference } from "../../composables/useUiPreference";
import ProjectCodeView from "./ProjectCodeView.vue";
import ProjectPipelineView from "./ProjectPipelineView.vue";
import ProjectWorkView from "./ProjectWorkView.vue";
import { projectWorkspaces, type ProjectWorkspace } from "./projectWorkspace";

type ProjectTab = "overview" | "work" | "code" | "pipelines" | "releases" | "activity" | "settings";
type OpenMenu = "project" | "repository" | "branch" | "file" | null;

const root = ref<HTMLElement | null>(null);
const content = ref<HTMLElement | null>(null);
const selectedProjectId = useUiPreference(
  "project-workspace-id",
  "laravel-app",
  (value): value is string => typeof value === "string" && projectWorkspaces.some((project) => project.id === value),
  "session",
);
const activeTab = useUiPreference(
  "project-workspace-tab",
  "pipelines" as ProjectTab,
  (value): value is ProjectTab => typeof value === "string" && ["overview", "work", "code", "pipelines", "releases", "activity", "settings"].includes(value),
  "session",
);
const openMenu = ref<OpenMenu>(null);
const selectedBranch = ref("");
const notice = ref("");
const settingsName = ref("");
const settingsPurpose = ref("");
const archivedIncluded = ref(false);
let noticeTimer: number | undefined;

const tabs: { id: ProjectTab; label: string; count?: number; icon: Component }[] = [
  { id: "overview", label: "Overview", icon: Boxes },
  { id: "work", label: "Work", count: 7, icon: CircleDot },
  { id: "code", label: "Code", icon: Code2 },
  { id: "pipelines", label: "Pipelines", count: 1, icon: Workflow },
  { id: "releases", label: "Releases", icon: Rocket },
  { id: "activity", label: "Activity", icon: Activity },
  { id: "settings", label: "Settings", icon: Settings },
];

const selectedProject = computed<ProjectWorkspace>(() => projectWorkspaces.find((project) => project.id === selectedProjectId.value) ?? projectWorkspaces[0]);
const selectedProjectIcon = computed<Component>(() => {
  if (selectedProject.value.kind === "server") return Server;
  if (selectedProject.value.kind === "client") return PanelsTopLeft;
  return Leaf;
});
const repositoryLabel = computed(() => selectedProject.value.repository);
const branchLabel = computed(() => selectedBranch.value || selectedProject.value.branch);
const fileOptions = ["app/Services/InventorySync.php", "tests/Feature/InventoryImportIdempotencyTest.php", "routes/api.php", "README.md"];

const overviewSignals = computed(() => [
  { icon: CircleDot, label: selectedProject.value.issue.id, title: selectedProject.value.issue.title, meta: selectedProject.value.issue.status, action: "work" as ProjectTab, tone: "yellow" },
  { icon: GitPullRequest, label: selectedProject.value.issue.reviewId, title: selectedProject.value.issue.reviewTitle, meta: `Review requested from ${selectedProject.value.issue.reviewer}`, action: "work" as ProjectTab, tone: "lavender" },
  { icon: Workflow, label: `Pipeline ${selectedProject.value.issue.pipelineNumber}`, title: "Build step needs attention", meta: "1 failed test · exact revision retained", action: "pipelines" as ProjectTab, tone: "red" },
]);

const releaseRows = computed(() => [
  { version: selectedProject.value.release, channel: "Staging", state: "Awaiting acceptance", revision: selectedProject.value.issue.revision, date: "Aug 7, 2026 11:58 AM", tone: "attention" },
  { version: selectedProject.value.id === "laravel-app" ? "v1.3.2" : "v0.8.1", channel: "Production", state: "Healthy", revision: "9c8b7a1", date: "Aug 5, 2026 4:42 PM", tone: "passed" },
  { version: selectedProject.value.id === "laravel-app" ? "v1.3.1" : "v0.8.0", channel: "Production", state: "Superseded", revision: "7e6d5c4", date: "Aug 1, 2026 2:19 PM", tone: "quiet" },
]);

function toggleMenu(menu: Exclude<OpenMenu, null>) {
  openMenu.value = openMenu.value === menu ? null : menu;
}

function selectProject(project: ProjectWorkspace) {
  selectedProjectId.value = project.id;
  selectedBranch.value = project.branch;
  settingsName.value = project.name;
  settingsPurpose.value = project.purpose;
  openMenu.value = null;
  showNotice(`${project.organization} / ${project.product} / ${project.name} opened.`);
}

function selectTab(tab: ProjectTab) {
  activeTab.value = tab;
  openMenu.value = null;
  nextTick(() => content.value?.scrollTo({ top: 0, left: 0 }));
}

function showNotice(message: string) {
  notice.value = message;
  window.clearTimeout(noticeTimer);
  noticeTimer = window.setTimeout(() => {
    notice.value = "";
  }, 2600);
}

function handleDocumentPointerDown(event: PointerEvent) {
  const target = event.target as HTMLElement;
  if (!root.value?.contains(target)) return;
  if (!target.closest(".project-menu-control")) openMenu.value = null;
}

function handleEscape(event: KeyboardEvent) {
  if (event.key === "Escape") openMenu.value = null;
}

function saveProjectSettings() {
  showNotice(`${settingsName.value || selectedProject.value.name} settings saved.`);
}

watch(selectedProject, (project) => {
  selectedBranch.value = project.branch;
  settingsName.value = project.name;
  settingsPurpose.value = project.purpose;
}, { immediate: true });

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
  <section ref="root" class="project-workspace" aria-label="Project workspace">
    <header class="project-workspace-header">
      <div class="project-header-topline">
        <div class="project-menu-control project-switcher">
          <button type="button" class="project-switcher-trigger" :aria-expanded="openMenu === 'project'" aria-haspopup="menu" @click="toggleMenu('project')">
            <span class="project-header-icon" :class="`project-header-icon--${selectedProject.kind}`"><component :is="selectedProjectIcon" :size="22" :stroke-width="1.7" aria-hidden="true" /></span>
            <span class="project-breadcrumb"><strong>{{ selectedProject.organization }}</strong><i>/</i><strong>{{ selectedProject.product }}</strong><i>/</i><strong>{{ selectedProject.name }}</strong></span>
            <ChevronDown :size="16" aria-hidden="true" />
          </button>
          <div v-if="openMenu === 'project'" class="project-popover project-switcher-menu" role="menu">
            <header><span>Switch project</span><small>Organization / Product / Project</small></header>
            <button v-for="project in projectWorkspaces" :key="project.id" type="button" role="menuitemradio" :aria-checked="selectedProject.id === project.id" @click="selectProject(project)">
              <span class="switcher-project-icon"><component :is="project.kind === 'server' ? Server : project.kind === 'client' ? PanelsTopLeft : Leaf" :size="17" aria-hidden="true" /></span>
              <span><strong>{{ project.name }}</strong><small>{{ project.organization }} / {{ project.product }}</small></span>
              <Check v-if="selectedProject.id === project.id" :size="15" aria-hidden="true" />
            </button>
            <footer><button type="button" @click="showNotice('Project creation flow opened.'); openMenu = null"><Plus :size="15" aria-hidden="true" />Create project</button><button type="button" @click="showNotice('Archived projects opened.'); openMenu = null"><Archive :size="15" aria-hidden="true" />Archived</button></footer>
          </div>
        </div>

        <div class="project-header-controls">
          <div class="project-menu-control project-repository-control">
            <button type="button" :aria-expanded="openMenu === 'repository'" aria-haspopup="menu" @click="toggleMenu('repository')"><GitBranch :size="16" aria-hidden="true" /><span>{{ repositoryLabel }}</span><ChevronDown :size="14" aria-hidden="true" /></button>
            <div v-if="openMenu === 'repository'" class="project-popover compact-project-menu" role="menu">
              <button type="button" role="menuitemradio" aria-checked="true" @click="openMenu = null"><GitBranch :size="16" aria-hidden="true" /><span><strong>{{ repositoryLabel }}</strong><small>Primary repository</small></span><Check :size="14" aria-hidden="true" /></button>
              <button type="button" role="menuitem" @click="showNotice('Repository connection opened.'); openMenu = null"><Plus :size="16" aria-hidden="true" /><span><strong>Connect repository</strong><small>Import, mirror, or create</small></span></button>
            </div>
          </div>

          <div class="project-menu-control project-branch-control">
            <button type="button" :aria-expanded="openMenu === 'branch'" aria-haspopup="menu" @click="toggleMenu('branch')"><GitBranch :size="15" aria-hidden="true" /><span>{{ branchLabel }}</span><ChevronDown :size="14" aria-hidden="true" /></button>
            <div v-if="openMenu === 'branch'" class="project-popover compact-project-menu" role="menu">
              <button v-for="branch in [selectedProject.branch, 'main', 'staging']" :key="branch" type="button" role="menuitemradio" :aria-checked="branchLabel === branch" @click="selectedBranch = branch; openMenu = null; showNotice(`${branch} selected.`)"><GitBranch :size="15" aria-hidden="true" /><span><strong>{{ branch }}</strong><small>{{ branch === selectedProject.branch ? 'Current workspace' : 'Repository branch' }}</small></span><Check v-if="branchLabel === branch" :size="14" aria-hidden="true" /></button>
            </div>
          </div>

          <div v-if="activeTab === 'code'" class="project-menu-control project-file-control">
            <button type="button" :aria-expanded="openMenu === 'file'" aria-haspopup="dialog" @click="toggleMenu('file')"><Search :size="16" aria-hidden="true" /><span>Go to file…</span><kbd>⌘P</kbd></button>
            <div v-if="openMenu === 'file'" class="project-popover file-finder" role="dialog" aria-label="Go to repository file">
              <header><Search :size="16" aria-hidden="true" /><span>Repository files</span><kbd>Esc</kbd></header>
              <button v-for="file in fileOptions" :key="file" type="button" @click="showNotice(`${file} opened.`); openMenu = null"><FileText :size="15" aria-hidden="true" /><span>{{ file }}</span></button>
            </div>
          </div>

          <button type="button" class="project-header-more" aria-label="More project actions"><MoreHorizontal :size="18" aria-hidden="true" /></button>
        </div>
      </div>

      <nav class="project-local-nav" aria-label="Project sections">
        <button v-for="tab in tabs" :key="tab.id" type="button" :class="{ 'is-active': activeTab === tab.id }" :aria-current="activeTab === tab.id ? 'page' : undefined" @click="selectTab(tab.id)">
          <component :is="tab.icon" :size="15" :stroke-width="1.7" aria-hidden="true" />
          <span>{{ tab.label }}</span><i v-if="tab.count">{{ tab.count }}</i>
        </button>
      </nav>
    </header>

    <div ref="content" class="project-workspace-content">
      <section v-if="activeTab === 'overview'" class="project-overview-view">
        <header class="overview-intro"><div><span>Project overview</span><h1>{{ selectedProject.name }}</h1><p>{{ selectedProject.purpose }}</p></div><button type="button" @click="selectTab('work')">Open work <CircleDot :size="16" aria-hidden="true" /></button></header>

        <section class="overview-delivery-flow" aria-label="Current delivery path">
          <header><h2>Current delivery</h2><span>Request → reviewed revision → verified release</span></header>
          <div>
            <button type="button" @click="selectTab('work')"><CircleDot :size="20" aria-hidden="true" /><span><small>Issue</small><strong>{{ selectedProject.issue.id }}</strong><em>{{ selectedProject.issue.status }}</em></span></button>
            <span class="overview-flow-arrow">→</span>
            <button type="button" @click="selectTab('work')"><GitPullRequest :size="20" aria-hidden="true" /><span><small>Change review</small><strong>{{ selectedProject.issue.reviewId }}</strong><em>Pending review</em></span></button>
            <span class="overview-flow-arrow">→</span>
            <button type="button" @click="selectTab('pipelines')"><Workflow :size="20" aria-hidden="true" /><span><small>Pipeline</small><strong>{{ selectedProject.issue.pipelineNumber }}</strong><em class="needs-attention">Needs attention</em></span></button>
            <span class="overview-flow-arrow">→</span>
            <button type="button" @click="selectTab('releases')"><Rocket :size="20" aria-hidden="true" /><span><small>Release</small><strong>{{ selectedProject.release }}</strong><em>Awaiting acceptance</em></span></button>
          </div>
        </section>

        <div class="overview-columns">
          <section class="overview-open-work"><header><h2>Needs attention</h2><button type="button" @click="selectTab('work')">View all</button></header><button v-for="signal in overviewSignals" :key="signal.label" type="button" @click="selectTab(signal.action)"><component :is="signal.icon" :size="19" aria-hidden="true" /><span><small>{{ signal.label }}</small><strong>{{ signal.title }}</strong><em>{{ signal.meta }}</em></span><i :class="`overview-signal--${signal.tone}`" /></button></section>
          <section class="overview-project-state"><h2>Project state</h2><dl><div><dt>Repository</dt><dd>{{ selectedProject.repository }}</dd></div><div><dt>Default branch</dt><dd>main</dd></div><div><dt>Active branch</dt><dd>{{ branchLabel }}</dd></div><div><dt>Latest release</dt><dd>{{ selectedProject.release }}</dd></div><div><dt>Protection</dt><dd><ShieldCheck :size="14" aria-hidden="true" />Required review</dd></div></dl><button type="button" @click="selectTab('settings')">Project settings <Settings :size="15" aria-hidden="true" /></button></section>
        </div>
      </section>

      <ProjectWorkView v-else-if="activeTab === 'work'" :project="selectedProject" @browse-code="selectTab('code')" @open-pipeline="selectTab('pipelines')" @notify="showNotice" />
      <ProjectCodeView v-else-if="activeTab === 'code'" :project="selectedProject" @open-review="selectTab('work')" @notify="showNotice" />
      <ProjectPipelineView v-else-if="activeTab === 'pipelines'" :project="selectedProject" @open-review="selectTab('work')" @browse-code="selectTab('code')" @notify="showNotice" />

      <section v-else-if="activeTab === 'releases'" class="project-releases-view">
        <header><div><span>Releases</span><h1>Verified delivery history</h1><p>Every release stays tied to its reviewed revision, checks, deployment evidence, and acceptance state.</p></div><button type="button" @click="showNotice('New release preparation opened.')"><Rocket :size="16" aria-hidden="true" />Prepare release</button></header>
        <div class="releases-layout">
          <section class="release-list"><div class="release-list-heading"><span>Version</span><span>Channel</span><span>Status</span><span>Revision</span><span>Published</span></div><button v-for="release in releaseRows" :key="release.version" type="button" @click="showNotice(`${release.version} selected.`)"><span><Tag :size="16" aria-hidden="true" /><strong>{{ release.version }}</strong></span><span>{{ release.channel }}</span><span :class="`release-state--${release.tone}`"><i />{{ release.state }}</span><code>{{ release.revision }}</code><span>{{ release.date }}</span></button></section>
          <aside class="release-evidence"><span>Selected release</span><h2>{{ selectedProject.release }}</h2><p>Staging is healthy. One human acceptance remains before production release can be prepared.</p><dl><div><dt>Reviewed revision</dt><dd>{{ selectedProject.issue.revision }}</dd></div><div><dt>Pipeline</dt><dd>{{ selectedProject.issue.pipelineNumber }}</dd></div><div><dt>Tests</dt><dd><CheckCircle2 :size="14" aria-hidden="true" />111 passed</dd></div><div><dt>Security</dt><dd><ShieldCheck :size="14" aria-hidden="true" />Policy passed</dd></div><div><dt>Rollback</dt><dd>Previous image retained</dd></div></dl><button type="button" @click="showNotice('Staging acceptance review opened.')">Review staging acceptance</button></aside>
        </div>
      </section>

      <section v-else-if="activeTab === 'activity'" class="project-activity-view">
        <header><div><span>Activity</span><h1>Project history</h1><p>Requests, source changes, pipelines, reviews, and releases in one scoped timeline.</p></div><button type="button" @click="archivedIncluded = !archivedIncluded"><History :size="16" aria-hidden="true" />{{ archivedIncluded ? 'Hide archived' : 'Include archived' }}</button></header>
        <div class="activity-timeline">
          <article><span class="activity-icon activity-icon--yellow"><XCircle :size="17" aria-hidden="true" /></span><div><strong>Pipeline {{ selectedProject.issue.pipelineNumber }} needs attention</strong><p>Build failed after one inventory idempotency test returned an unexpected payload.</p><small>Today at 9:12 AM · Infra Guard</small></div><button type="button" @click="selectTab('pipelines')">Open pipeline</button></article>
          <article><span class="activity-icon"><GitPullRequest :size="17" aria-hidden="true" /></span><div><strong>{{ selectedProject.issue.reviewer }} requested clarification on {{ selectedProject.issue.reviewId }}</strong><p>One inline conversation remains unresolved on the source-normalization path.</p><small>Today at 8:41 AM · {{ selectedProject.issue.reviewer }}</small></div><button type="button" @click="selectTab('work')">Open review</button></article>
          <article><span class="activity-icon activity-icon--green"><GitCommitHorizontal :size="17" aria-hidden="true" /></span><div><strong>{{ selectedProject.issue.revision }} pushed to {{ branchLabel }}</strong><p>{{ selectedProject.issue.reviewTitle }}</p><small>Yesterday at 4:18 PM · {{ selectedProject.issue.assignee }}</small></div><button type="button" @click="selectTab('code')">Browse code</button></article>
          <article><span class="activity-icon activity-icon--green"><PackageCheck :size="17" aria-hidden="true" /></span><div><strong>{{ releaseRows[1].version }} deployed to production</strong><p>Health checks passed and the prior image remains available for rollback.</p><small>Aug 5, 2026 at 4:42 PM · Vector</small></div><button type="button" @click="selectTab('releases')">View release</button></article>
        </div>
      </section>

      <section v-else class="project-settings-view">
        <header><div><span>Settings</span><h1>Project configuration</h1><p>Manage the durable project boundary, repository defaults, and review policy.</p></div><button type="button" class="settings-save" @click="saveProjectSettings"><Check :size="16" aria-hidden="true" />Save changes</button></header>
        <form @submit.prevent="saveProjectSettings">
          <section><h2>Project details</h2><label><span>Project name</span><input v-model="settingsName" /></label><label><span>Purpose</span><textarea v-model="settingsPurpose" /></label></section>
          <section><h2>Repository defaults</h2><label><span>Primary repository</span><input :value="selectedProject.repository" readonly /></label><label><span>Default branch</span><input value="main" readonly /></label><label class="settings-toggle"><span><strong>Require a linked work item</strong><small>Every change review must retain its originating request or issue.</small></span><input type="checkbox" checked /></label><label class="settings-toggle"><span><strong>Require pipeline success</strong><small>Block approval until all required checks have passed.</small></span><input type="checkbox" checked /></label></section>
          <section><h2>Review policy</h2><label class="settings-toggle"><span><strong>One human approval</strong><small>Agent policy checks supplement but do not replace human review.</small></span><input type="checkbox" checked /></label><label class="settings-toggle"><span><strong>Protect exact reviewed revision</strong><small>Release preparation fails closed if the revision changes after approval.</small></span><input type="checkbox" checked /></label></section>
        </form>
      </section>
    </div>

    <Transition name="project-notice">
      <p v-if="notice" class="project-notice" role="status">{{ notice }}</p>
    </Transition>
  </section>
</template>

<style scoped>
.project-workspace { position: relative; display: flex; width: 100%; height: 100%; min-width: 0; min-height: 0; flex-direction: column; overflow: hidden; background: #303744; color: #dce1e8; }
.project-workspace-header { position: relative; z-index: 20; flex: 0 0 auto; background: #2c333e; }
.project-header-topline { display: flex; min-height: 72px; align-items: center; justify-content: space-between; gap: 24px; padding: 10px 22px 6px; }
.project-menu-control { position: relative; }
.project-switcher { min-width: 0; }
.project-switcher-trigger { display: flex; min-width: 0; align-items: center; gap: 10px; padding: 0; background: transparent; cursor: pointer; }
.project-header-icon { display: grid; width: 38px; height: 38px; flex: 0 0 38px; place-items: center; border-radius: 9px; background: #3a414e; color: #c494c2; }
.project-header-icon--leaf { color: #98c85d; }
.project-breadcrumb { display: flex; min-width: 0; align-items: center; gap: 9px; }
.project-breadcrumb strong { overflow: hidden; color: #f0f2f5; font-size: 16px; font-weight: 700; text-overflow: ellipsis; white-space: nowrap; }
.project-breadcrumb i { color: #7f8997; font-style: normal; }
.project-switcher-trigger > svg { flex: 0 0 auto; color: #919caa; }
.project-switcher-trigger:hover .project-breadcrumb strong,
.project-switcher-trigger:focus-visible .project-breadcrumb strong { color: #f5dff2; }
.project-header-controls { display: flex; min-width: 0; align-items: center; justify-content: flex-end; gap: 9px; }
.project-header-controls > .project-menu-control > button,
.project-header-more { display: flex; height: 38px; min-width: 0; align-items: center; gap: 8px; padding: 0 11px; border-radius: 8px; background: #333b47; color: #bdc5cf; cursor: pointer; }
.project-header-controls > .project-menu-control > button:hover,
.project-header-controls > .project-menu-control > button:focus-visible,
.project-header-more:hover,
.project-header-more:focus-visible { outline: 0; background: #3d4653; color: #eef0f3; }
.project-header-controls button > span { overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.project-repository-control button { max-width: 190px; }
.project-branch-control button { max-width: 260px; }
.project-file-control button { width: 200px; }
.project-header-controls kbd { margin-left: auto; color: #7f8997; font: 650 9px/1 Inter, sans-serif; }
.project-header-more { display: grid; width: 38px; padding: 0; place-items: center; background: transparent; }

.project-popover { position: absolute; z-index: 50; top: calc(100% + 8px); padding: 7px; border-radius: 11px; background: #252c35; box-shadow: 0 18px 42px rgb(8 11 16 / 38%); }
.project-switcher-menu { left: 0; width: 330px; }
.project-switcher-menu > header { display: grid; gap: 4px; padding: 9px 9px 11px; }
.project-switcher-menu > header span { color: #dfe3e8; font-size: 11px; font-weight: 700; }
.project-switcher-menu > header small { color: #7f8a98; font-size: 9px; }
.project-switcher-menu > button,
.compact-project-menu > button { display: grid; width: 100%; min-height: 48px; grid-template-columns: 30px minmax(0, 1fr) 18px; align-items: center; gap: 9px; padding: 7px 9px; border-radius: 8px; background: transparent; text-align: left; cursor: pointer; }
.project-switcher-menu > button:hover,
.project-switcher-menu > button:focus-visible,
.compact-project-menu > button:hover,
.compact-project-menu > button:focus-visible { outline: 0; background: #343d49; }
.switcher-project-icon { display: grid; width: 30px; height: 30px; place-items: center; border-radius: 7px; background: #3d4552; color: #c494c2; }
.project-switcher-menu > button > span:nth-child(2),
.compact-project-menu > button > span { display: grid; min-width: 0; gap: 4px; }
.project-switcher-menu strong,
.compact-project-menu strong { color: #dfe3e8; font-size: 10px; }
.project-switcher-menu small,
.compact-project-menu small { color: #84909e; font-size: 9px; }
.project-switcher-menu > footer { display: flex; gap: 5px; margin-top: 5px; padding: 6px 4px 2px; }
.project-switcher-menu > footer button { display: inline-flex; height: 32px; flex: 1; align-items: center; justify-content: center; gap: 6px; border-radius: 7px; background: #323a46; color: #aeb7c2; font-size: 9px; cursor: pointer; }
.compact-project-menu { right: 0; width: 280px; }
.file-finder { right: 0; width: 390px; }
.file-finder > header { display: flex; min-height: 42px; align-items: center; gap: 9px; padding: 0 9px; color: #9ba6b3; }
.file-finder > header span { flex: 1; color: #d7dce2; font-size: 10px; }
.file-finder > header kbd { color: #778392; font-size: 9px; }
.file-finder > button { display: flex; width: 100%; height: 38px; align-items: center; gap: 9px; padding: 0 10px; border-radius: 7px; background: transparent; color: #aeb7c2; text-align: left; cursor: pointer; }
.file-finder > button:hover,
.file-finder > button:focus-visible { outline: 0; background: #343d49; color: #e5e8ec; }
.file-finder > button span { overflow: hidden; font: 500 10px/1 ui-monospace, SFMono-Regular, Menlo, monospace; text-overflow: ellipsis; white-space: nowrap; }

.project-local-nav { display: flex; min-height: 42px; align-items: flex-end; gap: 4px; padding: 0 22px; overflow-x: auto; }
.project-local-nav button { position: relative; display: inline-flex; height: 40px; flex: 0 0 auto; align-items: center; gap: 7px; padding: 0 12px; background: transparent; color: #8f99a7; cursor: pointer; }
.project-local-nav button:hover,
.project-local-nav button:focus-visible { outline: 0; color: #e5e8ec; }
.project-local-nav button.is-active { color: #f0d9ee; }
.project-local-nav button.is-active::after { position: absolute; right: 8px; bottom: 0; left: 8px; height: 2px; border-radius: 2px; background: #c494c2; content: ""; }
.project-local-nav i { display: grid; min-width: 19px; height: 19px; place-items: center; padding: 0 5px; border-radius: 999px; background: #45404b; color: #c9a2c7; font-size: 8px; font-style: normal; }
.project-workspace-content { min-width: 0; min-height: 0; flex: 1; overflow: hidden; }

.project-overview-view,
.project-releases-view,
.project-activity-view,
.project-settings-view { height: 100%; overflow-y: auto; padding: 32px 36px 50px; }
.overview-intro,
.project-releases-view > header,
.project-activity-view > header,
.project-settings-view > header { display: flex; align-items: flex-start; justify-content: space-between; gap: 24px; }
.overview-intro > div > span,
.project-releases-view > header span,
.project-activity-view > header span,
.project-settings-view > header span { color: #c99bc6; font-size: 10px; font-weight: 700; letter-spacing: .04em; text-transform: uppercase; }
.overview-intro h1,
.project-releases-view h1,
.project-activity-view h1,
.project-settings-view h1 { margin: 8px 0 0; color: #f0f2f5; font-size: 24px; letter-spacing: -.025em; }
.overview-intro p,
.project-releases-view > header p,
.project-activity-view > header p,
.project-settings-view > header p { max-width: 620px; margin: 9px 0 0; color: #929dab; font-size: 11px; line-height: 1.5; }
.overview-intro > button,
.project-releases-view > header button,
.project-activity-view > header button,
.settings-save { display: inline-flex; height: 40px; align-items: center; gap: 8px; padding: 0 14px; border-radius: 8px; background: #c494c2; color: #252934; font-weight: 700; cursor: pointer; }
.overview-delivery-flow { margin-top: 34px; padding: 23px 24px; border-radius: 11px; background: #343b47; }
.overview-delivery-flow > header { display: flex; align-items: center; justify-content: space-between; gap: 20px; }
.overview-delivery-flow h2,
.overview-columns h2,
.overview-project-state h2 { margin: 0; color: #e2e6ea; font-size: 13px; }
.overview-delivery-flow > header span { color: #82909e; font-size: 9px; }
.overview-delivery-flow > div { display: grid; grid-template-columns: 1fr auto 1fr auto 1fr auto 1fr; align-items: center; gap: 13px; margin-top: 22px; }
.overview-delivery-flow button { display: grid; min-width: 0; grid-template-columns: 24px minmax(0, 1fr); align-items: start; gap: 9px; padding: 8px; border-radius: 8px; background: transparent; color: #c494c2; text-align: left; cursor: pointer; }
.overview-delivery-flow button:hover { background: #3d4552; }
.overview-delivery-flow button > span { display: grid; min-width: 0; gap: 4px; }
.overview-delivery-flow small { color: #8793a1; font-size: 8px; }
.overview-delivery-flow strong { overflow: hidden; color: #e1e5e9; font-size: 11px; text-overflow: ellipsis; white-space: nowrap; }
.overview-delivery-flow em { color: #aab3bf; font-size: 9px; font-style: normal; }
.overview-delivery-flow .needs-attention { color: #e1af4e; }
.overview-flow-arrow { color: #687585; }
.overview-columns { display: grid; grid-template-columns: minmax(0, 1.5fr) minmax(280px, .7fr); gap: 22px; margin-top: 24px; }
.overview-open-work,
.overview-project-state { min-width: 0; padding: 22px 24px; border-radius: 11px; background: #343b47; }
.overview-open-work > header { display: flex; align-items: center; justify-content: space-between; margin-bottom: 10px; }
.overview-open-work > header button { padding: 0; background: transparent; color: #c79bc3; cursor: pointer; }
.overview-open-work > button { display: grid; width: 100%; min-height: 66px; grid-template-columns: 30px minmax(0, 1fr) 8px; align-items: center; gap: 10px; padding: 8px; border-radius: 8px; background: transparent; color: #c494c2; text-align: left; cursor: pointer; }
.overview-open-work > button:hover { background: #3e4653; }
.overview-open-work > button > span { display: grid; min-width: 0; gap: 4px; }
.overview-open-work small { color: #9c88a3; font-size: 8px; }
.overview-open-work strong { overflow: hidden; color: #dfe3e8; font-size: 11px; text-overflow: ellipsis; white-space: nowrap; }
.overview-open-work em { color: #8995a3; font-size: 9px; font-style: normal; }
.overview-open-work > button > i { width: 7px; height: 7px; border-radius: 50%; }
.overview-signal--yellow { background: #e8b64e; }
.overview-signal--lavender { background: #c494c2; }
.overview-signal--red { background: #e36a5d; }
.overview-project-state dl { display: grid; gap: 17px; margin: 22px 0 0; }
.overview-project-state dl div { display: flex; justify-content: space-between; gap: 16px; }
.overview-project-state dt { color: #8c97a5; font-size: 9px; }
.overview-project-state dd { display: inline-flex; min-width: 0; align-items: center; gap: 6px; margin: 0; color: #cfd5dc; font-size: 10px; text-align: right; }
.overview-project-state > button { display: inline-flex; align-items: center; gap: 7px; margin-top: 24px; padding: 0; background: transparent; color: #c79bc3; cursor: pointer; }

.project-releases-view > header button { background: #c494c2; }
.releases-layout { display: grid; grid-template-columns: minmax(620px, 1fr) 310px; gap: 28px; margin-top: 34px; }
.release-list-heading,
.release-list > button { display: grid; grid-template-columns: 120px 100px minmax(150px, 1fr) 100px 160px; align-items: center; gap: 13px; }
.release-list-heading { min-height: 36px; padding: 0 10px; color: #8793a1; font-size: 9px; }
.release-list > button { width: 100%; min-height: 60px; padding: 0 10px; border-radius: 8px; background: transparent; color: #aeb7c2; font-size: 10px; text-align: left; cursor: pointer; }
.release-list > button:hover { background: #38404d; }
.release-list > button > span:first-child { display: flex; align-items: center; gap: 8px; color: #c99bc6; }
.release-list strong { color: #e1e5e9; }
.release-list code { color: #c99bc6; font: 600 10px/1 ui-monospace, monospace; }
.release-list > button > span:nth-child(3) { display: inline-flex; align-items: center; gap: 7px; }
.release-list > button > span:nth-child(3) i { width: 7px; height: 7px; border-radius: 50%; background: currentColor; }
.release-state--attention { color: #e5b54f; }
.release-state--passed { color: #72d19c; }
.release-state--quiet { color: #8994a2; }
.release-evidence { padding: 23px; border-radius: 11px; background: #343b47; }
.release-evidence > span { color: #909baa; font-size: 9px; }
.release-evidence h2 { margin: 8px 0 0; color: #efdbed; font-size: 21px; }
.release-evidence p { margin: 13px 0 0; color: #a5afbb; font-size: 10px; line-height: 1.55; }
.release-evidence dl { display: grid; gap: 16px; margin: 24px 0 0; }
.release-evidence dl div { display: flex; justify-content: space-between; gap: 14px; }
.release-evidence dt { color: #8894a2; font-size: 9px; }
.release-evidence dd { display: inline-flex; align-items: center; gap: 6px; margin: 0; color: #d5dbe1; font-size: 10px; text-align: right; }
.release-evidence dd svg { color: #72d19c; }
.release-evidence button { width: 100%; height: 39px; margin-top: 24px; border-radius: 8px; background: #c494c2; color: #252934; font-weight: 700; cursor: pointer; }

.project-activity-view > header button { background: #39424f; color: #c7ced7; font-weight: 550; }
.activity-timeline { max-width: 940px; margin: 35px auto 0; }
.activity-timeline article { display: grid; min-height: 92px; grid-template-columns: 38px minmax(0, 1fr) auto; align-items: start; gap: 14px; padding: 13px 10px; border-radius: 9px; }
.activity-timeline article + article { margin-top: 6px; }
.activity-timeline article:hover { background: #363e4a; }
.activity-icon { display: grid; width: 36px; height: 36px; place-items: center; border-radius: 9px; background: #494150; color: #cc9fc9; }
.activity-icon--yellow { background: #4b4438; color: #e6b44e; }
.activity-icon--green { background: #354940; color: #73d19d; }
.activity-timeline article > div { display: grid; gap: 6px; }
.activity-timeline strong { color: #e0e4e9; font-size: 11px; }
.activity-timeline p { margin: 0; color: #a0aab6; font-size: 10px; line-height: 1.45; }
.activity-timeline small { color: #7f8b99; font-size: 9px; }
.activity-timeline article > button { align-self: center; padding: 0; background: transparent; color: #c99bc6; cursor: pointer; }

.project-settings-view > header { max-width: 930px; margin: 0 auto; }
.project-settings-view form { display: grid; max-width: 930px; gap: 18px; margin: 32px auto 0; }
.project-settings-view form section { padding: 24px; border-radius: 11px; background: #343b47; }
.project-settings-view h2 { margin: 0 0 19px; color: #e3e7eb; font-size: 13px; }
.project-settings-view label { display: grid; gap: 7px; }
.project-settings-view label + label { margin-top: 16px; }
.project-settings-view label > span { color: #aab3be; font-size: 10px; }
.project-settings-view input,
.project-settings-view textarea { width: 100%; border: 0; border-radius: 8px; outline: 0; background: #29313b; color: #e0e4e9; font: 500 11px/1.4 Inter, sans-serif; }
.project-settings-view input { height: 39px; padding: 0 11px; }
.project-settings-view textarea { min-height: 82px; padding: 10px 11px; resize: vertical; }
.settings-toggle { grid-template-columns: minmax(0, 1fr) auto; align-items: center; }
.settings-toggle > span { display: grid; gap: 5px; }
.settings-toggle strong { color: #d9dee4; font-size: 11px; }
.settings-toggle small { color: #8793a1; font-size: 9px; }
.settings-toggle input { width: 35px; height: 20px; accent-color: #c494c2; }

.project-notice { position: absolute; z-index: 100; right: 22px; bottom: 20px; max-width: 360px; margin: 0; padding: 12px 15px; border-radius: 9px; background: #212832; box-shadow: 0 12px 34px rgb(7 10 15 / 36%); color: #e2e6eb; font-size: 11px; }
.project-notice-enter-active,
.project-notice-leave-active { transition: opacity 160ms ease, transform 160ms ease; }
.project-notice-enter-from,
.project-notice-leave-to { opacity: 0; transform: translateY(6px); }

@media (max-width: 1120px) {
  .project-header-topline { padding-right: 16px; padding-left: 18px; }
  .project-repository-control { display: none; }
  .project-breadcrumb strong { font-size: 14px; }
  .project-overview-view,
  .project-releases-view,
  .project-activity-view,
  .project-settings-view { padding-right: 24px; padding-left: 24px; }
  .releases-layout { grid-template-columns: 1fr; }
  .release-evidence { max-width: 500px; }
}

@media (max-width: 820px) {
  .project-header-topline { align-items: flex-start; flex-direction: column; gap: 8px; padding-top: 16px; }
  .project-header-controls { width: 100%; justify-content: flex-start; }
  .project-branch-control { min-width: 0; flex: 1; }
  .project-branch-control > button { width: 100%; max-width: none !important; }
  .project-file-control { flex: 1; }
  .project-file-control button { width: 100%; }
  .project-local-nav { padding-left: 12px; }
  .project-local-nav button { padding: 0 10px; }
  .overview-delivery-flow > div { grid-template-columns: 1fr 1fr; }
  .overview-flow-arrow { display: none; }
  .overview-columns { grid-template-columns: 1fr; }
  .release-list { overflow-x: auto; }
  .release-list-heading,
  .release-list > button { min-width: 760px; }
}

@media (max-width: 620px) {
  .project-header-topline { padding-right: 12px; padding-left: 12px; }
  .project-header-icon { width: 34px; height: 34px; flex-basis: 34px; }
  .project-breadcrumb { gap: 5px; }
  .project-breadcrumb strong { max-width: 110px; font-size: 12px; }
  .project-breadcrumb strong:first-child { display: none; }
  .project-breadcrumb i:first-of-type { display: none; }
  .project-header-more { display: none; }
  .project-local-nav button svg { display: none; }
  .project-overview-view,
  .project-releases-view,
  .project-activity-view,
  .project-settings-view { padding: 24px 15px 42px; }
  .overview-intro,
  .project-releases-view > header,
  .project-activity-view > header,
  .project-settings-view > header { flex-direction: column; }
  .overview-delivery-flow { padding: 18px; }
  .overview-delivery-flow > header span { display: none; }
  .overview-delivery-flow > div { grid-template-columns: 1fr; }
  .activity-timeline article { grid-template-columns: 38px minmax(0, 1fr); }
  .activity-timeline article > button { grid-column: 2; justify-self: start; }
  .settings-save { width: 100%; justify-content: center; }
}

@media (max-width: 900px) {
  .project-workspace-header { z-index: 14; }
  .project-switcher { margin-left: 46px; }
}
</style>
