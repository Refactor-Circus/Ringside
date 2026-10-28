export const meta = {
  name: 'ring-master-factory',
  description: 'Build an approved Ring Master spec through gated review rounds; returns approved, needs-decision or escalated',
  whenToUse: 'Launched by /ring-master-factory with args from `vendor/bin/ringside job <spec> --json`',
  phases: [
    { title: 'Prepare', detail: 'job worktree and branch' },
    { title: 'Build', detail: 'one implementer per task group, wave by wave' },
    { title: 'Variants', detail: 'the whole job built several ways at once; a judge keeps the best' },
    { title: 'Integrate', detail: 'merge parallel group worktrees into the job branch' },
    { title: 'Gate', detail: 'verify command, ringside check, acceptance criteria' },
    { title: 'Review', detail: 'configured reviewers in parallel' },
    { title: 'Rework', detail: 'fix every finding at or above the threshold' },
    { title: 'Impact', detail: "each consuming package's tests against the job" },
    { title: 'Decide', detail: 'script rules; approver agent only when enabled' },
    { title: 'Brief', detail: "brief.md for the human reviewer: what changed, where to look, risk" },
  ],
}

// ---------------------------------------------------------------------------
// Input: the JSON from `ringside job <spec> --json` (see src/Specs/Job.php).
// The script never reads files; agents do, and each writes its own report.
// ---------------------------------------------------------------------------

const { spec, job, waves, decisions = [], index = {}, reviewers = [], config, workspace = null, impact = { enabled: false, consumers: [] }, replay = false, replay_from = null } = args
const SEVERITIES = ['architectural', 'blocker', 'major', 'minor', 'nit']
const review = config.review
const specDir = `${job.root}/${spec.dir}`
// A replay (an evaluation run) writes its reports under replay/ and never ticks tasks.md, so the real job's folder stays as it was.
const outDir = replay ? `${specDir}/replay` : specDir
const variantCount = Math.max(1, Math.min(4, Number(args.variants) || 1))
let redRounds = 0
let variantsWinner = null
// Every result carries what a replay's evaluation scores, whichever way the run ends.
const finish = result => ({ ...result, replay, gate_red_rounds: redRounds, variants_winner: variantsWinner })

const JUDGED = { type: 'object', required: ['winner', 'reasons'], properties: { winner: { type: 'number' }, reasons: { type: 'array', items: { type: 'string' } } } }
const BRIEFED = { type: 'object', required: ['risk'], properties: { risk: { type: 'string', enum: ['low', 'medium', 'high'] }, path: { type: 'string' } } }
const FINDING = {
  type: 'object',
  required: ['severity', 'summary'],
  properties: {
    severity: { type: 'string', enum: SEVERITIES },
    category: { type: 'string' },
    files: { type: 'array', items: { type: 'string' } },
    standard: { type: 'string' },
    summary: { type: 'string' },
    fix: { type: 'string' },
  },
}
const ASK = {
  type: 'object',
  required: ['question', 'options', 'recommendation'],
  properties: {
    question: { type: 'string' },
    options: { type: 'array', items: { type: 'string' } },
    recommendation: { type: 'string', description: 'The full text of one of options, copied exactly (not a letter or number)' },
    evidence: { type: 'string' },
  },
}
const PREPARED = { type: 'object', required: ['ready'], properties: { ready: { type: 'boolean' }, worktree: { type: 'string' }, detail: { type: 'string' } } }
const BUILT = {
  type: 'object',
  required: ['done', 'decisions'],
  properties: {
    done: { type: 'array', items: { type: 'string' } },
    files: { type: 'array', items: { type: 'string' } },
    tests: { type: 'string' },
    deviations: { type: 'array', items: { type: 'object', properties: { standard: { type: 'string' }, what: { type: 'string' }, why: { type: 'string' } } } },
    decisions: { type: 'array', items: ASK },
    blocked: { type: 'string' },
  },
}
const GATE = {
  type: 'object',
  required: ['passed', 'findings'],
  properties: {
    passed: { type: 'boolean' },
    findings: { type: 'array', items: FINDING },
    changed_files: { type: 'array', items: { type: 'string' } },
    criteria: { type: 'array', items: { type: 'object', properties: { n: { type: 'number' }, proven: { type: 'boolean' }, proof: { type: 'string' } } } },
  },
}
const INTEGRATED = {
  type: 'object',
  required: ['merged'],
  properties: { merged: { type: 'boolean' }, conflicts: { type: 'array', items: { type: 'string' } }, tests: { type: 'string' }, detail: { type: 'string' } },
}
const IMPACTED = {
  type: 'object',
  required: ['folder', 'passed'],
  properties: { folder: { type: 'string' }, passed: { type: 'boolean' }, summary: { type: 'string' }, output: { type: 'string' } },
}
const REVIEW = { type: 'object', required: ['findings', 'decisions'], properties: { findings: { type: 'array', items: FINDING }, decisions: { type: 'array', items: ASK } } }
const VERDICT = { type: 'object', required: ['decision', 'reasons'], properties: { decision: { type: 'string', enum: ['approve', 'escalate'] }, reasons: { type: 'array', items: { type: 'string' } } } }

// --- helpers ---------------------------------------------------------------

