<script setup lang="ts">
import {
  Check,
  CheckCircle2,
  ChevronDown,
  CircleDot,
  Code2,
  FileDiff,
  GitPullRequest,
  MessageSquare,
  Play,
  Send,
  ShieldCheck,
} from "@lucide/vue";
import { computed, ref } from "vue";
import type { ProjectWorkspace } from "./projectWorkspace";

const props = defineProps<{ project: ProjectWorkspace }>();

const emit = defineEmits<{
  "browse-code": [];
  "open-pipeline": [];
  notify: [message: string];
}>();

type WorkType = "review" | "issue" | "request";
type WorkItem = { id: string; title: string; type: WorkType; meta: string; status: string; tone: "lavender" | "yellow" | "green" };

const workItems = computed<WorkItem[]>(() => [
  { id: props.project.issue.reviewId, title: props.project.issue.reviewTitle, type: "review", meta: `Review requested by ${props.project.issue.assignee}`, status: "Needs review", tone: "yellow" },
  { id: props.project.issue.id, title: props.project.issue.title, type: "issue", meta: `Updated ${props.project.issue.updatedAt}`, status: props.project.issue.status, tone: "lavender" },
  { id: "REQ-318", title: "Confirm staging acceptance criteria", type: "request", meta: "Requested by delivery · 1h ago", status: "Waiting on you", tone: "yellow" },
  { id: "ERP-58", title: "Reconcile warehouse quantity drift", type: "issue", meta: "Assigned to Artisan · Yesterday", status: "In progress", tone: "green" },
  { id: "CR-5918", title: "Add import retry telemetry", type: "review", meta: "Sentinel approved · Yesterday", status: "1 approval", tone: "green" },
]);

const workFilter = ref<"all" | WorkType>("all");
const selectedWorkId = ref(props.project.issue.reviewId);
const reviewTab = ref<"files" | "commits" | "activity">("files");
const reviewDecision = ref<"pending" | "approved" | "changes">("pending");
const commentText = ref("");
const comments = ref([
  { author: "Atlas", avatar: "/avatars/atlas.png", time: "10:14 AM", body: "The idempotency key looks right. Could we keep the source normalization in the same transaction?" },
  { author: "morgan", avatar: "/brand/icon.svg", time: "10:31 AM", body: "Yes. I moved normalization ahead of the upsert and added the partial-payload case." },
]);

const visibleWork = computed(() => workFilter.value === "all" ? workItems.value : workItems.value.filter((item) => item.type === workFilter.value));
const selectedWork = computed(() => workItems.value.find((item) => item.id === selectedWorkId.value) ?? workItems.value[0]);

const diffRows = [
  { oldNo: "44", newNo: "44", old: "foreach ($payload['items'] as $item) {", next: "foreach ($payload['items'] as $item) {", tone: "same" },
  { oldNo: "45", newNo: "45", old: "    Inventory::create($item);", next: "    $externalId = $item['external_id'] ?? null;", tone: "changed" },
  { oldNo: "", newNo: "46", old: "", next: "    if (empty($externalId)) {", tone: "added" },
  { oldNo: "", newNo: "47", old: "", next: "        $results['errors']++;", tone: "added" },
  { oldNo: "", newNo: "48", old: "", next: "        continue;", tone: "added" },
  { oldNo: "", newNo: "49", old: "", next: "    }", tone: "added" },
  { oldNo: "46", newNo: "50", old: "}", next: "    Inventory::updateOrCreate(", tone: "changed" },
  { oldNo: "", newNo: "51", old: "", next: "        ['external_id' => $externalId, 'source' => $source],", tone: "added" },
  { oldNo: "", newNo: "52", old: "", next: "        ['sku' => $item['sku'], 'quantity' => $item['quantity']],", tone: "added" },
  { oldNo: "", newNo: "53", old: "", next: "    );", tone: "added" },
  { oldNo: "47", newNo: "54", old: "", next: "}", tone: "same" },
];

function selectWork(item: WorkItem) {
  selectedWorkId.value = item.id;
  if (item.type !== "review") reviewDecision.value = "pending";
}

function approveReview() {
  reviewDecision.value = "approved";
  emit("notify", `${selectedWork.value.id} approved.`);
}

function requestChanges() {
  reviewDecision.value = "changes";
  emit("notify", `Changes requested on ${selectedWork.value.id}.`);
}

