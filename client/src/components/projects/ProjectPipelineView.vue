<script setup lang="ts">
import {
  Ban,
  CheckCircle2,
  Circle,
  Clock3,
  Download,
  ExternalLink,
  MoreHorizontal,
  Play,
  RefreshCw,
  XCircle,
} from "@lucide/vue";
import { computed, onBeforeUnmount, ref } from "vue";
import ProjectIssueContext from "./ProjectIssueContext.vue";
import type { ProjectWorkspace } from "./projectWorkspace";

const props = defineProps<{ project: ProjectWorkspace }>();

const emit = defineEmits<{
  "open-review": [];
  "browse-code": [];
  notify: [message: string];
}>();

type StageStatus = "passed" | "failed" | "pending" | "running" | "cancelled";
type PipelineStage = { id: string; label: string; duration: string; status: StageStatus };

const stages = ref<PipelineStage[]>([
  { id: "prepare", label: "Prepare", duration: "1m 12s", status: "passed" },
  { id: "test", label: "Test", duration: "4m 40s", status: "passed" },
  { id: "security", label: "Security review", duration: "1m 18s", status: "passed" },
  { id: "build", label: "Build", duration: "Failed", status: "failed" },
  { id: "staging", label: "Staging deploy", duration: "Pending", status: "pending" },
  { id: "acceptance", label: "Acceptance", duration: "Pending", status: "pending" },
]);
const selectedStageId = ref("build");
const pipelineState = ref<"attention" | "running" | "passed">("attention");
const runStatus = ref("Needs attention");
let retryTimer: number | undefined;

const selectedStage = computed(() => stages.value.find((stage) => stage.id === selectedStageId.value) ?? stages.value[3]);

const recentRuns = [
  { id: "#1283", status: "Success", tone: "passed", started: "Aug 6, 2026 5:12 PM", duration: "11m 03s", commit: "9c8b7a1", trigger: "schedule" },
  { id: "#1282", status: "Success", tone: "passed", started: "Aug 6, 2026 1:47 PM", duration: "8m 56s", commit: "7e6d5c4", trigger: "morgan" },
  { id: "#1281", status: "Needs attention", tone: "attention", started: "Aug 6, 2026 10:09 AM", duration: "7m 44s", commit: "3d2c1b0", trigger: "Atlas" },
  { id: "#1280", status: "Success", tone: "passed", started: "Aug 5, 2026 9:22 PM", duration: "9m 18s", commit: "0a9b8c7", trigger: "schedule" },
  { id: "#1279", status: "Failed", tone: "failed", started: "Aug 5, 2026 3:11 PM", duration: "6m 33s", commit: "1b2a3d4", trigger: "morgan" },
];

const failureLog = [
  { number: 1, text: "09:11:31  › Start step: Build · Run tests", tone: "muted" },
  { number: 2, text: "09:11:31    Using runner: docker-linux-18c2", tone: "default" },
  { number: 3, text: "09:11:31    Checkout source (feature/ERP-62-inventory-idempotency)", tone: "default" },
  { number: 4, text: "09:11:33    Composer install (no-dev, optimized autoloader)", tone: "default" },
  { number: 5, text: "09:11:41    PHP 8.3.8 · PHPUnit 11.2.1", tone: "default" },
  { number: 6, text: "09:11:41    Running tests...", tone: "default" },
  { number: 7, text: "09:11:56    ........................................  95 / 112 (84%)", tone: "muted" },
  { number: 8, text: "09:12:02    ........................................ 104 / 112 (92%)", tone: "muted" },
  { number: 9, text: "09:12:06    E....................................... 112 / 112 (100%)", tone: "error" },
  { number: 10, text: "", tone: "default" },
  { number: 11, text: "FAIL  Tests\\Feature\\InventoryImportIdempotencyTest", tone: "error" },
  { number: 12, text: "  ✓ prevents duplicate imports when external_id exists", tone: "success" },
  { number: 13, text: "  ✕ skips duplicate rows in same payload", tone: "error" },
  { number: 14, text: "    Expected response JSON to contain key 'skipped'.", tone: "warning" },
  { number: 15, text: "    Failed asserting that an array has the key 'skipped'.", tone: "error" },
  { number: 16, text: "", tone: "default" },
  { number: 17, text: "    at tests/Feature/InventoryImportIdempotencyTest.php:58", tone: "muted" },
  { number: 18, text: "", tone: "default" },
  { number: 19, text: "Tests:  1 failed, 111 passed (684 assertions)", tone: "error" },
  { number: 20, text: "Time:   50.74s", tone: "muted" },
  { number: 21, text: "", tone: "default" },
  { number: 22, text: "ERROR: Job failed with exit code 1", tone: "error" },
];

