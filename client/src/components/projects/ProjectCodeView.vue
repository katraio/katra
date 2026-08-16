<script setup lang="ts">
import {
  ChevronDown,
  ChevronRight,
  Clock3,
  Copy,
  FileCode2,
  FileText,
  Folder,
  GitBranch,
  History,
  MoreHorizontal,
} from "@lucide/vue";
import { computed, ref } from "vue";
import ProjectIssueContext from "./ProjectIssueContext.vue";
import type { ProjectWorkspace } from "./projectWorkspace";

const props = defineProps<{ project: ProjectWorkspace }>();

const emit = defineEmits<{
  "open-review": [];
  notify: [message: string];
}>();

type TreeEntry = { name: string; type: "folder" | "file"; depth: number; expanded?: boolean };
type CodeSegment = { text: string; tone?: "keyword" | "type" | "string" | "variable" | "comment" | "method" };
type CodeLine = { number: number; segments: CodeSegment[] };

const treeEntries: TreeEntry[] = [
  { name: "app", type: "folder", depth: 0, expanded: true },
  { name: "Console", type: "folder", depth: 1 },
  { name: "Http", type: "folder", depth: 1 },
  { name: "Jobs", type: "folder", depth: 1 },
  { name: "Models", type: "folder", depth: 1 },
  { name: "Services", type: "folder", depth: 1, expanded: true },
  { name: "InventorySync.php", type: "file", depth: 2 },
  { name: "OrderProcessor.php", type: "file", depth: 2 },
  { name: "WebhookHandler.php", type: "file", depth: 2 },
  { name: "ReportGenerator.php", type: "file", depth: 2 },
  { name: "Providers", type: "folder", depth: 1 },
  { name: "bootstrap", type: "folder", depth: 0 },
  { name: "config", type: "folder", depth: 0 },
  { name: "database", type: "folder", depth: 0 },
  { name: "public", type: "folder", depth: 0 },
  { name: "resources", type: "folder", depth: 0 },
  { name: "routes", type: "folder", depth: 0 },
  { name: "storage", type: "folder", depth: 0 },
  { name: "tests", type: "folder", depth: 0 },
  { name: ".env.example", type: "file", depth: 0 },
  { name: "composer.json", type: "file", depth: 0 },
  { name: "README.md", type: "file", depth: 0 },
];

