<script setup lang="ts">
import {
  ArrowLeft,
  Check,
  ChevronDown,
  CircleCheckBig,
  ClipboardList,
  Code2,
  FileText,
  Flag,
  GitBranch,
  Lightbulb,
  MoreHorizontal,
  MousePointer2,
  PencilLine,
  Play,
  Plus,
  Search,
  Send,
  Settings2,
  Timer,
  Trash2,
  UserRound,
  UsersRound,
  Wrench,
  X,
  ZoomIn,
  ZoomOut,
} from "@lucide/vue";
import { computed, nextTick, onBeforeUnmount, onMounted, ref, watch } from "vue";
import initialWorkflowYaml from "../../workflows/customer-request-review.yaml?raw";

type AuthoringMode = "visual" | "guidance" | "yaml";
type NodeKind = "trigger" | "human" | "decision" | "finish";
type EditorIntent = "create" | "edit";
type GuidanceCategory = "Experience" | "Communication" | "Code quality" | "Testing" | "Security" | "Delivery" | "General";

type GuidanceItem = {
  id: string;
  text: string;
  category: GuidanceCategory;
  source: "team_feedback" | "manual";
  sourceLabel: string;
  author: string;
  recordedAt: string;
  visibility: "internal";
};

type WorkflowNode = {
  id: string;
  title: string;
  kind: NodeKind;
  owner: string;
  instructions: string;
  output: string;
  guidance: GuidanceItem[];
};

type NodePosition = { x: number; y: number };
type NodeSide = "top" | "right" | "bottom" | "left";
type WorkflowEdge = {
  id: string;
  from: string;
  to: string;
  fromSide: NodeSide;
  toSide: NodeSide;
  tone: "neutral" | "approved" | "changes";
  label?: string;
};

const props = withDefaults(defineProps<{ initialName?: string; intent?: EditorIntent }>(), {
  initialName: "Customer Request Review",
  intent: "edit",
});

const emit = defineEmits<{ back: [] }>();

const initialWorkflowName = props.initialName;
const initialWorkflowId = initialWorkflowName
  .trim()
  .toLowerCase()
  .replace(/[^a-z0-9]+/g, "-")
  .replace(/^-|-$/g, "") || "untitled-workflow";

const mode = ref<AuthoringMode>("visual");
const workflowName = ref(initialWorkflowName);
const workflowId = ref(initialWorkflowId);
const workflowStatus = ref<"draft" | "published">(props.intent === "edit" ? "published" : "draft");
const selectedNodeId = ref("review_request");
const nodeLibraryOpen = ref(false);
const notice = ref("");
const yamlEditor = ref<HTMLTextAreaElement | null>(null);
const yamlLineNumbers = ref<HTMLElement | null>(null);
const workflowCanvas = ref<HTMLElement | null>(null);
const canvasScroller = ref<HTMLElement | null>(null);
const workflowWorld = ref<HTMLElement | null>(null);
const canvasSize = ref({ width: 1800, height: 1100 });
const draggingNodeId = ref<string | null>(null);
const canvasPanning = ref(false);
const canvasGridOffset = ref({ x: 0, y: 0 });
const nodeLibraryPosition = ref({ x: 24, y: 120 });
const guidanceEditorId = ref<string | null>(null);
const guidanceDraft = ref("");
const guidanceComposerOpen = ref(false);
const newGuidanceDraft = ref("");
const newGuidanceCategory = ref<GuidanceCategory>("General");
const expandedGuidanceCategories = ref<GuidanceCategory[]>(["Experience", "Communication"]);
const guidanceCategoryOptions: GuidanceCategory[] = ["General", "Experience", "Communication", "Code quality", "Testing", "Security", "Delivery"];
let noticeTimer: number | undefined;
let dragState: { id: string; offsetX: number; offsetY: number; moved: boolean } | null = null;
let canvasPanState: { pointerId: number; startX: number; startY: number; scrollLeft: number; scrollTop: number } | null = null;
const minimumCanvasSize = { width: 1800, height: 1100 };
const canvasContentPadding = 180;

const nodes = ref<WorkflowNode[]>([
  { id: "manual_start", title: "Manual start", kind: "trigger", owner: "Unassigned", instructions: "Start this workflow when work is ready.", output: "Workflow started", guidance: [] },
  {
    id: "review_request",
    title: "Review request",
    kind: "human",
    owner: "Operations team",
    instructions: "Review the request and gather any missing information. If anything is unclear, send it back for changes.",
    output: "Request review",
    guidance: [
      {
        id: "guide-visual-borders",
        text: "For web design work, do not use borders to separate sections. Use spacing and surface contrast instead.",
        category: "Experience",
        source: "team_feedback",
        sourceLabel: "Web design task · KAT-248",
        author: "Morgan Lee",
        recordedAt: "Aug 2, 2026",
        visibility: "internal",
      },
      {
        id: "guide-plain-language",
        text: "Use plain language when asking for missing information.",
        category: "Communication",
        source: "team_feedback",
        sourceLabel: "Request review · KAT-231",
        author: "Operations team",
        recordedAt: "Jul 29, 2026",
        visibility: "internal",
      },
    ],
  },
  {
    id: "approved",
    title: "Approved?",
    kind: "decision",
    owner: "Unassigned",
    instructions: "Decide whether the request can move forward.",
    output: "Approved or needs changes",
    guidance: [
      { id: "guide-review-auth", text: "Check authorization at the resource level, not only at the route or controller level.", category: "Security", source: "team_feedback", sourceLabel: "Code review · KAT-219", author: "Sentinel", recordedAt: "Jul 25, 2026", visibility: "internal" },
      { id: "guide-review-errors", text: "Review failure paths and recovery behavior, not only the happy path.", category: "Code quality", source: "team_feedback", sourceLabel: "Code review · KAT-219", author: "Morgan Lee", recordedAt: "Jul 25, 2026", visibility: "internal" },
      { id: "guide-review-tests", text: "Require a focused regression test for every bug fix.", category: "Testing", source: "team_feedback", sourceLabel: "Review feedback · KAT-207", author: "Operations team", recordedAt: "Jul 21, 2026", visibility: "internal" },
      { id: "guide-review-scope", text: "Call out unrelated changes that make the review harder to reason about.", category: "Delivery", source: "team_feedback", sourceLabel: "Review feedback · KAT-201", author: "Morgan Lee", recordedAt: "Jul 19, 2026", visibility: "internal" },
    ],
  },
  {
    id: "complete_work",
    title: "Complete work",
    kind: "human",
    owner: "Operations team",
    instructions: "Complete the approved work and record the result.",
    output: "Completed work",
    guidance: [
      { id: "guide-small-changes", text: "Prefer small, reviewable changes that solve one bounded problem.", category: "Delivery", source: "team_feedback", sourceLabel: "Development task · KAT-244", author: "Morgan Lee", recordedAt: "Aug 1, 2026", visibility: "internal" },
      { id: "guide-existing-patterns", text: "Follow the repository's existing patterns before introducing a new abstraction.", category: "Code quality", source: "team_feedback", sourceLabel: "Development task · KAT-240", author: "Artisan", recordedAt: "Jul 31, 2026", visibility: "internal" },
      { id: "guide-no-driveby", text: "Do not include unrelated refactors or formatting changes in the same change set.", category: "Delivery", source: "team_feedback", sourceLabel: "Development task · KAT-237", author: "Morgan Lee", recordedAt: "Jul 30, 2026", visibility: "internal" },
      { id: "guide-error-paths", text: "Handle and test the important error paths explicitly.", category: "Testing", source: "team_feedback", sourceLabel: "Development task · KAT-233", author: "Operations team", recordedAt: "Jul 29, 2026", visibility: "internal" },
      { id: "guide-focused-tests", text: "Run the narrowest relevant tests first, then the broader project checks.", category: "Testing", source: "team_feedback", sourceLabel: "Development task · KAT-226", author: "Artisan", recordedAt: "Jul 27, 2026", visibility: "internal" },
      { id: "guide-no-secrets", text: "Never print, commit, or copy secret values into task notes or logs.", category: "Security", source: "team_feedback", sourceLabel: "Security review · KAT-218", author: "Sentinel", recordedAt: "Jul 24, 2026", visibility: "internal" },
      { id: "guide-accessible-controls", text: "Use semantic controls and preserve keyboard access for every interactive element.", category: "Experience", source: "team_feedback", sourceLabel: "Web interface task · KAT-212", author: "Morgan Lee", recordedAt: "Jul 23, 2026", visibility: "internal" },
      { id: "guide-explain-surprises", text: "Document behavior that would be surprising to the next person maintaining the code.", category: "Communication", source: "team_feedback", sourceLabel: "Development task · KAT-198", author: "Atlas", recordedAt: "Jul 18, 2026", visibility: "internal" },
    ],
  },
  { id: "request_changes", title: "Request changes", kind: "human", owner: "Operations team", instructions: "Explain what needs to change and return the request for review.", output: "Change request", guidance: [] },
  { id: "confirm_outcome", title: "Confirm outcome", kind: "finish", owner: "Operations team", instructions: "Confirm the result and close the workflow.", output: "Confirmed outcome", guidance: [] },
]);

const nodeDimensions: Record<string, { width: number; height: number }> = {
  manual_start: { width: 150, height: 78 },
  review_request: { width: 195, height: 78 },
  approved: { width: 165, height: 78 },
  complete_work: { width: 170, height: 78 },
  request_changes: { width: 180, height: 78 },
  confirm_outcome: { width: 175, height: 78 },
};

const nodeLayout = ref<Record<string, NodePosition>>({
  manual_start: { x: 60, y: 270 },
  review_request: { x: 370, y: 260 },
  approved: { x: 700, y: 270 },
  complete_work: { x: 1010, y: 120 },
  request_changes: { x: 760, y: 560 },
  confirm_outcome: { x: 1080, y: 560 },
});

