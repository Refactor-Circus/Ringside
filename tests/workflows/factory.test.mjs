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
  assert.deepEqual(calls.map(c => c.label), ['prepare', 'group 1', 'gate r1', 'code r1', 'security r1'])
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