const codeByFile: Record<string, CodeLine[]> = {
  "InventorySync.php": [
    { number: 1, segments: [{ text: "<?php", tone: "keyword" }] },
    { number: 2, segments: [] },
    { number: 3, segments: [{ text: "namespace ", tone: "keyword" }, { text: "App\\Services", tone: "type" }, { text: ";" }] },
    { number: 4, segments: [] },
    { number: 5, segments: [{ text: "use ", tone: "keyword" }, { text: "App\\Models\\Inventory", tone: "type" }, { text: ";" }] },
    { number: 6, segments: [{ text: "use ", tone: "keyword" }, { text: "Illuminate\\Support\\Facades\\DB", tone: "type" }, { text: ";" }] },
    { number: 7, segments: [{ text: "use ", tone: "keyword" }, { text: "Throwable", tone: "type" }, { text: ";" }] },
    { number: 8, segments: [] },
    { number: 9, segments: [{ text: "final class ", tone: "keyword" }, { text: "InventorySync", tone: "type" }] },
    { number: 10, segments: [{ text: "{" }] },
    { number: 11, segments: [{ text: "    /**", tone: "comment" }] },
    { number: 12, segments: [{ text: "     * Import inventory from the external ERP.", tone: "comment" }] },
    { number: 13, segments: [{ text: "     * The external id and source form the idempotency key.", tone: "comment" }] },
    { number: 14, segments: [{ text: "     */", tone: "comment" }] },
    { number: 15, segments: [{ text: "    public function ", tone: "keyword" }, { text: "import", tone: "method" }, { text: "(array ", tone: "type" }, { text: "$payload", tone: "variable" }, { text: ", string ", tone: "type" }, { text: "$source", tone: "variable" }, { text: "): array", tone: "type" }] },
    { number: 16, segments: [{ text: "    {" }] },
    { number: 17, segments: [{ text: "        $results", tone: "variable" }, { text: " = [" }] },
    { number: 18, segments: [{ text: "            'created'", tone: "string" }, { text: " => 0," }] },
    { number: 19, segments: [{ text: "            'updated'", tone: "string" }, { text: " => 0," }] },
    { number: 20, segments: [{ text: "            'skipped'", tone: "string" }, { text: " => 0," }] },
    { number: 21, segments: [{ text: "            'errors'", tone: "string" }, { text: " => 0," }] },
    { number: 22, segments: [{ text: "        ];" }] },
    { number: 23, segments: [] },
    { number: 24, segments: [{ text: "        DB", tone: "type" }, { text: "::", tone: "keyword" }, { text: "beginTransaction", tone: "method" }, { text: "();" }] },
    { number: 25, segments: [] },
    { number: 26, segments: [{ text: "        try", tone: "keyword" }, { text: " {" }] },
    { number: 27, segments: [{ text: "            foreach", tone: "keyword" }, { text: " (", tone: "variable" }, { text: "$payload", tone: "variable" }, { text: "['items'] as ", tone: "string" }, { text: "$item", tone: "variable" }, { text: ") {" }] },
    { number: 28, segments: [{ text: "                $externalId", tone: "variable" }, { text: " = ", tone: "keyword" }, { text: "$item", tone: "variable" }, { text: "['external_id'] ?? null;", tone: "string" }] },
    { number: 29, segments: [{ text: "                if", tone: "keyword" }, { text: " (empty(", tone: "method" }, { text: "$externalId", tone: "variable" }, { text: ")) {" }] },
    { number: 30, segments: [{ text: "                    $results", tone: "variable" }, { text: "['errors']++;", tone: "string" }] },
    { number: 31, segments: [{ text: "                    continue", tone: "keyword" }, { text: ";" }] },
    { number: 32, segments: [{ text: "                }" }] },
    { number: 33, segments: [] },
    { number: 34, segments: [{ text: "                $inventory", tone: "variable" }, { text: " = ", tone: "keyword" }, { text: "Inventory", tone: "type" }, { text: "::", tone: "keyword" }, { text: "updateOrCreate", tone: "method" }, { text: "(" }] },
    { number: 35, segments: [{ text: "                    ['external_id'", tone: "string" }, { text: " => ", tone: "keyword" }, { text: "$externalId", tone: "variable" }, { text: ", 'source'", tone: "string" }, { text: " => ", tone: "keyword" }, { text: "$source", tone: "variable" }, { text: "]," }] },
    { number: 36, segments: [{ text: "                    ['sku'", tone: "string" }, { text: " => ", tone: "keyword" }, { text: "$item", tone: "variable" }, { text: "['sku'], 'quantity'", tone: "string" }, { text: " => (int) ", tone: "keyword" }, { text: "$item", tone: "variable" }, { text: "['quantity']],", tone: "string" }] },
    { number: 37, segments: [{ text: "                );" }] },
    { number: 38, segments: [] },
    { number: 39, segments: [{ text: "                $results", tone: "variable" }, { text: "[$inventory->wasRecentlyCreated ? 'created' : 'updated']++;", tone: "string" }] },
    { number: 40, segments: [{ text: "            }" }] },
    { number: 41, segments: [] },
    { number: 42, segments: [{ text: "            DB", tone: "type" }, { text: "::", tone: "keyword" }, { text: "commit", tone: "method" }, { text: "();" }] },
    { number: 43, segments: [{ text: "        } catch", tone: "keyword" }, { text: " (", tone: "variable" }, { text: "Throwable", tone: "type" }, { text: " $error", tone: "variable" }, { text: ") {" }] },
    { number: 44, segments: [{ text: "            DB", tone: "type" }, { text: "::", tone: "keyword" }, { text: "rollBack", tone: "method" }, { text: "();" }] },
    { number: 45, segments: [{ text: "            throw", tone: "keyword" }, { text: " $error", tone: "variable" }, { text: ";" }] },
    { number: 46, segments: [{ text: "        }" }] },
    { number: 47, segments: [] },
    { number: 48, segments: [{ text: "        return", tone: "keyword" }, { text: " $results", tone: "variable" }, { text: ";" }] },
    { number: 49, segments: [{ text: "    }" }] },
    { number: 50, segments: [{ text: "}" }] },
  ],
};

const selectedFile = ref("InventorySync.php");
const expandedFolders = ref(new Set(["app", "Services"]));
const selectedCode = computed(() => codeByFile[selectedFile.value] ?? codeByFile["InventorySync.php"]);
const filePath = computed(() => selectedFile.value === "InventorySync.php" ? "app/Services/InventorySync.php" : `app/Services/${selectedFile.value}`);