const workflowEdges: WorkflowEdge[] = [
  { id: "start-review", from: "manual_start", to: "review_request", fromSide: "right", toSide: "left", tone: "neutral" },
  { id: "review-decision", from: "review_request", to: "approved", fromSide: "right", toSide: "left", tone: "neutral" },
  { id: "decision-complete", from: "approved", to: "complete_work", fromSide: "right", toSide: "left", tone: "approved", label: "Approved" },
  { id: "decision-changes", from: "approved", to: "request_changes", fromSide: "bottom", toSide: "top", tone: "changes", label: "Needs changes" },
  { id: "complete-finish", from: "complete_work", to: "confirm_outcome", fromSide: "bottom", toSide: "top", tone: "neutral" },
  { id: "changes-review", from: "request_changes", to: "review_request", fromSide: "left", toSide: "bottom", tone: "changes" },
];

const yamlSource = ref(
  initialWorkflowYaml
    .replaceAll("Customer Request Review", initialWorkflowName)
    .replaceAll("customer-request-review", initialWorkflowId),
);

const selectedNode = computed(() => nodes.value.find((node) => node.id === selectedNodeId.value) ?? nodes.value[1]);
const yamlLines = computed(() => yamlSource.value.split("\n").length);
const yamlValid = computed(() => (
  /^version:\s*katra\.workflow\/v1/m.test(yamlSource.value)
  && /^steps:/m.test(yamlSource.value)
  && /^\s+- id:/m.test(yamlSource.value)
));
const workflowPath = computed(() => `workflows/${workflowId.value}.yaml`);
const isEditing = computed(() => props.intent === "edit");
const workflowGuidanceCount = computed(() => nodes.value.reduce((count, node) => count + node.guidance.length, 0));
const guidanceGroups = computed(() => {
  const order: GuidanceCategory[] = ["Experience", "Communication", "Code quality", "Testing", "Security", "Delivery", "General"];
  return order
    .map((category) => ({ category, items: selectedNode.value.guidance.filter((item) => item.category === category) }))
    .filter((group) => group.items.length);
});

function showNotice(message: string) {
  notice.value = message;
  window.clearTimeout(noticeTimer);
  noticeTimer = window.setTimeout(() => {
    notice.value = "";
  }, 2600);
}

function nodeIcon(kind: NodeKind) {
  if (kind === "trigger") return Play;
  if (kind === "decision") return GitBranch;
  if (kind === "finish") return CircleCheckBig;
  return ClipboardList;
}

function ownerIcon(owner: string) {
  return owner === "Unassigned" ? UserRound : UsersRound;
}

function toggleGuidanceCategory(category: GuidanceCategory) {
  expandedGuidanceCategories.value = expandedGuidanceCategories.value.includes(category)
    ? expandedGuidanceCategories.value.filter((item) => item !== category)
    : [...expandedGuidanceCategories.value, category];
}

function selectGuidanceNode(id: string) {
  selectedNodeId.value = id;
  const firstCategory = nodes.value.find((node) => node.id === id)?.guidance[0]?.category;
  expandedGuidanceCategories.value = firstCategory ? [firstCategory] : [];
}

function clamp(value: number, minimum: number, maximum: number) {
  return Math.min(Math.max(value, minimum), maximum);
}

function getNodeFrame(id: string) {
  const position = nodeLayout.value[id] ?? { x: 0, y: 0 };
  const dimensions = nodeDimensions[id] ?? { width: 180, height: 78 };
  return {
    x: position.x,
    y: position.y,
    width: dimensions.width,
    height: dimensions.height,
  };
}

function nodeStyle(id: string) {
  const frame = getNodeFrame(id);
  return {
    left: `${frame.x}px`,
    top: `${frame.y}px`,
    width: `${frame.width}px`,
  };
}

function getAnchor(id: string, side: NodeSide): NodePosition {
  const frame = getNodeFrame(id);
  if (side === "top") return { x: frame.x + frame.width / 2, y: frame.y };
  if (side === "right") return { x: frame.x + frame.width, y: frame.y + frame.height / 2 };
  if (side === "bottom") return { x: frame.x + frame.width / 2, y: frame.y + frame.height };
  return { x: frame.x, y: frame.y + frame.height / 2 };
}

function getWorkflowEdge(id: string) {
  return workflowEdges.find((edge) => edge.id === id) ?? workflowEdges[0];
}

function connectorPath(edge: WorkflowEdge) {
  const start = getAnchor(edge.from, edge.fromSide);
  const end = getAnchor(edge.to, edge.toSide);

  if (edge.fromSide === "right" && edge.toSide === "left") {
    const pull = Math.max(42, Math.abs(end.x - start.x) * 0.48);
    return `M ${start.x} ${start.y} C ${start.x + pull} ${start.y}, ${end.x - pull} ${end.y}, ${end.x} ${end.y}`;
  }

  if (edge.fromSide === "bottom" && edge.toSide === "top") {
    const pull = Math.max(48, Math.abs(end.y - start.y) * 0.48);
    return `M ${start.x} ${start.y} C ${start.x} ${start.y + pull}, ${end.x} ${end.y - pull}, ${end.x} ${end.y}`;
  }

  const horizontalPull = Math.max(110, Math.abs(end.x - start.x) * 0.5);
  const verticalPull = Math.max(100, Math.abs(end.y - start.y) * 0.5);
  return `M ${start.x} ${start.y} C ${start.x - horizontalPull} ${start.y}, ${end.x} ${end.y + verticalPull}, ${end.x} ${end.y}`;
}

function connectorAddStyle(edgeId: string) {
  const edge = getWorkflowEdge(edgeId);
  const start = getAnchor(edge.from, edge.fromSide);
  const end = getAnchor(edge.to, edge.toSide);
  return {
    left: `${(start.x + end.x) / 2 - 13.5}px`,
    top: `${(start.y + end.y) / 2 - 13.5}px`,
  };
}

function branchLabelStyle(edgeId: string) {
  const edge = getWorkflowEdge(edgeId);
  const start = getAnchor(edge.from, edge.fromSide);
  const end = getAnchor(edge.to, edge.toSide);
  const verticalEdge = edge.fromSide === "bottom";
  return {
    left: `${(start.x + end.x) / 2 + (verticalEdge ? 12 : -28)}px`,
    top: `${(start.y + end.y) / 2 - (verticalEdge ? 8 : 26)}px`,
  };
}

function canvasContentExtent() {
  return Object.keys(nodeLayout.value).reduce((extent, id) => {
    const frame = getNodeFrame(id);
    return {
      width: Math.max(extent.width, frame.x + frame.width + canvasContentPadding),
      height: Math.max(extent.height, frame.y + frame.height + canvasContentPadding),
    };
  }, { ...minimumCanvasSize });
}

function expandCanvasToContent() {
  const content = canvasContentExtent();
  canvasSize.value = {
    width: Math.ceil(Math.max(canvasSize.value.width, content.width)),
    height: Math.ceil(Math.max(canvasSize.value.height, content.height)),
  };
}

function openNodeLibrary(event: MouseEvent) {
  event.stopPropagation();
  const canvas = workflowCanvas.value;
  const scroller = canvasScroller.value;
  const world = workflowWorld.value;
  const trigger = event.currentTarget as HTMLElement;
  if (!canvas || !scroller || !world) return;

  if (nodeLibraryOpen.value) {
    nodeLibraryOpen.value = false;
    return;
  }

  const worldRect = world.getBoundingClientRect();
  const triggerRect = trigger.getBoundingClientRect();
  const menuWidth = 260;
  const menuHeight = 382;
  const gap = 10;
  const visibleLeft = scroller.scrollLeft;
  const visibleTop = scroller.scrollTop;
  const visibleRight = visibleLeft + scroller.clientWidth;
  const visibleBottom = visibleTop + scroller.clientHeight;
  let x = triggerRect.left - worldRect.left + triggerRect.width / 2 - menuWidth / 2;
  let y = triggerRect.bottom - worldRect.top + gap;

  if (y + menuHeight > visibleBottom - 12) {
    y = triggerRect.top - worldRect.top - menuHeight - gap;
  }

  nodeLibraryPosition.value = {
    x: clamp(x, visibleLeft + 12, Math.max(visibleLeft + 12, Math.min(canvasSize.value.width - menuWidth - 12, visibleRight - menuWidth - 12))),
    y: clamp(y, visibleTop + 12, Math.max(visibleTop + 12, Math.min(canvasSize.value.height - menuHeight - 12, visibleBottom - menuHeight - 12))),
  };
  nodeLibraryOpen.value = true;
  nextTick(() => {
    canvas.querySelector<HTMLInputElement>(".node-library input")?.focus({ preventScroll: true });
  });
}

function startNodeDrag(event: PointerEvent, id: string) {
  if (event.button !== 0 || !workflowWorld.value) return;
  event.preventDefault();
  const target = event.currentTarget as HTMLElement;
  target.focus({ preventScroll: true });
  target.setPointerCapture?.(event.pointerId);
  selectedNodeId.value = id;
  nodeLibraryOpen.value = false;
  const worldRect = workflowWorld.value.getBoundingClientRect();
  const frame = getNodeFrame(id);
  dragState = {
    id,
    offsetX: event.clientX - worldRect.left - frame.x,
    offsetY: event.clientY - worldRect.top - frame.y,
    moved: false,
  };
  draggingNodeId.value = id;
  window.addEventListener("pointermove", moveNode);
  window.addEventListener("pointerup", stopNodeDrag, { once: true });
  window.addEventListener("pointercancel", stopNodeDrag, { once: true });
}

function moveNode(event: PointerEvent) {
  if (!dragState || !workflowWorld.value) return;
  event.preventDefault();
  const worldRect = workflowWorld.value.getBoundingClientRect();
  const dimensions = nodeDimensions[dragState.id];
  const x = Math.max(16, event.clientX - worldRect.left - dragState.offsetX);
  const y = Math.max(16, event.clientY - worldRect.top - dragState.offsetY);
  nodeLayout.value[dragState.id] = { x, y };
  canvasSize.value = {
    width: Math.ceil(Math.max(canvasSize.value.width, x + dimensions.width + canvasContentPadding)),
    height: Math.ceil(Math.max(canvasSize.value.height, y + dimensions.height + canvasContentPadding)),
  };
  dragState.moved = true;
}

function stopNodeDrag() {
  window.removeEventListener("pointermove", moveNode);
  window.removeEventListener("pointercancel", stopNodeDrag);
  if (dragState?.moved) {
    workflowStatus.value = "draft";
    serializeVisualState();
    showNotice("Node position saved to YAML.");
  }
  dragState = null;
  draggingNodeId.value = null;
}

