export const meta = {
  name: 'ring-master-post-mortem',
  description: 'Analyse a finished Ring Master job and propose standards updates, routed to the package or the global standards by kind',
  whenToUse: 'Launched by /ring-master-post-mortem with args from `vendor/bin/ringside job <spec> --json` plus the PR and ticket comments',
  phases: [
    { title: 'Analyze', detail: 'what happened, root causes, what human review caught' },
    { title: 'Discover', detail: 'standards proposals with evidence' },
  ],
}

const { spec, job, index = {}, config, comments = {}, runs = [], rejected = [], existing = [] } = args
// The spec folder wherever it lives (its job worktree, or main once merged); an older job JSON names only spec.dir, on main.
const specDir = spec.path || `${job.root}/${spec.dir}`
const globalPackages = (config.standards.global || []).map(g => (typeof g === 'string' ? g : g.package))
const globalAccepts = globalPackages.length > 0 && config.discovery.global.mode !== 'off'
const opts = (role, extra = {}) => {
  const r = config.roles[role] || {}
  return { ...extra, ...(r.model ? { model: r.model } : {}), ...(r.effort ? { effort: r.effort } : {}) }
}

const ANALYSIS = {
  type: 'object',
  required: ['metrics', 'root_causes', 'human_review_delta'],
  properties: {
    metrics: { type: 'object', properties: { rounds: { type: 'number' }, findings_by_severity: { type: 'object' }, decisions: { type: 'number' }, deviations: { type: 'number' }, escalations: { type: 'number' } } },
    root_causes: { type: 'array', items: { type: 'object', required: ['kind', 'summary'], properties: { kind: { type: 'string', enum: ['spec_gap', 'standards_gap', 'implementer_error', 'reviewer_noise', 'tooling'] }, summary: { type: 'string' }, evidence: { type: 'array', items: { type: 'string' } } } } },
    human_review_delta: { type: 'array', items: { type: 'string' } },
  },
}
const PROPOSALS = {
  type: 'object',
  required: ['proposals'],
  properties: {
    proposals: {
      type: 'array',
      items: {
        type: 'object',
        required: ['key', 'kind', 'action', 'text', 'rationale', 'evidence'],
        properties: {
          key: { type: 'string' },
          kind: { type: 'string', enum: ['architectural', 'convention', 'tooling'] },
          action: { type: 'string', enum: ['add', 'amend', 'retire'] },
          text: { type: 'string' },
          rationale: { type: 'string' },
          evidence: { type: 'array', items: { type: 'string' } },
        },
      },
    },
  },
}

phase('Analyze')
const analysis = await agent(`Write the post-mortem for spec ${spec.id} to ${specDir}/post-mortem.md.
Read everything in ${specDir}: spec.md, spec-review.md, build.md, every round-N/ folder, decisions.md, decision.md.
Run history: ${JSON.stringify(runs)}
PR review comments from people: ${JSON.stringify(comments.pr || [])}
Ticket comments: ${JSON.stringify(comments.ticket || [])}
Cover: metrics, what went well, what cost rounds and why (root causes), and human_review_delta: what the human reviewers caught that the factory's reviewers and gate missed. Be specific and cite files.`, opts('analyst', { agentType: 'ring-master-analyst', label: 'analyze', schema: ANALYSIS }))
if (!analysis) return { status: 'failed', stage: 'analyze' }

phase('Discover')
const discovered = await agent(`Propose standards changes from this job. Inputs: the post-mortem analysis ${JSON.stringify(analysis)}, ${job.root}/${config.path}/standards-feedback.md, findings repeated across ${specDir}/round-*/, and ${specDir}/decisions.md.
Current standards (key -> path, kind): ${JSON.stringify(index)}.
Already proposed elsewhere (do not repeat): ${JSON.stringify(existing)}. Rejected before (do not propose again): ${JSON.stringify(rejected)}.
Each proposal: a key (category/name), its kind (architectural = layering, boundaries, contracts, naming, models, events, actions; convention = this package's style; tooling = config of tools), add | amend | retire, the full proposed markdown text in the standards' style, a rationale, and evidence (file paths, finding summaries). Only propose what at least ${config.discovery.global.min_evidence} piece(s) of evidence support.`, opts('discoverer', { agentType: 'ring-master-discoverer', label: 'discover', schema: PROPOSALS }))

const proposals = (discovered ? discovered.proposals : []).map(p => {
  const global = p.kind === 'architectural' && globalAccepts
  return { ...p, target: global ? 'global' : 'project', could_be_global: p.kind === 'architectural' && !globalAccepts }
})

return {
  status: 'done',
  analysis,
  proposals,
  global_packages: globalPackages,
}