function toggleEntry(entry: TreeEntry) {
  if (entry.type === "file") {
    selectedFile.value = entry.name;
    emit("notify", `${entry.name} opened.`);
    return;
  }
  const next = new Set(expandedFolders.value);
  if (next.has(entry.name)) next.delete(entry.name);
  else next.add(entry.name);
  expandedFolders.value = next;
}

function copyRevision() {
  navigator.clipboard?.writeText(props.project.issue.revision);
  emit("notify", "Revision copied.");
}
</script>

<template>
  <div class="project-code-view">
    <aside class="code-file-tree" aria-label="Repository files">
      <header><strong>Files</strong><button type="button" aria-label="More file actions"><MoreHorizontal :size="17" aria-hidden="true" /></button></header>
      <div class="code-tree-scroll">
        <button
          v-for="entry in treeEntries"
          :key="`${entry.depth}-${entry.name}`"
          type="button"
          class="code-tree-entry"
          :class="{ 'is-selected': entry.type === 'file' && entry.name === selectedFile }"
          :style="{ paddingLeft: `${12 + entry.depth * 17}px` }"
          @click="toggleEntry(entry)"
        >
          <template v-if="entry.type === 'folder'">
            <ChevronDown v-if="expandedFolders.has(entry.name)" :size="13" aria-hidden="true" />
            <ChevronRight v-else :size="13" aria-hidden="true" />
            <Folder :size="15" :stroke-width="1.7" aria-hidden="true" />
          </template>
          <template v-else>
            <span class="tree-indent" />
            <FileCode2 v-if="entry.name.endsWith('.php')" :size="14" :stroke-width="1.7" aria-hidden="true" />
            <FileText v-else :size="14" :stroke-width="1.7" aria-hidden="true" />
          </template>
          <span>{{ entry.name }}</span>
        </button>
      </div>
      <footer>
        <span><GitBranch :size="14" aria-hidden="true" />{{ project.branch.split('/').at(-1) }}</span>
        <button type="button" @click="copyRevision"><code>{{ project.issue.revision }}</code><Copy :size="13" aria-hidden="true" /></button>
      </footer>
    </aside>

    <main class="code-reader" aria-label="Source code viewer">
      <header class="code-reader-header">
        <span><FileCode2 :size="16" :stroke-width="1.7" aria-hidden="true" /><strong>{{ filePath }}</strong></span>
        <div>
          <button type="button" @click="emit('notify', 'Blame view opened for this revision.')">Blame</button>
          <button type="button" aria-label="File history" @click="emit('notify', 'File history opened.')"><History :size="16" aria-hidden="true" /></button>
          <button type="button" aria-label="More code actions"><MoreHorizontal :size="17" aria-hidden="true" /></button>
        </div>
      </header>
      <div class="code-commit-strip">
        <span><img src="/brand/icon.svg" alt="" /><strong>You</strong> updated idempotency handling</span>
        <span><Clock3 :size="13" aria-hidden="true" />Yesterday · 1 author</span>
      </div>
      <div class="code-lines" role="region" aria-label="InventorySync.php source">
        <div v-for="line in selectedCode" :key="line.number" class="code-line">
          <span class="code-line-number">{{ line.number }}</span>
          <code><span v-for="(segment, index) in line.segments" :key="index" :class="segment.tone ? `token-${segment.tone}` : undefined">{{ segment.text }}</span></code>
        </div>
      </div>
      <footer class="code-reader-footer">
        <span>Ln 1, Col 1</span><span>Spaces: 4</span><span>UTF-8</span><span>LF</span><span>PHP</span>
      </footer>
    </main>

    <ProjectIssueContext :project="project" pipeline-status="attention" @open-review="$emit('open-review')" @browse-code="emit('notify', 'Already viewing the linked revision.')" />
  </div>
</template>

<style scoped>
.project-code-view {
  display: grid;
  height: 100%;
  min-width: 0;
  min-height: 0;
  grid-template-columns: 224px minmax(460px, 1fr) minmax(290px, 330px);
  overflow: hidden;
}

.code-file-tree,
.code-reader { min-width: 0; min-height: 0; }