function addComment() {
  const body = commentText.value.trim();
  if (!body) return;
  comments.value.push({ author: "morgan", avatar: "/brand/icon.svg", time: "Just now", body });
  commentText.value = "";
  emit("notify", "Review comment added.");
}
</script>

<template>
  <div class="project-work-view">
    <aside class="work-queue" aria-label="Project work">
      <header>
        <div><h1>Open work</h1><span>{{ workItems.length }}</span></div>
        <button type="button" aria-label="Work filters"><ChevronDown :size="16" aria-hidden="true" /></button>
      </header>
      <div class="work-filter-row" role="group" aria-label="Work type">
        <button v-for="filter in ['all', 'request', 'issue', 'review'] as const" :key="filter" type="button" :class="{ 'is-active': workFilter === filter }" @click="workFilter = filter">
          {{ filter === "all" ? "All" : `${filter[0].toUpperCase()}${filter.slice(1)}s` }}
        </button>
      </div>
      <div class="work-list">
        <button v-for="item in visibleWork" :key="item.id" type="button" :class="{ 'is-selected': selectedWorkId === item.id }" @click="selectWork(item)">
          <span class="work-item-icon">
            <GitPullRequest v-if="item.type === 'review'" :size="16" aria-hidden="true" />
            <CircleDot v-else-if="item.type === 'issue'" :size="16" aria-hidden="true" />
            <MessageSquare v-else :size="16" aria-hidden="true" />
          </span>
          <span><small>{{ item.id }}</small><strong>{{ item.title }}</strong><em>{{ item.meta }}</em></span>
          <i :class="`work-tone--${item.tone}`" />
        </button>
      </div>
    </aside>

    <main class="review-workspace">
      <template v-if="selectedWork.type === 'review'">
        <header class="review-header">
          <div>
            <span class="review-id"><GitPullRequest :size="17" aria-hidden="true" />{{ selectedWork.id }}</span>
            <h1>{{ selectedWork.title }}</h1>
            <p>Opened by {{ project.issue.assignee }} · {{ project.issue.revision }} · 6 files changed</p>
          </div>
          <div class="review-checks">
            <span><CheckCircle2 :size="16" aria-hidden="true" /><strong>3 / 3 checks</strong><small>All passed</small></span>
            <span><ShieldCheck :size="16" aria-hidden="true" /><strong>Policy passed</strong><small>Sentinel</small></span>
          </div>
        </header>

        <nav class="review-tabs" aria-label="Review sections">
          <button v-for="tab in ['files', 'commits', 'activity'] as const" :key="tab" type="button" :class="{ 'is-active': reviewTab === tab }" @click="reviewTab = tab">
            {{ tab[0].toUpperCase() + tab.slice(1) }}<span v-if="tab !== 'activity'">{{ tab === 'files' ? 6 : 3 }}</span>
          </button>
        </nav>

        <section v-if="reviewTab === 'files'" class="review-files">
          <header><span><FileDiff :size="16" aria-hidden="true" />app/Services/InventorySync.php</span><span class="diff-count">+18 −4</span></header>
          <div class="review-diff">
            <div v-for="(row, index) in diffRows" :key="index" :class="`review-diff-row review-diff-row--${row.tone}`">
              <span>{{ row.oldNo }}</span><code>{{ row.old || " " }}</code><span>{{ row.newNo }}</span><code>{{ row.next || " " }}</code>
            </div>
          </div>

          <section class="inline-review-thread">
            <header><MessageSquare :size="15" aria-hidden="true" /><strong>Conversation on line 51</strong></header>
            <article v-for="comment in comments" :key="`${comment.author}-${comment.time}`">
              <img :src="comment.avatar" alt="" /><div><strong>{{ comment.author }}</strong><small>{{ comment.time }}</small><p>{{ comment.body }}</p></div>
            </article>
            <form @submit.prevent="addComment">
              <textarea v-model="commentText" aria-label="Review comment" placeholder="Leave a review comment…" />
              <button type="submit" :disabled="!commentText.trim()" aria-label="Add comment"><Send :size="15" aria-hidden="true" /></button>
            </form>
          </section>
        </section>

        <section v-else-if="reviewTab === 'commits'" class="review-simple-list">
          <article><code>c34d90e</code><span><strong>Add inventory import idempotency</strong><small>morgan · Aug 7, 2026 9:04 AM</small></span><CheckCircle2 :size="16" aria-hidden="true" /></article>
          <article><code>f18a24b</code><span><strong>Cover partial payload imports</strong><small>morgan · Aug 7, 2026 8:42 AM</small></span><CheckCircle2 :size="16" aria-hidden="true" /></article>
          <article><code>82bc190</code><span><strong>Normalize external source identifiers</strong><small>Artisan · Aug 6, 2026 4:11 PM</small></span><CheckCircle2 :size="16" aria-hidden="true" /></article>
        </section>

        <section v-else class="review-simple-list">
          <article><MessageSquare :size="16" aria-hidden="true" /><span><strong>Atlas requested one clarification</strong><small>Today at 10:14 AM</small></span></article>
          <article><CheckCircle2 :size="16" aria-hidden="true" /><span><strong>All required checks passed</strong><small>Today at 9:31 AM</small></span></article>
          <article><GitPullRequest :size="16" aria-hidden="true" /><span><strong>{{ project.issue.assignee }} requested review</strong><small>Today at 9:28 AM</small></span></article>
        </section>

        <footer class="review-decision-bar">
          <span v-if="reviewDecision === 'approved'" class="review-result review-result--approved"><Check :size="16" aria-hidden="true" />Approved</span>
          <span v-else-if="reviewDecision === 'changes'" class="review-result review-result--changes"><MessageSquare :size="16" aria-hidden="true" />Changes requested</span>
          <span v-else>Review the change, leave comments, then submit your decision.</span>
          <div><button type="button" class="request-changes" @click="requestChanges">Request changes</button><button type="button" class="approve-review" @click="approveReview"><Check :size="16" aria-hidden="true" />Approve</button></div>
        </footer>
      </template>

      <template v-else>
        <section class="work-detail">
          <span class="work-detail-type"><component :is="selectedWork.type === 'issue' ? CircleDot : MessageSquare" :size="17" aria-hidden="true" />{{ selectedWork.type }}</span>
          <h1>{{ selectedWork.id }} · {{ selectedWork.title }}</h1>
          <p>{{ project.issue.description.join(' ') }}</p>
          <dl><div><dt>Status</dt><dd>{{ selectedWork.status }}</dd></div><div><dt>Assignee</dt><dd>{{ project.issue.assignee }}</dd></div><div><dt>Milestone</dt><dd>{{ project.release }}</dd></div></dl>
          <h2>Acceptance criteria</h2>
          <ul><li>Repeat imports do not create duplicate records.</li><li>Partial payloads report skipped and failed rows separately.</li><li>The exact reviewed revision passes staging acceptance.</li></ul>
          <div class="work-detail-actions"><button type="button" @click="$emit('browse-code')"><Code2 :size="16" aria-hidden="true" />Browse code</button><button type="button" class="primary" @click="$emit('open-pipeline')"><Play :size="16" aria-hidden="true" />Open pipeline</button></div>
        </section>
      </template>
    </main>
  </div>