const rank = s => SEVERITIES.indexOf(s)
const atLeast = (severity, threshold) => rank(severity) !== -1 && rank(severity) <= rank(threshold)
// escalated: after a red gate or a repeated finding, a role with escalate_model moves up to it.
const opts = (role, extra = {}, escalated = false) => {
  const r = config.roles[role] || {}
  const o = { ...extra }
  if (r.model) o.model = r.model
  if (r.effort) o.effort = r.effort
  if (escalated && r.escalate_model && r.escalate_model !== r.model) {
    o.model = r.escalate_model
    log(`Escalating ${role} (${extra.label || role}) to ${r.escalate_model}.`)
  }
  return o
}
const globToRegex = glob => new RegExp('^' + glob
  .replace(/[.+^${}()|[\]\\]/g, '\\$&')
  .replace(/\*\*\//g, '\u0000')
  .replace(/\*\*/g, '\u0001')
  .replace(/\*/g, '[^/]*')
  .replace(/\u0000/g, '(?:.*/)?')
  .replace(/\u0001/g, '.*') + '$')
const architecturalPaths = (config.escalation.architectural_paths || []).map(globToRegex)
// In a workspace, files are reported as <Package>/<path>; the rules apply inside each package.
const withinPackage = f => (workspace && workspace.packages[f.split('/')[0]] ? f.slice(f.indexOf('/') + 1) : f)
const touchesArchitecture = files => (files || []).some(f => architecturalPaths.some(re => re.test(f) || re.test(withinPackage(f))))
const decidedQuestions = new Set(decisions.map(d => d.question.trim().toLowerCase()))
const norm = s => String(s || '').trim().toLowerCase().replace(/\s+/g, ' ')
const findingKey = f => [(f.files || []).slice().sort().join(','), f.standard || f.category || '', norm(f.summary)].join('|')

function classify(gate, reviews) {
  const findings = []
  const asks = []
  for (const f of gate ? gate.findings : []) findings.push({ ...f, source: 'gate' })
  for (const r of reviews) {
    if (!r || r.missing) continue
    for (const f of r.result.findings) findings.push({ ...f, source: r.name })
    for (const a of r.result.decisions) asks.push({ ...a, source: r.name })
  }
  for (const r of reviews.filter(r => r && r.missing)) {
    findings.push({ severity: 'blocker', category: 'review', summary: `${r.name} review unavailable after a retry`, source: r.name })
  }
  // Promote only, never demote.
  for (const f of findings) {
    const standard = f.standard && index[f.standard]
    const deviates = f.category === 'standard-deviation' && standard && standard.kind === 'architectural'
    if (f.severity !== 'architectural' && (touchesArchitecture(f.files) || deviates)) f.severity = 'architectural'
  }
  for (const f of findings.filter(f => f.severity === 'architectural')) {
    asks.push({ question: f.summary, options: f.fix ? [f.fix, 'Keep it as built'] : [], recommendation: f.fix || '', evidence: (f.files || []).join(', '), source: f.source })
  }
  const seen = new Set()
  const unique = findings.filter(f => f.severity !== 'architectural').filter(f => {
    const k = findingKey(f)
    if (seen.has(k)) return false
    seen.add(k)
    return true
  })
  const open = asks.filter(a => !decidedQuestions.has(a.question.trim().toLowerCase()))
  return { findings: unique, decisions: open }
}

const majorKeys = list => list.filter(f => atLeast(f.severity, 'major')).map(findingKey).sort().join('\n')

// The same major+ findings as the round before: one round short of noProgress.
const repeated = (history, current) => history.length > 0 && majorKeys(current) !== '' && majorKeys(history[history.length - 1]) === majorKeys(current)

function noProgress(history, current, rounds) {
  if (!rounds || history.length < rounds - 1) return false
  const keys = majorKeys
  const now = keys(current)
  if (now === '') return false
  return history.slice(-(rounds - 1)).every(h => keys(h) === now)
}

const common = `You are working on the Ring Master job for spec "${spec.title}" (${spec.id}).
Code changes happen in the job worktree; reports go in the spec folder of the main checkout: ${outDir}.${replay ? ` This is a replay, an evaluation run: never push, never open or update a pull request, never call RingMaster's MCP tools, and never change ${specDir}/meta.yml, tasks.md, questions.yml or decision.md.` : ''}
Read ${specDir}/spec.md and ${specDir}/decisions.md (if present) first. Decisions there are final: never re-raise them.
Standards that apply: ${spec.standards.length ? spec.standards.map(p => `${job.root}/${p}`).join(', ') : 'see ' + specDir + '/standards.md'}.
Shell: one command per Bash call, with no \`&&\`, \`;\` or pipes. A session started by RingMaster's runner only runs the commands it allows, and a compound command is refused as a whole. To work in another folder, run \`cd <folder>\` as its own call first, or use \`git -C <folder>\` and \`composer --working-dir=<folder>\`. Read files with the Read tool, not cat. If a command is refused, split it up and try again before you report a failure.`

// --- Recovery ----------------------------------------------------------------
// A build agent that ends without its result (out of turns, an API error, a timeout, a killed process) gets one salvage
// of its uncommitted work and one retry of what is left. A group result that leaves tasks undone gets the retry alone (D-003).

const TICKED = { type: 'object', required: ['done'], properties: { done: { type: 'array', items: { type: 'string' } } } }
const openAsks = r => ((r && r.decisions) || []).filter(a => !decidedQuestions.has(a.question.trim().toLowerCase()))
// The test command run in <dir> through composer, so it never depends on the cwd.
const testIn = (cmd, dir) => {
  const c = String(cmd || '').trim()
  const run = /^composer\s/.test(c) ? c.replace(/^composer\s+/, `composer --working-dir=${dir} `)
    : c.startsWith('vendor/bin/') ? `composer --working-dir=${dir} exec ${c.slice('vendor/bin/'.length)}` : null
  return run ? `Run the test command with \`${run}\`` : `Run the test command (\`cd ${dir}\` as its own call, then \`${c}\`)`
}
// A path single-quoted for a shell command line.
const quoted = p => `'${String(p).replace(/'/g, `'\\''`)}'`
const lockCheck = where => `Before touching anything, check for \`.git/index.lock\` (in a worktree \`git -C ${where} rev-parse --git-path index.lock\` gives its path) and for a merge or rebase in progress (\`git -C ${where} status\`). If you find one, stop and return blocked saying what you found: never force, never delete the lock, never abort the merge or rebase.`

// The tasks still to do: the ids minus the validated done ids. Where tasks.md is ticked, a read-only agent reads it too (D-008),
// and the larger unfinished set wins, so a bogus or empty list retries every task not provably done.
async function remaining(label, done, ids, ticked, stage) {
  const claimed = new Set((done || []).filter(id => ids.includes(id)))
  let left = ids.filter(id => !claimed.has(id))
  if (ticked && ids.length) {
    const read = await agent(`${common}
Read ${specDir}/tasks.md and return in done the ids among ${ids.join(', ')} whose task is ticked (\`- [x]\`). Change nothing.`, opts('prepare', { phase: stage, label: `tasks ${label}`, schema: TICKED }))
    const ticks = new Set(((read && read.done) || []).filter(id => ids.includes(id)))
    const unticked = ids.filter(id => !ticks.has(id))
    if (unticked.length > left.length) left = unticked
  }
  return left
}

// Returns the final result, or null when the salvage or the retry returned none. retryUndone opts a caller whose agent
// reports its finished task ids in done (a Build-loop group) into retrying a non-null result that leaves tasks undone;
// without it only a null result is recovered.
async function recover(label, result, salvagePrompt, retry, { ids = [], ticked = false, stage = 'Build', retryUndone = false } = {}) {
  let base = result
  if (result) {
    if (!retryUndone || result.blocked || openAsks(result).length) return result
    if (ids.every(id => (result.done || []).includes(id))) return result
  } else {
    log(`${label}: the build agent returned no result; salvaging its work.`)
    base = await agent(salvagePrompt, opts('reworker', { agentType: 'ring-master-implementer', phase: stage, label: `salvage ${label}`, schema: BUILT }))
    if (!base || base.blocked || openAsks(base).length) return base
  }
  const left = await remaining(label, base.done, ids, ticked, stage)
  if (!left.length) return base
  log(`${label}: retrying ${left.join(', ')}.`)
  const again = await retry(left)
  if (!again) return null
  const finished = (base.done || []).filter(id => ids.includes(id) && !left.includes(id))
  return { ...again, done: [...new Set([...finished, ...(again.done || [])])] }
}

// --- Prepare ---------------------------------------------------------------

phase('Prepare')
const worktreeMode = config.build.worktree.mode
const packages = workspace ? Object.keys(workspace.packages) : []
const ws = await agent(`${common}
Prepare the job workspace. ${workspace
    ? `This is a workspace job across ${packages.join(', ')}. From ${job.root}, run \`vendor/bin/ringside worktrees ${spec.id} --setup\` and return ready=true only if it exits 0 (put its output in detail otherwise).`
    : worktreeMode === 'per-job'
    ? `From ${job.root}, run \`vendor/bin/ringside worktrees ${spec.id} --setup${replay ? ` --replay${replay_from ? ` --from=${replay_from}` : ''}` : ''}\`: it creates or reuses the job worktree at ${job.worktree} on branch ${job.branch} and installs its dependencies. Return ready=true only if it exits 0, with worktree set to the path it prints. On a non-zero exit, return ready=false with the command output in detail: do not repair anything manually, and never create the worktree with git yourself or fall back to any other command.`
    : `Work in ${job.root} itself: create or check out branch ${job.branch} (based on ${config.host.base}).`}
Return ready=false with the error output in detail if anything fails.`, opts('prepare', { label: 'prepare', schema: PREPARED }))
if (!ws || !ws.ready) return finish({ status: 'escalated', stage: 'prepare', reasons: ['workspace setup failed'], detail: ws && ws.detail })
const tree = workspace ? `${job.root}/${workspace.dir}` : ws.worktree || (worktreeMode === 'per-job' ? job.worktree : job.root)
const treeFor = pkg => (workspace && pkg ? `${tree}/${pkg}` : tree)
const command = (pkg, name) => (workspace && pkg ? workspace.packages[pkg].commands[name] : config.commands[name])
const trees = workspace ? packages.map(p => `${p}: ${treeFor(p)}`).join('; ') : tree

// --- Build -----------------------------------------------------------------

const variants = variantCount > 1 && !workspace && worktreeMode === 'per-job' ? variantCount : 1
if (variantCount > 1 && variants === 1) log(`Variants: ${variantCount} asked, but only a single package with per-job worktrees builds variants; building once.`)
const todo = t => replay || !t.done

if (variants > 1) {
  // --- Variants: the whole job built N ways at once, each gated; a judge keeps the best ---
  phase('Variants')
  const HINTS = [
    'Make the simplest change that meets every acceptance criterion.',
    'Follow the patterns already in this codebase as closely as you can; reuse before you add.',
    'Aim for the most robust result: cover edge cases and failure paths with tests.',
    'Take a different approach from the obvious one where the spec allows it.',
  ]
  const models = (config.roles.variant && config.roles.variant.models) || []
  const vTree = n => `${tree}--v${n}`
  const vBranch = n => `${job.branch}--v${n}`
  const ns = Array.from({ length: variants }, (_, i) => i + 1)
  const made = await agent(`${common}
From ${job.root}, run \`vendor/bin/ringside worktrees ${spec.id} --variants=${variants} --setup${replay ? ' --replay' : ''}\`: it creates ${variants} worktrees beside ${tree}, each on its own branch from ${job.branch}, and installs them. Return ready=true only if it exits 0; otherwise ready=false with the output in detail. Never create the worktrees with git yourself.`,
    opts('prepare', { phase: 'Variants', label: `variant worktrees ${variants}`, schema: PREPARED }))
  if (!made || !made.ready) return finish({ status: 'escalated', stage: 'variants', reasons: ['variant worktree setup failed'], detail: made && made.detail })

  const pending = waves.flat().flatMap(g => g.tasks.filter(todo).map(t => ({ id: t.id, line: `${t.id} ${t.text} (group ${g.n}: ${g.name})` })))
  const tasks = pending.map(t => t.line)
  const criteria = [...new Set(waves.flat().flatMap(g => g.criteria))]
  // ids, when given (a retry), replace the todo filter: only those tasks are listed.
  const buildVariant = (n, ids) => {
    const o = opts('implementer', { agentType: 'ring-master-implementer', phase: 'Variants', label: ids ? `variant ${n} retry` : `variant ${n}`, schema: BUILT })
    if (models[n - 1]) o.model = models[n - 1]
    return agent(`${common}
Build variant ${n} of ${variants} of this job, on your own, in its worktree ${vTree(n)} (branch ${vBranch(n)}, made from ${job.branch}, which may already hold an earlier attempt you are free to rewrite). The other variants are built at the same time by other agents; a judge keeps one.${ids ? ` This is a retry: earlier work on this variant is already committed on ${vBranch(n)}; keep it and finish only the tasks below.` : ''}
Approach for this variant: ${HINTS[n - 1]}
Tasks, in this order: ${(ids ? pending.filter(t => ids.includes(t.id)) : pending).map(t => t.line).join('; ')}.
Acceptance criteria: ${criteria.join(', ') || 'see spec.md'}.
Write the tests first, then the code, and run \`${config.commands.test}\` in ${vTree(n)} until it passes. Commit on ${vBranch(n)} with the message "${spec.id}: variant ${n}". Do not tick tasks.md; write ${outDir}/variants/v${n}.md (approach, files, tests, deviations) instead.
If a task needs a choice the spec and decisions don't settle, stop at a safe point and return it in decisions with options and your recommendation instead of choosing.`, o)
  }
  const gateVariant = n => agent(`${common}
Gate variant ${n} in ${vTree(n)}: run \`${config.commands.verify}\`${config.commands.format_check ? ` and \`${config.commands.format_check}\`` : ''}, then \`composer --working-dir=${vTree(n)} exec ringside check\`. Map every numbered acceptance criterion in spec.md to the test that proves it. Every failure is a blocker finding. List changed_files (git -C ${vTree(n)} diff --name-only ${config.host.base}...${vBranch(n)}).
Write ${outDir}/variants/v${n}-gate.md starting with "VERDICT: PASS" or "VERDICT: CHANGES".`, opts('gate', { phase: 'Variants', label: `gate v${n}`, schema: GATE }))
  const salvageVariant = n => `${common}
Salvage variant ${n} of this job in its worktree ${vTree(n)}. Its build agent ended without returning a result (out of turns, an API error, a timeout or a killed process), so its work may be half done and uncommitted.
${lockCheck(vTree(n))}
Then run \`git -C ${vTree(n)} status --porcelain\` and \`git -C ${vTree(n)} diff\` and compare them with the variant's tasks: ${tasks.join('; ')}.
${testIn(config.commands.test, vTree(n))} and keep only the work that passes.
Commit what passes on ${vBranch(n)} with the message "${spec.id}: variant ${n} (salvaged)". Do not tick tasks.md; add a "## Variant ${n} (salvaged)" note to ${outDir}/variants/v${n}.md (what you found, what you committed, what is left).
Return done with the ids of the finished tasks only. Do not use blocked for unfinished tasks: the workflow retries them.`
  const results = await pipeline(ns, async n => {
    // Variants never tick tasks.md, so the salvage's own done ids are the only source. A variant's own result does not
    // list its finished ids, so only a null one is recovered (no retryUndone).
    const b = await recover(`v${n}`, await buildVariant(n), salvageVariant(n), ids => buildVariant(n, ids), { ids: pending.map(t => t.id), stage: 'Variants' })
    if (!b || b.blocked) return { n, built: b, gate: null }
    if (b.decisions && b.decisions.length) return { n, built: b, gate: null }
    return { n, built: b, gate: await gateVariant(n) }
  })
  const done = results.filter(r => r && r.built && !r.built.blocked)
  const asks = done.flatMap(r => r.built.decisions || []).filter(a => !decidedQuestions.has(a.question.trim().toLowerCase()))
  const gated = done.filter(r => r.gate)
  if (!gated.length) {
    if (asks.length) return finish({ status: 'needs-decision', stage: 'build', decisions: asks })
    return finish({ status: 'escalated', stage: 'variants', reasons: ['no variant built and gated'], variants: results.map(r => r && { n: r.n, blocked: r.built && r.built.blocked }) })
  }
  const proven = r => (r.gate.criteria || []).filter(c => c.proven).length
  const fallback = gated.slice().sort((a, b) => (b.gate.passed - a.gate.passed) || (proven(b) - proven(a)) || (a.gate.findings.length - b.gate.findings.length) || (a.n - b.n))[0].n
  const summary = gated.map(r => ({ variant: r.n, passed: r.gate.passed, findings: r.gate.findings.length, criteria_proven: proven(r), files: (r.gate.changed_files || []).length }))
  const judged = gated.length === 1 ? null : await agent(`${common}
Judge the ${gated.length} variants of this job and pick the one to keep. Their gate results: ${JSON.stringify(summary)}. Read each one's ${outDir}/variants/v<n>.md and gate report, and its diff (\`git -C ${tree}--v<n> diff --stat ${config.host.base}...${job.branch}--v<n>\`, then the files that matter). Prefer, in order: a green gate, every acceptance criterion proven, the least risk to the rest of the code, then the smaller and clearer diff. Return the winner's number and your reasons, and write them to ${outDir}/variants.md.`,
    opts('judge', { phase: 'Variants', label: 'judge', schema: JUDGED }))
  const winner = judged && gated.some(r => r.n === judged.winner) ? judged.winner : fallback
  if (!judged && gated.length > 1) log(`Judge unavailable: keeping variant ${winner} by gate result.`)
  log(`Variants: ${winner} of ${variants} kept${judged ? ` (${judged.reasons.join('; ')})` : ''}.`)
  variantsWinner = winner
  const adopted = await agent(`${common}
Variant ${winner} won. In ${tree}, fast-forward ${job.branch} to it: \`git -C ${tree} merge --ff-only ${vBranch(winner)}\`. ${replay ? '' : `Tick the tasks it finished in ${specDir}/tasks.md (${tasks.length} in all) and append a "## Variant ${winner}" section to ${specDir}/build.md from ${outDir}/variants/v${winner}.md. `}Then from ${job.root} run \`vendor/bin/ringside worktrees ${spec.id} --remove-variants${replay ? ' --replay' : ''}\`. Return merged=false with the details if the fast-forward fails.`,
    opts('integrator', { phase: 'Variants', label: `adopt v${winner}`, schema: INTEGRATED }))
  if (!adopted || !adopted.merged) return finish({ status: 'escalated', stage: 'variants', reasons: [adopted ? adopted.detail || 'the winning variant did not merge' : 'adopting the winning variant failed'], variants_winner: winner })
  const won = results.find(r => r && r.n === winner)
  const wonAsks = ((won && won.built.decisions) || []).filter(a => !decidedQuestions.has(a.question.trim().toLowerCase()))
  if (wonAsks.length) return finish({ status: 'needs-decision', stage: 'build', decisions: wonAsks, variants_winner: winner })
}