function nudgeNode(event: KeyboardEvent, id: string) {
  const amount = event.shiftKey ? 24 : 10;
  const movement = {
    ArrowLeft: { x: -amount, y: 0 },
    ArrowRight: { x: amount, y: 0 },
    ArrowUp: { x: 0, y: -amount },
    ArrowDown: { x: 0, y: amount },
  }[event.key];
  if (!movement) return;
  event.preventDefault();
  const frame = getNodeFrame(id);
  const dimensions = nodeDimensions[id];
  const x = Math.max(16, frame.x + movement.x);
  const y = Math.max(16, frame.y + movement.y);
  nodeLayout.value[id] = { x, y };
  canvasSize.value = {
    width: Math.ceil(Math.max(canvasSize.value.width, x + dimensions.width + canvasContentPadding)),
    height: Math.ceil(Math.max(canvasSize.value.height, y + dimensions.height + canvasContentPadding)),
  };
  workflowStatus.value = "draft";
  serializeVisualState();
}

function centerWorkflowInView() {
  const scroller = canvasScroller.value;
  if (!scroller) return;
  const frames = Object.keys(nodeLayout.value).map(getNodeFrame);
  const left = Math.min(...frames.map((frame) => frame.x));
  const top = Math.min(...frames.map((frame) => frame.y));
  const right = Math.max(...frames.map((frame) => frame.x + frame.width));
  const bottom = Math.max(...frames.map((frame) => frame.y + frame.height));
  scroller.scrollTo({
    left: Math.max(0, (left + right - scroller.clientWidth) / 2),
    top: Math.max(0, (top + bottom - scroller.clientHeight) / 2),
    behavior: "smooth",
  });
  showNotice("Workflow centered in the canvas.");
}

function startCanvasPan(event: PointerEvent) {
  const scroller = canvasScroller.value;
  const world = workflowWorld.value;
  if (event.button !== 0 || !scroller || !world) return;
  event.preventDefault();
  scroller.focus({ preventScroll: true });
  world.setPointerCapture?.(event.pointerId);
  nodeLibraryOpen.value = false;
  canvasPanState = {
    pointerId: event.pointerId,
    startX: event.clientX,
    startY: event.clientY,
    scrollLeft: scroller.scrollLeft,
    scrollTop: scroller.scrollTop,
  };
  canvasPanning.value = true;
  window.addEventListener("pointermove", moveCanvasPan);
  window.addEventListener("pointerup", stopCanvasPan, { once: true });
  window.addEventListener("pointercancel", stopCanvasPan, { once: true });
}

function moveCanvasPan(event: PointerEvent) {
  const scroller = canvasScroller.value;
  if (!canvasPanState || !scroller || event.pointerId !== canvasPanState.pointerId) return;
  event.preventDefault();
  scroller.scrollLeft = canvasPanState.scrollLeft - (event.clientX - canvasPanState.startX);
  scroller.scrollTop = canvasPanState.scrollTop - (event.clientY - canvasPanState.startY);
}

function stopCanvasPan() {
  window.removeEventListener("pointermove", moveCanvasPan);
  window.removeEventListener("pointercancel", stopCanvasPan);
  canvasPanState = null;
  canvasPanning.value = false;
}

function panCanvasWithKeyboard(event: KeyboardEvent) {
  const scroller = canvasScroller.value;
  if (!scroller) return;
  const amount = event.shiftKey ? 160 : 64;
  const movement = {
    ArrowLeft: { left: -amount, top: 0 },
    ArrowRight: { left: amount, top: 0 },
    ArrowUp: { left: 0, top: -amount },
    ArrowDown: { left: 0, top: amount },
  }[event.key];
  if (!movement) return;
  event.preventDefault();
  scroller.scrollBy({ ...movement, behavior: "smooth" });
}

function syncCanvasGrid() {
  const scroller = canvasScroller.value;
  if (!scroller) return;
  canvasGridOffset.value = { x: scroller.scrollLeft, y: scroller.scrollTop };
}

function serializeGuidance(items: GuidanceItem[]) {
  if (!items.length) return "";
  const entries = items.map((item) => {
    const text = item.text.trim().replaceAll("\n", "\n          ");
    return `      - id: ${item.id}\n        text: >-\n          ${text}\n        category: ${JSON.stringify(item.category)}\n        provenance:\n          source: ${item.source}\n          source_label: ${JSON.stringify(item.sourceLabel)}\n          author: ${JSON.stringify(item.author)}\n          recorded_at: ${JSON.stringify(item.recordedAt)}\n          visibility: ${item.visibility}`;
  }).join("\n");
  return `    guidance:\n${entries}\n`;
}

function commitVisualChange(message?: string) {
  workflowStatus.value = "draft";
  serializeVisualState();
  if (message) showNotice(message);
}

function startGuidanceEdit(item: GuidanceItem) {
  guidanceEditorId.value = item.id;
  guidanceDraft.value = item.text;
  guidanceComposerOpen.value = false;
}

function cancelGuidanceEdit() {
  guidanceEditorId.value = null;
  guidanceDraft.value = "";
}

function saveGuidanceEdit(item: GuidanceItem) {
  const text = guidanceDraft.value.trim();
  if (!text) return;
  item.text = text;
  cancelGuidanceEdit();
  commitVisualChange("Guidance updated in the workflow YAML.");
}

function removeGuidance(itemId: string) {
  const index = selectedNode.value.guidance.findIndex((item) => item.id === itemId);
  if (index < 0) return;
  selectedNode.value.guidance.splice(index, 1);
  if (guidanceEditorId.value === itemId) cancelGuidanceEdit();
  commitVisualChange("Guidance removed from future runs.");
}

function openGuidanceComposer() {
  guidanceComposerOpen.value = true;
  guidanceEditorId.value = null;
  guidanceDraft.value = "";
  nextTick(() => {
    document.querySelector<HTMLTextAreaElement>(".guidance-composer textarea")?.focus({ preventScroll: true });
  });
}

function closeGuidanceComposer() {
  guidanceComposerOpen.value = false;
  newGuidanceDraft.value = "";
  newGuidanceCategory.value = "General";
}

function addGuidance() {
  const text = newGuidanceDraft.value.trim();
  if (!text) return;
  selectedNode.value.guidance.push({
    id: `guide-manual-${Date.now()}`,
    text,
    category: newGuidanceCategory.value,
    source: "manual",
    sourceLabel: "Added in workflow editor",
    author: "Morgan Lee",
    recordedAt: "Aug 7, 2026",
    visibility: "internal",
  });
  closeGuidanceComposer();
  commitVisualChange("Guidance added to future runs.");
}

function serializeVisualState() {
  const review = nodes.value.find((node) => node.id === "review_request");
  const complete = nodes.value.find((node) => node.id === "complete_work");
  const changes = nodes.value.find((node) => node.id === "request_changes");
  const confirm = nodes.value.find((node) => node.id === "confirm_outcome");
  const role = (review?.owner ?? "Operations team").toLowerCase().replaceAll(" team", "").replaceAll(" ", "_");
  const layoutYaml = Object.entries(nodeLayout.value)
    .map(([id, position]) => `  ${id}:\n    x: ${Math.round(position.x)}\n    y: ${Math.round(position.y)}`)
    .join("\n");

  const approved = nodes.value.find((node) => node.id === "approved");
  yamlSource.value = `# Katra workflow definitions are portable, reviewable files.\nversion: katra.workflow/v1\nid: ${workflowId.value}\nname: ${workflowName.value}\n\nlayout:\n  canvas:\n    width: ${canvasSize.value.width}\n    height: ${canvasSize.value.height}\n${layoutYaml}\n\ntrigger:\n  type: manual\n\nsteps:\n  - id: review_request\n    name: ${review?.title}\n    use: human_task\n    assigned_to:\n      type: team\n      role: ${role}\n    instructions: >-\n      ${review?.instructions}\n    required_output: ${review?.output}\n${serializeGuidance(review?.guidance ?? [])}    next: approved\n\n  - id: approved\n    name: Approved?\n    use: decision\n${serializeGuidance(approved?.guidance ?? [])}    branches:\n      - when: approved\n        next: complete_work\n      - when: needs_changes\n        next: request_changes\n\n  - id: complete_work\n    name: ${complete?.title}\n    use: human_task\n    assigned_to:\n      type: team\n      role: operations\n${serializeGuidance(complete?.guidance ?? [])}    next: confirm_outcome\n\n  - id: request_changes\n    name: ${changes?.title}\n    use: human_task\n    assigned_to:\n      type: team\n      role: operations\n${serializeGuidance(changes?.guidance ?? [])}    next: review_request\n\n  - id: confirm_outcome\n    name: ${confirm?.title}\n    use: human_task\n    assigned_to:\n      type: team\n      role: operations\n${serializeGuidance(confirm?.guidance ?? [])}    next: complete\n`;
}

function readYamlScalar(value: string) {
  const trimmed = value.trim();
  if (trimmed.startsWith('"')) {
    try {
      return JSON.parse(trimmed) as string;
    } catch {
      return trimmed.replace(/^"|"$/g, "");
    }
  }
  return trimmed;
}

function syncGuidanceFromYaml(node: WorkflowNode) {
  const lines = yamlSource.value.split("\n");
  const stepStart = lines.findIndex((line) => line === `  - id: ${node.id}`);
  if (stepStart < 0) return;
  let stepEnd = lines.findIndex((line, index) => index > stepStart && line.startsWith("  - id: "));
  if (stepEnd < 0) stepEnd = lines.length;
  const guidanceStart = lines.findIndex((line, index) => index > stepStart && index < stepEnd && line === "    guidance:");
  if (guidanceStart < 0) {
    node.guidance = [];
    return;
  }

  const parsed: GuidanceItem[] = [];
  let cursor = guidanceStart + 1;
  while (cursor < stepEnd && lines[cursor].startsWith("      - id: ")) {
    const id = lines[cursor].slice("      - id: ".length).trim();
    cursor += 1;
    if (lines[cursor]?.trim() !== "text: >-") break;
    cursor += 1;
    const textLines: string[] = [];
    while (cursor < stepEnd && lines[cursor].startsWith("          ") && lines[cursor].trim() !== "provenance:") {
      textLines.push(lines[cursor].slice(10));
      cursor += 1;
    }
    let category: GuidanceCategory = "General";
    if (lines[cursor]?.trim().startsWith("category:")) {
      category = readYamlScalar(lines[cursor].split(":").slice(1).join(":")) as GuidanceCategory;
      cursor += 1;
    }
    if (lines[cursor]?.trim() === "provenance:") cursor += 1;
    const provenance: Record<string, string> = {};
    while (cursor < stepEnd && lines[cursor].startsWith("          ")) {
      const separator = lines[cursor].indexOf(":");
      if (separator > -1) {
        provenance[lines[cursor].slice(10, separator)] = readYamlScalar(lines[cursor].slice(separator + 1));
      }
      cursor += 1;
    }
    parsed.push({
      id,
      text: textLines.join("\n").trim(),
      category,
      source: provenance.source === "manual" ? "manual" : "team_feedback",
      sourceLabel: provenance.source_label || "Workflow guidance",
      author: provenance.author || "Katra team",
      recordedAt: provenance.recorded_at || "Date unavailable",
      visibility: "internal",
    });
  }
  node.guidance = parsed;
}

