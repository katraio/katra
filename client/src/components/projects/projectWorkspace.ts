export type ProjectKind = "server" | "client" | "leaf";

export type ProjectIssue = {
  id: string;
  title: string;
  status: string;
  createdAt: string;
  updatedAt: string;
  description: string[];
  labels: string[];
  milestone: string;
  assignee: string;
  assigneeAvatar: string;
  reviewId: string;
  reviewTitle: string;
  reviewer: string;
  reviewerAvatar: string;
  revision: string;
  pipelineNumber: string;
};

export type ProjectWorkspace = {
  id: string;
  organization: string;
  product: string;
  name: string;
  kind: ProjectKind;
  repository: string;
  branch: string;
  purpose: string;
  release: string;
  issue: ProjectIssue;
};

export const projectWorkspaces: ProjectWorkspace[] = [
  {
    id: "laravel-app",
    organization: "Northstar Goods",
    product: "ERP",
    name: "Laravel App",
    kind: "leaf",
    repository: "northstar-goods/erp",
    branch: "feature/ERP-62-inventory-idempotency",
    purpose: "Operate and extend Northstar Goods' ERP application and customer-specific workflows.",
    release: "v1.4.0",
    issue: {
      id: "ERP-62",
      title: "Prevent duplicate inventory imports",
      status: "In progress",
      createdAt: "Aug 6, 2026 9:14 AM",
      updatedAt: "Aug 7, 2026 9:28 AM",
      description: [
        "Users are seeing duplicate inventory items when the ERP pushes the same payload multiple times.",
        "Upsert by external_id and source, then add coverage for duplicate and partial payload scenarios.",
      ],
      labels: ["bug", "import", "inventory"],
      milestone: "v1.4.0",
      assignee: "morgan",
      assigneeAvatar: "/brand/icon.svg",
      reviewId: "CR-5931",
      reviewTitle: "Prevent duplicate inventory imports",
      reviewer: "Atlas",
      reviewerAvatar: "/avatars/atlas.png",
      revision: "a1b2c3d",
      pipelineNumber: "#1284",
    },
  },
  {
    id: "server",
    organization: "DevOption",
    product: "Katra",
    name: "Server",
    kind: "server",
    repository: "katra/server",
    branch: "feature/KAT-207-workflow-recovery",
    purpose: "Coordinate Katra's API, durable workflows, workspaces, and delivery state.",
    release: "v0.9.0",
    issue: {
      id: "KAT-207",
      title: "Restore durable workflow recovery",
      status: "In progress",
      createdAt: "Aug 6, 2026 8:41 AM",
      updatedAt: "Aug 7, 2026 10:12 AM",
      description: [
        "A workflow can remain stranded after a runner reconnects under load.",
        "Recover the active stage idempotently and retain the exact handoff evidence for replay.",
      ],
      labels: ["workflow", "recovery", "server"],
      milestone: "v0.9.0",
      assignee: "morgan",
      assigneeAvatar: "/brand/icon.svg",
      reviewId: "CR-5944",
      reviewTitle: "Recover workflow after runner reconnect",
      reviewer: "Sentinel",
      reviewerAvatar: "/avatars/sentinel.png",
      revision: "f82ac91",
      pipelineNumber: "#8752",
    },
  },
  {
    id: "client",
    organization: "DevOption",
    product: "Katra",
    name: "Client",
    kind: "client",
    repository: "katra/client",
    branch: "feature/KAT-184-project-workspace",
    purpose: "Deliver the shared Katra experience for browser, desktop, and future mobile clients.",
    release: "v0.8.2",
    issue: {
      id: "KAT-184",
      title: "Build the project delivery workspace",
      status: "Review requested",
      createdAt: "Aug 6, 2026 4:18 PM",
      updatedAt: "Aug 7, 2026 11:03 AM",
      description: [
        "Projects need a complete operating surface for code, reviews, pipelines, and releases.",
        "Keep project navigation dense, fast, and intentionally border-light.",
      ],
      labels: ["client", "projects", "ux"],
      milestone: "v0.8.2",
      assignee: "morgan",
      assigneeAvatar: "/brand/icon.svg",
      reviewId: "CR-5962",
      reviewTitle: "Add project workspace navigation",
      reviewer: "Atlas",
      reviewerAvatar: "/avatars/atlas.png",
      revision: "c34d90e",
      pipelineNumber: "#1291",
    },
  },
];