function retryFailedStep() {
  window.clearTimeout(retryTimer);
  const build = stages.value.find((stage) => stage.id === "build");
  if (!build) return;
  build.status = "running";
  build.duration = "Running";
  pipelineState.value = "running";
  runStatus.value = "Running";
  emit("notify", "Build step restarted.");
  retryTimer = window.setTimeout(() => {
    build.status = "passed";
    build.duration = "2m 31s";
    const staging = stages.value.find((stage) => stage.id === "staging");
    if (staging) {
      staging.status = "running";
      staging.duration = "Deploying";
      selectedStageId.value = "staging";
    }
    pipelineState.value = "running";
    runStatus.value = "Staging deploy running";
    emit("notify", "Tests passed. Staging deployment started.");
  }, 1400);
}

function cancelRun() {
  window.clearTimeout(retryTimer);
  stages.value = stages.value.map((stage) => stage.status === "running" ? { ...stage, status: "cancelled", duration: "Cancelled" } : stage);
  pipelineState.value = "attention";
  runStatus.value = "Cancelled";
  emit("notify", "Pipeline run cancelled.");
}

function selectRecentRun(run: typeof recentRuns[number]) {
  emit("notify", `Pipeline ${run.id} selected.`);
}

onBeforeUnmount(() => window.clearTimeout(retryTimer));
</script>

