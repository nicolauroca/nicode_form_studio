# ADR-0006 — Database, jobs and private storage boundaries

Status: Accepted

## Context

The package needs relational authoring, immutable snapshots and a regenerable typed
search projection. Large maintenance operations cannot run in installer requests.
The existing machine's MariaDB is not to be modified for extension testing.

## Decision

Target MySQL 8.0.13+, MariaDB 10.6+ and PostgreSQL 14+ with dialect-specific install
and migration scripts. A declared release requires tests on each declared engine;
writing a SQL script alone does not establish compatibility. Initial integration
work uses a separate portable MariaDB 11.4.5 under ignored `build`.

Use Joomla DatabaseInterface repositories, parameter binding and explicit local
transactions. Store canonical submissions as JSON text together with immutable
FormVersion ID. Index only declared fields into nullable typed columns. Decimal
search columns use DECIMAL(38,12); publishing an indexed field whose configured
precision/scale exceeds that storage range must fail. Store UUIDs as canonical
36-character strings, timestamps as UTC DATETIME/TIMESTAMP without zero dates.

Jobs use renewable leases, owner tokens, bounded chunks, durable cursors and
optimistic revision checks. A Joomla console integration runs them independently
of a browser tab. External Actions are not part of a distributed DB transaction;
successful executions are not retried automatically. Ambiguous remote outcomes
must be explicit and require an idempotency strategy.

Files and generated exports live in a configured private directory outside the
public web root with opaque storage keys. Storage repositories own only files
registered to FormStudio. Download authorization is always checked by a controller.
Retaining form definitions never implies retaining visitor personal data.

## Consequences

Database and filesystem acceptance tests are mandatory. No support claim is made
until executed. Portable test servers bind only to loopback on a non-default port
and use workspace-specific data directories. Tests never repurpose the user's
existing XAMPP database directory.
