# ADR-0008 — Transactional form assets

Status: Accepted

## Context

A form and its Joomla ACL asset must be created or updated atomically. Integration
against Joomla 6.0.0 with its PDO MySQL driver showed that the standard nested
table's `LOCK TABLES` commits an existing MariaDB transaction implicitly. Wrapping
`Asset::store()` in a transaction alone therefore does not provide atomicity.

## Decision

Keep Joomla's `Asset` validation, nested-set writes and events. Use a narrowly
scoped subclass for FormStudio writes that replaces `_lock`/`_unlock` with a
transactional root-row mutex (`SELECT ... FOR UPDATE`). Acquire that mutex before
reading tree positions. Release locks only on the outer transaction's completion.
Use Joomla driver's savepoint support when repository transactions are nested.

The adapter requires an existing Joomla root and component asset. It never
creates an alternative ACL tree or falls back to component permissions when a
form asset is missing. The usual Joomla table locks must also wait for ongoing
transactional writes; FormStudio changes serialize on the root mutex.

## Verification and limits

`tests/joomla-acl.php` exercises actual Joomla asset creation, rename, inherited
allow and explicit deny. A deliberately injected failure after `Asset::store()`
must remove both the new form and asset and restore every nested-set boundary.
This passes on the isolated MariaDB 11.4.5 instance. Concurrent mixed Joomla /
FormStudio writer stress testing and the additional database engines remain part
of release acceptance.

Joomla changes to the protected lock hooks must be checked during upgrades. The
ordinary Joomla classes are not patched. SQL migrations and schema changes must
not be performed within these application transactions.