function syncVisualFromYaml() {
  if (!yamlValid.value) return;

  const name = yamlSource.value.match(/^name:\s*(.+)$/m)?.[1]?.trim();
  const id = yamlSource.value.match(/^id:\s*(.+)$/m)?.[1]?.trim();
  if (name) workflowName.value = name;
  if (id) workflowId.value = id;

  const canvasMatch = yamlSource.value.match(/^  canvas:\n    width:\s*([0-9.]+)\n    height:\s*([0-9.]+)/m);
  if (canvasMatch) {
    canvasSize.value = {
      width: Math.max(minimumCanvasSize.width, Number(canvasMatch[1])),
      height: Math.max(minimumCanvasSize.height, Number(canvasMatch[2])),
    };
  }

  for (const nodeId of Object.keys(nodeLayout.value)) {
    const layoutMatch = yamlSource.value.match(new RegExp(`^  ${nodeId}:\\n    x:\\s*([0-9.]+)\\n    y:\\s*([0-9.]+)`, "m"));
    if (!layoutMatch) continue;
    nodeLayout.value[nodeId] = {
      x: Math.max(0, Number(layoutMatch[1])),
      y: Math.max(0, Number(layoutMatch[2])),
    };
  }
  expandCanvasToContent();

  for (const node of nodes.value.filter((item) => item.id !== "manual_start")) syncGuidanceFromYaml(node);
}

function syncYamlScroll(event: Event) {
  const editor = event.currentTarget as HTMLTextAreaElement;
  if (yamlLineNumbers.value) yamlLineNumbers.value.scrollTop = editor.scrollTop;
}

async function setMode(nextMode: AuthoringMode) {
  if (nextMode === mode.value) return;
  const previousMode = mode.value;

  if (nextMode === "yaml") {
    serializeVisualState();
    mode.value = "yaml";
    await nextTick();
    yamlEditor.value?.focus({ preventScroll: true });
    yamlEditor.value?.setSelectionRange(0, 0);
    if (yamlEditor.value) {
      yamlEditor.value.scrollTop = 0;
      yamlEditor.value.scrollLeft = 0;
    }
    if (yamlLineNumbers.value) yamlLineNumbers.value.scrollTop = 0;
    showNotice("Visual changes synchronized to YAML.");
    return;
  }

  if (mode.value === "yaml") {
    if (!yamlValid.value) {
      showNotice("Fix the YAML validation error before leaving YAML.");
      return;
    }
    syncVisualFromYaml();
  }

  mode.value = nextMode;
  if (nextMode === "visual" && previousMode === "yaml") showNotice("Visual builder updated from YAML.");
  else if (nextMode === "visual") showNotice("Visual builder opened.");
  else showNotice("Guidance manager opened.");
}

function addNode(kind: "human" | "decision" | "wait" | "update" | "agent" | "system" | "finish") {
  nodeLibraryOpen.value = false;
  const labels = {
    human: "New human task",
    decision: "New decision",
    wait: "Wait",
    update: "Send update",
    agent: "Agent task",
    system: "System action",
    finish: "Finish workflow",
  };
  showNotice(`${labels[kind]} added to the draft.`);
}

function testWorkflow() {
  showNotice("Manual test run started. The first task is ready in Inbox.");
}

function publishWorkflow() {
  if (!yamlValid.value) {
    showNotice("The workflow must contain valid YAML before publishing.");
    return;
  }
  workflowStatus.value = "published";
  showNotice(isEditing.value ? "Workflow version 2 published." : "Workflow version 1 published.");
}

watch(selectedNodeId, () => {
  cancelGuidanceEdit();
  closeGuidanceComposer();
});

watch(mode, async (nextMode) => {
  if (nextMode !== "visual") return;
  await nextTick();
  expandCanvasToContent();
});

onMounted(() => {
  expandCanvasToContent();
});

onBeforeUnmount(() => {
  window.removeEventListener("pointermove", moveNode);
  window.removeEventListener("pointerup", stopNodeDrag);
  window.removeEventListener("pointercancel", stopNodeDrag);
  window.removeEventListener("pointermove", moveCanvasPan);
  window.removeEventListener("pointerup", stopCanvasPan);
  window.removeEventListener("pointercancel", stopCanvasPan);
  window.clearTimeout(noticeTimer);
});
</script>

