# ADR-0011 — Irreversible form deletion in bounded jobs

Status: Accepted

## Decision

Trash remains reversible. Permanent deletion requires component access, form
management, `core.delete` and `formstudio.submissions.delete`, a current editor
revision, the trashed state and explicit confirmation of the form UUID. Before
confirmation the native UI shows response/file counts and approximate owned bytes.

Confirmation atomically switches the form to `deleting` and queues `form-delete`.
That state cannot be edited, restored, reactivated or accept new submissions.
Submission admission and the transition serialize on the form row; publication
and all draft mutations reject the deletion state. Existing running actions must
finish or expire before their response can be erased. Competing form jobs are
cancelled and exports revoked; new administrative jobs reject deleting forms.

Each worker transaction deletes a bounded response batch through the existing
privacy service, or a bounded batch of one relational child table, and commits
with its checkpoint. Owned files enter the durable cleanup outbox before their
download authority is removed. Cleanup jobs survive deletion of the form itself.
The final transaction removes only the verified native form asset and form row.
Minimal audit events and safe job history remain; reusable resources are copies
and are never deleted as a side effect of deleting a form.

Confirmed deletion cannot be cancelled or rolled back. Failure or revoked
permissions leave the form unavailable; an authorized administrator can requeue
its deletion. Missing objects are idempotent cleanup successes. A form job's
completion establishes database deletion; separate cleanup jobs establish physical
file removal. Package purge must wait for these outboxes before dropping schema.

## Consequences

No synchronous request deletes all submissions. An interrupted worker cannot
commit changes without its lease/checkpoint. No browser-supplied path or object
key is accepted. Installer purge and final cleanup acceptance remain distinct
from form deletion.
