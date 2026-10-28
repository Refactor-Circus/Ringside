export const meta = {
  name: 'gate-command',
  description: 'Helper module, not a workflow to launch: rewrites a gate command so it runs in the job tree whatever the cwd (composer --working-dir)',
}

// The gate runs from the main checkout, and a bare `vendor/bin/pest` there
// silently tests the wrong tree. cwdProof(tree, command) rewrites a command
// to name the tree explicitly (D-007, D-008, D-011):
//
// - `vendor/bin/<x> args` becomes `composer --working-dir='<tree>' exec -- <x> args`
//   (the `--` hands options such as `--test` to the binary; without it
//   Composer reads them itself and refuses the ones it does not know);
// - `composer args` becomes `composer --working-dir='<tree>' args`;
// - anything else (`node --test ...`) passes through unchanged.
//
// Each `&&`, `||`, `;` and `|` segment is rewritten on its own. A segment with
// an env prefix (`ENV=1 composer ...`) or a composer segment that already
// names a working dir (`-d`, `--working-dir`) is left as it is. A command that
// already contains `--working-dir=` comes back unchanged, so the rewrite is
// idempotent. The tree is POSIX single-quoted (`'` becomes `'\''`).
// Pure and self-contained: no imports, no globals.

const WORKING_DIR = '--working-dir='
const ENV_PREFIX = /^[A-Za-z_][A-Za-z0-9_]*=/

export function cwdProof(tree, command) {
  if (typeof command !== 'string' || command.trim() === '' || command.includes(WORKING_DIR)) {
    return command
  }

  const quoted = `'${String(tree).replace(/'/g, `'\\''`)}'`

  return split(command)
    .map((part, i) => (i % 2 === 1 ? part : rewrite(part, quoted)))
    .join('')
}

function rewrite(segment, quoted) {
  const [, lead, core, trail] = segment.match(/^(\s*)(.*?)(\s*)$/s)

  if (core === '' || ENV_PREFIX.test(core)) {
    return segment
  }

  const binary = core.match(/^vendor\/bin\/(\S+)(.*)$/s)

  if (binary) {
    return `${lead}composer --working-dir=${quoted} exec -- ${binary[1]}${binary[2]}${trail}`
  }

  const composer = core.match(/^composer(\s+)(.*)$/s)

  if (composer && !namesWorkingDir(composer[2])) {
    return `${lead}composer --working-dir=${quoted}${composer[1]}${composer[2]}${trail}`
  }

  return segment
}

// `-d <dir>`, `-d<dir>`, `--working-dir <dir>` or `--working-dir=<dir>`.
function namesWorkingDir(args) {
  return args.split(/\s+/).some(t => t === '--working-dir' || t.startsWith(WORKING_DIR) || /^-d/.test(t))
}

// Splits a command at top-level `&&`, `||`, `;` and `|`, ignoring separators
// inside quotes. Returns [segment, separator, segment, ...], so joining the
// parts gives back the input exactly.
function split(command) {
  const parts = []
  let current = ''
  let quote = null

  for (let i = 0; i < command.length; i++) {
    const c = command[i]

    if (quote) {
      current += c
      if (c === '\\' && quote === '"' && i + 1 < command.length) {
        current += command[++i]
      } else if (c === quote) {
        quote = null
      }
      continue
    }

    if (c === '\\' && i + 1 < command.length) {
      current += c + command[++i]
      continue
    }

    if (c === "'" || c === '"') {
      quote = c
      current += c
      continue
    }

    const pair = command.slice(i, i + 2)
    const separator = pair === '&&' || pair === '||' ? pair : (c === ';' || c === '|' ? c : null)

    if (separator) {
      parts.push(current, separator)
      current = ''
      i += separator.length - 1
      continue
    }

    current += c
  }

  parts.push(current)

  return parts
}