phase('Build')
const testsInGroup = !config.build.parallel
// Parallel groups of a single per-job package each get their own worktree, then an integrator merges them.
const groupTrees = config.build.parallel && worktreeMode === 'per-job' && !workspace
const groupTree = n => `${tree}--g${n}`
const groupBranch = n => `${job.branch}--g${n}`
for (const wave of variants > 1 ? [] : waves) {
  const own = groupTrees && wave.length > 1
  const whereOf = group => (own ? groupTree(group.n) : treeFor(group.package))
  const branchOf = group => (own ? groupBranch(group.n) : job.branch)
  const listed = (group, ids) => (ids ? group.tasks.filter(t => ids.includes(t.id)) : group.tasks.filter(todo)).map(t => `${t.id} ${t.text}`).join('; ')
  // ids, when given (a retry), replace the todo filter: only those tasks are listed.
  const build = (group, ids) => agent(`${common}
Implement task group ${group.n}: "${group.name}" in ${own ? `its own worktree ${groupTree(group.n)} (branch ${groupBranch(group.n)}, made from ${job.branch}); other groups of this wave build beside it at the same time` : treeFor(group.package)}${workspace ? ` (package ${group.package}; the other packages of this job are beside it in ${tree})` : ''}.${ids ? ` This is a retry: earlier work on this group is already committed on ${branchOf(group)}; keep it and finish only the tasks below.` : ''}
Tasks: ${listed(group, ids)}.
Files it owns: ${group.files.join(', ') || 'not listed'}.${workspace && workspace.packages[group.package].standards.length ? ` ${group.package}'s own standards, which override a global one with the same key: ${workspace.packages[group.package].standards.map(p => `${job.root}/${p}`).join(', ')}.` : ''} Acceptance criteria it proves: ${group.criteria.join(', ') || 'see tasks'}.
Write the tests first, then the code. ${testsInGroup ? `Run \`${command(group.package, 'test')}\` in ${treeFor(group.package)} until it passes.` : 'Do not run the test suite; the gate runs it after the build.'}
Commit your changes on ${own ? groupBranch(group.n) : job.branch} with the message "${spec.id}: group ${group.n} ${group.name}", ${replay ? 'do not tick tasks.md,' : `tick the finished tasks in ${specDir}/tasks.md,`} and append a "## Group ${group.n}" section to ${outDir}/build.md (files, tests, deviations).
If a task needs a choice the spec and decisions don't settle (a public contract, schema, config shape, dependency, module boundary, or two viable designs), stop at a safe point and return it in decisions with options and your recommendation instead of choosing.`,
    opts('implementer', { agentType: 'ring-master-implementer', phase: 'Build', label: ids ? `group ${group.n} retry` : `group ${group.n}`, schema: BUILT }))

  // Scoped to the group's files (D-004): other groups of the wave may have uncommitted work in the same tree.
  const salvageGroup = group => {
    const where = whereOf(group)
    const scope = group.files.length ? ` -- ${group.files.map(quoted).join(' ')}` : ''
    return `${common}
Salvage task group ${group.n}: "${group.name}" in ${where}. Its build agent ended without returning a result (out of turns, an API error, a timeout or a killed process), so its work may be half done and uncommitted.
${lockCheck(where)}
Then run \`git -C ${where} status --porcelain${scope}\` and \`git -C ${where} diff${scope}\` and compare them with the group's tasks: ${listed(group)}.${scope ? ` Touch only these files (${group.files.join(', ')}); leave every other path alone, since other groups may have uncommitted work in the same tree.` : ''}
${testsInGroup ? `${testIn(command(group.package, 'test'), where)} and keep only the work that passes.` : 'Do not run the test suite; the gate runs it after the build.'}
Commit ${testsInGroup ? 'what passes' : 'the finished work'}${scope ? ', only those paths,' : ''} on ${branchOf(group)} with the message "${spec.id}: group ${group.n} ${group.name} (salvaged)", ${replay ? 'do not tick tasks.md,' : `tick only the finished tasks in ${specDir}/tasks.md,`} and add a "## Group ${group.n} (salvaged)" note to ${outDir}/build.md (what you found, what you committed, what is left).
Return done with the ids of the finished tasks only. Do not use blocked for unfinished tasks: the workflow retries them.`
  }
  const recoverGroup = (group, result) => recover(`g${group.n}`, result, salvageGroup(group), ids => build(group, ids), { ids: group.tasks.filter(todo).map(t => t.id), ticked: !replay, retryUndone: true })

  if (own) {
    const made = await agent(`${common}
From ${job.root}, run \`vendor/bin/ringside worktrees ${spec.id} --groups=${wave.map(g => g.n).join(',')} --setup${replay ? ' --replay' : ''}\`: it creates a worktree per task group beside ${tree}, each on its own branch from ${job.branch}, and installs it. Return ready=true only if it exits 0; otherwise ready=false with the output in detail. Never create the worktrees with git yourself.`,
      opts('prepare', { phase: 'Build', label: `group worktrees ${wave.map(g => g.n).join(',')}`, schema: PREPARED }))
    if (!made || !made.ready) return finish({ status: 'escalated', stage: 'build', reasons: ['group worktree setup failed'], detail: made && made.detail })
  }

  // Parallel only when enabled and, in a shared tree, no two groups in the wave share a file; at most max_concurrency at once.
  const files = wave.flatMap(g => g.files)
  const parallelOk = config.build.parallel && (own || new Set(files).size === files.length)
  const size = parallelOk ? Math.max(1, config.build.max_concurrency) : 1
  const first = []
  // pipeline passes (value, item, index): call build with the group alone, so no index is read as task ids.
  for (let i = 0; i < wave.length; i += size) first.push(...await pipeline(wave.slice(i, i + size), g => build(g)))
  // Every group of a wave without a result points at an outage or a rate limit, not a group out of turns: no salvage.
  if (wave.length > 1 && first.every(b => !b)) {
    log(`Every group of wave ${waves.indexOf(wave) + 1} returned no result (probable outage or rate limit); escalating without a salvage.`)
    return finish({ status: 'escalated', stage: 'build', reasons: ['a build agent failed'], built: first })
  }
  const built = []
  for (let i = 0; i < wave.length; i += size) built.push(...await pipeline(wave.slice(i, i + size), g => recoverGroup(g, first[wave.indexOf(g)])))

  const asks = built.flatMap(b => (b && b.decisions) || []).filter(a => !decidedQuestions.has(a.question.trim().toLowerCase()))
  if (asks.length) return finish({ status: 'needs-decision', stage: 'build', decisions: asks, built })
  if (built.some(b => !b)) return finish({ status: 'escalated', stage: 'build', reasons: ['a build agent failed'], built })
  if (built.some(b => b.blocked)) return finish({ status: 'escalated', stage: 'build', reasons: built.filter(b => b.blocked).map(b => b.blocked), built })

  if (own) {
    phase('Integrate')
    const merged = await agent(`${common}
Integrate wave ${waves.indexOf(wave) + 1} into ${job.branch} in ${tree}: merge ${wave.map(g => groupBranch(g.n)).join(', ')} one at a time (\`git -C ${tree} merge --no-ff <branch>\`). Resolve every conflict so each group's tests and intent survive, and commit the merge. Then run \`${config.commands.test}\` in ${tree} until it passes, committing any fix on ${job.branch} with "${spec.id}: integrate wave ${waves.indexOf(wave) + 1}".
When it is green, from ${job.root} run \`vendor/bin/ringside worktrees ${spec.id} --remove-groups${replay ? ' --replay' : ''}\`. Return merged=false with the details if a conflict needs a choice the spec and decisions don't settle, or the tests stay red.`,
      opts('integrator', { agentType: 'ring-master-implementer', phase: 'Integrate', label: `integrate w${waves.indexOf(wave) + 1}`, schema: INTEGRATED }))
    if (!merged || !merged.merged) return finish({ status: 'escalated', stage: 'integrate', reasons: [merged ? merged.detail || 'the group branches did not integrate' : 'the integrator failed'], conflicts: merged && merged.conflicts })
  }
}