<template>
  <section class="workflow-editor" aria-labelledby="workflow-editor-title">
    <header class="editor-header">
      <div class="editor-identity">
        <button type="button" class="back-button" @click="emit('back')">
          <ArrowLeft :size="16" aria-hidden="true" />
          <span>Back to workflows</span>
        </button>
        <div class="editor-title-row">
          <input id="workflow-editor-title" v-model="workflowName" aria-label="Workflow name" @change="commitVisualChange()" />
          <PencilLine :size="15" aria-hidden="true" />
          <span class="draft-badge" :class="{ 'is-published': workflowStatus === 'published' }">{{ workflowStatus === 'draft' ? 'Draft' : 'Published' }}</span>
        </div>
        <button type="button" class="workflow-file-path" @click="showNotice('Workflow path copied.')">
          {{ workflowPath }} <FileText :size="13" aria-hidden="true" />
        </button>
        <div class="save-state"><i /> {{ workflowStatus === 'published' && isEditing ? 'Published definition' : 'Autosaved just now' }} <span><Check :size="14" aria-hidden="true" /> {{ yamlValid ? (workflowStatus === 'published' && isEditing ? 'No unpublished changes' : 'All changes valid') : 'YAML needs attention' }}</span></div>
      </div>

      <div class="authoring-tabs" role="tablist" aria-label="Workflow authoring mode">
        <button type="button" role="tab" :aria-selected="mode === 'visual'" :class="{ 'is-active': mode === 'visual' }" @click="setMode('visual')">Visual</button>
        <button type="button" role="tab" :aria-selected="mode === 'guidance'" :class="{ 'is-active': mode === 'guidance' }" @click="setMode('guidance')">Guidance</button>
        <button type="button" role="tab" :aria-selected="mode === 'yaml'" :class="{ 'is-active': mode === 'yaml' }" @click="setMode('yaml')">YAML</button>
      </div>

      <div class="editor-actions">
        <button type="button" class="quiet-action" aria-label="More workflow actions" @click="showNotice('Workflow actions opened.')"><MoreHorizontal :size="19" aria-hidden="true" /></button>
        <button type="button" class="test-action" @click="testWorkflow"><Play :size="16" aria-hidden="true" /> Test workflow</button>
        <button type="button" class="publish-action" @click="publishWorkflow"><Send :size="16" aria-hidden="true" /> {{ isEditing ? 'Publish changes' : 'Publish' }}</button>
      </div>
    </header>

    <div v-if="mode === 'visual'" class="visual-workspace" role="tabpanel" aria-label="Visual workflow builder">
      <section ref="workflowCanvas" class="workflow-canvas" aria-label="Workflow canvas">
        <div class="canvas-controls">
          <button type="button" aria-label="Center workflow in view" @click="centerWorkflowInView"><Settings2 :size="16" aria-hidden="true" /></button>
          <span><button type="button" aria-label="Zoom out"><ZoomOut :size="15" /></button><b>100%</b><button type="button" aria-label="Zoom in"><ZoomIn :size="15" /></button></span>
        </div>

        <div
          ref="canvasScroller"
          class="workflow-canvas-scroller"
          :style="{ backgroundPosition: `${-(canvasGridOffset.x % 19)}px ${-(canvasGridOffset.y % 19)}px` }"
          tabindex="0"
          aria-label="Workflow workspace. Drag empty space to pan, or use arrow keys when focused."
          @scroll="syncCanvasGrid"
          @keydown.self="panCanvasWithKeyboard"
          @click.self="nodeLibraryOpen = false"
        >
          <div
            ref="workflowWorld"
            class="workflow-canvas-world"
            :class="{ 'is-panning': canvasPanning }"
            :style="{ width: `${canvasSize.width}px`, height: `${canvasSize.height}px` }"
            @pointerdown.self="startCanvasPan"
            @click.self="nodeLibraryOpen = false"
          >
        <svg class="flow-connections" :viewBox="`0 0 ${canvasSize.width} ${canvasSize.height}`" preserveAspectRatio="none" aria-hidden="true">
          <path
            v-for="edge in workflowEdges"
            :key="edge.id"
            :d="connectorPath(edge)"
            :class="`flow-connection flow-connection--${edge.tone}`"
            vector-effect="non-scaling-stroke"
          />
        </svg>

        <button type="button" class="flow-node flow-node--start" :class="{ 'is-selected': selectedNodeId === 'manual_start', 'is-dragging': draggingNodeId === 'manual_start' }" :style="nodeStyle('manual_start')" title="Drag to reposition. Use arrow keys for precise movement." @pointerdown="startNodeDrag($event, 'manual_start')" @keydown="nudgeNode($event, 'manual_start')" @click="selectedNodeId = 'manual_start'">
          <span class="node-icon node-icon--trigger"><Play :size="24" aria-hidden="true" /></span>
          <span><small>1</small><strong>Manual start</strong><em>Trigger</em></span>
        </button>

        <button type="button" class="connector-add connector-add--one" :style="connectorAddStyle('start-review')" aria-label="Add a step after Manual start" @click="openNodeLibrary"><Plus :size="15" /></button>

        <button type="button" class="flow-node flow-node--review" :class="{ 'is-selected': selectedNodeId === 'review_request', 'is-dragging': draggingNodeId === 'review_request' }" :style="nodeStyle('review_request')" title="Drag to reposition. Use arrow keys for precise movement." @pointerdown="startNodeDrag($event, 'review_request')" @keydown="nudgeNode($event, 'review_request')" @click="selectedNodeId = 'review_request'">
          <span class="node-icon"><ClipboardList :size="23" aria-hidden="true" /></span>
          <span><small>2</small><strong>{{ nodes[1].title }}</strong><em>Human task · {{ nodes[1].owner }}</em></span>
        </button>

        <button type="button" class="connector-add connector-add--two" :style="connectorAddStyle('review-decision')" aria-label="Add a step after Review request" @click="openNodeLibrary"><Plus :size="15" /></button>

        <button type="button" class="flow-node flow-node--decision" :class="{ 'is-selected': selectedNodeId === 'approved', 'is-dragging': draggingNodeId === 'approved' }" :style="nodeStyle('approved')" title="Drag to reposition. Use arrow keys for precise movement." @pointerdown="startNodeDrag($event, 'approved')" @keydown="nudgeNode($event, 'approved')" @click="selectedNodeId = 'approved'">
          <span class="node-icon node-icon--decision"><GitBranch :size="22" aria-hidden="true" /></span>
          <span><small>3</small><strong>Approved?</strong><em>Decision · Unassigned</em></span>
        </button>

        <span class="branch-label branch-label--approved" :style="branchLabelStyle('decision-complete')">Approved</span>
        <span class="branch-label branch-label--changes" :style="branchLabelStyle('decision-changes')">Needs changes</span>

        <button type="button" class="flow-node flow-node--complete" :class="{ 'is-selected': selectedNodeId === 'complete_work', 'is-dragging': draggingNodeId === 'complete_work' }" :style="nodeStyle('complete_work')" title="Drag to reposition. Use arrow keys for precise movement." @pointerdown="startNodeDrag($event, 'complete_work')" @keydown="nudgeNode($event, 'complete_work')" @click="selectedNodeId = 'complete_work'">
          <span class="node-icon node-icon--complete"><Wrench :size="23" aria-hidden="true" /></span>
          <span><small>4</small><strong>Complete work</strong><em>Human task · Operations team</em></span>
        </button>

        <button type="button" class="flow-node flow-node--changes" :class="{ 'is-selected': selectedNodeId === 'request_changes', 'is-dragging': draggingNodeId === 'request_changes' }" :style="nodeStyle('request_changes')" title="Drag to reposition. Use arrow keys for precise movement." @pointerdown="startNodeDrag($event, 'request_changes')" @keydown="nudgeNode($event, 'request_changes')" @click="selectedNodeId = 'request_changes'">
          <span class="node-icon node-icon--changes"><PencilLine :size="23" aria-hidden="true" /></span>
          <span><small>5</small><strong>Request changes</strong><em>Human task · Operations team</em></span>
        </button>

        <button type="button" class="flow-node flow-node--finish" :class="{ 'is-selected': selectedNodeId === 'confirm_outcome', 'is-dragging': draggingNodeId === 'confirm_outcome' }" :style="nodeStyle('confirm_outcome')" title="Drag to reposition. Use arrow keys for precise movement." @pointerdown="startNodeDrag($event, 'confirm_outcome')" @keydown="nudgeNode($event, 'confirm_outcome')" @click="selectedNodeId = 'confirm_outcome'">
          <span class="node-icon node-icon--complete"><CircleCheckBig :size="23" aria-hidden="true" /></span>
          <span><small>6</small><strong>Confirm outcome</strong><em>Human task · Operations team</em></span>
        </button>

        <div v-if="nodeLibraryOpen" class="node-library" :style="{ left: `${nodeLibraryPosition.x}px`, top: `${nodeLibraryPosition.y}px` }" role="menu">
          <label><Search :size="14" aria-hidden="true" /><input aria-label="Search workflow building blocks" placeholder="Search nodes…" /></label>
          <button type="button" role="menuitem" @click="addNode('human')"><ClipboardList :size="17" /><span><strong>Human task</strong><small>A step performed by a person</small></span></button>
          <button type="button" role="menuitem" @click="addNode('decision')"><GitBranch :size="17" /><span><strong>Decision</strong><small>Branch based on a condition</small></span></button>
          <button type="button" role="menuitem" @click="addNode('wait')"><Timer :size="17" /><span><strong>Wait</strong><small>Pause for time or an event</small></span></button>
          <button type="button" role="menuitem" @click="addNode('update')"><Send :size="17" /><span><strong>Update</strong><small>Send an update or notification</small></span></button>
          <button type="button" role="menuitem" @click="addNode('agent')"><Code2 :size="17" /><span><strong>Agent task</strong><small>Available when an agent exists</small></span></button>
          <button type="button" role="menuitem" @click="addNode('system')"><Settings2 :size="17" /><span><strong>System action</strong><small>Run a system operation</small></span></button>
          <button type="button" role="menuitem" @click="addNode('finish')"><Flag :size="17" /><span><strong>Finish</strong><small>End the workflow</small></span></button>
        </div>
          </div>
        </div>

        <div class="canvas-toolbox" aria-label="Canvas tools">
          <button type="button" class="is-active" aria-label="Select"><MousePointer2 :size="16" /></button>
          <button type="button" aria-label="Add step" @click="openNodeLibrary"><Plus :size="16" /></button>
          <button type="button" aria-label="Connect steps" @click="showNotice('Choose two steps to connect them.')"><GitBranch :size="16" /></button>
        </div>

        <footer class="canvas-status"><span><i /> Visual changes update YAML</span><span>Drag empty canvas to pan</span><span>{{ canvasSize.width }} × {{ canvasSize.height }}</span><span>Schema v1</span></footer>
      </section>

      <aside class="step-inspector" aria-label="Selected step settings">
        <header>
          <span class="inspector-icon"><component :is="nodeIcon(selectedNode.kind)" :size="18" /></span>
          <div><small>Step {{ nodes.findIndex((node) => node.id === selectedNode.id) + 1 }}</small><strong>{{ selectedNode.title }}</strong></div>
          <button type="button" aria-label="Close step settings" @click="selectedNodeId = 'manual_start'"><X :size="17" /></button>
        </header>

        <label><span>Step type</span><button type="button" class="select-control"><component :is="nodeIcon(selectedNode.kind)" :size="16" /><strong>{{ selectedNode.kind === 'human' || selectedNode.kind === 'finish' ? 'Human task' : selectedNode.kind === 'decision' ? 'Decision' : 'Manual trigger' }}</strong><ChevronDown :size="15" /></button></label>
        <label><span>Assigned to</span><button type="button" class="select-control"><component :is="ownerIcon(selectedNode.owner)" :size="16" /><strong>{{ selectedNode.owner }}</strong><ChevronDown :size="15" /></button><small>A person or team will handle this step.</small></label>
        <label><span>Instructions</span><textarea v-model="selectedNode.instructions" @change="commitVisualChange()" /><small>Clear guidance for the person working this step.</small></label>

        <section class="guidance-section" aria-labelledby="step-guidance-title">
          <header>
            <div><span class="guidance-heading-icon"><Lightbulb :size="14" aria-hidden="true" /></span><h3 id="step-guidance-title">Guidance</h3><small>{{ selectedNode.guidance.length }}</small></div>
            <button type="button" class="add-guidance-button" @click="setMode('guidance')">Manage</button>
          </header>
          <p>Approved team feedback applied whenever this step runs.</p>
          <div v-if="guidanceGroups.length" class="guidance-group-summary">
            <span v-for="group in guidanceGroups" :key="group.category">{{ group.category }} <b>{{ group.items.length }}</b></span>
          </div>
          <p v-else class="guidance-empty">No guidance has been approved for this step.</p>
          <button type="button" class="manage-guidance-button" @click="setMode('guidance')">Manage {{ selectedNode.guidance.length || '' }} guidance {{ selectedNode.guidance.length === 1 ? 'item' : 'items' }} <ChevronDown :size="14" aria-hidden="true" /></button>
        </section>

        <label><span>Required output</span><input v-model="selectedNode.output" @change="commitVisualChange()" /><small>The result this step must produce.</small></label>
        <label><span>Next step</span><button type="button" class="select-control"><GitBranch :size="16" /><strong>{{ selectedNode.id === 'review_request' ? 'Approved?' : 'Continue workflow' }}</strong><ChevronDown :size="15" /></button></label>
        <button type="button" class="advanced-button" @click="showNotice('Advanced step settings opened.')"><span>Advanced</span><ChevronDown :size="15" /></button>
      </aside>
    </div>

    <section v-else-if="mode === 'guidance'" class="guidance-workspace" role="tabpanel" aria-label="Workflow guidance manager">
      <header class="guidance-workspace-header">
        <div class="guidance-workspace-title">
          <span><Lightbulb :size="19" aria-hidden="true" /></span>
          <div><small>Workflow guidance</small><h2>{{ selectedNode.title }}</h2><p>{{ selectedNode.guidance.length }} active on this step · {{ workflowGuidanceCount }} across the workflow</p></div>
        </div>
        <button type="button" class="guidance-add-primary" @click="openGuidanceComposer"><Plus :size="15" aria-hidden="true" /> Add guidance</button>
      </header>

      <div class="guidance-manager">
        <nav class="guidance-step-nav" aria-label="Workflow steps with guidance">
          <header><h3>Steps</h3><span>{{ workflowGuidanceCount }} total</span></header>
          <button
            v-for="(node, index) in nodes.filter((item) => item.id !== 'manual_start')"
            :key="node.id"
            type="button"
            :class="{ 'is-active': selectedNode.id === node.id }"
            @click="selectGuidanceNode(node.id)"
          >
            <span>{{ index + 2 }}</span><div><strong>{{ node.title }}</strong><small>{{ node.guidance.length ? `${node.guidance.length} active guidance` : 'No guidance yet' }}</small></div><b>{{ node.guidance.length }}</b>
          </button>
        </nav>

        <section class="guidance-rule-panel" :aria-labelledby="`guidance-rules-${selectedNode.id}`">
          <header>
            <div><small>Step {{ nodes.findIndex((node) => node.id === selectedNode.id) + 1 }}</small><h3 :id="`guidance-rules-${selectedNode.id}`">{{ selectedNode.title }}</h3><p>Each approved item is applied independently and keeps the feedback that created it.</p></div>
            <button type="button" @click="setMode('visual')"><ArrowLeft :size="14" aria-hidden="true" /> Back to visual</button>
          </header>

          <form v-if="guidanceComposerOpen" class="guidance-composer guidance-composer--manager" @submit.prevent="addGuidance">
            <label for="new-guidance">Add guidance to {{ selectedNode.title }}</label>
            <textarea id="new-guidance" v-model="newGuidanceDraft" placeholder="What should every future owner of this step know?" />
            <fieldset>
              <legend>Category</legend>
              <button v-for="category in guidanceCategoryOptions" :key="category" type="button" :class="{ 'is-active': newGuidanceCategory === category }" @click="newGuidanceCategory = category">{{ category }}</button>
            </fieldset>
            <small>Manual additions are stored with their author and source in YAML.</small>
            <div><button type="button" @click="closeGuidanceComposer">Cancel</button><button type="submit" :disabled="!newGuidanceDraft.trim()">Add guidance</button></div>
          </form>

          <div v-if="guidanceGroups.length" class="guidance-groups">
            <section v-for="group in guidanceGroups" :key="group.category" class="guidance-group">
              <button type="button" class="guidance-group-toggle" :aria-expanded="expandedGuidanceCategories.includes(group.category)" @click="toggleGuidanceCategory(group.category)">
                <span><ChevronDown :size="15" :class="{ 'is-open': expandedGuidanceCategories.includes(group.category) }" aria-hidden="true" /><strong>{{ group.category }}</strong></span><small>{{ group.items.length }} {{ group.items.length === 1 ? 'item' : 'items' }}</small>
              </button>
              <ul v-if="expandedGuidanceCategories.includes(group.category)" class="guidance-list">
                <li v-for="item in group.items" :key="item.id" class="guidance-item">
                  <form v-if="guidanceEditorId === item.id" class="guidance-edit-form" @submit.prevent="saveGuidanceEdit(item)">
                    <textarea v-model="guidanceDraft" aria-label="Edit guidance" />
                    <div><button type="button" @click="cancelGuidanceEdit">Cancel</button><button type="submit" :disabled="!guidanceDraft.trim()">Save</button></div>
                  </form>
                  <template v-else>
                    <p>{{ item.text }}</p>
                    <div class="guidance-meta"><span>{{ item.source === 'team_feedback' ? 'Team feedback' : 'Manual guidance' }}</span><small>{{ item.sourceLabel }} · {{ item.author }} · {{ item.recordedAt }}</small></div>
                    <div class="guidance-actions">
                      <button type="button" :aria-label="`Edit guidance: ${item.text}`" title="Edit guidance" @click="startGuidanceEdit(item)"><PencilLine :size="14" aria-hidden="true" /></button>
                      <button type="button" :aria-label="`Remove guidance: ${item.text}`" title="Remove guidance" @click="removeGuidance(item.id)"><Trash2 :size="14" aria-hidden="true" /></button>
                    </div>
                  </template>
                </li>
              </ul>
            </section>
          </div>
          <div v-else class="guidance-manager-empty"><Lightbulb :size="22" aria-hidden="true" /><strong>No guidance for this step</strong><p>Add the first approved instruction for future owners of this work.</p><button type="button" @click="openGuidanceComposer">Add guidance</button></div>
        </section>
      </div>
    </section>

    <section v-else class="yaml-workspace" role="tabpanel" aria-label="YAML workflow builder">
      <header class="yaml-toolbar">
        <div><FileText :size="17" aria-hidden="true" /><span>{{ workflowPath }}</span></div>
        <p><span :class="{ 'is-invalid': !yamlValid }"><Check v-if="yamlValid" :size="14" />{{ yamlValid ? 'Valid YAML' : 'Validation error' }}</span><span>Schema v1</span><span>Comments preserved</span></p>
      </header>
      <div class="yaml-editor-shell">
        <pre ref="yamlLineNumbers" class="line-numbers" aria-hidden="true"><span v-for="line in yamlLines" :key="line">{{ line }}</span></pre>
        <textarea ref="yamlEditor" v-model="yamlSource" spellcheck="false" aria-label="Workflow YAML source" @input="workflowStatus = 'draft'" @scroll="syncYamlScroll" />
      </div>
      <footer class="yaml-statusbar">
        <span :class="{ 'is-invalid': !yamlValid }"><i /> {{ yamlValid ? 'Valid YAML' : 'YAML contains errors' }}</span>
        <span>Schema v1</span>
        <span>Comments preserved</span>
        <span class="yaml-statusbar-spacer" />
        <span>Ln 1, Col 1</span>
        <span>Spaces: 2</span>
        <span>YAML</span>
      </footer>
    </section>

    <Transition name="editor-notice">
      <p v-if="notice" class="editor-notice" role="status">{{ notice }}</p>
    </Transition>
  </section>
