export const meta = {
  name: 'ring-master-spec-review',
  description: 'Critique a Ring Master spec from several angles, fix what is not architectural, and return the design questions for you',
  whenToUse: 'Launched by /ring-master-spec-review with args from `vendor/bin/ringside job <spec> --json`',
  phases: [
    { title: 'Critique', detail: 'configured critics in parallel' },
    { title: 'Revise', detail: 'fix non-architectural findings in spec.md' },
  ],
}

const { spec, job, decisions = [], config } = args
const SEVERITIES = ['architectural', 'blocker', 'major', 'minor', 'nit']
const settings = config.stages.spec_review
const specDir = `${job.root}/${spec.dir}`
const critics = settings.critics || []
const rounds = Math.max(1, settings.rounds || 1)
const threshold = config.review.rework_threshold

const FINDING = { type: 'object', required: ['severity', 'summary'], properties: { severity: { type: 'string', enum: SEVERITIES }, section: { type: 'string' }, summary: { type: 'string' }, fix: { type: 'string' } } }
const ASK = { type: 'object', required: ['question', 'options', 'recommendation'], properties: { question: { type: 'string' }, options: { type: 'array', items: { type: 'string' } }, recommendation: { type: 'string' }, evidence: { type: 'string' } } }
const CRITIQUE = { type: 'object', required: ['findings', 'decisions'], properties: { findings: { type: 'array', items: FINDING }, decisions: { type: 'array', items: ASK } } }

const rank = s => SEVERITIES.indexOf(s)
const atLeast = (s, t) => rank(s) !== -1 && rank(s) <= rank(t)
const decided = new Set(decisions.map(d => d.question.trim().toLowerCase()))
const opts = (role, extra = {}) => {
  const r = config.roles[role] || {}
  return { ...extra, ...(r.model ? { model: r.model } : {}), ...(r.effort ? { effort: r.effort } : {}) }
}

const LENSES = {
  scope: 'Is the goal clear, is scope bounded, is anything out of scope missing from "Out of scope", does every acceptance criterion serve the goal?',
  risk: 'What could break: data, security, upgrades for existing consumers, failure modes the design ignores?',
  testability: 'Is every acceptance criterion observable and checkable by a test? Which are vague or untestable?',
  standards: 'Does the design follow the standards listed in standards.md? Where would it have to deviate?',
  compat: 'Does it change any public API, config key, event, route or schema consumers rely on?',
}

let open = []
let all = []

for (let round = 1; round <= rounds; round++) {
  phase('Critique')
  const results = await parallel(critics.map(name => () => agent(`Critique the spec in ${specDir}/spec.md (read shape.md, standards.md and decisions.md too) through the "${name}" lens: ${LENSES[name] || name}.
Use the severity rubric in your instructions. Anything that picks or changes a public contract, schema, config shape, dependency or module boundary, or chooses between two viable designs the spec doesn't settle, goes in decisions with options and your recommendation, never in findings.
If the spec needs a new package, each of its name, Composer name, namespace, type (Laravel or plain PHP), skeleton features (config, routes, views, translations, migrations, assets, commands, facade) and the packages it depends on is a decision unless decisions.md or meta.yml new_packages already settles it.
Decisions already in decisions.md are final.`, opts('critic', { agentType: 'ring-master-critic', phase: 'Critique', label: `${name} r${round}`, schema: CRITIQUE }))))

  const findings = results.flatMap((r, i) => (r ? r.findings.map(f => ({ ...f, critic: critics[i] })) : []))
  const asks = results.filter(Boolean).flatMap(r => r.decisions).filter(a => !decided.has(a.question.trim().toLowerCase()))
  for (const f of findings.filter(f => f.severity === 'architectural')) asks.push({ question: f.summary, options: f.fix ? [f.fix] : [], recommendation: f.fix || '', evidence: f.section || '' })
  // Questions accumulate across rounds: a later clean round never drops an earlier one.
  for (const a of asks) if (!open.some(o => o.question.trim().toLowerCase() === a.question.trim().toLowerCase())) open.push(a)
  all = all.concat(findings)
  const fix = findings.filter(f => f.severity !== 'architectural' && atLeast(f.severity, threshold))

  if (fix.length === 0) {
    log(`Round ${round}: nothing at or above ${threshold}; spec review done.`)
    break
  }

  phase('Revise')
  await agent(`Revise ${specDir}/spec.md to fix these findings without changing anything the open questions depend on. Append what you changed, and why, to ${specDir}/spec-review.md under "## Round ${round}".
Findings: ${JSON.stringify(fix)}
Open questions (leave alone): ${JSON.stringify(open.map(a => a.question))}`, opts('critic', { phase: 'Revise', label: `revise r${round}` }))
}

return {
  status: open.length ? 'needs-decision' : 'reviewed',
  decisions: open,
  findings: all.filter(f => f.severity !== 'architectural'),
}