// --- Review rounds -----------------------------------------------------------

const history = []
let red = 0
let last = { findings: [], decisions: [] }

for (let round = 1; round <= review.rounds.max; round++) {
  const roundDir = `${outDir}/round-${round}`
  const scope = workspace ? ` in each package of the job (${trees})` : ''
  const sinceNote = round === 1 ? `the whole diff of ${job.branch} against ${config.host.base}${scope}` : `the changes since round ${round - 1}${scope} (open findings were: ${history[history.length - 1].map(f => f.summary).join('; ') || 'none'})`

  phase('Gate')
  const runGate = (pkg, file) => agent(`${common}
Run the gate in ${treeFor(pkg)}: \`${command(pkg, 'verify')}\`${command(pkg, 'format_check') ? ` and \`${command(pkg, 'format_check')}\`` : ''}${workspace ? '' : `, then \`composer --working-dir=${treeFor(pkg)} exec ringside check\` (not vendor/bin/ringside by path, which the runner refuses)`}.
${review.gate.criteria_mapping ? `Map every numbered acceptance criterion in spec.md${workspace ? ` that ${pkg} proves` : ''} to the test (file and name) or code that proves it.` : ''}
Every failure is a blocker finding with the failing output summarised${workspace ? `; report files as ${pkg}/<path>` : ''}. List changed_files (git diff --name-only ${config.host.base}...${job.branch}${workspace ? `, prefixed with ${pkg}/` : ''}).
Write ${roundDir}/${file} starting with "VERDICT: PASS" or "VERDICT: CHANGES".`, opts('gate', { phase: 'Gate', label: workspace ? `gate ${pkg} r${round}` : `gate r${round}`, schema: GATE }, red > 0))
  const gate = workspace
    ? await parallel(packages.map(p => () => runGate(p, `gate-${p}.md`))).then(gates => gates.some(g => !g) ? null : {
        passed: gates.every(g => g.passed),
        findings: gates.flatMap(g => g.findings),
        changed_files: gates.flatMap(g => g.changed_files || []),
        criteria: gates.flatMap(g => g.criteria || []),
      })
    : await runGate(null, 'gate.md')

  let reviews = []
  if (gate && (gate.passed || !review.gate.run_first)) {
    red = 0
    phase('Review')
    const run = r => agent(`${common}
Review ${sinceNote}${workspace ? '' : ` in ${tree}`} as the ${r.name} reviewer.${workspace ? ` Report files as <Package>/<path>. Each package's own standards override a global one with the same key: ${packages.map(p => `${p}: ${workspace.packages[p].standards.length ? workspace.packages[p].standards.map(s => `${job.root}/${s}`).join(', ') : 'none'}`).join('; ')}.` : ''} Use the severity rubric in your instructions exactly.
Return findings with severity, files, the standard key if one applies, and the fix. A clear break of a standard is a major finding fixed by following it; use category "standard-deviation" only when following the standard is impossible or wrong here.
Put any choice the spec and decisions don't settle in decisions, never in findings.
Write ${roundDir}/review-${r.name}.md starting with "VERDICT: PASS" or "VERDICT: CHANGES".`,
      { ...(r.model ? { model: r.model } : {}), ...(r.effort ? { effort: r.effort } : {}), agentType: `ring-master-reviewer-${r.name}`, phase: 'Review', label: `${r.name} r${round}`, schema: REVIEW })
    const first = await parallel(reviewers.map(r => () => run(r)))
    reviews = await parallel(reviewers.map((r, i) => async () => {
      const result = first[i] || await run(r)
      return result ? { name: r.name, result } : { name: r.name, missing: true }
    }))
  } else {
    red++
    redRounds++
  }

  let current = classify(gate || { findings: [{ severity: 'blocker', category: 'gate', summary: 'gate agent failed' }] }, reviews)

  if (config.roles.triage && config.roles.triage.enabled && current.findings.length > 1) {
    const triaged = await agent(`Deduplicate these review findings for spec ${spec.id}; you may move a finding one step between blocker, major, minor and nit, never to or from architectural:\n${JSON.stringify(current.findings)}`,
      opts('triage', { phase: 'Review', label: `triage r${round}`, schema: { type: 'object', required: ['findings'], properties: { findings: { type: 'array', items: FINDING } } } }))
    if (triaged) current = { ...current, findings: triaged.findings.filter(f => f.severity !== 'architectural') }
  }

  last = current
  const fix = current.findings.filter(f => atLeast(f.severity, review.rework_threshold))
  const nits = current.findings.filter(f => !atLeast(f.severity, review.rework_threshold))
  const escalate = red > 0 || repeated(history, fix)

  if (current.decisions.length) {
    if (config.escalation.fix_others_first && fix.length) {
      phase('Rework')
      await agent(`${common}\nFix these findings in ${workspace ? `the job's package worktrees (${trees}); each finding's file starts with its package` : tree} (round ${round}); do not touch the open questions listed after them.\nFindings: ${JSON.stringify(fix)}\nOpen questions (leave alone): ${JSON.stringify(current.decisions.map(d => d.question))}\nRun ${workspace ? "each changed package's test command" : `\`${config.commands.test}\``}, commit on ${job.branch}${workspace ? ' in every package you changed' : ''}, and note the fixes in ${outDir}/build.md.`,
        opts('reworker', { agentType: 'ring-master-implementer', phase: 'Rework', label: `rework r${round}`, schema: BUILT }, escalate))
    }
    return finish({ status: 'needs-decision', stage: 'review', round, decisions: current.decisions, findings: fix, nits })
  }

  if (fix.length === 0 && round >= review.rounds.min) {
    log(`Round ${round}: only nits left (${nits.length}); stopping early.`)
    break
  }
  if (red >= review.gate.max_consecutive_red) return finish({ status: 'escalated', stage: 'gate', round, reasons: [`gate red ${red} rounds in a row`], findings: fix })
  if (noProgress(history, fix, review.stop_when_no_progress)) return finish({ status: 'escalated', stage: 'review', round, reasons: ['no progress between rounds'], findings: fix })
  if (round === review.rounds.max) return finish({ status: 'escalated', stage: 'review', round, reasons: ['review rounds exhausted'], findings: fix, nits })

  history.push(fix)
  phase('Rework')
  await agent(`${common}\nFix these findings in ${workspace ? `the job's package worktrees (${trees}); each finding's file starts with its package` : tree} (round ${round}). Fix the nits too where it is cheap.\nFindings: ${JSON.stringify(fix)}\nNits: ${JSON.stringify(nits)}\nRun ${workspace ? "each changed package's test command" : `\`${config.commands.test}\``}, commit on ${job.branch}${workspace ? ' in every package you changed' : ''} with "${spec.id}: rework round ${round}", and note the fixes in ${outDir}/build.md.\nIf a fix needs a choice the spec doesn't settle, return it in decisions instead.`,
    opts('reworker', { agentType: 'ring-master-implementer', phase: 'Rework', label: `rework r${round}`, schema: BUILT }, escalate)).then(r => {
    if (r && r.decisions && r.decisions.length) last = { ...last, decisions: r.decisions }
  })
  if (last.decisions.length) return finish({ status: 'needs-decision', stage: 'rework', round, decisions: last.decisions })
}

