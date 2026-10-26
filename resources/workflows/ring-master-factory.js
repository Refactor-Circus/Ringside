export const meta = {
  name: 'ring-master-factory',
  description: 'Build an approved Ring Master spec through gated review rounds; returns approved, needs-decision or escalated',
  whenToUse: 'Launched by /ring-master-factory with args from `vendor/bin/ringside job <spec> --json`',
  phases: [
    { title: 'Prepare', detail: 'job worktree and branch' },
    { title: 'Build', detail: 'one implementer per task group, wave by wave' },
    { title: 'Gate', detail: 'verify command, ringside check, acceptance criteria' },
    { title: 'Review', detail: 'configured reviewers in parallel' },
    { title: 'Rework', detail: 'fix every finding at or above the threshold' },
    { title: 'Decide', detail: 'script rules; approver agent only when enabled' },
  ],
}

// ---------------------------------------------------------------------------
// Input: the JSON from `ringside job <spec> --json` (see src/Specs/Job.php).
// The script never reads files; agents do, and each writes its own report.
// ---------------------------------------------------------------------------

const { spec, job, waves, decisions = [], index = {}, reviewers = [], config, workspace = null } = args
const SEVERITIES = ['architectural', 'blocker', 'major', 'minor', 'nit']
const review = config.review
const specDir = `${job.root}/${spec.dir}`

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
const REVIEW = { type: 'object', required: ['findings', 'decisions'], properties: { findings: { type: 'array', items: FINDING }, decisions: { type: 'array', items: ASK } } }
const VERDICT = { type: 'object', required: ['decision', 'reasons'], properties: { decision: { type: 'string', enum: ['approve', 'escalate'] }, reasons: { type: 'array', items: { type: 'string' } } } }

// --- helpers ---------------------------------------------------------------