<template>
  <div class="project-pipeline-view">
    <main class="pipeline-main">
      <header class="pipeline-heading">
        <div>
          <button type="button" class="pipeline-back" @click="emit('notify', 'Pipeline run list opened.')">← <span>Back to pipelines</span></button>
          <h1>Pipeline {{ project.issue.pipelineNumber }}</h1>
          <p>
            <span :class="`pipeline-run-state pipeline-run-state--${pipelineState}`"><i />{{ runStatus }}</span>
            <span>Started Aug 7, 2026 9:10 AM</span>
            <span><Clock3 :size="14" aria-hidden="true" />6m 42s</span>
          </p>
        </div>
        <div class="pipeline-actions">
          <button type="button" class="pipeline-retry" :disabled="pipelineState === 'running'" @click="retryFailedStep">
            <RefreshCw :size="16" :class="{ 'is-spinning': pipelineState === 'running' }" aria-hidden="true" />
            {{ pipelineState === "running" ? "Running step" : "Retry failed step" }}
          </button>
          <button type="button" class="pipeline-cancel" @click="cancelRun"><Ban :size="16" aria-hidden="true" />Cancel run</button>
          <button type="button" class="pipeline-more" aria-label="More pipeline actions"><MoreHorizontal :size="18" aria-hidden="true" /></button>
        </div>
      </header>

      <div class="pipeline-stages" aria-label="Pipeline stages">
        <button
          v-for="stage in stages"
          :key="stage.id"
          type="button"
          :class="[`pipeline-stage--${stage.status}`, { 'is-selected': selectedStageId === stage.id }]"
          @click="selectedStageId = stage.id"
        >
          <span class="pipeline-stage-marker">
            <CheckCircle2 v-if="stage.status === 'passed'" :size="20" aria-hidden="true" />
            <XCircle v-else-if="stage.status === 'failed'" :size="20" aria-hidden="true" />
            <RefreshCw v-else-if="stage.status === 'running'" :size="19" class="is-spinning" aria-hidden="true" />
            <Ban v-else-if="stage.status === 'cancelled'" :size="19" aria-hidden="true" />
            <Circle v-else :size="19" aria-hidden="true" />
          </span>
          <strong>{{ stage.label }}</strong>
          <small>{{ stage.duration }}</small>
        </button>
      </div>

      <section class="pipeline-log-section">
        <header>
          <div>
            <component :is="selectedStage.status === 'passed' ? CheckCircle2 : selectedStage.status === 'running' ? RefreshCw : XCircle" :size="20" :class="[`stage-title-icon--${selectedStage.status}`, { 'is-spinning': selectedStage.status === 'running' }]" aria-hidden="true" />
            <span><strong>{{ selectedStage.label }}</strong><small>{{ selectedStage.status === "failed" ? "Run tests · Failed 2m 13s" : selectedStage.duration }}</small></span>
          </div>
          <div>
            <button type="button" @click="emit('notify', 'Step details opened.')"><ExternalLink :size="14" aria-hidden="true" />View step details</button>
            <button type="button" @click="emit('notify', 'Pipeline log download prepared.')"><Download :size="14" aria-hidden="true" />Download log</button>
          </div>
        </header>
        <div class="pipeline-log" role="log" aria-label="Pipeline step output">
          <template v-if="selectedStage.id === 'build'">
            <div v-for="line in failureLog" :key="line.number" class="pipeline-log-line" :class="`pipeline-log-line--${line.tone}`">
              <span>{{ line.number }}</span><code>{{ line.text }}</code>
            </div>
          </template>
          <div v-else class="pipeline-stage-summary">
            <component :is="selectedStage.status === 'passed' ? CheckCircle2 : selectedStage.status === 'running' ? Play : Circle" :size="28" aria-hidden="true" />
            <strong>{{ selectedStage.label }}</strong>
            <span>{{ selectedStage.status === "passed" ? "Completed successfully." : selectedStage.status === "running" ? "This step is currently running." : "Waiting for the previous stage to complete." }}</span>
          </div>
        </div>
      </section>

      <section class="pipeline-recent-runs">
        <header><h2>Recent runs</h2><button type="button" @click="emit('notify', 'All pipeline runs opened.')">View all runs</button></header>
        <div class="recent-runs-heading"><span>Run</span><span>Status</span><span>Started</span><span>Duration</span><span>Commit</span><span>Triggered by</span></div>
        <button v-for="run in recentRuns" :key="run.id" type="button" class="recent-run" @click="selectRecentRun(run)">
          <code>{{ run.id }}</code>
          <span :class="`recent-run-status recent-run-status--${run.tone}`"><i />{{ run.status }}</span>
          <span>{{ run.started }}</span><span>{{ run.duration }}</span><code>{{ run.commit }}</code><span>{{ run.trigger }}</span>
        </button>
      </section>
    </main>

    <ProjectIssueContext :project="project" :pipeline-status="pipelineState" @open-review="$emit('open-review')" @browse-code="$emit('browse-code')" />
  </div>
</template>