// --- Decide ------------------------------------------------------------------

phase('Decide')
const leftover = last.findings.filter(f => atLeast(f.severity, review.rework_threshold))
const ruled = leftover.length === 0 && last.decisions.length === 0
let verdict = { decision: ruled ? 'approve' : 'escalate', reasons: ruled ? ['gate green; only nits left'] : leftover.map(f => f.summary) }
if (config.roles.approver && config.roles.approver.enabled && ruled) {
  const judged = await agent(`${common}\nDecide whether ${job.branch} is ready for a pull request. The gate is green and only nits remain: ${JSON.stringify(last.findings)}. Escalate only for a concrete risk you can name.`,
    opts('approver', { agentType: 'ring-master-approver', phase: 'Decide', label: 'approver', schema: VERDICT }))
  if (judged) verdict = judged
}

// --- Impact ------------------------------------------------------------------
// Never fails the job: broken consumers ride along with the approval as follow-up work. A replay skips it.

let broken = []
if (!replay && verdict.decision === 'approve' && impact.enabled && impact.consumers.length) {
  phase('Impact')
  const checked = await parallel(impact.consumers.map(c => () => agent(`${common}
Check whether ${c.folder} (${c.path}), which requires this package, still passes against ${job.branch}. Run \`composer --working-dir=${job.root} exec ringside impact ${spec.id} --package=${c.folder} --json\` (it exits 0 whenever the check ran) and return the first entry of its \`results\` as folder, passed, summary and output. Change nothing yourself.`,
    opts('impact', { phase: 'Impact', label: `impact ${c.folder}`, schema: IMPACTED }))))
  broken = impact.consumers
    .map((c, i) => checked[i] || { folder: c.folder, passed: false, summary: 'the impact check did not run', output: '' })
    .filter(r => !r.passed)
    .map(r => ({ folder: r.folder, passed: false, summary: r.summary || '', output: r.output || '' }))
  if (broken.length) log(`Impact: ${broken.map(b => b.folder).join(', ')} break against ${job.branch}.`)
}

