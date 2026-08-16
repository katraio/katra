<script setup lang="ts">
import hljs from "highlight.js/lib/core";
import bash from "highlight.js/lib/languages/bash";
import css from "highlight.js/lib/languages/css";
import diff from "highlight.js/lib/languages/diff";
import dockerfile from "highlight.js/lib/languages/dockerfile";
import go from "highlight.js/lib/languages/go";
import java from "highlight.js/lib/languages/java";
import javascript from "highlight.js/lib/languages/javascript";
import json from "highlight.js/lib/languages/json";
import markdown from "highlight.js/lib/languages/markdown";
import php from "highlight.js/lib/languages/php";
import plaintext from "highlight.js/lib/languages/plaintext";
import python from "highlight.js/lib/languages/python";
import rust from "highlight.js/lib/languages/rust";
import sql from "highlight.js/lib/languages/sql";
import typescript from "highlight.js/lib/languages/typescript";
import xml from "highlight.js/lib/languages/xml";
import yaml from "highlight.js/lib/languages/yaml";
import MarkdownIt from "markdown-it";
import { computed } from "vue";

type MessageTarget = { name: string; first_name?: string };
type MarkdownEnvironment = { mentions: MessageTarget[]; attentionTargets: MessageTarget[] };
type MarkdownToken = { content: string; attrSet(name: string, value: string): void };
type MarkdownRenderer = { renderToken(tokens: MarkdownToken[], index: number, options: unknown): string };
type RenderRule = (tokens: MarkdownToken[], index: number, options: unknown, environment: unknown, renderer: MarkdownRenderer) => string;

const props = withDefaults(defineProps<{
  body: string;
  mentions?: MessageTarget[];
  attentionTargets?: MessageTarget[];
}>(), {
  mentions: () => [],
  attentionTargets: () => [],
});

const languages = {
  bash, shell: bash, css, diff, dockerfile, go, java, javascript, js: javascript, json,
  markdown, md: markdown, php, plaintext, text: plaintext, python, py: python, rust,
  sql, typescript, ts: typescript, html: xml, xml, yaml, yml: yaml,
};

Object.entries(languages).forEach(([name, definition]) => {
  if (!hljs.getLanguage(name)) hljs.registerLanguage(name, definition);
});

const copyIcon = '<svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect width="14" height="14" x="8" y="8" rx="2" ry="2"></rect><path d="M4 16c-1.1 0-2-.9-2-2V4c0-1.1.9-2 2-2h10c1.1 0 2 .9 2 2"></path></svg>';
const copiedIcon = '<svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m5 12 4 4L19 6"></path></svg>';

const parser: InstanceType<typeof MarkdownIt> = new MarkdownIt({
  html: false,
  breaks: true,
  linkify: true,
  typographer: false,
  highlight(source, requestedLanguage): string {
    const language = requestedLanguage.trim().toLowerCase().replace(/[^a-z0-9_+-]/g, "");
    const highlighted = language && hljs.getLanguage(language)
      ? hljs.highlight(source, { language, ignoreIllegals: true }).value
      : parser.utils.escapeHtml(source);
    const languageClass = language ? ` language-${language}` : "";
    const languageLabel: string = language ? ` data-language="${parser.utils.escapeHtml(language)}"` : "";
    return `<pre class="markdown-code-block"${languageLabel}><button class="markdown-code-copy" type="button" aria-label="Copy code block" title="Copy code">${copyIcon}</button><code class="hljs${languageClass}">${highlighted}</code></pre>`;
  },
});

parser.renderer.rules.image = ((tokens, index) => parser.utils.escapeHtml(tokens[index]?.content ?? "")) as RenderRule;

const existingLinkOpen = parser.renderer.rules.link_open as unknown as RenderRule | undefined;
const defaultLinkOpen: RenderRule = existingLinkOpen
  ?? ((tokens, index, options, _environment, renderer) => renderer.renderToken(tokens, index, options));
parser.renderer.rules.link_open = ((tokens, index, options, environment, self) => {
  tokens[index]?.attrSet("target", "_blank");
  tokens[index]?.attrSet("rel", "noopener noreferrer");
  return defaultLinkOpen(tokens, index, options, environment, self);
}) as RenderRule;

function patternsFor(target: MessageTarget, prefix: "@" | "!!"): string[] {
  return [`${prefix}${target.name}`, target.first_name ? `${prefix}${target.first_name}` : ""]
    .filter((pattern, index, values) => pattern.length > prefix.length && values.indexOf(pattern) === index)
    .sort((left, right) => right.length - left.length);
}

