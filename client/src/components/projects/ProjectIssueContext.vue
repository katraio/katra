<script setup lang="ts">
import { CheckCircle2, ChevronDown, Code2, ExternalLink, ShieldCheck } from "@lucide/vue";
import type { ProjectWorkspace } from "./projectWorkspace";

defineProps<{
  project: ProjectWorkspace;
  pipelineStatus?: "attention" | "running" | "passed";
}>();

defineEmits<{
  "open-review": [];
  "browse-code": [];
}>();
</script>

<template>
  <aside class="project-context-pane" aria-label="Project work context">
    <section class="context-source">
      <div class="context-eyebrow">
        <span>Source issue</span>
        <span class="context-status"><i />{{ project.issue.status }}</span>
      </div>
      <button type="button" class="context-issue-link" @click="$emit('open-review')">{{ project.issue.id }}</button>
      <h2>{{ project.issue.title }}</h2>
      <p class="context-dates">Created {{ project.issue.createdAt }}<br />Updated {{ project.issue.updatedAt }}</p>
    </section>

    <dl class="context-metadata">
      <div>
        <dt>Assignee</dt>
        <dd><img :src="project.issue.assigneeAvatar" alt="" />{{ project.issue.assignee }}</dd>
      </div>
      <div>
        <dt>Labels</dt>
        <dd class="context-labels"><span v-for="label in project.issue.labels" :key="label">{{ label }}</span></dd>
      </div>
      <div>
        <dt>Milestone</dt>
        <dd>{{ project.issue.milestone }} <ChevronDown :size="13" aria-hidden="true" /></dd>
      </div>
    </dl>

    <section class="context-description">
      <h3>Description</h3>
      <p v-for="paragraph in project.issue.description" :key="paragraph">{{ paragraph }}</p>
    </section>

    <section class="context-linked-change">
      <h3>Linked change</h3>
      <button type="button" @click="$emit('open-review')">
        <span><strong>{{ project.issue.reviewId }}</strong><small>{{ project.issue.reviewTitle }}</small></span>
        <span>Open review <ExternalLink :size="13" aria-hidden="true" /></span>
      </button>
    </section>

    <section class="context-revision">
      <h3>Revision</h3>
      <div><code>{{ project.issue.revision }}</code><span>Aug 7, 2026 11:58 AM</span></div>
      <button type="button" @click="$emit('browse-code')">Browse code <Code2 :size="13" aria-hidden="true" /></button>
    </section>

    <section class="context-reviewers">
      <h3>Reviewers</h3>
      <div>
        <span><img :src="project.issue.reviewerAvatar" alt="" /><strong>{{ project.issue.reviewer }}</strong><small>Reviewer</small></span>
        <span class="context-pending"><i />Pending</span>
      </div>
    </section>

    <section class="context-approvals">
      <div class="context-heading-row"><h3>Approvals</h3><span>1 required</span></div>
      <div>
        <ShieldCheck :size="21" :stroke-width="1.7" aria-hidden="true" />
        <span><strong>Infra Guard</strong><small>Policy check</small></span>
        <span class="context-passed"><CheckCircle2 :size="13" aria-hidden="true" />Passed</span>
      </div>
      <div v-if="pipelineStatus" class="context-pipeline-state">
        <span>Pipeline {{ project.issue.pipelineNumber }}</span>
        <strong :class="`context-pipeline-state--${pipelineStatus}`">
          {{ pipelineStatus === "passed" ? "Passed" : pipelineStatus === "running" ? "Running" : "Needs attention" }}
        </strong>
      </div>
    </section>
  </aside>
</template>

<style scoped>
.project-context-pane {
  min-width: 0;
  overflow-y: auto;
  padding: 27px 28px 34px;
  background: rgb(35 42 52 / 42%);
  color: #cfd5de;
}

.project-context-pane section + section,
.context-metadata + section { margin-top: 30px; }

.context-eyebrow,
.context-heading-row,
.context-reviewers > div,
.context-approvals > div,
.context-revision > div,
.context-pipeline-state { display: flex; align-items: center; justify-content: space-between; gap: 12px; }