// --- Brief -------------------------------------------------------------------
// What a human reviewer needs to review the PR in five minutes. A replay skips it.

let brief = null
if (!replay && verdict.decision === 'approve') {
  phase('Brief')
  const briefed = await agent(`${common}
Write ${specDir}/brief.md for the person who reviews this job's pull request. Read the diff (\`git -C ${workspace ? `${tree}/<package>` : tree} diff ${config.host.base}...${job.branch}\`${workspace ? ` in each package: ${packages.join(', ')}` : ''}), spec.md, decisions.md, and the last round's gate report under ${specDir}/round-*/.
The first line is exactly \`Risk: low\`, \`Risk: medium\` or \`Risk: high\`, then a blank line, then exactly these sections, in this order:
## What changed: the change in a few sentences, by area.
## Why: the problem it solves, from spec.md, with the decisions that shaped it.
## Look at: the 1 to 5 places a reviewer should read closely, each as \`path:line\` and why.
## Tested: which tests prove which acceptance criteria.
## Risks: what could go wrong in use, and anything left open (nits: ${last.findings.filter(f => f.severity === 'nit').length}).
Judge the risk from the size of the diff, changes to architectural paths (${(config.escalation.architectural_paths || []).join(', ')}), findings left, and packages it breaks (${broken.map(b => b.folder).join(', ') || 'none'}): high for a public contract, schema, config or dependency change or a broken consumer, medium for a wide change, low otherwise. Return the risk and the path you wrote.`,
    opts('briefer', { phase: 'Brief', label: 'brief', schema: BRIEFED }))
  brief = briefed ? { risk: briefed.risk, path: `${spec.dir}/brief.md` } : null
}

return finish({
  status: verdict.decision === 'approve' ? 'approved' : 'escalated',
  stage: 'decide',
  reasons: verdict.reasons,
  nits: last.findings.filter(f => f.severity === 'nit'),
  findings_left: leftover,
  branch: job.branch,
  worktree: tree,
  packages: workspace ? packages : null,
  rounds: history.length + 1,
  impact: broken,
  brief,
})