<style scoped>
.project-pipeline-view { display: grid; height: 100%; min-width: 0; min-height: 0; grid-template-columns: minmax(620px, 1fr) minmax(290px, 330px); overflow: hidden; }
.pipeline-main { min-width: 0; min-height: 0; overflow-y: auto; padding: 24px 24px 42px; background: #303744; }
.pipeline-heading { display: flex; align-items: center; justify-content: space-between; gap: 24px; }
.pipeline-heading h1 { margin: 12px 0 0; color: #f0f2f5; font-size: 21px; letter-spacing: -0.02em; }
.pipeline-back { display: inline-flex; gap: 7px; padding: 0; background: transparent; color: #a7b0bc; cursor: pointer; }
.pipeline-back:hover,
.pipeline-back:focus-visible { outline: 0; color: #e5e8ed; }
.pipeline-heading p { display: flex; flex-wrap: wrap; align-items: center; gap: 11px; margin: 11px 0 0; color: #8e98a6; font-size: 11px; }
.pipeline-heading p > span { display: inline-flex; align-items: center; gap: 5px; }
.pipeline-run-state { color: #deb24f; }
.pipeline-run-state i { width: 8px; height: 8px; border-radius: 50%; background: currentColor; }
.pipeline-run-state--running { color: #d0a4cd; }
.pipeline-run-state--passed { color: #6ed29d; }
.pipeline-actions { display: flex; flex: 0 0 auto; align-items: center; gap: 10px; }
.pipeline-actions button { display: inline-flex; height: 40px; align-items: center; justify-content: center; gap: 8px; border-radius: 8px; cursor: pointer; }
.pipeline-retry { padding: 0 16px; background: #c494c2; color: #252934; font-weight: 700; }
.pipeline-retry:hover,
.pipeline-retry:focus-visible { outline: 0; background: #d3a8d0; }
.pipeline-retry:disabled { opacity: .68; cursor: default; }
.pipeline-cancel { padding: 0 14px; background: #3b4350; color: #c5cdd7; }
.pipeline-cancel:hover,
.pipeline-cancel:focus-visible,
.pipeline-more:hover,
.pipeline-more:focus-visible { outline: 0; background: #46505e; color: #eef0f3; }
.pipeline-more { width: 38px; background: transparent; color: #9ca6b3; }

.pipeline-stages { display: grid; grid-template-columns: repeat(6, minmax(90px, 1fr)); margin-top: 38px; }
.pipeline-stages button { position: relative; display: grid; min-width: 0; justify-items: start; gap: 7px; padding: 0 8px 0 0; background: transparent; color: #8f99a7; text-align: left; cursor: pointer; }
.pipeline-stages button::before { position: absolute; top: 9px; right: 4px; left: 23px; height: 2px; background: #4b5563; content: ""; }
.pipeline-stages button:last-child::before { display: none; }
.pipeline-stages button.pipeline-stage--passed::before { background: #77d79a; }
.pipeline-stages button.pipeline-stage--failed::before { background: #d76359; }
.pipeline-stage-marker { position: relative; z-index: 1; display: grid; width: 21px; height: 21px; place-items: center; border-radius: 50%; background: #303744; color: #7d8998; }
.pipeline-stage--passed .pipeline-stage-marker { color: #76d59a; }
.pipeline-stage--failed .pipeline-stage-marker,
.pipeline-stage--cancelled .pipeline-stage-marker { color: #e36559; }
.pipeline-stage--running .pipeline-stage-marker { color: #d0a4cd; }
.pipeline-stages strong { color: #e0e4e9; font-size: 12px; }
.pipeline-stages small { overflow: hidden; color: inherit; font-size: 11px; text-overflow: ellipsis; white-space: nowrap; }
.pipeline-stages button.is-selected strong { color: #f1d8ef; }

.pipeline-log-section { margin-top: 34px; }
.pipeline-log-section > header { display: flex; min-height: 45px; align-items: center; justify-content: space-between; gap: 16px; }
.pipeline-log-section > header > div { display: flex; align-items: center; gap: 9px; }
.pipeline-log-section > header > div:first-child > span { display: grid; gap: 4px; }
.pipeline-log-section strong { color: #e5e8ec; font-size: 13px; }
.pipeline-log-section small { color: #8d98a6; font-size: 10px; }
.pipeline-log-section header button { display: inline-flex; align-items: center; gap: 6px; padding: 7px 8px; border-radius: 7px; background: transparent; color: #9da7b4; cursor: pointer; }
.pipeline-log-section header button:hover,
.pipeline-log-section header button:focus-visible { outline: 0; background: #3a4350; color: #e3e7eb; }
.stage-title-icon--failed { color: #e86458; }
.stage-title-icon--passed { color: #73d39e; }
.stage-title-icon--running { color: #d0a4cd; }
.pipeline-log { min-height: 390px; overflow: auto; padding: 14px 0 18px; border-radius: 8px; background: #242b35; }
.pipeline-log-line { display: grid; min-width: 720px; grid-template-columns: 48px minmax(0, 1fr); color: #bdc5cf; font: 500 11px/19px ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, monospace; }
.pipeline-log-line > span { padding-right: 12px; color: #596676; text-align: right; user-select: none; }
.pipeline-log-line code { white-space: pre; }
.pipeline-log-line--muted code { color: #8b96a5; }
.pipeline-log-line--error code { color: #ed6b62; }
.pipeline-log-line--warning code { color: #e8b85a; }
.pipeline-log-line--success code { color: #75d59d; }
.pipeline-stage-summary { display: grid; min-height: 340px; place-content: center; justify-items: center; gap: 10px; color: #8793a1; text-align: center; }
.pipeline-stage-summary svg { color: #c79bc3; }
.pipeline-stage-summary strong { color: #e2e6ea; }

.pipeline-recent-runs { margin-top: 23px; }
.pipeline-recent-runs > header { display: flex; align-items: center; justify-content: space-between; }
.pipeline-recent-runs h2 { margin: 0; color: #e3e7eb; font-size: 14px; }
.pipeline-recent-runs header button { padding: 0; background: transparent; color: #c79bc3; cursor: pointer; }
.recent-runs-heading,
.recent-run { display: grid; grid-template-columns: 72px 130px minmax(148px, 1fr) 90px 90px 105px; align-items: center; column-gap: 12px; }
.recent-runs-heading { min-height: 34px; color: #8b96a4; font-size: 10px; }
.recent-run { width: 100%; min-height: 38px; padding: 0; border-radius: 7px; background: transparent; color: #aeb7c3; font-size: 11px; text-align: left; cursor: pointer; }
.recent-run:hover,
.recent-run:focus-visible { outline: 0; background: rgb(58 67 80 / 55%); }
.recent-run code { color: #d0a2cc; font: 600 11px/1 ui-monospace, SFMono-Regular, Menlo, monospace; }
.recent-run-status { display: inline-flex; align-items: center; gap: 7px; }
.recent-run-status i { width: 8px; height: 8px; border-radius: 50%; background: currentColor; }
.recent-run-status--passed { color: #76d69e; }
.recent-run-status--attention { color: #e6b651; }
.recent-run-status--failed { color: #e66b60; }

.is-spinning { animation: pipeline-spin 850ms linear infinite; }
@keyframes pipeline-spin { to { transform: rotate(360deg); } }

@media (max-width: 1140px) {
  .pipeline-main { padding: 24px 22px 38px; }
  .project-pipeline-view { grid-template-columns: minmax(580px, 1fr) 280px; }
  .recent-runs-heading,
  .recent-run { grid-template-columns: 62px 116px minmax(140px, 1fr) 78px 78px; }
  .recent-runs-heading > :last-child,
  .recent-run > :last-child { display: none; }
}

@media (max-width: 920px) {
  .project-pipeline-view { grid-template-columns: 1fr; overflow-y: auto; }
  .pipeline-main { overflow: visible; }
  .project-pipeline-view :deep(.project-context-pane) { display: none; }
}

@media (max-width: 690px) {
  .pipeline-main { padding: 20px 15px 34px; }
  .pipeline-heading { align-items: flex-start; flex-direction: column; }
  .pipeline-actions { width: 100%; }
  .pipeline-retry { flex: 1; }
  .pipeline-stages { grid-template-columns: repeat(6, 112px); overflow-x: auto; padding-bottom: 10px; }
  .pipeline-log { min-height: 360px; }
  .recent-runs-heading,
  .recent-run { grid-template-columns: 64px 118px minmax(150px, 1fr); }
  .recent-runs-heading > :nth-child(n+4),
  .recent-run > :nth-child(n+4) { display: none; }
}
</style>