</template>

<style scoped>
.workflow-editor {
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

.editor-header {
  position: relative;
  z-index: 8;
  display: grid;
  grid-template-columns: minmax(280px, 1fr) auto minmax(300px, 1fr);
  min-height: 128px;
  flex: 0 0 auto;
  align-items: center;
  gap: 18px;
  padding: 18px 24px;
  background: #2f3642;
}

.editor-identity { align-self: stretch; display: flex; min-width: 0; flex-direction: column; justify-content: center; }
.back-button,
.workflow-file-path { display: inline-flex; width: fit-content; align-items: center; gap: 7px; padding: 0; background: transparent; color: #cda5c9; font-size: 11px; cursor: pointer; }
.back-button:hover,
.workflow-file-path:hover { color: #f0d2ed; }
.editor-title-row { display: flex; min-width: 0; align-items: center; gap: 9px; margin-top: 10px; }
.editor-title-row input { width: min(360px, 100%); padding: 0; border: 0; outline: 0; background: transparent; color: #f3f5f8; font: 730 22px/1.15 Inter, sans-serif; letter-spacing: -0.025em; }
.editor-title-row svg { flex: 0 0 auto; color: #9ca8b7; }
.draft-badge { padding: 6px 12px; border-radius: 6px; background: #57492f; color: #f0c67d; font-size: 10px; font-weight: 750; }
.draft-badge.is-published { background: #284a3a; color: #90d9b3; }
.workflow-file-path { margin-top: 7px; color: #9ea9b8; }
.save-state { display: flex; align-items: center; gap: 7px; margin-top: 8px; color: #9aa6b5; font-size: 10px; }
.save-state > i { width: 7px; height: 7px; border-radius: 50%; background: #79d5a2; }
.save-state > span { display: inline-flex; align-items: center; gap: 4px; color: #80d7aa; }

.authoring-tabs { display: grid; width: 258px; grid-template-columns: repeat(3, 1fr); padding: 4px; border-radius: 9px; background: #272d37; }
.authoring-tabs button { height: 36px; border-radius: 7px; background: transparent; color: #aab4c2; font-size: 11px; font-weight: 650; cursor: pointer; }
.authoring-tabs button.is-active { background: #c49ac0; color: #282e37; box-shadow: 0 4px 18px rgb(8 10 14 / 12%); }

.editor-actions { display: flex; min-width: 0; justify-content: flex-end; gap: 10px; }
.editor-actions button { display: inline-flex; height: 40px; align-items: center; justify-content: center; gap: 8px; border-radius: 8px; background: #29303a; color: #dde2e9; font-size: 11px; font-weight: 650; cursor: pointer; }
.editor-actions button:hover { background: #3b4451; }
.editor-actions .quiet-action { width: 40px; padding: 0; }
.editor-actions .test-action { padding: 0 17px; box-shadow: inset 0 0 0 1px #505968; }
.editor-actions .publish-action { padding: 0 18px; background: #c49ac0; color: #2b3039; }
.editor-actions .publish-action:hover { background: #d3aad0; }

.visual-workspace { display: grid; min-width: 0; min-height: 0; flex: 1; grid-template-columns: minmax(0, 1fr) 330px; }
.workflow-canvas { position: relative; isolation: isolate; min-width: 0; min-height: 0; overflow: hidden; background: #2b323d; }
.workflow-canvas-scroller { position: absolute; z-index: 1; inset: 0 0 30px; overflow: auto; outline: 0; background-color: #2b323d; background-image: radial-gradient(circle, rgb(179 192 210 / 16%) 1px, transparent 1px); background-size: 19px 19px; overscroll-behavior: contain; scrollbar-gutter: stable; }
.workflow-canvas-scroller:focus-visible { box-shadow: inset 0 0 0 2px rgb(197 154 193 / 58%); }
.workflow-canvas-world { position: relative; isolation: isolate; overflow: hidden; outline: 0; background: transparent; cursor: grab; touch-action: none; }
.workflow-canvas-world.is-panning { cursor: grabbing; }
.canvas-controls { position: absolute; z-index: 6; top: 18px; right: 18px; display: flex; gap: 10px; }
.canvas-controls > button,
.canvas-controls > span { display: flex; height: 36px; align-items: center; border-radius: 8px; background: #303844; box-shadow: 0 10px 24px rgb(8 10 14 / 14%); }
.canvas-controls > button { width: 38px; justify-content: center; padding: 0; color: #aab5c3; line-height: 0; cursor: pointer; }
.canvas-controls > span { overflow: hidden; }
.canvas-controls > span button { display: grid; width: 35px; height: 36px; flex: 0 0 35px; padding: 0; place-items: center; background: transparent; color: #9da9b7; line-height: 0; cursor: pointer; }
.canvas-controls svg { display: block; }
.canvas-controls b { display: flex; min-width: 52px; height: 36px; align-items: center; justify-content: center; color: #cdd4dd; font-size: 10px; line-height: 1; text-align: center; }

.flow-node { position: absolute; z-index: 4; display: flex; min-height: 78px; align-items: center; gap: 12px; padding: 12px; border: 1px solid #697383; border-radius: 10px; background: #343c48; color: #dfe4eb; box-shadow: 0 12px 26px rgb(8 10 14 / 12%); text-align: left; cursor: grab; touch-action: none; user-select: none; }
.flow-node:hover { background: #3a4350; }
.flow-node.is-selected { border-color: #d0a5ce; box-shadow: 0 0 0 2px rgb(197 154 193 / 55%), 0 14px 30px rgb(8 10 14 / 16%); }
.flow-node.is-dragging { z-index: 7; cursor: grabbing; box-shadow: 0 0 0 2px rgb(197 154 193 / 72%), 0 22px 44px rgb(8 10 14 / 28%); }
.flow-node > span:last-child { display: grid; min-width: 0; gap: 4px; }
.flow-node small { position: absolute; top: 8px; right: 10px; color: #8793a2; font-size: 9px; font-style: normal; }
.flow-node strong { overflow: hidden; color: #f0f3f7; font-size: 12px; text-overflow: ellipsis; white-space: nowrap; }
.flow-node em { overflow: hidden; color: #9ba7b5; font-size: 9px; font-style: normal; text-overflow: ellipsis; white-space: nowrap; }
.node-icon { display: grid; width: 42px; height: 42px; flex: 0 0 auto; place-items: center; border-radius: 10px; background: #8b648a; color: #f4eaf3; }
.node-icon--trigger,
.node-icon--complete { background: #5e9c77; color: #f0fff6; }
.node-icon--decision { background: #5a8570; }
.node-icon--changes { background: #8d6a9e; }
.flow-connections { position: absolute; z-index: 1; inset: 0; width: 100%; height: 100%; overflow: visible; pointer-events: none; }
.flow-connection { fill: none; stroke: #9da9b7; stroke-width: 2; stroke-linecap: round; }
.flow-connection--approved { stroke: #7fc394; }
.flow-connection--changes { stroke: #d27f84; opacity: .82; }
.connector-add { position: absolute; z-index: 5; display: grid; width: 27px; height: 27px; place-items: center; border: 2px solid #8b96a5; border-radius: 50%; background: #353d49; color: #e8edf3; cursor: pointer; }
.connector-add:hover { border-color: #d1a8ce; background: #4a3e4b; }
.branch-label { position: absolute; z-index: 3; padding: 4px 8px; border-radius: 5px; font-size: 9px; font-weight: 700; }
.branch-label--approved { background: #315a42; color: #94d8ae; }
.branch-label--changes { background: #643e43; color: #f3a1a8; }

.node-library { position: absolute; z-index: 10; display: grid; width: 260px; padding: 9px; border-radius: 10px; background: #353d49; box-shadow: 0 24px 58px rgb(8 10 14 / 34%); }
.node-library label { display: flex; height: 36px; align-items: center; gap: 8px; padding: 0 10px; border-radius: 7px; background: #282f38; color: #8f9baa; }
.node-library input { min-width: 0; flex: 1; border: 0; outline: 0; background: transparent; color: #dce2e9; font: 10px Inter, sans-serif; }
.node-library > button { display: flex; min-height: 46px; align-items: center; gap: 11px; padding: 7px 10px; border-radius: 7px; background: transparent; color: #cbd2dc; text-align: left; cursor: pointer; }
.node-library > button:hover,
.node-library > button:first-of-type { background: #493f4b; }
.node-library > button > svg { flex: 0 0 auto; color: #c79bc3; }
.node-library > button span { display: grid; gap: 2px; }
.node-library > button strong { font-size: 10px; }
.node-library > button small { color: #929eac; font-size: 9px; }
.canvas-toolbox { position: absolute; bottom: 42px; left: 20px; display: flex; gap: 4px; padding: 5px; border-radius: 8px; background: #343c48; box-shadow: 0 10px 24px rgb(8 10 14 / 16%); }
.canvas-toolbox button { display: grid; width: 34px; height: 32px; place-items: center; border-radius: 6px; background: transparent; color: #a9b4c1; cursor: pointer; }
.canvas-toolbox button:hover,
.canvas-toolbox button.is-active { background: #584759; color: #f0d7ed; }
.canvas-status { position: absolute; right: 0; bottom: 0; left: 0; display: flex; height: 30px; align-items: center; gap: 18px; padding: 0 18px; background: #303844; color: #929eac; font-size: 9px; }
.canvas-status span { display: inline-flex; align-items: center; gap: 7px; }
.canvas-status i { width: 7px; height: 7px; border-radius: 50%; background: #6dd398; }

.step-inspector { min-width: 0; min-height: 0; overflow-y: auto; padding: 22px 20px; background: #343c48; }
.step-inspector > header { display: grid; grid-template-columns: auto 1fr auto; align-items: center; gap: 11px; padding-bottom: 18px; }
.inspector-icon { display: grid; width: 35px; height: 35px; place-items: center; border-radius: 50%; background: #7e5d7c; color: #f5eaf4; }
.step-inspector > header div { display: grid; gap: 3px; }
.step-inspector > header small { color: #8f9baa; font-size: 9px; }
.step-inspector > header strong { color: #f0f3f7; font-size: 13px; }
.step-inspector > header button { display: grid; width: 30px; height: 30px; place-items: center; border-radius: 6px; background: transparent; color: #98a5b4; cursor: pointer; }
.step-inspector > header button:hover { background: #424b58; }
.step-inspector > label { display: grid; gap: 7px; padding: 15px 0; color: #b8c1cc; font-size: 10px; font-weight: 650; }
.step-inspector label > small { color: #8e9aa9; font-size: 9px; font-weight: 400; line-height: 1.45; }
.select-control,
.step-inspector textarea,
.step-inspector input { width: 100%; border: 0; border-radius: 7px; outline: 0; background: #2a313b; color: #dce2e9; font: 10px Inter, sans-serif; }
.select-control { display: grid; height: 40px; grid-template-columns: auto 1fr auto; align-items: center; gap: 9px; padding: 0 11px; text-align: left; cursor: pointer; }
.select-control strong { font-size: 10px; font-weight: 550; }
.step-inspector textarea { min-height: 105px; resize: vertical; padding: 11px; line-height: 1.55; }
.step-inspector input { height: 40px; padding: 0 11px; }
.guidance-section { display: grid; gap: 10px; padding: 16px 0 8px; }
.guidance-section > header { display: flex; align-items: center; justify-content: space-between; gap: 12px; }
.guidance-section > header > div { display: flex; align-items: center; gap: 7px; }
.guidance-section h3 { margin: 0; color: #e4e8ed; font-size: 11px; }
.guidance-section > header small { display: grid; min-width: 20px; height: 20px; place-items: center; padding: 0 6px; border-radius: 999px; background: #554655; color: #e4c5e1; font-size: 9px; }
.guidance-heading-icon { display: grid; width: 24px; height: 24px; place-items: center; border-radius: 7px; background: #514451; color: #d9b5d5; }
.guidance-section > p { margin: 0; color: #8e9aa9; font-size: 9px; line-height: 1.45; }
.add-guidance-button { display: inline-flex; height: 28px; align-items: center; gap: 5px; padding: 0 8px; border-radius: 6px; background: #45404a; color: #e3c6df; font-size: 9px; font-weight: 650; cursor: pointer; }
.add-guidance-button:hover { background: #554c58; }
.guidance-group-summary { display: flex; flex-wrap: wrap; gap: 6px; }
.guidance-group-summary > span { display: inline-flex; align-items: center; gap: 5px; padding: 5px 7px; border-radius: 6px; background: #2d343e; color: #aab4c1; font-size: 8px; }
.guidance-group-summary b { color: #d9b5d5; font-size: 8px; }
.manage-guidance-button { display: flex; width: 100%; height: 34px; align-items: center; justify-content: space-between; padding: 0 10px; border-radius: 7px; background: #45404a; color: #e1c4dd; font-size: 9px; font-weight: 650; cursor: pointer; }
.manage-guidance-button svg { transform: rotate(-90deg); }
.manage-guidance-button:hover { background: #554c58; }
.guidance-list { display: grid; gap: 8px; margin: 0; padding: 0; list-style: none; }
.guidance-item { position: relative; min-width: 0; padding: 11px 38px 11px 12px; border-radius: 8px; background: #2d343e; }
.guidance-item > p { margin: 0; color: #dce2e9; font-size: 10px; line-height: 1.5; }
.guidance-meta { display: grid; gap: 4px; margin-top: 9px; }
.guidance-meta > span { width: fit-content; padding: 3px 6px; border-radius: 5px; background: #3d4b45; color: #8ed4aa; font-size: 8px; font-weight: 700; }
.guidance-meta > small { overflow: hidden; color: #818d9c; font-size: 8px; line-height: 1.4; text-overflow: ellipsis; white-space: nowrap; }
.guidance-actions { position: absolute; top: 7px; right: 6px; display: grid; gap: 3px; }
.guidance-actions button { display: grid; width: 27px; height: 27px; place-items: center; border-radius: 6px; background: transparent; color: #8f9aa8; cursor: pointer; }
.guidance-actions button:hover { background: #414954; color: #e3c6df; }
.guidance-actions button:last-child:hover { color: #ef9da1; }
.guidance-edit-form,
.guidance-composer { display: grid; gap: 8px; }
.guidance-edit-form textarea,
.guidance-composer textarea { min-height: 76px; resize: vertical; padding: 9px; background: #252c35; font-size: 9px; line-height: 1.5; }
.guidance-edit-form > div,
.guidance-composer > div { display: flex; justify-content: flex-end; gap: 6px; }
.guidance-edit-form button,
.guidance-composer button { height: 28px; padding: 0 9px; border-radius: 6px; background: transparent; color: #a8b2bf; font-size: 9px; cursor: pointer; }
.guidance-edit-form button[type="submit"],
.guidance-composer button[type="submit"] { background: #c49ac0; color: #2b3039; font-weight: 700; }
.guidance-edit-form button:disabled,
.guidance-composer button:disabled { opacity: .45; cursor: not-allowed; }
.guidance-composer { padding: 11px; border-radius: 8px; background: #2d343e; }
.guidance-composer > label { color: #d6dce4; font-size: 10px; font-weight: 650; }
.guidance-composer > small { color: #84909f; font-size: 8px; line-height: 1.45; }
.guidance-empty { padding: 10px; border-radius: 7px; background: #2d343e; color: #8793a2 !important; }
.advanced-button { display: flex; width: 100%; align-items: center; justify-content: space-between; padding: 17px 0; background: transparent; color: #c4ccd6; font-size: 10px; cursor: pointer; }

.guidance-workspace { display: flex; min-width: 0; min-height: 0; flex: 1; flex-direction: column; background: #2a313b; }
.guidance-workspace-header { display: flex; min-height: 78px; flex: 0 0 auto; align-items: center; justify-content: space-between; gap: 20px; padding: 14px 22px; background: #333b47; }
.guidance-workspace-title { display: flex; min-width: 0; align-items: center; gap: 12px; }
.guidance-workspace-title > span { display: grid; width: 38px; height: 38px; flex: 0 0 auto; place-items: center; border-radius: 10px; background: #554655; color: #e2c4de; }
.guidance-workspace-title > div { display: grid; min-width: 0; gap: 3px; }
.guidance-workspace-title small { color: #a99aae; font-size: 8px; font-weight: 700; letter-spacing: .08em; text-transform: uppercase; }
.guidance-workspace-title h2 { overflow: hidden; margin: 0; color: #f0f3f7; font-size: 15px; text-overflow: ellipsis; white-space: nowrap; }
.guidance-workspace-title p { margin: 0; color: #919dab; font-size: 9px; }
.guidance-add-primary { display: inline-flex; height: 34px; flex: 0 0 auto; align-items: center; gap: 7px; padding: 0 12px; border-radius: 7px; background: #c49ac0; color: #2c313a; font-size: 9px; font-weight: 750; cursor: pointer; }
.guidance-add-primary:hover { background: #d2a8ce; }
.guidance-manager { display: grid; min-width: 0; min-height: 0; flex: 1; grid-template-columns: 235px minmax(0, 1fr); }
.guidance-step-nav { min-width: 0; min-height: 0; overflow-y: auto; padding: 17px 12px; background: #303742; }
.guidance-step-nav > header { display: flex; align-items: center; justify-content: space-between; padding: 0 8px 10px; }
.guidance-step-nav h3 { margin: 0; color: #dce2e9; font-size: 10px; }
.guidance-step-nav header span { color: #8793a2; font-size: 8px; }
.guidance-step-nav > button { display: grid; width: 100%; min-height: 56px; grid-template-columns: 26px minmax(0, 1fr) auto; align-items: center; gap: 8px; padding: 8px; border-radius: 8px; background: transparent; color: #aeb8c4; text-align: left; cursor: pointer; }
.guidance-step-nav > button:hover { background: #39414d; }
.guidance-step-nav > button.is-active { background: #493f4b; color: #eddbea; }
.guidance-step-nav > button > span { display: grid; width: 24px; height: 24px; place-items: center; border-radius: 7px; background: #3e4652; color: #9faab8; font-size: 8px; }
.guidance-step-nav > button.is-active > span { background: #745b73; color: #f5eaf3; }
.guidance-step-nav > button > div { display: grid; min-width: 0; gap: 3px; }
.guidance-step-nav strong { overflow: hidden; font-size: 9px; text-overflow: ellipsis; white-space: nowrap; }
.guidance-step-nav small { overflow: hidden; color: #818d9b; font-size: 8px; text-overflow: ellipsis; white-space: nowrap; }
.guidance-step-nav > button > b { display: grid; min-width: 22px; height: 22px; place-items: center; padding: 0 5px; border-radius: 999px; background: #3d4551; color: #bac3ce; font-size: 8px; }
.guidance-step-nav > button.is-active > b { background: #614f61; color: #eccfe8; }
.guidance-rule-panel { min-width: 0; min-height: 0; overflow-y: auto; padding: 20px 24px 56px; background: #2b323d; }
.guidance-rule-panel > header { display: flex; align-items: flex-start; justify-content: space-between; gap: 18px; padding-bottom: 16px; }
.guidance-rule-panel > header > div { display: grid; gap: 3px; }
.guidance-rule-panel > header small { color: #a1869f; font-size: 8px; font-weight: 700; }
.guidance-rule-panel > header h3 { margin: 0; color: #eef1f5; font-size: 16px; }
.guidance-rule-panel > header p { margin: 2px 0 0; color: #8f9baa; font-size: 9px; line-height: 1.45; }
.guidance-rule-panel > header > button { display: inline-flex; height: 31px; flex: 0 0 auto; align-items: center; gap: 6px; padding: 0 9px; border-radius: 6px; background: #353d48; color: #b8c1cc; font-size: 8px; cursor: pointer; }
.guidance-groups { display: grid; gap: 8px; }
.guidance-group { overflow: hidden; border-radius: 9px; background: #303844; }
.guidance-group-toggle { display: flex; width: 100%; min-height: 46px; align-items: center; justify-content: space-between; gap: 14px; padding: 0 13px; background: transparent; color: #d7dde5; cursor: pointer; }
.guidance-group-toggle > span { display: inline-flex; align-items: center; gap: 8px; }
.guidance-group-toggle svg { color: #8f9baa; transform: rotate(-90deg); transition: transform 150ms ease; }
.guidance-group-toggle svg.is-open { transform: rotate(0); }
.guidance-group-toggle strong { font-size: 10px; }
.guidance-group-toggle small { color: #8e9aa9; font-size: 8px; }
.guidance-group .guidance-list { gap: 1px; padding: 0 8px 8px; }
.guidance-group .guidance-item { border-radius: 7px; background: #2a313b; }
.guidance-composer--manager { margin-bottom: 14px; padding: 14px; background: #303844; }
.guidance-composer--manager textarea { width: 100%; min-height: 88px; border: 0; border-radius: 7px; outline: 0; color: #dce2e9; font: 9px/1.5 Inter, sans-serif; }
.guidance-composer fieldset { display: flex; flex-wrap: wrap; gap: 5px; padding: 0; border: 0; }
.guidance-composer legend { width: 100%; margin-bottom: 5px; color: #919dab; font-size: 8px; }
.guidance-composer fieldset button { height: 25px; padding: 0 7px; background: #3a424e; color: #aab4c1; }
.guidance-composer fieldset button.is-active { background: #695568; color: #f0d9ed; }
.guidance-manager-empty { display: grid; min-height: 220px; place-items: center; align-content: center; gap: 7px; border-radius: 10px; background: #303844; color: #8f9baa; text-align: center; }
.guidance-manager-empty strong { color: #dce2e9; font-size: 11px; }
.guidance-manager-empty p { margin: 0; font-size: 9px; }
.guidance-manager-empty button { height: 30px; margin-top: 4px; padding: 0 10px; border-radius: 6px; background: #c49ac0; color: #2b3039; font-size: 9px; font-weight: 700; cursor: pointer; }

.yaml-workspace { display: flex; min-width: 0; min-height: 0; flex: 1; flex-direction: column; background: #282f39; }
.yaml-toolbar { display: flex; min-height: 48px; flex: 0 0 auto; align-items: center; justify-content: space-between; gap: 20px; padding: 0 20px; background: #333b47; color: #aeb8c5; font-size: 10px; }
.yaml-toolbar div,
.yaml-toolbar p,
.yaml-toolbar span { display: inline-flex; align-items: center; gap: 8px; }
.yaml-toolbar p { gap: 16px; margin: 0; }
.yaml-toolbar p span:first-child { color: #7fd5a6; }
.yaml-toolbar .is-invalid { color: #ef9a9d !important; }
.yaml-editor-shell { display: grid; min-height: 0; flex: 1; grid-template-columns: 58px minmax(0, 1fr); overflow: hidden; }
.line-numbers { display: flex; min-height: 100%; flex-direction: column; gap: 0; margin: 0; padding: 19px 14px 80px 0; overflow: hidden; background: #252b34; color: #687484; font: 12px/1.62 "SFMono-Regular", Consolas, "Liberation Mono", monospace; text-align: right; user-select: none; }
.line-numbers span { display: block; height: 19.44px; }
.yaml-editor-shell textarea { width: 100%; height: 100%; min-height: 0; resize: none; padding: 18px 24px 80px; border: 0; outline: 0; overflow: auto; background: #29303a; color: #d7c9dc; caret-color: #e4b7df; font: 12px/1.62 "SFMono-Regular", Consolas, "Liberation Mono", monospace; tab-size: 2; white-space: pre; }
.yaml-editor-shell textarea:focus { box-shadow: inset 3px 0 0 #c59ac2; }
.yaml-statusbar { display: flex; height: 31px; flex: 0 0 auto; align-items: center; gap: 18px; padding: 0 16px; background: #303844; color: #929eac; font-size: 9px; }
.yaml-statusbar > span { display: inline-flex; align-items: center; gap: 6px; }
.yaml-statusbar i { width: 7px; height: 7px; border-radius: 50%; background: #6fd29a; }
.yaml-statusbar .is-invalid { color: #ef9a9d; }
.yaml-statusbar .is-invalid i { background: #e4767b; }
.yaml-statusbar-spacer { flex: 1; }

.editor-notice { position: absolute; z-index: 30; right: 22px; bottom: 18px; margin: 0; padding: 11px 14px; border-radius: 8px; background: #4b5565; color: #f0f3f7; box-shadow: 0 14px 34px rgb(8 10 14 / 28%); font-size: 10px; }
.editor-notice-enter-active,
.editor-notice-leave-active { transition: opacity 160ms ease, transform 160ms ease; }
.editor-notice-enter-from,
.editor-notice-leave-to { opacity: 0; transform: translateY(7px); }

@media (max-width: 1320px) {
  .editor-header { grid-template-columns: 1fr auto; min-height: 154px; }
  .authoring-tabs { grid-column: 1; grid-row: 2; justify-self: start; }
  .editor-actions { grid-column: 2; grid-row: 1 / span 2; }
  .visual-workspace { grid-template-columns: minmax(0, 1fr) 290px; overflow: hidden; }
}

@media (max-width: 760px) {
  .editor-header { display: flex; align-items: stretch; padding: 58px 16px 14px; flex-direction: column; gap: 12px; }
  .editor-actions { justify-content: flex-start; }
  .authoring-tabs { width: 100%; }
  .visual-workspace { display: flex; overflow-x: auto; }
  .workflow-canvas { min-width: 540px; }
  .step-inspector { min-width: 290px; }
  .yaml-toolbar { align-items: flex-start; padding-block: 10px; flex-direction: column; }
}
</style>
