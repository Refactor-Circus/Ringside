import { test } from 'node:test'
import assert from 'node:assert/strict'
import { run, baseArgs } from './harness.mjs'

test('spec review returns design questions and fixes the rest', async () => {
  const { result, calls } = await run('ring-master-spec-review', baseArgs(), call => {
    if (call.label === 'scope r1') return { findings: [{ severity: 'minor', summary: 'criterion 3 is vague', fix: 'name the format' }], decisions: [{ question: 'Sync or queued import?', options: ['sync', 'queued'], recommendation: 'queued' }] }
    return { findings: [], decisions: [] }
  })
  assert.equal(result.status, 'needs-decision')
  assert.equal(result.decisions[0].question, 'Sync or queued import?')
  assert.ok(calls.some(c => c.label === 'revise r1'))
})

test('spec review stops when only nits remain', async () => {
  const { result, calls } = await run('ring-master-spec-review', baseArgs(), () => ({ findings: [{ severity: 'nit', summary: 'typo' }], decisions: [] }))
  assert.equal(result.status, 'reviewed')
  assert.ok(!calls.some(c => c.label.startsWith('revise')))
})

const analysis = { metrics: { rounds: 2 }, root_causes: [], human_review_delta: ['missed N+1 query'] }
const proposals = {
  proposals: [
    { key: 'models/eager-loading', kind: 'architectural', action: 'add', text: '# Eager loading', rationale: 'r', evidence: ['PR comment'] },
    { key: 'testing/fixtures', kind: 'convention', action: 'add', text: '# Fixtures', rationale: 'r', evidence: ['round-1'] },
  ],
}
const respond = call => (call.label === 'analyze' ? analysis : proposals)

test('post-mortem routes architectural proposals to the global standards and the rest to the package', async () => {
  const { result } = await run('ring-master-post-mortem', baseArgs(), respond)
  assert.deepEqual(result.proposals.map(p => [p.key, p.target]), [['models/eager-loading', 'global'], ['testing/fixtures', 'project']])
})

test('without global standards every proposal stays in the package, marked could_be_global', async () => {
  const args = baseArgs({ config: { standards: { global: [] } } })
  const { result } = await run('ring-master-post-mortem', args, respond)
  assert.deepEqual(result.proposals.map(p => [p.target, p.could_be_global]), [['project', true], ['project', false]])
})