function renderTargetedText(text: string, environment: MarkdownEnvironment): string {
  const targets = [
    ...environment.mentions.flatMap((target) => patternsFor(target, "@").map((pattern) => ({ pattern, kind: "mention" }))),
    ...environment.attentionTargets.flatMap((target) => patternsFor(target, "!!").map((pattern) => ({ pattern, kind: "attention" }))),
  ];
  if (!targets.length) return parser.utils.escapeHtml(text);

  const lowerText = text.toLocaleLowerCase();
  let cursor = 0;
  let output = "";
  while (cursor < text.length) {
    let selected: { index: number; pattern: string; kind: string } | null = null;
    for (const target of targets) {
      const index = lowerText.indexOf(target.pattern.toLocaleLowerCase(), cursor);
      if (index >= 0 && (!selected || index < selected.index || (index === selected.index && target.pattern.length > selected.pattern.length))) {
        selected = { index, pattern: target.pattern, kind: target.kind };
      }
    }
    if (!selected) {
      output += parser.utils.escapeHtml(text.slice(cursor));
      break;
    }
    output += parser.utils.escapeHtml(text.slice(cursor, selected.index));
    const matched = text.slice(selected.index, selected.index + selected.pattern.length);
    output += `<span class="live-inline-${selected.kind}">${parser.utils.escapeHtml(matched)}</span>`;
    cursor = selected.index + selected.pattern.length;
  }
  return output;
}

parser.renderer.rules.text = ((tokens, index, _options, environment) => renderTargetedText(
  tokens[index]?.content ?? "",
  environment as MarkdownEnvironment,
)) as RenderRule;

const displayBody = computed(() => {
  const lowerBody = props.body.toLocaleLowerCase();
  const missingMentions = props.mentions.filter((target) => !patternsFor(target, "@").some((pattern) => lowerBody.includes(pattern.toLocaleLowerCase())));
  const missingAttention = props.attentionTargets.filter((target) => !patternsFor(target, "!!").some((pattern) => lowerBody.includes(pattern.toLocaleLowerCase())));
  const prefix = [
    ...missingMentions.map((target) => `@${target.name}`),
    ...missingAttention.map((target) => `!!${target.name}`),
  ].join(", ");
  return prefix ? `${prefix} ${props.body}` : props.body;
});

const rendered = computed(() => parser.render(displayBody.value, {
  mentions: props.mentions,
  attentionTargets: props.attentionTargets,
} satisfies MarkdownEnvironment));

async function copyCode(event: MouseEvent): Promise<void> {
  const button = (event.target as HTMLElement).closest<HTMLButtonElement>(".markdown-code-copy");
  if (!button) return;

  const code = button.parentElement?.querySelector("code")?.textContent ?? "";

  try {
    await navigator.clipboard.writeText(code);
    button.innerHTML = copiedIcon;
    button.setAttribute("aria-label", "Code copied");
    button.setAttribute("title", "Copied");
    button.dataset.state = "copied";
    window.setTimeout(() => {
      button.innerHTML = copyIcon;
      button.setAttribute("aria-label", "Copy code block");
      button.setAttribute("title", "Copy code");
      delete button.dataset.state;
    }, 1_500);
  } catch {
    button.setAttribute("aria-label", "Copy failed");
    button.setAttribute("title", "Copy failed");
    window.setTimeout(() => {
      button.innerHTML = copyIcon;
      button.setAttribute("aria-label", "Copy code block");
      button.setAttribute("title", "Copy code");
    }, 1_500);
  }
}
</script>

<template>
  <div class="markdown-message" @click="copyCode" v-html="rendered" />
</template>

<style scoped>
.markdown-message :deep(.markdown-code-block) {
  position: relative;
}

.markdown-message :deep(.markdown-code-copy) {
  position: absolute;
  top: 7px;
  right: 7px;
  z-index: 1;
  display: grid;
  width: 28px;
  height: 24px;
  place-items: center;
  border: 1px solid rgb(216 222 233 / 12%);
  border-radius: 6px;
  background: #3b4252;
  color: #d8dee9;
  font: inherit;
  font-size: 10px;
  font-weight: 700;
  cursor: pointer;
}

.markdown-message :deep(.markdown-code-copy:hover),
.markdown-message :deep(.markdown-code-copy:focus-visible) {
  outline: 0;
  border-color: rgb(180 142 173 / 58%);
  background: #4c566a;
  color: #eceff4;
}

.markdown-message :deep(.markdown-code-copy[data-state="copied"]) {
  border-color: rgb(163 190 140 / 38%);
  color: #a3be8c;
}

.markdown-message :deep(.markdown-code-block code) {
  padding-right: 76px;
}
</style>
