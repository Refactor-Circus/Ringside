import { test } from 'node:test'
import assert from 'node:assert/strict'
import { readFileSync } from 'node:fs'
import { run, baseArgs, clean, built, green } from './harness.mjs'
import { cwdProof } from '../../resources/workflows/gate-command.js'

// A clean preflight: nothing uncommitted, an origin remote, and ringside check applies.
const preflightClean = { dirty: [], remote: true, check_applies: true }
// Default responder: preflight clean, workspace ready, builds clean, gate green, reviews clean.
const responder = (overrides = {}) => (call, calls) => {
  const l = call.label
  for (const [pattern, value] of Object.entries(overrides)) {
    if (new RegExp(pattern).test(l)) return typeof value === 'function' ? value(call, calls) : value
  }
  if (l === 'prepare') return { ready: true, worktree: '/work/pkg--import', ...preflightClean }
  if (l === 'prepare setup') return { ready: true, worktree: '/work/pkg--import' }
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
  assert.match(prompt, /Run `composer --working-dir=\/work\/pkg exec ringside -- worktrees 2026-10-23-1430-import --setup`/)
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
  assert.ok(prompt.includes("`composer --working-dir='/work/pkg--import' exec ringside check`"))
  assert.doesNotMatch(prompt, /vendor\/bin\/ringside/)
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
  assert.match(calls.find(c => c.label === 'prepare').prompt, /ringside -- worktrees 2026-10-23-1430-import --setup/)
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
  assert.match(calls.find(c => c.label === 'group worktrees 1,2').prompt, /ringside -- worktrees 2026-10-23-1430-import --groups=1,2 --setup/)
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
  assert.match(calls.find(c => c.label === 'prepare').prompt, /ringside -- worktrees 2026-10-23-1430-import --setup --replay --from=abc123/)
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
// A variant builds every group's tasks, so a finished one reports them all done.
const vbuilt = { ...built, done: ['1.1', '2.1'] }

test('variants build the whole job several ways at once, each gated, and the judge picks the one to keep', async () => {
  let running = 0
  let peak = 0
  const { result, calls, logs, phases } = await run('ring-master-factory', variantArgs(), responder({
    '^variant worktrees': { ready: true },
    '^variant \\d$': async () => { running++; peak = Math.max(peak, running); await new Promise(r => setTimeout(r, 5)); running--; return vbuilt },
    '^gate v1$': gateFor(true), '^gate v2$': gateFor(false), '^gate v3$': gateFor(true),
    '^judge$': { winner: 3, reasons: ['smaller diff'] },
    '^adopt': { merged: true },
  }))
  assert.equal(result.status, 'approved')
  assert.equal(result.variants_winner, 3)
  assert.equal(peak, 3)
  assert.ok(phases.includes('Variants'))
  assert.ok(!calls.some(c => /^group \d$/.test(c.label)), 'variants replace the group build')
  assert.match(calls.find(c => c.label === 'variant worktrees 3').prompt, /ringside -- worktrees 2026-10-23-1430-import --variants=3 --setup/)
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
    '^variant \\d$': vbuilt,
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

// --- ringside check stays out of Ringside's own gate (D-009, tooling/verification-gate) ---

// The package is named after the job root's folder, as Job.php names it; a Windows root uses backslashes.
const ringsideJob = root => ({ branch: 'ring-master/2026-10-23-1430-import', worktree: '../Ringside--2026-10-23-1430-import', root })

test('the gate of the Ringside package never runs ringside check; other packages still do', async () => {
  for (const root of ['/work/Ringside', 'C:\\work\\Ringside', '/work/ringside/']) {
    const { result, calls } = await run('ring-master-factory', baseArgs({ job: ringsideJob(root) }), responder())
    assert.equal(result.status, 'approved', root)
    const prompt = calls.find(c => c.label === 'gate r1').prompt
    assert.ok(prompt.includes(`Run the gate in /work/pkg--import: \`${cwdProof('/work/pkg--import', 'composer test')}\` and \`${cwdProof('/work/pkg--import', 'vendor/bin/pint --test')}\`.`), root)
    assert.doesNotMatch(prompt, /ringside check/, root)
  }
  for (const root of ['/work/pkg', '/work/Ringside-app', '/work/Keystone']) {
    const { calls } = await run('ring-master-factory', baseArgs({ job: ringsideJob(root) }), responder())
    assert.match(calls.find(c => c.label === 'gate r1').prompt, /`composer --working-dir='\/work\/pkg--import' exec ringside check`/, root)
  }
})

test('the variant gates of the Ringside package never run ringside check; other packages still do', async () => {
  const variants = { '^variant worktrees': { ready: true }, '^variant \\d$': vbuilt, '^gate v\\d$': gateFor(true), '^judge$': { winner: 1, reasons: ['first'] }, '^adopt': { merged: true } }
  const ringside = await run('ring-master-factory', variantArgs(2, { job: ringsideJob('/work/Ringside') }), responder(variants))
  assert.equal(ringside.result.status, 'approved')
  for (const label of ['gate v1', 'gate v2', 'gate r1']) {
    const prompt = ringside.calls.find(c => c.label === label).prompt
    assert.doesNotMatch(prompt, /ringside check/, label)
  }
  assert.ok(ringside.calls.find(c => c.label === 'gate v1').prompt.includes(`run \`${cwdProof('/work/pkg--import--v1', 'composer test')}\` and \`${cwdProof('/work/pkg--import--v1', 'vendor/bin/pint --test')}\`. `))
  const other = await run('ring-master-factory', variantArgs(2), responder(variants))
  assert.match(other.calls.find(c => c.label === 'gate v2').prompt, /`composer --working-dir='\/work\/pkg--import--v2' exec ringside check`/)
})

// --- A build agent that returns no result: salvage, then one retry of what is left ---

const recoverRoles = { implementer: { model: 'opus', effort: 'high' }, reworker: { model: 'sonnet', effort: 'medium' } }
const twoTasks = (extra = {}, done = false) => {
  const args = withRoles(recoverRoles, extra)
  args.waves = [[{ n: 1, name: 'Data', files: ['src/A.php', 'tests/ATest.php'], criteria: [1], tasks: [{ id: '1.1', text: 'tests', done }, { id: '1.2', text: 'code', done }] }]]
  return args
}
const salvaged = done => ({ done, files: ['src/A.php'], tests: 'green', deviations: [], decisions: [] })
const labels = calls => calls.map(c => c.label)
const ask = { question: 'Keep the old importer?', options: ['keep', 'drop'], recommendation: 'keep' }

test('a group that returns no result is salvaged on the reworker role, the rest retried, and the run reaches the gate', async () => {
  const { result, calls } = await run('ring-master-factory', twoTasks(), responder({
    '^group 1$': null,
    '^salvage g1$': salvaged(['1.1']),
    '^tasks g1$': { done: ['1.1'] },
  }))
  assert.equal(result.status, 'approved')
  assert.deepEqual(labels(calls).slice(0, 6), ['prepare', 'group 1', 'salvage g1', 'tasks g1', 'group 1 retry', 'gate r1'])
  const group = calls.find(c => c.label === 'group 1')
  const salvage = calls.find(c => c.label === 'salvage g1')
  assert.equal(salvage.opts.agentType, 'ring-master-implementer')
  assert.equal(salvage.opts.phase, 'Build')
  assert.equal(salvage.opts.model, 'sonnet', 'the salvage runs on the reworker role, not the implementer')
  assert.equal(salvage.opts.effort, 'medium')
  assert.equal(group.opts.model, 'opus')
  assert.deepEqual(salvage.opts.schema, group.opts.schema, 'the salvage returns BUILT')
  assert.deepEqual(salvage.opts.schema.required, ['done', 'decisions'])
  const tasks = calls.find(c => c.label === 'tasks g1')
  assert.equal(tasks.opts.model, 'haiku', 'the tasks.md cross-check is a read-only prepare call')
  assert.match(tasks.prompt, /\/work\/pkg\/docs\/agent\/specs\/2026-10-23-1430-import\/tasks\.md/)
  const retry = calls.find(c => c.label === 'group 1 retry')
  assert.equal(retry.opts.agentType, 'ring-master-implementer')
  assert.equal(retry.opts.model, 'opus')
  assert.match(retry.prompt, /Tasks: 1\.2 code\./, 'the non-replay retry lists only the remaining task')
  assert.doesNotMatch(retry.prompt, /1\.1 tests/)
})

test('a salvage or retry that returns no result escalates, with the null still in built', async () => {
  const twice = await run('ring-master-factory', twoTasks(), responder({
    '^group 1$': null,
    '^salvage g1$': salvaged([]),
    '^tasks g1$': { done: [] },
    '^group 1 retry$': null,
  }))
  assert.equal(twice.result.status, 'escalated')
  assert.equal(twice.result.stage, 'build')
  assert.deepEqual(twice.result.reasons, ['a build agent failed'])
  assert.deepEqual(twice.result.built, [null])
  assert.deepEqual(labels(twice.calls), ['prepare', 'group 1', 'salvage g1', 'tasks g1', 'group 1 retry'], 'no escalation before the salvage and the retry have returned')

  const lost = await run('ring-master-factory', twoTasks(), responder({ '^group 1$': null, '^salvage g1$': null }))
  assert.equal(lost.result.status, 'escalated')
  assert.deepEqual(lost.result.reasons, ['a build agent failed'])
  assert.deepEqual(lost.result.built, [null])
  assert.deepEqual(labels(lost.calls), ['prepare', 'group 1', 'salvage g1'])
})

test('a replay retry lists only the remaining ids and trusts the salvage, since tasks.md is never ticked', async () => {
  const args = twoTasks({ replay: true }, true)
  const { result, calls } = await run('ring-master-factory', args, responder({ '^group 1$': null, '^salvage g1$': salvaged(['1.1']) }))
  assert.equal(result.status, 'approved')
  assert.ok(!calls.some(c => c.label === 'tasks g1'), 'no tasks.md cross-check in a replay')
  const retry = calls.find(c => c.label === 'group 1 retry')
  assert.match(retry.prompt, /Tasks: 1\.2 code\./)
  assert.doesNotMatch(retry.prompt, /1\.1 tests/)
  const salvage = calls.find(c => c.label === 'salvage g1').prompt
  assert.match(salvage, /do not tick tasks\.md/)
  assert.match(salvage, /specs\/2026-10-23-1430-import\/replay\/build\.md/)
})

test('a bogus, empty or disputed done list retries every task not provably done', async () => {
  const retried = async overrides => {
    const { calls } = await run('ring-master-factory', twoTasks(), responder({ '^group 1$': null, ...overrides }))
    return calls.find(c => c.label === 'group 1 retry').prompt
  }
  const bogus = await retried({ '^salvage g1$': salvaged(['9.9', '1.1 tests']), '^tasks g1$': { done: [] } })
  assert.match(bogus, /Tasks: 1\.1 tests; 1\.2 code\./)
  const empty = await retried({ '^salvage g1$': salvaged([]), '^tasks g1$': null })
  assert.match(empty, /Tasks: 1\.1 tests; 1\.2 code\./)
  // The salvage claims both; tasks.md ticks only 1.1: the larger unfinished set wins.
  const disputed = await retried({ '^salvage g1$': salvaged(['1.1', '1.2']), '^tasks g1$': { done: ['1.1', '7.7'] } })
  assert.match(disputed, /Tasks: 1\.2 code\./)
  assert.doesNotMatch(disputed, /1\.1 tests/)
})

test('a salvage that leaves nothing to do is not retried', async () => {
  const { result, calls } = await run('ring-master-factory', twoTasks(), responder({
    '^group 1$': null,
    '^salvage g1$': salvaged(['1.1', '1.2']),
    '^tasks g1$': { done: ['1.1', '1.2'] },
  }))
  assert.equal(result.status, 'approved')
  assert.ok(!calls.some(c => c.label === 'group 1 retry'))
})

test('a build result that leaves tasks undone retries them without a salvage (D-003)', async () => {
  const { result, calls } = await run('ring-master-factory', twoTasks(), responder({
    '^group 1$': { ...built, done: ['1.1'] },
    '^tasks g1$': { done: ['1.1'] },
  }))
  assert.equal(result.status, 'approved')
  assert.ok(!calls.some(c => c.label.startsWith('salvage')))
  assert.match(calls.find(c => c.label === 'group 1 retry').prompt, /Tasks: 1\.2 code\./)
})

test('a salvage that finds a lock or a merge in progress returns blocked, and the job escalates with its reason', async () => {
  const { result, calls } = await run('ring-master-factory', twoTasks(), responder({
    '^group 1$': null,
    '^salvage g1$': { ...salvaged([]), blocked: 'index.lock is held by another git process' },
  }))
  assert.equal(result.status, 'escalated')
  assert.equal(result.stage, 'build')
  assert.deepEqual(result.reasons, ['index.lock is held by another git process'])
  assert.ok(!calls.some(c => c.label === 'group 1 retry' || c.label === 'tasks g1'))

  const retryBlocked = await run('ring-master-factory', twoTasks(), responder({
    '^group 1$': null,
    '^salvage g1$': salvaged([]),
    '^group 1 retry$': { ...salvaged([]), blocked: 'the API is missing' },
  }))
  assert.equal(retryBlocked.result.status, 'escalated')
  assert.deepEqual(retryBlocked.result.reasons, ['the API is missing'])
})

test('decisions from a salvage or a retry end the run as needs-decision', async () => {
  const fromSalvage = await run('ring-master-factory', twoTasks(), responder({ '^group 1$': null, '^salvage g1$': { ...salvaged([]), decisions: [ask] } }))
  assert.equal(fromSalvage.result.status, 'needs-decision')
  assert.equal(fromSalvage.result.stage, 'build')
  assert.deepEqual(fromSalvage.result.decisions.map(d => d.question), [ask.question])
  assert.ok(!fromSalvage.calls.some(c => c.label === 'group 1 retry'))
  assert.ok(!fromSalvage.calls.some(c => c.label.startsWith('gate')))

  const fromRetry = await run('ring-master-factory', twoTasks(), responder({
    '^group 1$': null,
    '^salvage g1$': salvaged(['1.1']),
    '^group 1 retry$': { ...built, done: [], decisions: [ask] },
  }))
  assert.equal(fromRetry.result.status, 'needs-decision')
  assert.deepEqual(fromRetry.result.decisions.map(d => d.question), [ask.question])
})

test('when every group of a wave returns no result, the job escalates straight away without a salvage', async () => {
  const { result, calls, logs } = await run('ring-master-factory', parallelArgs(), responder({
    '^group worktrees': { ready: true },
    '^group \\d$': null,
  }))
  assert.equal(result.status, 'escalated')
  assert.equal(result.stage, 'build')
  assert.deepEqual(result.reasons, ['a build agent failed'])
  assert.deepEqual(result.built, [null, null])
  assert.ok(!calls.some(c => c.label.startsWith('salvage') || c.label.endsWith('retry') || c.label.startsWith('integrate')))
  assert.ok(logs.some(l => /every group .* returned no result/i.test(l)))
})

test('the salvage prompt checks for locks, stays inside the group files, tests through composer and commits as salvaged', async () => {
  const { calls } = await run('ring-master-factory', twoTasks(), responder({ '^group 1$': null, '^salvage g1$': salvaged(['1.1', '1.2']), '^tasks g1$': { done: ['1.1', '1.2'] } }))
  const prompt = calls.find(c => c.label === 'salvage g1').prompt
  assert.ok(prompt.includes('.git/index.lock'), 'checks for an index lock')
  assert.match(prompt, /merge or rebase in progress/)
  assert.match(prompt, /stop and return blocked/)
  assert.match(prompt, /never force/)
  assert.ok(prompt.includes("`git -C /work/pkg--import status --porcelain -- 'src/A.php' 'tests/ATest.php'`"))
  assert.ok(prompt.includes("`git -C /work/pkg--import diff -- 'src/A.php' 'tests/ATest.php'`"))
  assert.match(prompt, /1\.1 tests; 1\.2 code/, 'compares the changes with the group tasks')
  assert.ok(prompt.includes('`composer --working-dir=/work/pkg--import exec pest`'))
  assert.ok(prompt.includes('"2026-10-23-1430-import: group 1 Data (salvaged)"'))
  assert.match(prompt, /on ring-master\/2026-10-23-1430-import with the message/)
  assert.ok(prompt.includes('tick only the finished tasks in /work/pkg/docs/agent/specs/2026-10-23-1430-import/tasks.md'))
  assert.ok(prompt.includes('"## Group 1 (salvaged)" note to /work/pkg/docs/agent/specs/2026-10-23-1430-import/build.md'))
  assert.match(prompt, /Return done with the ids of the finished tasks only/)
  assert.match(prompt, /Do not use blocked for unfinished tasks/)
})

test('a salvage in a parallel group worktree uses that tree and skips the test run the build skipped', async () => {
  const { result, calls } = await run('ring-master-factory', parallelArgs(), responder({
    '^group worktrees': { ready: true },
    '^group 1$': null,
    '^salvage g1$': salvaged([]),
    '^integrate': { merged: true, conflicts: [] },
  }))
  assert.equal(result.status, 'approved')
  const prompt = calls.find(c => c.label === 'salvage g1').prompt
  assert.ok(prompt.includes("`git -C /work/pkg--import--g1 status --porcelain -- 'src/A.php'`"))
  assert.ok(prompt.includes("`git -C /work/pkg--import--g1 diff -- 'src/A.php'`"))
  assert.match(prompt, /on ring-master\/2026-10-23-1430-import--g1 with the message/)
  assert.match(prompt, /Do not run the test suite; the gate runs it after the build/)
  assert.doesNotMatch(prompt, /composer --working-dir=\/work/)
  assert.doesNotMatch(prompt, /Run the test command/)
  assert.ok(!calls.some(c => c.label === 'group 1 retry' || c.label === 'tasks g1'), 'a group with no tasks left is not retried')
  assert.ok(calls.findIndex(c => c.label === 'salvage g1') < calls.findIndex(c => c.label === 'integrate w1'))
})

test('a variant that returns no result is salvaged in its own worktree and retried before it counts as failed', async () => {
  const args = variantArgs(2)
  args.config.roles.reworker = { model: 'sonnet', effort: 'medium' }
  const { result, calls } = await run('ring-master-factory', args, responder({
    '^variant worktrees': { ready: true },
    '^variant 1$': null,
    '^variant 2$': vbuilt,
    '^salvage v1$': salvaged(['1.1']),
    '^variant 1 retry$': { ...built, done: ['2.1'] },
    '^gate v\\d$': gateFor(true),
    '^judge$': { winner: 1, reasons: ['smaller diff'] },
    '^adopt': { merged: true },
  }))
  assert.equal(result.status, 'approved')
  assert.equal(result.variants_winner, 1)
  const salvage = calls.find(c => c.label === 'salvage v1')
  assert.equal(salvage.opts.agentType, 'ring-master-implementer')
  assert.equal(salvage.opts.phase, 'Variants')
  assert.equal(salvage.opts.model, 'sonnet', 'the variant salvage runs on the reworker role, not the variant model')
  assert.deepEqual(salvage.opts.schema.required, ['done', 'decisions'])
  assert.ok(salvage.prompt.includes('`git -C /work/pkg--import--v1 status --porcelain`'))
  assert.ok(salvage.prompt.includes('"2026-10-23-1430-import: variant 1 (salvaged)"'))
  assert.match(salvage.prompt, /Do not tick tasks\.md/)
  assert.ok(!calls.some(c => c.label === 'tasks v1'), 'variants never tick tasks.md, so there is no cross-check')
  const retry = calls.find(c => c.label === 'variant 1 retry')
  assert.match(retry.prompt, /Tasks, in this order: 2\.1 screen \(group 2: Screen\)\./)
  assert.doesNotMatch(retry.prompt, /1\.1 tests/)
  assert.ok(calls.indexOf(retry) < calls.findIndex(c => c.label === 'gate v1'))

  const failed = await run('ring-master-factory', variantArgs(2), responder({ '^variant worktrees': { ready: true }, '^variant \\d$': null, '^salvage v\\d$': null }))
  assert.equal(failed.result.status, 'escalated')
  assert.equal(failed.result.stage, 'variants')
  assert.deepEqual(labels(failed.calls).filter(l => l.startsWith('salvage')).sort(), ['salvage v1', 'salvage v2'])
})

test('a variant that returns a normal result is kept as it is, even with an empty done list: only a null one is recovered', async () => {
  const { result, calls } = await run('ring-master-factory', variantArgs(2), responder({
    '^variant worktrees': { ready: true },
    '^variant \\d$': { ...built, done: [] },
    '^gate v\\d$': gateFor(true),
    '^judge$': { winner: 2, reasons: ['smaller diff'] },
    '^adopt': { merged: true },
  }))
  assert.equal(result.status, 'approved')
  assert.equal(result.variants_winner, 2)
  assert.ok(!calls.some(c => /^salvage v\d$/.test(c.label)), 'no salvage for a variant that returned a result')
  assert.ok(!calls.some(c => /^variant \d retry$/.test(c.label)), 'no retry for a variant that returned a result')
  assert.ok(!calls.some(c => /^tasks v\d$/.test(c.label)))
  assert.deepEqual(labels(calls).filter(l => /^gate v\d$/.test(l)).sort(), ['gate v1', 'gate v2'])
})

test('a rework that returns no result is still ignored, with no salvage', async () => {
  const { result, calls } = await run('ring-master-factory', baseArgs(), responder({ '^code r1$': { findings: [major], decisions: [] }, '^rework r1$': null }))
  assert.equal(result.status, 'approved')
  assert.ok(calls.some(c => c.label === 'code r2'))
  assert.ok(!calls.some(c => c.label.startsWith('salvage')))
})

// --- cwd-proof ringside calls and the Prepare preflight ----------------------

const DIRTY_ASK = 'Commit or stash these main changes before the build?'
const REMOTE_ASK = 'No origin remote found. What is the remote URL for the PR?'
const NO_CD = 'never `cd` into the worktree first'
// The Prepare agent held back `worktrees --setup` and left the preflight to the script.
const held = (preflight = {}) => ({ ready: false, ...preflightClean, ...preflight })
// A job that opens a PR: not a replay, and the config names a host.
const prArgs = (extra = {}) => baseArgs({ ...extra, config: { host: { base: 'main', provider: 'github' } } })
// The inline code spans of a prompt, so assertions read commands, not prose.
const codeSpans = prompt => prompt.split('`').filter((_, i) => i % 2 === 1)
const ringsideCalls = prompt => codeSpans(prompt).filter(s => /\bringside\b/.test(s))
const RINGSIDE_LABELS = /^(prepare|prepare setup|variant worktrees \d+|adopt v\d+|group worktrees [\d,]+|integrate w\d+)$/

// Every kind of run that sends a Prepare, variant, group-worktree or removal prompt.
const everyRun = async () => [
  await run('ring-master-factory', baseArgs(), responder()),
  await run('ring-master-factory', baseArgs(), responder({ '^prepare$': held({ dirty: [' M README.md'] }) })),
  await run('ring-master-factory', variantArgs(2), responder({
    '^variant worktrees': { ready: true },
    '^variant \\d$': built,
    '^gate v\\d$': gateFor(true),
    '^judge$': { winner: 1, reasons: ['smaller diff'] },
    '^adopt': { merged: true },
  })),
  await run('ring-master-factory', parallelArgs(), responder({ '^group worktrees': { ready: true }, '^integrate': { merged: true, conflicts: [] } })),
  await run('ring-master-factory', workspaceArgs(), responder()),
  await run('ring-master-factory', baseArgs({ replay: true }), responder({ '^prepare$': { ready: true, worktree: '/work/pkg--import--replay' } })),
]

test('no prompt runs vendor/bin/ringside; every Prepare, variant, group-worktree and removal call goes through composer --working-dir, never after a cd', async () => {
  const runs = await everyRun()
  const calls = runs.flatMap(r => r.calls)
  for (const c of calls) assert.ok(!c.prompt.includes('vendor/bin/ringside'), `${c.label} runs vendor/bin/ringside`)
  const scoped = calls.filter(c => RINGSIDE_LABELS.test(c.label))
  for (const label of ['prepare', 'prepare setup', 'variant worktrees 2', 'adopt v1', 'group worktrees 1,2', 'integrate w1']) {
    assert.ok(scoped.some(c => c.label === label), `a ${label} prompt was sent`)
  }
  for (const c of scoped) {
    const uses = ringsideCalls(c.prompt)
    assert.ok(uses.length > 0, `${c.label} calls ringside`)
    for (const u of uses) assert.ok(u.startsWith('composer --working-dir=/work/pkg exec ringside -- '), `${c.label}: ${u}`)
    assert.ok(c.prompt.includes(NO_CD), `${c.label} tells the agent never to cd first`)
  }
  assert.match(calls.find(c => c.label === 'adopt v1').prompt, /`composer --working-dir=\/work\/pkg exec ringside -- worktrees 2026-10-23-1430-import --remove-variants`/)
  assert.match(calls.find(c => c.label === 'integrate w1').prompt, /`composer --working-dir=\/work\/pkg exec ringside -- worktrees 2026-10-23-1430-import --remove-groups`/)
})

test('the Prepare prompt runs the preflight as separate Bash calls ahead of worktrees --setup, and holds back --setup on dirt or no remote', async () => {
  const { calls } = await run('ring-master-factory', baseArgs(), responder())
  const prompt = calls.find(c => c.label === 'prepare').prompt
  const setup = prompt.indexOf('`composer --working-dir=/work/pkg exec ringside -- worktrees 2026-10-23-1430-import --setup`')
  assert.ok(setup > 0)
  for (const step of ['`git -C /work/pkg status --porcelain`', '`git -C /work/pkg remote get-url origin`', '/work/pkg/docs/agent/standards.index.yml', '/work/pkg/ring-master.yml']) {
    assert.ok(prompt.includes(step), step)
    assert.ok(prompt.indexOf(step) < setup, `${step} comes before worktrees --setup`)
  }
  assert.match(prompt, /each command as its own Bash call/)
  assert.match(prompt, /If dirty is non-empty or remote is false, return ready=false[^\n]*do not run `worktrees --setup`/)
  assert.match(prompt, /exactly as printed/)
})

test('the PREPARED schema accepts dirty, remote and check_applies, and a clean preflight builds', async () => {
  const { result, calls } = await run('ring-master-factory', baseArgs(), responder())
  const { properties } = calls.find(c => c.label === 'prepare').opts.schema
  assert.deepEqual(properties.dirty, { type: 'array', items: { type: 'string' } })
  assert.deepEqual(properties.remote, { type: 'boolean' })
  assert.deepEqual(properties.check_applies, { type: 'boolean' })
  assert.equal(result.status, 'approved')
  assert.deepEqual(calls.map(c => c.label).slice(0, 2), ['prepare', 'group 1'])
})

test('a replay has no preflight and is never stopped by one', async () => {
  const replayed = await run('ring-master-factory', prArgs({ replay: true }), responder({ '^prepare$': { ready: true, worktree: '/work/pkg--import--replay', dirty: [' M src/A.php'], remote: false } }))
  const prompt = replayed.calls.find(c => c.label === 'prepare').prompt
  assert.doesNotMatch(prompt, /status --porcelain/)
  assert.doesNotMatch(prompt, /remote get-url/)
  assert.doesNotMatch(prompt, /standards\.index\.yml/)
  assert.equal(replayed.result.status, 'approved')
  // A legacy result, without the three fields, reads as the safe values on a replay: ringside check still runs.
  const legacy = await run('ring-master-factory', baseArgs({ replay: true }), responder({ '^prepare$': { ready: true, worktree: '/work/pkg--import--replay' } }))
  assert.equal(legacy.result.status, 'approved')
  assert.match(legacy.calls.find(c => c.label === 'gate r1').prompt, /exec ringside check/)
})

const stopsOnDirty = async (lines, files, args = baseArgs()) => {
  const { result, calls } = await run('ring-master-factory', args, responder({ '^prepare$': held({ dirty: lines }) }))
  assert.equal(result.status, 'needs-decision')
  assert.equal(result.stage, 'prepare')
  assert.equal(result.decisions.length, 1)
  assert.equal(result.decisions[0].question, DIRTY_ASK)
  assert.deepEqual(result.dirty, files)
  for (const f of files) assert.ok(result.decisions[0].evidence.includes(f), `the ASK lists ${f}`)
  assert.deepEqual(calls.map(c => c.label), ['prepare'], 'no build or group agent call')
}

test('dirt on a file a task group lists stops at prepare with the dirty ASK and the files', async () => {
  await stopsOnDirty([' M src/A.php', '?? notes.txt'], ['src/A.php'])
  await stopsOnDirty(['MM src/A.php'], ['src/A.php'])
  await stopsOnDirty(['R  src/Old.php -> src/A.php'], ['src/A.php'])
})

test('an untracked directory that holds a listed file stops at prepare (directory prefix)', async () => {
  const args = baseArgs()
  args.waves[0][0].files = ['src/Import/Reader.php']
  await stopsOnDirty(['?? src/Import/'], ['src/Import/'], args)
  await stopsOnDirty(['?? src/'], ['src/'], args)
})

test('other jobs\' spec folders never stop a build: sessions write their reports into the main checkout', async () => {
  const dirty = [' M docs/agent/specs/2026-10-01-0900-other/spec.md', '?? docs/agent/specs/2026-10-02-1000-new/', ' M docs/agent/specs/2026-10-23-1430-import/meta.yml', '?? .ringside-pr-body.md']
  const { result, calls } = await run('ring-master-factory', baseArgs(), responder({ '^prepare$': held({ dirty }) }))
  assert.equal(result.status, 'approved')
  assert.ok(!calls.some(c => c.label === 'prepare' && result.decisions))
  assert.deepEqual(calls.map(c => c.label).slice(0, 3), ['prepare', 'prepare setup', 'group 1'])
})

test('dirt that matches no listed file, only the job\'s own spec folder, or a group with no files lets the build go ahead', async () => {
  const args = baseArgs()
  args.waves = [[
    { n: 1, name: 'Data', files: ['src/A.php'], criteria: [1], tasks: [{ id: '1.1', text: 'tests', done: false }] },
    { n: 2, name: 'Docs', files: [], criteria: [], tasks: [{ id: '2.1', text: 'docs', done: false }] },
  ]]
  const dirty = [' M docs/agent/specs/2026-10-23-1430-import/meta.yml', '?? docs/agent/specs/2026-10-23-1430-import/round-1/', ' M src/B.php', ' M docs/agent/tickets/T-002.md']
  const { result, calls } = await run('ring-master-factory', args, responder({ '^prepare$': held({ dirty }) }))
  assert.equal(result.status, 'approved')
  assert.deepEqual(calls.map(c => c.label).slice(0, 4), ['prepare', 'prepare setup', 'group 1', 'group 2'])
  const setup = calls.find(c => c.label === 'prepare setup').prompt
  assert.match(setup, /`composer --working-dir=\/work\/pkg exec ringside -- worktrees 2026-10-23-1430-import --setup`/)
  assert.doesNotMatch(setup, /status --porcelain/)
})

test('no origin remote stops at prepare with its ASK when the job opens a PR', async () => {
  const { result, calls } = await run('ring-master-factory', prArgs(), responder({ '^prepare$': held({ remote: false, detail: 'error: No such remote \'origin\'' }) }))
  assert.equal(result.status, 'needs-decision')
  assert.equal(result.stage, 'prepare')
  assert.equal(result.decisions.length, 1)
  assert.equal(result.decisions[0].question, REMOTE_ASK)
  assert.deepEqual(calls.map(c => c.label), ['prepare'], 'no build call')
})

test('a job that never opens a PR ignores a missing remote', async () => {
  const { result, calls } = await run('ring-master-factory', baseArgs(), responder({ '^prepare$': held({ remote: false }) }))
  assert.equal(result.status, 'approved')
  assert.deepEqual(calls.map(c => c.label).slice(0, 3), ['prepare', 'prepare setup', 'group 1'])
})

test('dirty main and no remote raise only the dirty ASK', async () => {
  const { result, calls } = await run('ring-master-factory', prArgs(), responder({ '^prepare$': held({ dirty: [' M src/A.php'], remote: false }) }))
  assert.equal(result.status, 'needs-decision')
  assert.deepEqual(result.decisions.map(d => d.question), [DIRTY_ASK])
  assert.deepEqual(calls.map(c => c.label), ['prepare'])
})

test('a non-replay Prepare result that omits a preflight field is a failed preflight', async () => {
  for (const omitted of [{ ready: true, worktree: '/work/pkg--import' }, { ready: true, worktree: '/work/pkg--import', dirty: [], remote: true }]) {
    const { result, calls } = await run('ring-master-factory', baseArgs(), responder({ '^prepare$': omitted }))
    assert.equal(result.status, 'needs-decision')
    assert.equal(result.stage, 'prepare')
    assert.equal(result.decisions.length, 1)
    assert.match(result.decisions[0].evidence, /check_applies/)
    assert.deepEqual(calls.map(c => c.label), ['prepare'], 'no build call')
  }
})

test('a Prepare that fails its setup with a clean preflight still escalates', async () => {
  const { result, calls } = await run('ring-master-factory', baseArgs(), responder({ '^prepare$': held({ detail: 'composer install failed' }) }))
  assert.equal(result.status, 'escalated')
  assert.equal(result.stage, 'prepare')
  assert.equal(result.detail, 'composer install failed')
  assert.deepEqual(calls.map(c => c.label), ['prepare'])
  const setupFails = await run('ring-master-factory', baseArgs(), responder({ '^prepare$': held({ dirty: ['?? notes.txt'] }), '^prepare setup$': { ready: false, detail: 'worktree add failed' } }))
  assert.equal(setupFails.result.status, 'escalated')
  assert.equal(setupFails.result.detail, 'worktree add failed')
})

const variantRun = prepare => run('ring-master-factory', variantArgs(2), responder({
  '^prepare$': prepare,
  '^variant worktrees': { ready: true },
  '^variant \\d$': built,
  '^gate v\\d$': gateFor(true),
  '^judge$': { winner: 1, reasons: ['smaller diff'] },
  '^adopt': { merged: true },
}))

test('check_applies false drops ringside check from both gate prompts and logs it', async () => {
  const { result, calls, logs } = await variantRun({ ready: true, worktree: '/work/pkg--import', ...preflightClean, check_applies: false, detail: 'missing ring-master.yml' })
  assert.equal(result.status, 'approved')
  for (const label of ['gate v1', 'gate v2', 'gate r1']) assert.doesNotMatch(calls.find(c => c.label === label).prompt, /ringside check/, label)
  assert.ok(logs.some(l => /ringside check/.test(l) && /dropped/.test(l) && /ring-master\.yml/.test(l)), logs.join('\n'))
  const single = await run('ring-master-factory', baseArgs(), responder({ '^prepare$': { ready: true, worktree: '/work/pkg--import', ...preflightClean, check_applies: false } }))
  assert.doesNotMatch(single.calls.find(c => c.label === 'gate r1').prompt, /ringside check/)
  assert.ok(single.logs.some(l => /ringside check/.test(l) && /dropped/.test(l)))
})

test('a clean preflight keeps ringside check in both gate prompts', async () => {
  const { calls, logs } = await variantRun({ ready: true, worktree: '/work/pkg--import', ...preflightClean })
  assert.match(calls.find(c => c.label === 'gate v1').prompt, /`composer --working-dir='\/work\/pkg--import--v1' exec ringside check`/)
  assert.match(calls.find(c => c.label === 'gate r1').prompt, /`composer --working-dir='\/work\/pkg--import' exec ringside check`/)
  assert.ok(!logs.some(l => /dropped/.test(l)))
})

test('a workspace job runs the preflight in each package folder and aggregates the results', async () => {
  const { calls } = await run('ring-master-factory', workspaceArgs(), responder())
  const prompt = calls.find(c => c.label === 'prepare').prompt
  const setup = prompt.indexOf('worktrees 2026-10-23-1430-import --setup')
  for (const p of ['Keystone', 'Atrium']) {
    for (const step of [`\`git -C /work/pkg/${p} status --porcelain\``, `\`git -C /work/pkg/${p} remote get-url origin\``, `/work/pkg/${p}/ring-master.yml`, `/work/pkg/${p}/docs/agent/standards.index.yml`]) {
      assert.ok(prompt.includes(step), step)
      assert.ok(prompt.indexOf(step) < setup, `${step} comes before worktrees --setup`)
    }
  }
  assert.match(prompt, /prefixed with its package folder and a colon/)
  // Each package's dirt is matched against that package's groups only.
  await stopsOnDirty(['Keystone: M src/Importer.php', 'Atrium:?? scratch.txt', 'Atrium: M src/Importer.php'], ['Keystone/src/Importer.php'], workspaceArgs())
  const unrelated = await run('ring-master-factory', workspaceArgs(), responder({ '^prepare$': held({ dirty: ['Atrium: M src/Importer.php'] }) }))
  assert.equal(unrelated.result.status, 'approved')
  const pr = workspaceArgs()
  pr.config.host = { base: 'main', provider: 'github' }
  const noRemote = await run('ring-master-factory', pr, responder({ '^prepare$': held({ remote: false, detail: 'Atrium has no origin remote' }) }))
  assert.deepEqual(noRemote.result.decisions.map(d => d.question), [REMOTE_ASK])
  assert.deepEqual(noRemote.calls.map(c => c.label), ['prepare'])
})

// cwd-proof gate commands (resources/workflows/gate-command.js, D-007, D-008, D-010, D-011).
const tree = '/work/pkg--import'
const wd = "--working-dir='/work/pkg--import'"

test('cwdProof injects --working-dir into a composer script', () => {
  assert.equal(cwdProof(tree, 'composer test'), `composer ${wd} test`)
  assert.equal(cwdProof(tree, 'composer lint:check'), `composer ${wd} lint:check`)
  assert.equal(cwdProof(tree, 'composer --no-interaction test:unit'), `composer ${wd} --no-interaction test:unit`)
})

test('cwdProof runs a vendor/bin binary through composer exec, passing its options after --', () => {
  assert.equal(cwdProof(tree, 'vendor/bin/pest'), `composer ${wd} exec -- pest`)
  assert.equal(cwdProof(tree, 'vendor/bin/pint --test'), `composer ${wd} exec -- pint --test`)
  assert.equal(cwdProof(tree, 'vendor/bin/pest -d memory_limit=1G --filter=Gate'), `composer ${wd} exec -- pest -d memory_limit=1G --filter=Gate`)
})

test('cwdProof passes any other command through unchanged', () => {
  for (const cmd of ['node --test tests/workflows/factory.test.mjs', 'npm test', './vendor/bin/pest', 'composer']) {
    assert.equal(cwdProof(tree, cmd), cmd)
  }
  assert.equal(cwdProof(tree, undefined), undefined)
  assert.equal(cwdProof(tree, ''), '')
})

test('cwdProof is idempotent: already-rewritten input comes back unchanged', () => {
  const inputs = ['composer test', 'vendor/bin/pint --test', 'node --test a.test.mjs', 'composer analyse && vendor/bin/pest']
  for (const cmd of inputs) {
    const once = cwdProof(tree, cmd)
    assert.equal(cwdProof(tree, once), once)
    assert.equal(cwdProof('/some/other tree', once), once)
  }
  assert.equal(cwdProof(tree, 'composer --working-dir=/elsewhere test'), 'composer --working-dir=/elsewhere test')
})

test('cwdProof single-quotes the tree path, escaping quotes the POSIX way', () => {
  assert.equal(cwdProof('/work/my pkg', 'composer test'), "composer --working-dir='/work/my pkg' test")
  assert.equal(cwdProof("/work/it's here", 'vendor/bin/pest'), "composer --working-dir='/work/it'\\''s here' exec -- pest")
  assert.equal(cwdProof('/work/"q" $HOME', 'composer test'), "composer --working-dir='/work/\"q\" $HOME' test")
})

test('cwdProof rewrites each &&, ; and | segment of a compound command by the same rule', () => {
  assert.equal(
    cwdProof(tree, 'composer lint:check && vendor/bin/pest; vendor/bin/phpstan analyse | tee out.txt'),
    `composer ${wd} lint:check && composer ${wd} exec -- pest; composer ${wd} exec -- phpstan analyse | tee out.txt`,
  )
  assert.equal(cwdProof(tree, 'vendor/bin/pest || composer test'), `composer ${wd} exec -- pest || composer ${wd} test`)
  // A separator inside quotes does not start a segment.
  assert.equal(cwdProof(tree, 'node -e "a; vendor/bin/pest"'), 'node -e "a; vendor/bin/pest"')
})

test('cwdProof leaves an env-prefixed command unchanged', () => {
  for (const cmd of ['XDEBUG_MODE=off composer test', 'APP_ENV=testing vendor/bin/pest']) {
    assert.equal(cwdProof(tree, cmd), cmd)
  }
  assert.equal(cwdProof(tree, 'XDEBUG_MODE=off composer test && vendor/bin/pint --test'), `XDEBUG_MODE=off composer test && composer ${wd} exec -- pint --test`)
})

test('cwdProof leaves a composer command that already names a working dir unchanged', () => {
  for (const cmd of ['composer -d /other test', 'composer -d/other test', 'composer --working-dir /other test', 'composer --working-dir=/other test']) {
    assert.equal(cwdProof(tree, cmd), cmd)
  }
  for (const cmd of ['composer -d./rel test', 'composer -d../rel test', 'composer -d~/pkg test', "composer -d'/my pkg' test", 'composer -d"/my pkg" test', 'composer -dsub/pkg test', 'composer test -d /other']) {
    assert.equal(cwdProof(tree, cmd), cmd)
  }
})

test('cwdProof counts only -d or -d followed by a path as a working dir, and nothing after --', () => {
  // An option that merely starts with -d is not a working dir.
  assert.equal(cwdProof(tree, 'composer test -dev'), `composer ${wd} test -dev`)
  assert.equal(cwdProof(tree, 'composer show -direct'), `composer ${wd} show -direct`)
  // After a bare --, arguments belong to the script, not to Composer.
  assert.equal(cwdProof(tree, 'composer test -- -d memory_limit=1G'), `composer ${wd} test -- -d memory_limit=1G`)
  assert.equal(cwdProof(tree, 'composer test -- -d/tmp'), `composer ${wd} test -- -d/tmp`)
  assert.equal(cwdProof(tree, 'composer test -- --working-dir /tmp'), `composer ${wd} test -- --working-dir /tmp`)
})

// --- not_run: commands the gate could not run (AC1, AC3, AC5, AC6, AC7, AC9, AC10) ---

const binCommands = { test: 'vendor/bin/pest', verify: 'vendor/bin/pest', format_check: 'vendor/bin/pint --test' }
const refused = (command = `composer ${wd} exec -- pest`, reason = 'runner refused: not in allowlist') => ({ command, reason })
const unrunGate = (entries, extra = {}) => ({ passed: false, findings: [], changed_files: [], not_run: entries, ...extra })
const redGate = { passed: false, findings: [{ severity: 'blocker', summary: '2 tests fail' }], changed_files: [] }

// An escalation at stage gate: the escalating round ran no agent after its gate, so no rework, review or commit (AC5, AC6).
function assertGateEscalation({ result, calls }, reasons, { round = 1, redRounds = 0 } = {}) {
  assert.equal(result.status, 'escalated')
  assert.equal(result.stage, 'gate')
  assert.deepEqual(result.reasons, reasons)
  assert.equal(result.gate_red_rounds, redRounds)
  assert.ok(!result.reasons.some(r => /gate red \d+ rounds/.test(r)), 'no red-round reason')
  assert.ok(!result.reasons.some(r => r.includes('undefined')), 'no undefined in reasons')
  assert.ok(!calls.some(c => c.label === `rework r${round}`), 'no rework in the escalating round')
  const lastGate = calls.map(c => c.label).findLastIndex(l => l.startsWith('gate'))
  assert.deepEqual(calls.slice(lastGate + 1).map(c => c.label), [], 'no agent (rework, review, commit) after the escalating gate')
  assert.ok(!calls.some(c => /^(adopt|integrate|brief)/.test(c.label)), 'nothing commits or briefs')
}

test('a gate that could not run a command escalates at stage gate in round 1, with no rework and no red round (9a)', async () => {
  const r = await run('ring-master-factory', baseArgs(), responder({ '^gate r1$': unrunGate([refused()]) }))
  assertGateEscalation(r, [`gate could not run: composer ${wd} exec -- pest: runner refused: not in allowlist`])
  assert.equal(r.result.round, 1)
  assert.ok(!r.calls.some(c => /^(code|security) r1$/.test(c.label)), 'no reviewer runs')
})

test('a workspace package that could not run a command escalates the job, its package name in the reason (9b)', async () => {
  const r = await run('ring-master-factory', workspaceArgs(), responder({ '^gate Atrium r1$': unrunGate([refused('composer --working-dir=/tmp/t exec pest')]) }))
  assertGateEscalation(r, ['gate could not run: Atrium: composer --working-dir=/tmp/t exec pest: runner refused: not in allowlist'])
})

test('workspace not_run reasons keep package order, one per entry, the reason verbatim after the second colon', async () => {
  const r = await run('ring-master-factory', workspaceArgs(), responder({
    '^gate Keystone r1$': unrunGate([refused('composer test', 'refused: rule 3: no composer')]),
    '^gate Atrium r1$': unrunGate([refused('composer analyse', 'one'), refused('composer lint:check', 'two')]),
  }))
  assertGateEscalation(r, [
    'gate could not run: Keystone: composer test: refused: rule 3: no composer',
    'gate could not run: Atrium: composer analyse: one',
    'gate could not run: Atrium: composer lint:check: two',
  ])
})

test('a gate that ran everything and failed still counts a red round and reworks (9c, AC7)', async () => {
  let gates = 0
  const { result, calls } = await run('ring-master-factory', baseArgs(), responder({ '^gate': () => (++gates === 1 ? redGate : green) }))
  assert.equal(result.status, 'approved')
  assert.equal(result.gate_red_rounds, 1)
  assert.ok(calls.some(c => c.label === 'rework r1'))
})

test('an empty not_run never escalates: a red gate reworks, a green one passes (9d, AC7)', async () => {
  let gates = 0
  const red = await run('ring-master-factory', baseArgs(), responder({ '^gate': () => (++gates === 1 ? { ...redGate, not_run: [] } : { ...green, not_run: [] }) }))
  assert.equal(red.result.status, 'approved')
  assert.equal(red.result.gate_red_rounds, 1)
  assert.ok(red.calls.some(c => c.label === 'rework r1'))
  const clean = await run('ring-master-factory', baseArgs(), responder({ '^gate': { ...green, not_run: [] } }))
  assert.equal(clean.result.status, 'approved')
  assert.equal(clean.result.gate_red_rounds, 0)
})

test('real findings with a non-empty not_run escalate at stage gate: not_run wins and no rework runs (9e, D-004)', async () => {
  const r = await run('ring-master-factory', baseArgs(), responder({ '^gate r1$': unrunGate([refused()], { findings: redGate.findings }) }))
  assertGateEscalation(r, [`gate could not run: composer ${wd} exec -- pest: runner refused: not in allowlist`])
})

test('passed: true with a non-empty not_run escalates like any other not_run (9f)', async () => {
  const r = await run('ring-master-factory', baseArgs(), responder({ '^gate r1$': { ...green, not_run: [refused()] } }))
  assertGateEscalation(r, [`gate could not run: composer ${wd} exec -- pest: runner refused: not in allowlist`])
  assert.ok(!r.calls.some(c => /^(code|security) r1$/.test(c.label)), 'no reviewer runs')
})

test('not_run in round 2 after a red round escalates with gate_red_rounds left where it was (9g, AC6)', async () => {
  let gates = 0
  const r = await run('ring-master-factory', baseArgs(), responder({ '^gate': () => (++gates === 1 ? redGate : unrunGate([refused()])) }))
  assertGateEscalation(r, [`gate could not run: composer ${wd} exec -- pest: runner refused: not in allowlist`], { round: 2, redRounds: 1 })
  assert.equal(r.result.round, 2)
  assert.deepEqual(r.calls.filter(c => c.label.startsWith('rework')).map(c => c.label), ['rework r1'], 'only the red round reworked')
})

test('a variant whose gate could not run is disqualified; the other proceeds without a judge (9h, D-003)', async () => {
  const { result, calls, logs } = await run('ring-master-factory', variantArgs(2), responder({
    '^variant worktrees': { ready: true },
    '^variant \\d$': built,
    '^gate v1$': { ...gateFor(true), not_run: [refused('composer test')] },
    '^gate v2$': gateFor(true),
    '^adopt': { merged: true },
  }))
  assert.equal(result.status, 'approved')
  assert.equal(result.variants_winner, 2)
  assert.ok(!calls.some(c => c.label === 'judge'), 'one eligible variant: the judge is skipped')
  assert.ok(calls.some(c => c.label === 'adopt v2'))
  assert.ok(logs.some(l => /Variant 1 disqualified/.test(l)))
})

test('when every variant could not run a command, the job escalates at stage gate, never reaching the fallback or the judge (9i)', async () => {
  const r = await run('ring-master-factory', variantArgs(2), responder({
    '^variant worktrees': { ready: true },
    '^variant \\d$': built,
    '^gate v1$': { ...gateFor(true), not_run: [refused('composer test', 'refused one')] },
    '^gate v2$': { ...gateFor(false), not_run: [refused('composer lint:check', 'refused: two')] },
  }))
  assertGateEscalation(r, ['gate could not run: variant 1: composer test: refused one', 'gate could not run: variant 2: composer lint:check: refused: two'])
  assert.equal(r.result.variants_winner, null)
  assert.ok(!r.calls.some(c => c.label === 'judge'))
})

test('no eligible variant with pending asks still escalates at stage gate, reasons first, the asks carried (9i, D-009)', async () => {
  const ask = { question: 'One row per file or per line?', options: ['file', 'line'], recommendation: 'file' }
  const r = await run('ring-master-factory', variantArgs(2), responder({
    '^variant worktrees': { ready: true },
    '^variant 1$': built,
    '^variant 2$': { ...built, decisions: [ask] },
    '^gate v1$': { ...gateFor(true), not_run: [refused('composer test', 'refused one')] },
  }))
  assertGateEscalation(r, ['gate could not run: variant 1: composer test: refused one'])
  assert.deepEqual(r.result.decisions, [ask])
  assert.ok(!r.calls.some(c => c.label === 'judge' || c.label === 'gate v2'))
})

test('a gate without not_run, with not_run: null, or a null gate behaves as before (9j, AC1)', async () => {
  const without = await run('ring-master-factory', baseArgs(), responder())
  assert.equal(without.result.status, 'approved')
  assert.equal(without.result.gate_red_rounds, 0)

  let gates = 0
  const nulled = await run('ring-master-factory', baseArgs(), responder({ '^gate': () => (++gates === 1 ? { ...redGate, not_run: null } : { ...green, not_run: null }) }))
  assert.equal(nulled.result.status, 'approved')
  assert.equal(nulled.result.gate_red_rounds, 1)
  assert.ok(nulled.calls.some(c => c.label === 'rework r1'))

  let tries = 0
  const dead = await run('ring-master-factory', baseArgs(), responder({ '^gate': () => (++tries === 1 ? null : green) }))
  assert.equal(dead.result.status, 'approved')
  assert.equal(dead.result.gate_red_rounds, 1, 'a null gate still counts red')
  assert.match(dead.calls.find(c => c.label === 'rework r1').prompt, /gate agent failed/)

  const deadPackage = await run('ring-master-factory', workspaceArgs(), responder({ '^gate Atrium r1$': null }))
  assert.equal(deadPackage.result.status, 'approved')
  assert.equal(deadPackage.result.gate_red_rounds, 1)
})

test('a malformed not_run entry is coerced: no undefined in the reasons, each reason cut to 500 characters (9j, AC1)', async () => {
  const r = await run('ring-master-factory', baseArgs(), responder({
    '^gate r1$': unrunGate([{ reason: 42 }, { command: 'composer test' }, { command: 'composer analyse', reason: { code: 1 } }, { command: 'composer lint:check', reason: 'x'.repeat(600) }]),
  }))
  assertGateEscalation(r, [
    'gate could not run: : 42',
    'gate could not run: composer test: ',
    'gate could not run: composer analyse: [object Object]',
    `gate could not run: composer lint:check: ${'x'.repeat(500)}`,
  ])
})

// AC3: every gate prompt names its checkout through composer and says when a command is not_run.
function assertGatePrompt(prompt, gateTree) {
  for (const phrase of ['composer --working-dir=', 'not_run', 'did not start', 'exact refusal text']) assert.ok(prompt.includes(phrase), `gate prompt has "${phrase}"`)
  assert.ok(!prompt.includes('vendor/bin/'), 'no gate prompt runs vendor/bin/ by path')
  assert.ok(prompt.includes(`composer --working-dir='${gateTree}' exec -- pest`), 'the configured vendor/bin/pest is rewritten')
  assert.ok(prompt.includes(`composer --working-dir='${gateTree}' exec -- pint --test`), 'the format check is rewritten')
  assert.match(prompt, /never not_run/)
  const common = prompt.slice(0, prompt.indexOf('before you report a failure.'))
  assert.ok(common.startsWith('You are working on the Ring Master job'))
  assert.ok(!common.includes('vendor/bin/'), 'the shared common text has no vendor/bin/')
}

test('the single-package gate prompt rewrites a vendor/bin verify command and states the not_run rule (AC3)', async () => {
  const { calls } = await run('ring-master-factory', baseArgs({ config: { commands: binCommands } }), responder())
  const prompt = calls.find(c => c.label === 'gate r1').prompt
  assertGatePrompt(prompt, '/work/pkg--import')
  assert.ok(prompt.includes(`composer ${wd} exec ringside check`))
})

test('every workspace per-package gate prompt rewrites its vendor/bin verify command and states the not_run rule (AC3)', async () => {
  const args = workspaceArgs()
  for (const p of Object.values(args.workspace.packages)) p.commands = { ...binCommands }
  const { calls } = await run('ring-master-factory', args, responder())
  for (const p of ['Keystone', 'Atrium']) assertGatePrompt(calls.find(c => c.label === `gate ${p} r1`).prompt, `/work/pkg/.ring-master/jobs/2026-10-23-1430-import/${p}`)
})

test('every variant gate prompt rewrites its vendor/bin verify command and states the not_run rule (AC3)', async () => {
  const { calls } = await run('ring-master-factory', variantArgs(2, { config: { commands: binCommands } }), responder({
    '^variant worktrees': { ready: true },
    '^variant \\d$': built,
    '^gate v\\d$': gateFor(true),
    '^judge$': { winner: 1, reasons: ['first'] },
    '^adopt': { merged: true },
  }))
  for (const n of [1, 2]) {
    const prompt = calls.find(c => c.label === `gate v${n}`).prompt
    assertGatePrompt(prompt, `/work/pkg--import--v${n}`)
    assert.ok(prompt.includes(`composer --working-dir='/work/pkg--import--v${n}' exec ringside check`))
  }
})

// AC8: the factory skill tells whoever runs the factory about the not_run channel and its escalation.
test('the factory skill documents the not_run channel and the gate could not run: reasons (AC8)', () => {
  const skill = readFileSync(new URL('../../resources/skills/workflow/factory.md', import.meta.url), 'utf8')
  assert.ok(skill.includes('not_run'), 'factory.md names the not_run channel')
  assert.ok(skill.includes('gate could not run:'), 'factory.md names the gate could not run: reason prefix')
})