const rank = s => SEVERITIES.indexOf(s)
const atLeast = (severity, threshold) => rank(severity) !== -1 && rank(severity) <= rank(threshold)
const opts = (role, extra = {}) => {
  const r = config.roles[role] || {}
  const o = { ...extra }
  if (r.model) o.model = r.model
  if (r.effort) o.effort = r.effort
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

function noProgress(history, current, rounds) {
  if (!rounds || history.length < rounds - 1) return false
  const keys = list => list.filter(f => atLeast(f.severity, 'major')).map(findingKey).sort().join('\n')
  const now = keys(current)
  if (now === '') return false
  return history.slice(-(rounds - 1)).every(h => keys(h) === now)
}

const common = `You are working on the Ring Master job for spec "${spec.title}" (${spec.id}).
Code changes happen in the job worktree; reports go in the spec folder of the main checkout: ${specDir}.
Read ${specDir}/spec.md and ${specDir}/decisions.md (if present) first. Decisions there are final: never re-raise them.
Standards that apply: ${spec.standards.length ? spec.standards.map(p => `${job.root}/${p}`).join(', ') : 'see ' + specDir + '/standards.md'}.
Shell: one command per Bash call, with no \`&&\`, \`;\` or pipes. A session started by RingMaster's runner only runs the commands it allows, and a compound command is refused as a whole. To work in another folder, run \`cd <folder>\` as its own call first, or use \`git -C <folder>\` and \`composer --working-dir=<folder>\`. Read files with the Read tool, not cat. If a command is refused, split it up and try again before you report a failure.`

// --- Prepare ---------------------------------------------------------------

phase('Prepare')
const worktreeMode = config.build.worktree.mode
const packages = workspace ? Object.keys(workspace.packages) : []
const ws = await agent(`${common}
Prepare the job workspace. ${workspace
    ? `This is a workspace job across ${packages.join(', ')}. From ${job.root}, run \`vendor/bin/ringside worktrees ${spec.id} --setup\` and return ready=true only if it exits 0 (put its output in detail otherwise).`
    : worktreeMode === 'per-job'
    ? `From ${job.root}, create or reuse a git worktree at ${job.worktree} on branch ${job.branch} (based on ${config.host.base}; reuse it if it already exists). Then run \`${config.build.worktree.setup}\` inside it.`
    : `Work in ${job.root} itself: create or check out branch ${job.branch} (based on ${config.host.base}).`}
Return ready=false with the error output in detail if anything fails.`, opts('prepare', { label: 'prepare', schema: PREPARED }))
if (!ws || !ws.ready) return { status: 'escalated', stage: 'prepare', reasons: ['workspace setup failed'], detail: ws && ws.detail }
const tree = workspace ? `${job.root}/${workspace.dir}` : ws.worktree || (worktreeMode === 'per-job' ? job.worktree : job.root)
const treeFor = pkg => (workspace && pkg ? `${tree}/${pkg}` : tree)
const command = (pkg, name) => (workspace && pkg ? workspace.packages[pkg].commands[name] : config.commands[name])
const trees = workspace ? packages.map(p => `${p}: ${treeFor(p)}`).join('; ') : tree

// --- Build -----------------------------------------------------------------

phase('Build')
const testsInGroup = !config.build.parallel
for (const wave of waves) {
  const build = group => agent(`${common}
Implement task group ${group.n}: "${group.name}" in ${treeFor(group.package)}${workspace ? ` (package ${group.package}; the other packages of this job are beside it in ${tree})` : ''}.
Tasks: ${group.tasks.filter(t => !t.done).map(t => `${t.id} ${t.text}`).join('; ')}.
Files it owns: ${group.files.join(', ') || 'not listed'}.${workspace && workspace.packages[group.package].standards.length ? ` ${group.package}'s own standards, which override a global one with the same key: ${workspace.packages[group.package].standards.map(p => `${job.root}/${p}`).join(', ')}.` : ''} Acceptance criteria it proves: ${group.criteria.join(', ') || 'see tasks'}.
Write the tests first, then the code. ${testsInGroup ? `Run \`${command(group.package, 'test')}\` in ${treeFor(group.package)} until it passes.` : 'Do not run the test suite; the gate runs it after the build.'}
Commit your changes on ${job.branch} with the message "${spec.id}: group ${group.n} ${group.name}", tick the finished tasks in ${specDir}/tasks.md, and append a "## Group ${group.n}" section to ${specDir}/build.md (files, tests, deviations).
If a task needs a choice the spec and decisions don't settle (a public contract, schema, config shape, dependency, module boundary, or two viable designs), stop at a safe point and return it in decisions with options and your recommendation instead of choosing.`,
    opts('implementer', { agentType: 'ring-master-implementer', phase: 'Build', label: `group ${group.n}`, schema: BUILT }))

  // Parallel only when enabled and no two groups in the wave share a file; at most max_concurrency at once.
  const files = wave.flatMap(g => g.files)
  const parallelOk = config.build.parallel && new Set(files).size === files.length
  const size = parallelOk ? Math.max(1, config.build.max_concurrency) : 1
  const built = []
  for (let i = 0; i < wave.length; i += size) built.push(...await pipeline(wave.slice(i, i + size), build))

  const asks = built.flatMap(b => (b && b.decisions) || []).filter(a => !decidedQuestions.has(a.question.trim().toLowerCase()))
  if (asks.length) return { status: 'needs-decision', stage: 'build', decisions: asks, built }
  if (built.some(b => !b)) return { status: 'escalated', stage: 'build', reasons: ['a build agent failed'], built }
  if (built.some(b => b.blocked)) return { status: 'escalated', stage: 'build', reasons: built.filter(b => b.blocked).map(b => b.blocked), built }
}

// --- Review rounds -----------------------------------------------------------

const history = []
let red = 0
let last = { findings: [], decisions: [] }

