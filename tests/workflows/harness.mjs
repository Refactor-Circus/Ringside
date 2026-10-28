// Runs a shipped workflow script against scripted agents, outside Claude Code.
// The runtime's globals (agent, parallel, pipeline, phase, log, args) are injected.
import { readFileSync } from 'node:fs'
import { fileURLToPath } from 'node:url'
import { dirname, join } from 'node:path'

const root = join(dirname(fileURLToPath(import.meta.url)), '..', '..')
const AsyncFunction = Object.getPrototypeOf(async function () {}).constructor

export async function run(name, args, respond) {
  const source = readFileSync(join(root, 'resources', 'workflows', `${name}.js`), 'utf8')
  const body = source.replace(/^export const meta = /m, 'const meta = ')
  const calls = []
  const phases = []
  const logs = []
  const agent = async (prompt, opts = {}) => {
    const call = { prompt, opts, label: opts.label || '' }
    calls.push(call)
    return respond(call, calls)
  }
  const parallel = thunks => Promise.all(thunks.map(t => t().catch(() => null)))
  const pipeline = (items, ...stages) => Promise.all(items.map(async (item, i) => {
    let value = item
    for (const stage of stages) {
      try { value = await stage(value, item, i) } catch { return null }
    }
    return value
  }))
  const fn = new AsyncFunction('agent', 'parallel', 'pipeline', 'phase', 'log', 'args', body)
  const result = await fn(agent, parallel, pipeline, t => phases.push(t), m => logs.push(m), structuredClone(args))
  return { result, calls, phases, logs }
}

export function baseArgs(overrides = {}) {
  const config = {
    path: 'docs/agent',
    commands: { test: 'vendor/bin/pest', verify: 'composer test', format_check: 'vendor/bin/pint --test' },
    standards: { global: ['acme/standards'] },
    host: { base: 'main' },
    stages: { spec_review: { enabled: true, critics: ['scope', 'risk', 'testability'], rounds: 2 } },
    roles: { prepare: { model: 'haiku' }, reviewer: { model: 'sonnet' }, triage: { enabled: false }, approver: { enabled: false } },
    review: { rounds: { max: 3, min: 1 }, rework_threshold: 'minor', stop_when_no_progress: 2, gate: { run_first: true, max_consecutive_red: 3, criteria_mapping: true } },
    escalation: { architectural_paths: ['composer.json', 'config/**', 'database/migrations/**', '**/Contracts/**'], fix_others_first: true },
    build: { parallel: false, max_concurrency: 2, worktree: { mode: 'per-job', setup: 'composer install' } },
    discovery: { global: { mode: 'queue', min_evidence: 1 } },
    ...overrides.config,
  }
  return {
    // Absolute folder of the shipped workflow scripts, as `ringside job --json` passes it (D-013).
    workflows_dir: join(root, 'resources', 'workflows'),
    spec: { id: '2026-10-23-1430-import', dir: 'docs/agent/specs/2026-10-23-1430-import', title: 'Import', standards: [] },
    job: { branch: 'ring-master/2026-10-23-1430-import', worktree: '../pkg--2026-10-23-1430-import', root: '/work/pkg' },
    waves: [[{ n: 1, name: 'Data', files: ['src/A.php'], criteria: [1], tasks: [{ id: '1.1', text: 'tests', done: false }] }]],
    decisions: [],
    index: { 'architecture/naming': { path: 'vendor/acme/standards/standards/architecture/naming.md', kind: 'architectural' } },
    reviewers: [{ name: 'code' }, { name: 'security' }],
    ...overrides,
    config,
  }
}

export const clean = { findings: [], decisions: [] }
export const built = { done: ['1.1'], files: ['src/A.php'], tests: 'green', deviations: [], decisions: [] }
export const green = { passed: true, findings: [], changed_files: ['src/A.php'], criteria: [] }