.context-eyebrow { color: #98a2b0; font-size: 11px; }
.context-status,
.context-pending,
.context-passed { display: inline-flex; align-items: center; gap: 6px; }
.context-status i,
.context-pending i { width: 7px; height: 7px; border-radius: 50%; background: #efbd45; }

.context-issue-link {
  margin-top: 16px;
  padding: 0;
  background: transparent;
  color: #d7a6d3;
  font-size: 13px;
  cursor: pointer;
}
.context-issue-link:hover,
.context-issue-link:focus-visible { outline: 0; color: #f0c7ec; }

.context-source h2 { margin: 9px 0 0; color: #f0f2f5; font-size: 18px; line-height: 1.3; }
.context-dates { margin: 12px 0 0; color: #919cab; font-size: 11px; line-height: 1.55; }

.context-metadata { display: grid; gap: 16px; margin: 28px 0 0; }
.context-metadata > div { display: grid; grid-template-columns: 76px minmax(0, 1fr); align-items: center; gap: 14px; }
.context-metadata dt { color: #929cab; font-size: 11px; }
.context-metadata dd { display: flex; min-width: 0; align-items: center; gap: 8px; margin: 0; color: #e0e4e9; font-size: 12px; }
.context-metadata img,
.context-reviewers img { width: 28px; height: 28px; border-radius: 50%; object-fit: cover; }
.context-labels { flex-wrap: wrap; }
.context-labels span { padding: 5px 8px; border-radius: 999px; background: #303845; color: #c9b1cf; font-size: 10px; }

.project-context-pane h3 { margin: 0 0 11px; color: #dfe3e8; font-size: 12px; }
.context-description p { margin: 0; color: #a9b1bd; font-size: 11px; line-height: 1.55; }
.context-description p + p { margin-top: 10px; }

.context-linked-change > button {
  display: flex;
  width: 100%;
  align-items: center;
  justify-content: space-between;
  gap: 16px;
  padding: 10px 12px;
  border-radius: 8px;
  background: #303845;
  color: #b8c0cb;
  text-align: left;
  cursor: pointer;
}
.context-linked-change > button:hover,
.context-linked-change > button:focus-visible { outline: 0; background: #394250; }
.context-linked-change > button > span:first-child { display: grid; min-width: 0; gap: 4px; }
.context-linked-change strong { color: #d9a9d5; font-size: 12px; }
.context-linked-change small { overflow: hidden; color: #9da7b4; font-size: 10px; text-overflow: ellipsis; white-space: nowrap; }
.context-linked-change > button > span:last-child { display: inline-flex; flex: 0 0 auto; align-items: center; gap: 6px; color: #d7a6d3; font-size: 10px; }

.context-revision > div { justify-content: flex-start; color: #8f99a7; font-size: 10px; }
.context-revision code { color: #d5abd2; font: 600 11px/1 ui-monospace, SFMono-Regular, Menlo, monospace; }
.context-revision button { display: inline-flex; align-items: center; gap: 6px; margin-top: 11px; padding: 0; background: transparent; color: #c99bc6; font-size: 10px; cursor: pointer; }

.context-reviewers > div > span:first-child,
.context-approvals > div > span { display: grid; min-width: 0; grid-template-columns: auto 1fr; column-gap: 8px; align-items: center; }
.context-reviewers strong,
.context-approvals strong { color: #e1e5e9; font-size: 11px; }
.context-reviewers small,
.context-approvals small { color: #8e99a7; font-size: 9px; }
.context-reviewers img { grid-row: 1 / 3; }
.context-pending { color: #d0a950; font-size: 10px; }

.context-heading-row > span { color: #c7a1c5; font-size: 10px; }
.context-approvals > div { justify-content: flex-start; }
.context-approvals svg { color: #aab4c0; }
.context-passed { margin-left: auto; color: #73d4a2; font-size: 10px; }
.context-pipeline-state { margin-top: 19px; padding-top: 3px; color: #919ba8; font-size: 10px; }
.context-pipeline-state strong { font-size: 10px; }
.context-pipeline-state--attention { color: #e4b655; }
.context-pipeline-state--running { color: #d3a7cf; }
.context-pipeline-state--passed { color: #72d3a1; }

@media (max-width: 1120px) {
  .project-context-pane { padding: 23px 20px 28px; }
}

@media (max-width: 860px) {
  .project-context-pane { overflow: visible; }
}
</style>