.code-file-tree {
  display: flex;
  flex-direction: column;
  background: rgb(38 45 55 / 45%);
}
.code-file-tree > header { display: flex; min-height: 48px; align-items: center; justify-content: space-between; padding: 0 14px 0 16px; }
.code-file-tree > header strong { color: #e3e7ec; font-size: 12px; }
.code-file-tree button { background: transparent; cursor: pointer; }
.code-file-tree > header button { display: grid; width: 28px; height: 28px; place-items: center; border-radius: 7px; color: #909ba9; }
.code-file-tree > header button:hover { background: #343d49; color: #e2e6eb; }
.code-tree-scroll { min-height: 0; flex: 1; overflow-y: auto; padding: 4px 8px 14px; }
.code-tree-entry { display: flex; width: 100%; height: 29px; align-items: center; gap: 6px; padding-right: 8px; border-radius: 6px; color: #aeb6c2; text-align: left; }
.code-tree-entry:hover,
.code-tree-entry:focus-visible { outline: 0; background: #343d49; color: #e0e4e9; }
.code-tree-entry.is-selected { background: #46404e; color: #e9d1e7; }
.code-tree-entry svg:nth-child(2) { color: #9a74a2; }
.code-tree-entry > span:last-child { overflow: hidden; font-size: 11px; text-overflow: ellipsis; white-space: nowrap; }
.tree-indent { width: 13px; flex: 0 0 13px; }
.code-file-tree > footer { display: flex; min-height: 40px; align-items: center; justify-content: space-between; gap: 8px; padding: 0 12px; background: #29313b; color: #8994a2; font-size: 9px; }
.code-file-tree > footer span,
.code-file-tree > footer button { display: inline-flex; min-width: 0; align-items: center; gap: 5px; }
.code-file-tree > footer span { overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.code-file-tree > footer button { padding: 0; color: #a994b0; }
.code-file-tree > footer code { font: 600 9px/1 ui-monospace, SFMono-Regular, Menlo, monospace; }

.code-reader { display: flex; flex-direction: column; overflow: hidden; background: #2f3642; }
.code-reader-header { display: flex; min-height: 48px; align-items: center; justify-content: space-between; gap: 16px; padding: 0 18px; background: rgb(35 42 52 / 34%); }
.code-reader-header > span,
.code-reader-header > div { display: flex; min-width: 0; align-items: center; gap: 9px; }
.code-reader-header strong { overflow: hidden; color: #e1e5ea; font: 600 11px/1.2 ui-monospace, SFMono-Regular, Menlo, monospace; text-overflow: ellipsis; white-space: nowrap; }
.code-reader-header button { display: grid; min-width: 28px; height: 28px; place-items: center; padding: 0 7px; border-radius: 7px; background: transparent; color: #9da7b4; cursor: pointer; }
.code-reader-header button:hover,
.code-reader-header button:focus-visible { outline: 0; background: #39424e; color: #e5e8ec; }
.code-commit-strip { display: flex; min-height: 42px; align-items: center; justify-content: space-between; gap: 16px; padding: 0 18px; background: rgb(42 49 60 / 56%); color: #8f99a7; font-size: 9px; }
.code-commit-strip > span { display: flex; align-items: center; gap: 7px; }
.code-commit-strip img { width: 20px; height: 20px; border-radius: 50%; }
.code-commit-strip strong { color: #cdd3dc; }
.code-lines { min-height: 0; flex: 1; overflow: auto; padding: 12px 0 24px; background: #2b323d; }
.code-line { display: grid; min-width: 760px; min-height: 21px; grid-template-columns: 54px minmax(0, 1fr); align-items: start; color: #c9d0d9; font: 500 11px/21px ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, monospace; }
.code-line:hover { background: rgb(69 77 91 / 28%); }
.code-line-number { padding-right: 14px; color: #687586; text-align: right; user-select: none; }
.code-line code { white-space: pre; }
.token-keyword { color: #ce94c9; }
.token-type { color: #7fb7d1; }
.token-string { color: #a5c97e; }
.token-variable { color: #dda45f; }
.token-comment { color: #758493; }
.token-method { color: #d6bf78; }
.code-reader-footer { display: flex; min-height: 30px; align-items: center; justify-content: flex-end; gap: 18px; padding: 0 14px; background: #29313b; color: #8994a2; font-size: 9px; }

@media (max-width: 1180px) {
  .project-code-view { grid-template-columns: 190px minmax(430px, 1fr) 280px; }
}

@media (max-width: 1000px) {
  .project-code-view { grid-template-columns: 180px minmax(430px, 1fr); overflow: auto; }
  .project-code-view :deep(.project-context-pane) { display: none; }
}

@media (max-width: 720px) {
  .project-code-view { display: block; overflow-y: auto; }
  .code-file-tree { display: none; }
  .code-reader { min-height: 620px; }
}
</style>
