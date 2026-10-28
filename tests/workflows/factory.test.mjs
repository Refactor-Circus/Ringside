import { test } from 'node:test'
import assert from 'node:assert/strict'
import { run, baseArgs, clean, built, green } from './harness.mjs'

// Default responder: workspace ready, builds clean, gate green, reviews clean.
const responder = (overrides = {}) => (call, calls) => {
  const l = call.label
  for (const [pattern, value] of Object.entries(overrides)) {
    if (new RegExp(pattern).test(l)) return typeof value === 'function' ? value(call, calls) : value
  }
  if (l === 'prepare') return { ready: true, worktree: '/work/pkg--import' }
  if (l.startsWith('group') || l.startsWith('rework')) return built
  if (l.startsWith('gate')) return green
  return clean
}
const major = { severity: 'major', category: 'correctness', files: ['src/A.php'], summary: 'off by one', fix: 'use <=' }
const nit = { severity: 'nit', files: ['src/A.php'], summary: 'rename $x' }

test('a clean job is approved after one round with no rework', async () => {
  const { result, calls } = await run('ring-master-factory', baseArgs(), responder())
  assert.equal(result.status, 'approved')
  assert.equal(calls.filter(c => c.label.startsWith('rework')).length, 0)
  assert.deepEqual(calls.map(c => c.label), ['prepare', 'group 1', 'gate r1', 'code r1', 'security r1', 'brief'])
})

test('only nits left stops early, before the max rounds', async () => {
  const { result, calls, logs } = await run('ring-master-factory', baseArgs(), responder({ '^code r1$': { findings: [nit], decisions: [] } }))
  assert.equal(result.status, 'approved')
  assert.equal(result.nits.length, 1)
  assert.equal(calls.filter(c => c.label.startsWith('rework')).length, 0)
  assert.match(logs[0], /only nits/)
})

test('a major finding is fixed automatically, then the job passes', async () => {
  const { result, calls } = await run('ring-master-factory', baseArgs(), responder({ '^code r1$': { findings: [major], decisions: [] } }))
  assert.equal(result.status, 'approved')
  assert.deepEqual(calls.filter(c => c.label.startsWith('rework')).map(c => c.label), ['rework r1'])
  assert.ok(calls.some(c => c.label === 'code r2'))
})