for (let round = 1; round <= review.rounds.max; round++) {
  const roundDir = `${specDir}/round-${round}`
  const scope = workspace ? ` in each package of the job (${trees})` : ''
  const sinceNote = round === 1 ? `the whole diff of ${job.branch} against ${config.host.base}${scope}` : `the changes since round ${round - 1}${scope} (open findings were: ${history[history.length - 1].map(f => f.summary).join('; ') || 'none'})`

  phase('Gate')
  const runGate = (pkg, file) => agent(`${common}
Run the gate in ${treeFor(pkg)}: \`${command(pkg, 'verify')}\`${command(pkg, 'format_check') ? ` and \`${command(pkg, 'format_check')}\`` : ''}${workspace ? '' : ', then `vendor/bin/ringside check`'}.
${review.gate.criteria_mapping ? `Map every numbered acceptance criterion in spec.md${workspace ? ` that ${pkg} proves` : ''} to the test (file and name) or code that proves it.` : ''}
Every failure is a blocker finding with the failing output summarised${workspace ? `; report files as ${pkg}/<path>` : ''}. List changed_files (git diff --name-only ${config.host.base}...${job.branch}${workspace ? `, prefixed with ${pkg}/` : ''}).
Write ${roundDir}/${file} starting with "VERDICT: PASS" or "VERDICT: CHANGES".`, opts('gate', { phase: 'Gate', label: workspace ? `gate ${pkg} r${round}` : `gate r${round}`, schema: GATE }))
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

  if (current.decisions.length) {
    if (config.escalation.fix_others_first && fix.length) {
      phase('Rework')
      await agent(`${common}\nFix these findings in ${workspace ? `the job's package worktrees (${trees}); each finding's file starts with its package` : tree} (round ${round}); do not touch the open questions listed after them.\nFindings: ${JSON.stringify(fix)}\nOpen questions (leave alone): ${JSON.stringify(current.decisions.map(d => d.question))}\nRun ${workspace ? "each changed package's test command" : `\`${config.commands.test}\``}, commit on ${job.branch}${workspace ? ' in every package you changed' : ''}, and note the fixes in ${specDir}/build.md.`,
        opts('reworker', { agentType: 'ring-master-implementer', phase: 'Rework', label: `rework r${round}`, schema: BUILT }))
    }
    return { status: 'needs-decision', stage: 'review', round, decisions: current.decisions, findings: fix, nits }
  }

  if (fix.length === 0 && round >= review.rounds.min) {
    log(`Round ${round}: only nits left (${nits.length}); stopping early.`)
    break
  }
  if (red >= review.gate.max_consecutive_red) return { status: 'escalated', stage: 'gate', round, reasons: [`gate red ${red} rounds in a row`], findings: fix }
  if (noProgress(history, fix, review.stop_when_no_progress)) return { status: 'escalated', stage: 'review', round, reasons: ['no progress between rounds'], findings: fix }
  if (round === review.rounds.max) return { status: 'escalated', stage: 'review', round, reasons: ['review rounds exhausted'], findings: fix, nits }

  history.push(fix)
  phase('Rework')
  await agent(`${common}\nFix these findings in ${workspace ? `the job's package worktrees (${trees}); each finding's file starts with its package` : tree} (round ${round}). Fix the nits too where it is cheap.\nFindings: ${JSON.stringify(fix)}\nNits: ${JSON.stringify(nits)}\nRun ${workspace ? "each changed package's test command" : `\`${config.commands.test}\``}, commit on ${job.branch}${workspace ? ' in every package you changed' : ''} with "${spec.id}: rework round ${round}", and note the fixes in ${specDir}/build.md.\nIf a fix needs a choice the spec doesn't settle, return it in decisions instead.`,
    opts('reworker', { agentType: 'ring-master-implementer', phase: 'Rework', label: `rework r${round}`, schema: BUILT })).then(r => {
    if (r && r.decisions && r.decisions.length) last = { ...last, decisions: r.decisions }
  })
  if (last.decisions.length) return { status: 'needs-decision', stage: 'rework', round, decisions: last.decisions }
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

return {
  status: verdict.decision === 'approve' ? 'approved' : 'escalated',
  stage: 'decide',
  reasons: verdict.reasons,
  nits: last.findings.filter(f => f.severity === 'nit'),
  branch: job.branch,
  worktree: tree,
  packages: workspace ? packages : null,
  rounds: history.length + 1,
}
