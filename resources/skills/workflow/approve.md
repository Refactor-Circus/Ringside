---
name: ring-master-approve
description: Accept an escalated Ring Master job as it stands and open its PR.
disable-model-invocation: true
argument-hint: "[spec id]"
---
{{marker}}

# Approve an escalated job

1. Pick the spec: `$ARGUMENTS`, or the newest `escalated` spec. Show the reasons and open findings from `decision.md`, and confirm the user accepts them as they stand (skip the confirmation when the user already chose "Approve as is" in RingMaster).
2. Draft the PR as `/ring-master-factory` does, and list the accepted findings under "Known issues" in the body.
3. Push and open the PR with the host adapter; link the ticket. Set `status: in-review` and `pr:` in `meta.yml`.

{{host_adapter}}