</template>

<style scoped>
.project-work-view { display: grid; height: 100%; min-width: 0; min-height: 0; grid-template-columns: 292px minmax(620px, 1fr); overflow: hidden; }
.work-queue { display: flex; min-width: 0; min-height: 0; flex-direction: column; padding: 22px 12px 18px; background: rgb(38 45 55 / 50%); }
.work-queue > header { display: flex; min-height: 34px; align-items: center; justify-content: space-between; padding: 0 7px; }
.work-queue > header > div { display: flex; align-items: center; gap: 8px; }
.work-queue h1 { margin: 0; color: #e7eaee; font-size: 14px; }
.work-queue > header span { display: grid; width: 22px; height: 22px; place-items: center; border-radius: 50%; background: #414956; color: #b8c0ca; font-size: 9px; }
.work-queue > header button { display: grid; width: 28px; height: 28px; place-items: center; border-radius: 7px; background: transparent; color: #919ba8; cursor: pointer; }
.work-filter-row { display: flex; gap: 4px; margin: 13px 4px 12px; }
.work-filter-row button { height: 29px; padding: 0 9px; border-radius: 7px; background: transparent; color: #8f99a7; font-size: 10px; cursor: pointer; }
.work-filter-row button:hover,
.work-filter-row button:focus-visible { outline: 0; background: #39424f; color: #e4e7eb; }
.work-filter-row button.is-active { background: #47414e; color: #eedbec; }
.work-list { min-height: 0; flex: 1; overflow-y: auto; }
.work-list > button { position: relative; display: grid; width: 100%; min-height: 86px; grid-template-columns: 30px minmax(0, 1fr) 8px; align-items: start; gap: 9px; padding: 13px 10px; border-radius: 9px; background: transparent; color: #aeb7c2; text-align: left; cursor: pointer; }
.work-list > button + button { margin-top: 3px; }
.work-list > button:hover,
.work-list > button:focus-visible { outline: 0; background: rgb(58 67 80 / 52%); }
.work-list > button.is-selected { background: #3b4350; }
.work-item-icon { display: grid; width: 28px; height: 28px; place-items: center; border-radius: 7px; background: #303845; color: #c494c2; }
.work-list > button > span:nth-child(2) { display: grid; min-width: 0; gap: 5px; }
.work-list small { color: #a68caa; font-size: 9px; }
.work-list strong { color: #e1e5e9; font-size: 11px; line-height: 1.3; }
.work-list em { overflow: hidden; color: #8793a1; font-size: 9px; font-style: normal; text-overflow: ellipsis; white-space: nowrap; }
.work-list > button > i { width: 7px; height: 7px; margin-top: 3px; border-radius: 50%; }
.work-tone--lavender { background: #c494c2; }
.work-tone--yellow { background: #e9b74f; }
.work-tone--green { background: #71d19b; }

.review-workspace { min-width: 0; min-height: 0; overflow-y: auto; padding: 27px 30px 96px; background: #303744; }
.review-header { display: flex; align-items: flex-start; justify-content: space-between; gap: 24px; }
.review-id { display: inline-flex; align-items: center; gap: 7px; color: #d4a5d0; font-size: 11px; }
.review-header h1 { margin: 10px 0 0; color: #f0f2f5; font-size: 21px; letter-spacing: -0.02em; }
.review-header p { margin: 9px 0 0; color: #8e99a7; font-size: 10px; }
.review-checks { display: flex; gap: 10px; }
.review-checks > span { display: grid; min-width: 130px; grid-template-columns: 21px 1fr; align-items: center; gap: 3px 7px; padding: 10px 12px; border-radius: 8px; background: #383f4c; }
.review-checks svg { grid-row: 1 / 3; color: #74d39e; }
.review-checks strong { color: #dde2e7; font-size: 10px; }
.review-checks small { color: #8f9aa8; font-size: 9px; }
.review-tabs { display: flex; gap: 22px; margin-top: 26px; }
.review-tabs button { position: relative; display: inline-flex; height: 38px; align-items: center; gap: 7px; padding: 0; background: transparent; color: #929daa; cursor: pointer; }
.review-tabs button.is-active { color: #eedbec; }
.review-tabs button.is-active::after { position: absolute; right: 0; bottom: 0; left: 0; height: 2px; border-radius: 2px; background: #c494c2; content: ""; }
.review-tabs span { display: grid; min-width: 19px; height: 19px; place-items: center; border-radius: 50%; background: #444c59; font-size: 8px; }
.review-files { margin-top: 16px; }
.review-files > header { display: flex; min-height: 42px; align-items: center; justify-content: space-between; padding: 0 12px; border-radius: 8px 8px 0 0; background: #39414e; color: #cbd2db; font-size: 10px; }
.review-files > header > span:first-child { display: inline-flex; align-items: center; gap: 8px; }
.diff-count { color: #9ccf9a; }
.review-diff { overflow-x: auto; border-radius: 0 0 8px 8px; background: #29313b; }
.review-diff-row { display: grid; min-width: 780px; grid-template-columns: 38px minmax(320px, 1fr) 38px minmax(320px, 1fr); min-height: 27px; color: #bfc7d0; font: 500 10px/27px ui-monospace, SFMono-Regular, Menlo, monospace; }
.review-diff-row > span { color: #667383; text-align: center; }
.review-diff-row code { padding: 0 10px; white-space: pre; }
.review-diff-row--changed code:first-of-type { background: rgb(129 59 62 / 34%); color: #e2a4a4; }
.review-diff-row--changed code:last-of-type,
.review-diff-row--added code:last-of-type { background: rgb(54 106 77 / 38%); color: #a8d5b3; }
.inline-review-thread { max-width: 680px; margin: 20px 0 0 auto; padding: 15px; border-radius: 9px; background: #373f4b; }
.inline-review-thread > header { display: flex; align-items: center; gap: 8px; color: #cfd5dd; }
.inline-review-thread article { display: grid; grid-template-columns: 30px minmax(0, 1fr); gap: 10px; margin-top: 14px; }
.inline-review-thread img { width: 30px; height: 30px; border-radius: 50%; object-fit: cover; }
.inline-review-thread article strong { color: #e4e8eb; font-size: 10px; }
.inline-review-thread article small { margin-left: 7px; color: #84909f; font-size: 9px; }
.inline-review-thread article p { margin: 5px 0 0; color: #b0b8c3; font-size: 10px; line-height: 1.5; }
.inline-review-thread form { display: flex; align-items: flex-end; gap: 8px; margin-top: 14px; }
.inline-review-thread textarea { min-height: 58px; flex: 1; resize: none; padding: 10px; border: 0; border-radius: 8px; outline: 0; background: #2b323d; color: #e0e4e9; font: 500 10px/1.4 Inter, sans-serif; }
.inline-review-thread form button { display: grid; width: 34px; height: 34px; place-items: center; border-radius: 8px; background: #c494c2; color: #242933; cursor: pointer; }
.inline-review-thread form button:disabled { opacity: .42; cursor: default; }
.review-simple-list { display: grid; gap: 4px; margin-top: 18px; }
.review-simple-list article { display: grid; min-height: 58px; grid-template-columns: 90px minmax(0, 1fr) 20px; align-items: center; gap: 12px; padding: 0 12px; border-radius: 8px; color: #74d39e; }
.review-simple-list article:hover { background: #38404d; }
.review-simple-list code { color: #cda0ca; font: 600 10px/1 ui-monospace, monospace; }
.review-simple-list span { display: grid; gap: 5px; }
.review-simple-list strong { color: #dfe3e8; font-size: 11px; }
.review-simple-list small { color: #8995a3; font-size: 9px; }
.review-decision-bar { position: sticky; z-index: 3; bottom: -96px; display: flex; min-height: 74px; align-items: center; justify-content: space-between; gap: 20px; margin: 24px -30px -96px; padding: 0 30px; background: #2c333e; color: #929daa; font-size: 10px; }
.review-decision-bar > div { display: flex; gap: 10px; }
.review-decision-bar button { display: inline-flex; height: 40px; align-items: center; justify-content: center; gap: 7px; padding: 0 16px; border-radius: 8px; cursor: pointer; }
.request-changes { background: #3b4350; color: #d6c1d5; }
.approve-review { background: #c494c2; color: #252934; font-weight: 700; }
.review-result { display: inline-flex; align-items: center; gap: 7px; font-weight: 700; }
.review-result--approved { color: #75d49e; }
.review-result--changes { color: #e2b252; }
.work-detail { max-width: 760px; margin: 32px auto; }
.work-detail-type { display: inline-flex; align-items: center; gap: 7px; color: #c99bc6; font-size: 11px; text-transform: capitalize; }
.work-detail h1 { margin: 13px 0 0; color: #f0f2f5; font-size: 24px; line-height: 1.25; }
.work-detail > p { max-width: 650px; margin: 16px 0 0; color: #aeb6c1; font-size: 12px; line-height: 1.6; }
.work-detail dl { display: flex; flex-wrap: wrap; gap: 26px; margin: 28px 0; }
.work-detail dl div { display: grid; gap: 6px; }
.work-detail dt { color: #8d98a6; font-size: 10px; }
.work-detail dd { margin: 0; color: #dce1e6; font-size: 11px; }
.work-detail h2 { margin: 31px 0 12px; color: #dde2e7; font-size: 13px; }
.work-detail ul { display: grid; gap: 10px; padding-left: 19px; color: #aeb7c2; font-size: 11px; line-height: 1.5; }
.work-detail-actions { display: flex; gap: 10px; margin-top: 30px; }
.work-detail-actions button { display: inline-flex; height: 40px; align-items: center; gap: 8px; padding: 0 15px; border-radius: 8px; background: #3b4350; color: #cbd2db; cursor: pointer; }
.work-detail-actions .primary { background: #c494c2; color: #242933; font-weight: 700; }

@media (max-width: 930px) {
  .project-work-view { grid-template-columns: 240px minmax(560px, 1fr); overflow-x: auto; }
  .review-workspace { padding-right: 20px; padding-left: 20px; }
  .review-checks { display: none; }
}

@media (max-width: 700px) {
  .project-work-view { display: block; overflow-y: auto; }
  .work-queue { min-height: 280px; max-height: 42%; }
  .review-workspace { min-height: 620px; overflow: visible; }
}
</style>