test('a change to an architectural path is never fixed automatically: it becomes a decision', async () => {
  const contract = { severity: 'major', files: ['src/Import/Contracts/Importer.php'], summary: 'widen the Importer contract', fix: 'add a stream() method' }
  const { result, calls } = await run('ring-master-factory', baseArgs(), responder({ '^code r1$': { findings: [contract, major], decisions: [] } }))
  assert.equal(result.status, 'needs-decision')
  assert.equal(result.decisions[0].question, 'widen the Importer contract')
  // The other finding is fixed first, so you see a clean diff and one list of questions.
  assert.deepEqual(calls.filter(c => c.label.startsWith('rework')).map(c => c.label), ['rework r1'])
  assert.doesNotMatch(calls.find(c => c.label === 'rework r1').prompt, /stream\(\) method"/)
})

test('a deviation from an architectural standard is a decision; a plain break of one is fixed', async () => {
  const breaks = { severity: 'major', category: 'naming', standard: 'architecture/naming', files: ['src/A.php'], summary: 'class should be ImportModel' }
  const deviates = { ...breaks, category: 'standard-deviation', summary: 'naming cannot apply to generated proxies' }
  const fixed = await run('ring-master-factory', baseArgs(), responder({ '^code r1$': { findings: [breaks], decisions: [] } }))
  assert.equal(fixed.result.status, 'approved')
  const asked = await run('ring-master-factory', baseArgs(), responder({ '^code r1$': { findings: [deviates], decisions: [] } }))
  assert.equal(asked.result.status, 'needs-decision')
})

test('a question during the build ends the run before review', async () => {
  const ask = { question: 'One row per file or per line?', options: ['file', 'line'], recommendation: 'file' }
  const { result, calls } = await run('ring-master-factory', baseArgs(), responder({ '^group 1$': { ...built, decisions: [ask] } }))
  assert.equal(result.status, 'needs-decision')
  assert.equal(result.stage, 'build')
  assert.ok(!calls.some(c => c.label.startsWith('gate')))
})

test('a question already in decisions.md is never asked again', async () => {
  const ask = { question: 'One row per file or per line?', options: ['file', 'line'], recommendation: 'file' }
  const args = baseArgs({ decisions: [{ id: 'D-001', question: 'One row per file or per line?', choice: 'file' }] })
  const { result } = await run('ring-master-factory', args, responder({ '^group 1$': { ...built, decisions: [ask] }, '^code r1$': { findings: [], decisions: [ask] } }))
  assert.equal(result.status, 'approved')
})

test('the same major finding two rounds in a row escalates as no progress', async () => {
  const { result, calls } = await run('ring-master-factory', baseArgs({ config: { review: { rounds: { max: 5, min: 1 }, rework_threshold: 'minor', stop_when_no_progress: 2, gate: { run_first: true, max_consecutive_red: 3, criteria_mapping: true } } } }),
    responder({ '^code r\\d$': { findings: [major], decisions: [] } }))
  assert.equal(result.status, 'escalated')
  assert.deepEqual(result.reasons, ['no progress between rounds'])
  assert.ok(!calls.some(c => c.label === 'code r3'))
})

test('findings left after the configured rounds escalate with them', async () => {
  let n = 0
  const { result } = await run('ring-master-factory', baseArgs({ config: { review: { rounds: { max: 2, min: 1 }, rework_threshold: 'minor', stop_when_no_progress: 0, gate: { run_first: true, max_consecutive_red: 3, criteria_mapping: true } } } }),
    responder({ '^code r\\d$': () => ({ findings: [{ ...major, summary: `bug ${++n}` }], decisions: [] }) }))
  assert.equal(result.status, 'escalated')
  assert.deepEqual(result.reasons, ['review rounds exhausted'])
})

test('a red gate skips the reviewers and is fixed as a blocker', async () => {
  let gates = 0
  const { result, calls } = await run('ring-master-factory', baseArgs(), responder({
    '^gate': () => (++gates === 1 ? { passed: false, findings: [{ severity: 'blocker', summary: '2 tests fail' }], changed_files: [] } : green),
  }))
  assert.equal(result.status, 'approved')
  assert.ok(!calls.some(c => c.label === 'code r1'))
  assert.ok(calls.some(c => c.label === 'rework r1'))
})

test('a reviewer that dies twice counts as a blocker, never a pass', async () => {
  let tries = 0
  const { calls } = await run('ring-master-factory', baseArgs(), responder({ '^security r1$': () => { tries++; return null } }))
  assert.equal(tries, 2)
  assert.match(calls.find(c => c.label === 'rework r1').prompt, /security review unavailable/)
})

test('each role gets its configured model', async () => {
  const { calls } = await run('ring-master-factory', baseArgs({ reviewers: [{ name: 'code', model: 'opus' }] }), responder())
  assert.equal(calls.find(c => c.label === 'prepare').opts.model, 'haiku')
  assert.equal(calls.find(c => c.label === 'code r1').opts.model, 'opus')
  assert.equal(calls.find(c => c.label === 'code r1').opts.agentType, 'ring-master-reviewer-code')
})

test('parallel waves never run two groups that share a file at once', async () => {
  const args = baseArgs({ config: { build: { parallel: true, max_concurrency: 4, worktree: { mode: 'per-job', setup: 'x' } } } })
  args.waves = [[
    { n: 1, name: 'A', files: ['src/A.php'], criteria: [], tasks: [] },
    { n: 2, name: 'B', files: ['src/A.php'], criteria: [], tasks: [] },
  ]]
  let running = 0
  let peak = 0
  await run('ring-master-factory', args, responder({
    '^group': async () => { running++; peak = Math.max(peak, running); await new Promise(r => setTimeout(r, 5)); running--; return built },
  }))
  assert.equal(peak, 1)
})

test('a single-package per-job job prepares its worktree with ringside worktrees --setup', async () => {
  const setup = 'composer install --no-interaction --prefer-dist'
  const args = baseArgs({ config: { build: { parallel: false, max_concurrency: 2, worktree: { mode: 'per-job', setup } } } })
  const { calls } = await run('ring-master-factory', args, responder())
  const prompt = calls.find(c => c.label === 'prepare').prompt
  assert.match(prompt, /From \/work\/pkg, run `vendor\/bin\/ringside worktrees 2026-10-23-1430-import --setup`/)
  assert.match(prompt, /ready=true only if it exits 0/)
  assert.match(prompt, /non-zero exit, return ready=false with the command output in detail/)
  assert.match(prompt, /do not repair anything manually/)
  assert.match(prompt, /never create the worktree with git yourself or fall back to any other command/)
  assert.doesNotMatch(prompt, /git worktree add/)
  assert.doesNotMatch(prompt, /create or reuse a git worktree/)
  assert.ok(!prompt.includes(setup), 'the configured setup string is not in the prompt')
})

test('the gate runs ringside check in the job worktree through composer, never by path', async () => {
  const args = baseArgs({ config: { build: { parallel: false, max_concurrency: 2, worktree: { mode: 'per-job', setup: 'auto' } } } })
  const { calls } = await run('ring-master-factory', args, responder())
  const prompt = calls.find(c => c.label === 'gate r1').prompt
  assert.match(prompt, /`composer --working-dir=\/work\/pkg--import exec ringside check`/)
  assert.doesNotMatch(prompt, /then `vendor\/bin\/ringside check`/)
})

const workspaceArgs = () => {
  const args = baseArgs({
    workspace: {
      dir: '.ring-master/jobs/2026-10-23-1430-import',
      packages: {
        Keystone: { name: 'refactor-circus/keystone', commands: { test: 'composer test:unit', verify: 'composer test', format_check: 'composer lint:check' }, standards: [] },
        Atrium: { name: 'refactor-circus/atrium', commands: { test: 'composer test:unit', verify: 'composer test', format_check: null }, standards: ['Atrium/docs/agent/standards/atrium/components.md'] },
      },
    },
  })
  args.waves = [[{ n: 1, name: 'Contract', package: 'Keystone', files: ['src/Importer.php'], criteria: [1], tasks: [] }], [{ n: 2, name: 'Screen', package: 'Atrium', files: ['src/Import.php'], criteria: [2], tasks: [] }]]
  return args
}

test('a workspace job builds each group in its own package worktree and gates every package', async () => {
  const { result, calls } = await run('ring-master-factory', workspaceArgs(), responder())
  assert.equal(result.status, 'approved')
  assert.deepEqual(result.packages, ['Keystone', 'Atrium'])
  assert.match(calls.find(c => c.label === 'prepare').prompt, /ringside worktrees 2026-10-23-1430-import --setup/)
  assert.match(calls.find(c => c.label === 'group 1').prompt, /\.ring-master\/jobs\/2026-10-23-1430-import\/Keystone/)
  assert.match(calls.find(c => c.label === 'group 2').prompt, /\.ring-master\/jobs\/2026-10-23-1430-import\/Atrium/)
  assert.match(calls.find(c => c.label === 'group 2').prompt, /Atrium's own standards, which override.*atrium\/components\.md/)
  assert.match(calls.find(c => c.label === 'code r1').prompt, /Atrium: \/work\/pkg\/Atrium\/docs\/agent\/standards\/atrium\/components\.md/)
  assert.deepEqual(calls.filter(c => c.label.startsWith('gate')).map(c => c.label).sort(), ['gate Atrium r1', 'gate Keystone r1'])
})

test('a red gate in one package of a workspace job is fixed before review', async () => {
  let red = true
  const { result, calls } = await run('ring-master-factory', workspaceArgs(), responder({
    '^gate Atrium': () => (red ? (red = false, { passed: false, findings: [{ severity: 'blocker', files: ['Atrium/tests/ImportTest.php'], summary: 'test fails' }], changed_files: [] }) : green),
  }))
  assert.equal(result.status, 'approved')
  assert.ok(calls.some(c => c.label === 'rework r1'))
  assert.ok(!calls.some(c => c.label === 'code r1'))
})

test('architectural paths apply inside each package of a workspace', async () => {
  const contract = { severity: 'major', files: ['Keystone/composer.json'], summary: 'require a new package', fix: 'add it' }
  const { result } = await run('ring-master-factory', workspaceArgs(), responder({ '^code r1$': { findings: [contract], decisions: [] } }))
  assert.equal(result.status, 'needs-decision')
})

const withRoles = (roles, extra = {}) => baseArgs({ ...extra, config: { roles: { prepare: { model: 'haiku' }, reviewer: { model: 'sonnet' }, triage: { enabled: false }, approver: { enabled: false }, ...roles }, ...(extra.config || {}) } })
const escalating = { gate: { model: 'sonnet', escalate_model: 'opus' }, reworker: { model: 'sonnet', escalate_model: 'opus' } }

test('after a red gate, the next gate and the rework run on the escalated model', async () => {
  let gates = 0
  const { calls, logs } = await run('ring-master-factory', withRoles(escalating), responder({
    '^gate': () => (++gates === 1 ? { passed: false, findings: [{ severity: 'blocker', summary: '2 tests fail' }], changed_files: [] } : green),
  }))
  assert.equal(calls.find(c => c.label === 'gate r1').opts.model, 'sonnet')
  assert.equal(calls.find(c => c.label === 'rework r1').opts.model, 'opus')
  assert.equal(calls.find(c => c.label === 'gate r2').opts.model, 'opus')
  assert.ok(logs.some(l => /Escalating gate .*opus/.test(l)))
})

test('the reworker escalates once the same major finding repeats, one round before no-progress', async () => {
  const review = { rounds: { max: 5, min: 1 }, rework_threshold: 'minor', stop_when_no_progress: 3, gate: { run_first: true, max_consecutive_red: 3, criteria_mapping: true } }
  const { calls } = await run('ring-master-factory', withRoles(escalating, { config: { review } }), responder({ '^code r[12]$': { findings: [major], decisions: [] } }))
  assert.equal(calls.find(c => c.label === 'rework r1').opts.model, 'sonnet')
  assert.equal(calls.find(c => c.label === 'rework r2').opts.model, 'opus')
  assert.equal(calls.find(c => c.label === 'gate r2').opts.model, 'sonnet')
})

test('escalate_model null keeps the role on its model', async () => {
  let gates = 0
  const { calls } = await run('ring-master-factory', withRoles({ gate: { model: 'sonnet', escalate_model: null }, reworker: { model: 'sonnet' } }), responder({
    '^gate': () => (++gates === 1 ? { passed: false, findings: [{ severity: 'blocker', summary: 'red' }], changed_files: [] } : green),
  }))
  assert.equal(calls.find(c => c.label === 'gate r2').opts.model, 'sonnet')
  assert.equal(calls.find(c => c.label === 'rework r1').opts.model, 'sonnet')
})

const impactArgs = (overrides = {}) => withRoles({ impact: { model: 'sonnet' } }, {
  impact: { enabled: true, consumers: [{ folder: 'App', path: '/work/App', test: 'vendor/bin/pest' }, { folder: 'Shop', path: '/work/Shop', test: 'composer test' }] },
  ...overrides,
})

test('an approved job checks every consumer in parallel and carries the broken ones, still approved', async () => {
  const { result, calls, phases } = await run('ring-master-factory', impactArgs(), responder({
    '^impact App$': { folder: 'App', passed: true, summary: 'App passes' },
    '^impact Shop$': { folder: 'Shop', passed: false, summary: 'Shop fails', output: 'Class McpServerRegistry not found' },
  }))
  assert.equal(result.status, 'approved')
  assert.deepEqual(result.impact, [{ folder: 'Shop', passed: false, summary: 'Shop fails', output: 'Class McpServerRegistry not found' }])
  assert.ok(phases.includes('Impact'))
  assert.match(calls.find(c => c.label === 'impact Shop').prompt, /`composer --working-dir=\/work\/pkg exec ringside impact 2026-10-23-1430-import --package=Shop --json`/)
  assert.equal(calls.find(c => c.label === 'impact App').opts.model, 'sonnet')
})

test('an impact agent that dies counts as a broken consumer, never a pass', async () => {
  const { result } = await run('ring-master-factory', impactArgs(), responder({ '^impact App$': null, '^impact Shop$': { folder: 'Shop', passed: true } }))
  assert.equal(result.status, 'approved')
  assert.deepEqual(result.impact.map(i => [i.folder, i.summary]), [['App', 'the impact check did not run']])
})

test('impact runs only for an approval, and not at all without consumers', async () => {
  const escalated = await run('ring-master-factory', impactArgs({ config: { review: { rounds: { max: 1, min: 1 }, rework_threshold: 'minor', stop_when_no_progress: 0, gate: { run_first: true, max_consecutive_red: 3, criteria_mapping: true } } } }),
    responder({ '^code r1$': { findings: [major], decisions: [] } }))
  assert.equal(escalated.result.status, 'escalated')
  assert.ok(!escalated.calls.some(c => c.label.startsWith('impact')))
  const none = await run('ring-master-factory', baseArgs(), responder())
  assert.deepEqual(none.result.impact, [])
  assert.ok(!none.calls.some(c => c.label.startsWith('impact')))
})

const parallelArgs = () => {
  const args = baseArgs({ config: { build: { parallel: true, max_concurrency: 4, worktree: { mode: 'per-job', setup: 'auto' } } } })
  args.waves = [[
    { n: 1, name: 'A', files: ['src/A.php'], criteria: [], tasks: [] },
    { n: 2, name: 'B', files: ['src/A.php'], criteria: [], tasks: [] },
  ]]
  return args
}

test('parallel groups of a per-job package build at once in their own worktrees, then one integrator merges them', async () => {
  let running = 0
  let peak = 0
  const { result, calls, phases } = await run('ring-master-factory', parallelArgs(), responder({
    '^group worktrees': { ready: true },
    '^group \\d$': async () => { running++; peak = Math.max(peak, running); await new Promise(r => setTimeout(r, 5)); running--; return built },
    '^integrate': { merged: true, conflicts: [] },
  }))
  assert.equal(result.status, 'approved')
  assert.equal(peak, 2, 'groups that share a file still run at once: each has its own worktree')
  assert.match(calls.find(c => c.label === 'group worktrees 1,2').prompt, /ringside worktrees 2026-10-23-1430-import --groups=1,2 --setup/)
  assert.match(calls.find(c => c.label === 'group 2').prompt, /its own worktree \/work\/pkg--import--g2 \(branch ring-master\/2026-10-23-1430-import--g2/)
  assert.match(calls.find(c => c.label === 'group 2').prompt, /Commit your changes on ring-master\/2026-10-23-1430-import--g2/)
  const integrate = calls.find(c => c.label === 'integrate w1')
  assert.match(integrate.prompt, /merge ring-master\/2026-10-23-1430-import--g1, ring-master\/2026-10-23-1430-import--g2/)
  assert.match(integrate.prompt, /--remove-groups/)
  assert.ok(phases.includes('Integrate'))
  assert.ok(calls.indexOf(integrate) < calls.findIndex(c => c.label === 'gate r1'))
})

test('a failed integration escalates before the gate', async () => {
  const { result, calls } = await run('ring-master-factory', parallelArgs(), responder({
    '^group worktrees': { ready: true },
    '^integrate': { merged: false, conflicts: ['src/A.php'], detail: 'both groups rename the same method differently' },
  }))
  assert.equal(result.status, 'escalated')
  assert.equal(result.stage, 'integrate')
  assert.deepEqual(result.conflicts, ['src/A.php'])
  assert.ok(!calls.some(c => c.label.startsWith('gate')))
})

test('a single-group wave, a workspace job and a serial build keep the shared job worktree', async () => {
  const one = baseArgs({ config: { build: { parallel: true, max_concurrency: 4, worktree: { mode: 'per-job', setup: 'auto' } } } })
  const single = await run('ring-master-factory', one, responder())
  assert.ok(!single.calls.some(c => c.label.startsWith('group worktrees') || c.label.startsWith('integrate')))
  const serial = parallelArgs()
  serial.config.build.parallel = false
  const s = await run('ring-master-factory', serial, responder())
  assert.ok(!s.calls.some(c => c.label.startsWith('integrate')))
  assert.match(s.calls.find(c => c.label === 'group 1').prompt, /in \/work\/pkg--import\./)
})

test('an approved job gets a brief for the reviewer, written by the briefer, with its risk', async () => {
  const { result, calls, phases } = await run('ring-master-factory', withRoles({ briefer: { model: 'sonnet', effort: 'low' } }), responder({
    '^brief$': { risk: 'medium', path: 'docs/agent/specs/2026-10-23-1430-import/brief.md' },
  }))
  assert.equal(result.status, 'approved')
  assert.deepEqual(result.brief, { risk: 'medium', path: 'docs/agent/specs/2026-10-23-1430-import/brief.md' })
  assert.ok(phases.includes('Brief'))
  const brief = calls.find(c => c.label === 'brief')
  assert.equal(brief.opts.model, 'sonnet')
  for (const section of ['## What changed', '## Why', '## Look at', '## Tested', '## Risks']) assert.ok(brief.prompt.includes(section), section)
  assert.match(brief.prompt, /The first line is exactly `Risk: low`, `Risk: medium` or `Risk: high`/)
  assert.match(brief.prompt, /\/work\/pkg\/docs\/agent\/specs\/2026-10-23-1430-import\/brief\.md/)
})

test('an escalated job gets no brief', async () => {
  const { result, calls } = await run('ring-master-factory', baseArgs({ config: { review: { rounds: { max: 1, min: 1 }, rework_threshold: 'minor', stop_when_no_progress: 0, gate: { run_first: true, max_consecutive_red: 3, criteria_mapping: true } } } }),
    responder({ '^code r1$': { findings: [major], decisions: [] } }))
  assert.equal(result.status, 'escalated')
  assert.equal(result.brief, undefined)
  assert.ok(!calls.some(c => c.label === 'brief'))
})

test('a replay rebuilds on its own worktree, keeps the real spec folder untouched, and reports what an evaluation scores', async () => {
  let gates = 0
  const args = impactArgs({ replay: true, replay_from: 'abc123' })
  args.job = { ...args.job, branch: 'ring-master/2026-10-23-1430-import--replay', worktree: '../pkg--2026-10-23-1430-import--replay' }
  args.waves[0][0].tasks = [{ id: '1.1', text: 'tests', done: true }]
  const { result, calls } = await run('ring-master-factory', args, responder({
    '^prepare$': { ready: true, worktree: '/work/pkg--import--replay' },
    '^gate': () => (++gates === 1 ? { passed: false, findings: [{ severity: 'blocker', summary: 'red' }], changed_files: [] } : green),
  }))
  assert.equal(result.status, 'approved')
  assert.equal(result.replay, true)
  assert.equal(result.gate_red_rounds, 1)
  assert.equal(result.variants_winner, null)
  assert.match(calls.find(c => c.label === 'prepare').prompt, /ringside worktrees 2026-10-23-1430-import --setup --replay --from=abc123/)
  const group = calls.find(c => c.label === 'group 1').prompt
  assert.match(group, /Tasks: 1\.1 tests/, 'a replay builds even the tasks the real job ticked')
  assert.match(group, /do not tick tasks\.md/)
  assert.match(group, /specs\/2026-10-23-1430-import\/replay\/build\.md/)
  assert.match(group, /never push, never open or update a pull request, never call RingMaster's MCP tools/)
  assert.match(calls.find(c => c.label === 'gate r1').prompt, /import\/replay\/round-1\/gate\.md/)
  assert.ok(!calls.some(c => c.label.startsWith('impact') || c.label === 'brief'))
})

const variantArgs = (n = 3, extra = {}) => {
  const args = withRoles({ judge: { model: 'opus', effort: 'high' }, variant: { models: ['opus', 'opus', 'sonnet'] }, implementer: { model: 'opus' } }, extra)
  args.variants = n
  args.waves = [[{ n: 1, name: 'Data', files: ['src/A.php'], criteria: [1], tasks: [{ id: '1.1', text: 'tests', done: false }] }], [{ n: 2, name: 'Screen', files: ['src/B.php'], criteria: [2], tasks: [{ id: '2.1', text: 'screen', done: false }] }]]
  return args
}
const gateFor = passed => ({ passed, findings: passed ? [] : [{ severity: 'blocker', summary: 'red' }], changed_files: ['src/A.php'], criteria: [{ n: 1, proven: true }] })

test('variants build the whole job several ways at once, each gated, and the judge picks the one to keep', async () => {
  let running = 0
  let peak = 0
  const { result, calls, logs, phases } = await run('ring-master-factory', variantArgs(), responder({
    '^variant worktrees': { ready: true },
    '^variant \\d$': async () => { running++; peak = Math.max(peak, running); await new Promise(r => setTimeout(r, 5)); running--; return built },
    '^gate v1$': gateFor(true), '^gate v2$': gateFor(false), '^gate v3$': gateFor(true),
    '^judge$': { winner: 3, reasons: ['smaller diff'] },
    '^adopt': { merged: true },
  }))
  assert.equal(result.status, 'approved')
  assert.equal(result.variants_winner, 3)
  assert.equal(peak, 3)
  assert.ok(phases.includes('Variants'))
  assert.ok(!calls.some(c => /^group \d$/.test(c.label)), 'variants replace the group build')
  assert.match(calls.find(c => c.label === 'variant worktrees 3').prompt, /ringside worktrees 2026-10-23-1430-import --variants=3 --setup/)
  const v2 = calls.find(c => c.label === 'variant 2')
  assert.match(v2.prompt, /worktree \/work\/pkg--import--v2 \(branch ring-master\/2026-10-23-1430-import--v2/)
  assert.match(v2.prompt, /Follow the patterns already in this codebase/)
  assert.match(v2.prompt, /1\.1 tests \(group 1: Data\); 2\.1 screen \(group 2: Screen\)/)
  assert.match(v2.prompt, /Do not tick tasks\.md/)
  assert.equal(calls.find(c => c.label === 'variant 3').opts.model, 'sonnet')
  assert.equal(calls.find(c => c.label === 'judge').opts.model, 'opus')
  const adopt = calls.find(c => c.label === 'adopt v3')
  assert.match(adopt.prompt, /merge --ff-only ring-master\/2026-10-23-1430-import--v3/)
  assert.match(adopt.prompt, /--remove-variants/)
  assert.ok(calls.indexOf(adopt) < calls.findIndex(c => c.label === 'gate r1'))
  assert.ok(logs.some(l => /Variants: 3 of 3 kept/.test(l)))
})

test('without a usable verdict from the judge, the best gate result wins', async () => {
  const { result } = await run('ring-master-factory', variantArgs(2), responder({
    '^variant worktrees': { ready: true },
    '^variant \\d$': built,
    '^gate v1$': gateFor(false), '^gate v2$': gateFor(true),
    '^judge$': { winner: 7, reasons: ['no such variant'] },
    '^adopt': { merged: true },
  }))
  assert.equal(result.variants_winner, 2)
})

test('a variant that blocks drops out; when none is left the job escalates', async () => {
  const { result } = await run('ring-master-factory', variantArgs(2), responder({
    '^variant worktrees': { ready: true },
    '^variant \\d$': { ...built, blocked: 'the API is missing' },
  }))
  assert.equal(result.status, 'escalated')
  assert.equal(result.stage, 'variants')
})

test('a workspace job ignores variants and builds once', async () => {
  const args = workspaceArgs()
  args.variants = 3
  const { calls, logs } = await run('ring-master-factory', args, responder())
  assert.ok(!calls.some(c => c.label.startsWith('variant')))
  assert.ok(logs.some(l => /only a single package with per-job worktrees builds variants/.test(l)))
})
