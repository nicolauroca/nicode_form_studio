> Current status (2026-10-09): Nicode Form Studio is in development, pending functional and visual review. The closure/acceptance records below describe the predecessor before the rename; they are historical evidence, not acceptance of the renamed package.

# Requirement acceptance audit

The complete product remains in progress. This ledger closes individual normative
requirements only after reviewing their implementation and relevant negative and
positive tests. A closed requirement does not certify release packaging, all
supported platform versions, unrelated UI behavior, or the complete product.
Those remain separately tracked by their own requirements and acceptance checks.

## 2026-09-27

### DATA-005 — native file-cache process boundary (in progress)

The previous native test used two adapters in one PHP process. It now also
launches independent PHP workers against the isolated Joomla file backend:
parent-to-worker and worker-to-parent Unicode payloads, worker deletion visible
to a fresh parent adapter, and exact TTL expiry followed by backend removal.
This prevents process-local state from standing in for persistent-cache evidence.
Only synthetic reserved keys are touched and the parent removes its key in a
finally block. `joomla-sources-results.json` records this evidence. Other Joomla
cache backends retain pending acceptance; the read-only health probe still does
not perform write tests.

### OPS-007 — effective source-cache diagnostics (in progress)

Health now resolves the runtime cache service, distinguishes disabled shared
caching from request-only fallback, and reports backend read exceptions without
their text. A random reserved-key read bypasses local memory and performs no
store/remove calls. Integration tests prove disabled mode causes no read and
all modes cause no writes. The native container test verifies request-only
fallback; configured cache_path failure is diagnosed without revealing its
path, and application configuration is restored. A backend miss can mask an
internal failure, so the translated report explicitly does not certify writes
or shared-cache availability. Complete backend/operational acceptance remains
pending.

### OPS-007 — foreign-key diagnostics (in progress)

Read-only catalog checks now verify each required form/submission reference,
source/target columns, same-schema target and restrictive update/delete rules.
PostgreSQL additionally requires validated non-deferrable constraints. Tests on
all three engines remove an existing relation, replace it with ON DELETE CASCADE,
verify rejection and restore the original restrictive relation in finally blocks.
Native health and HTTP cover the installed probe and translated explanation.
Schema diagnostics do not scan for historical orphan rows or certify global
database settings. Complete provider/cache/operational acceptance remains open.

### OPS-007 — identity and column collation diagnostics (in progress)

Column health now checks automatic IDs, excludes computed columns and verifies
the character storage policy. MariaDB/MySQL require auto-increment IDs and
utf8mb4_bin text; binary option identities remain charset-free. PostgreSQL
requires BY DEFAULT identity and default column collation, without certifying
the operator-selected database-wide collation. Three-engine tests change a
column collation and identity metadata, detect one affected table and restore
the original definition; MySQL auto-increment counters are captured/restored.
Unit tests also cover reduced charset and generated-column metadata. Foreign
keys and complete operational acceptance remain pending.

### OPS-007 — column type and nullability diagnostics (in progress)

DDL generation and health now share SQL type mappings. Catalog checks cover
all required columns including IDs, exact character/binary lengths, decimal
precision/scale, temporal precision, nullable flags and signed integer semantics.
Unit tests cover narrowing, unsigned integers and time-zone changes; isolated
three-engine tests alter nullability and widen bigint to decimal, detect the
affected table and restore the original type without losing bigint values.
MySQL dictionary label casing is normalized. Native health and HTTP verify the
installed probe and translated scope. Collations, identity generation, foreign
keys and broader operational acceptance remain pending.

### OPS-007 — required index diagnostics (in progress)

Catalog checks now compare primary status, uniqueness, complete ordered columns
and usable B-tree indexes against the shared schema contract. Tests on all three
engines remove and restore a required index and substitute a wrong definition
under the correct name. PostgreSQL additionally verifies equivalent legacy
names, partial-index rejection and descending-index rejection. Schema mutations
are confined to the named integration databases with finally restoration.
Native Joomla health verifies installed indexes; native HTTP checks the
translated report label. Reports return only affected-table counts. Full column
types, collation, foreign-key and operational acceptance remain pending.

### OPS-007 — required schema tables and columns (in progress)

Health now probes all expected columns across 30 tables using empty SELECTs;
matching version checkpoints alone no longer provide the only schema signal.
The runtime and DDL generator share `SchemaDefinition`, avoiding a separate
hand-maintained list. MariaDB, MySQL and PostgreSQL tests temporarily rename a
required column and a table in isolated integration databases, verify one safe
failure, restore each in finally blocks and verify recovery. Native Joomla
health checks the installed schema. Reports contain only status and a bounded
failure count, without exception text or stored values. Types, nullability,
indexes and constraints remain outside this check and keep full health
acceptance open.

### OPS-007 — SMTP configuration diagnostics (in progress)

Health no longer marks SMTP configured solely because mail is enabled and the
sender is valid. Static checks now cover host presence/control characters,
integer port bounds, configured security and required authentication settings.
Closed translated reason codes guide correction without exposing configuration.
Unit tests cover each failure, valid boundaries, unauthenticated SMTP and other
transports; native Joomla tests verify missing-host classification, private
setting exclusion and in-memory restoration. Native HTTP checks the translated
diagnostic explanation. No probe connects or sends, and successful static
configuration explicitly leaves delivery unverified. Complete health/schema/
provider operational acceptance remains pending.

### PRIV-001 — historical masking and reveal audit (in progress)

The reader now counts only sensitive fields in reveal audit metadata and records
technical request metadata separately. Public answers no longer inflate the
count, and IP/User-Agent-only access records two technical items rather than an
unexplained zero-field event. The audit viewer preserves these bounded numeric
counts without displaying values. Three-engine regression tests cover a mixed
public/private response, denied/ordinary reads producing no successful-reveal
event, and policy changes across immutable versions: making a field public does
not expose historical private answers/files, and later private policy does not
rewrite an earlier public response. Broader privacy acceptance remains tracked
as PARTIAL.

### PRIV-006 — IP/User-Agent opt-in policy

Closed against SPEC-14. Each flag defaults off and requires a real boolean;
neither inferred truthy values nor null enable collection. The Joomla adapter
uses the connection IP and HTTP User-Agent only, ignoring POST metadata and
forwarded IP headers. Collection is deferred until validation, CAPTCHA and the
pre-persistence event pass; replay and no-storage mode do not invoke it.
IP normalization, invalid input rejection, UTF-8/control filtering and the
512-byte User-Agent bound have unit coverage. User-Agent is client-declared
text, not identity evidence.

MariaDB 11.4.5, MySQL 8.4.8 and PostgreSQL 14.24 verify both persistence modes,
disabled collection, retained first-attempt metadata, denied reveal, audited
authorized reveal, ordinary-read/export exclusion and anonymization. The native
HTTP fixture additionally verifies POST/header forgery rejection and absence of
a retained row in no-storage mode. Native browser evidence shows metadata hidden
until explicit reveal, HTML-like User-Agent displayed as literal text, and
independent editor toggles surviving save/reload (`joomla-request-metadata-policy.png`
and `joomla-request-metadata-revealed.png`). General privacy lifecycle, platform
matrix and product release gates retain their separate statuses.

### LAYOUT-001/002/003 — grouping, rows and responsive layout (in progress)

Installed preview/public rendering now has direct evidence for nested
section/row/columns/fieldset, semantic legend and independent conceptual widths.
The audit fixed fieldsets missing grid layout, step descriptions occupying a
single track and empty headings from untitled containers. Native screenshots
verify three/two/one columns at desktop/tablet/mobile sizes. See
`http-admin-layout.php` and `joomla-layout-desktop/tablet/mobile.png`.
These requirements are PARTIAL: complete structural authoring, all width and
nesting combinations and broader accessibility acceptance are not implied by
this three-field fixture.

### ADMIN-003 — builder acceptance in progress

SPEC-05 branch collapse/expand is now implemented with localized keyboard
controls, expanded state and controlled-list references. It preserves local
expansion across redraws without dirtying the authoring definition. Selection
and movement reveal ancestor branches. Model tests cover ancestor-only opening,
unchanged draft data and cycle-safe traversal. Native browser evidence verifies
collapse, retained state after selection and opening after keyboard reparenting:
`joomla-builder-collapsed.png`, `joomla-builder-revealed.png`. ADMIN-003 remains
PARTIAL; this is not acceptance of every builder operation or provider inspector.

Branch duplication now uses the server's provider-aware remapper, creates unique
names/UUIDs and copies internal references, field validators, translations and
branch-targeted rule effects while preserving external references and literals.
It requires edit permission and a current draft revision and does not activate
the copy. Unit tests cover copy semantics; three-engine database checks verify
ACL, revision fencing and exact stored draft. Native HTTP also verifies POST,
CSRF and invalid-source isolation. Browser evidence duplicates a complete step
with two children and inspects the new field name (`joomla-builder-branch-copy.png`).

### LAYOUT-005 — drag/drop and keyboard alternative

The builder now supports tree drag/drop before/after rows, into containers and
back to top level. A visible destination marker distinguishes placement.
Only a source started in the same editor is accepted. The shared model rejects
cycles, missing targets, non-container parents and nested steps before mutation;
descendant ownership, UUIDs, provider configuration and rule references survive.
`js/builder-drag.test.mjs` covers these boundaries and adapter cancellation/external
payload isolation. Existing Move up/Move down and Container controls remain the
keyboard alternative. Native editor form 1470 moved Initial answer from Contact
details into the next Answer stage with a real pointer drag, focused the moved
row and saved the draft. Follow-up native pointer tests verify before/after
ordering of Initial answer around Middle answer, reject a step-to-descendant
drop without changing the tree, and move a root row to the end through the root
destination. Keyboard Enter selects Middle answer; Home/Enter in Container
moves it to root; Enter on Move up/down reorders it and Save persists the result.
Evidence: `joomla-builder-keyboard-root.png`. The nested-field-to-root pointer
case initially failed; accepting validated destinations in both `dragenter` and
`dragover` corrected the reproduced case. The same native gesture then moved
Initial answer out of Answer stage; save/reload retained Top level, UUID and
machine name (`joomla-builder-drag-root.png`). The adapter regression now asserts
entry acceptance in addition to root and edge/centre routing. These native and
model checks close LAYOUT-005's drag/drop and keyboard alternative scope. Global
accessibility, other builder functions and release/platform acceptance remain
separately tracked.

### FORM-008 — Compile & Publish

The audit covers compiler diagnostics, deployment readiness and atomic activation.
FORM-008 is closed against the responsibilities in SPEC-03 section 5. Evidence:

| Boundary | Evidence and demonstrated behavior |
| --- | --- |
| Structure and stable identities | `unit/domain.php`, `unit/malformed.php`: malformed nested input, schema, names, orphan fields, cycles and deterministic immutable snapshots. |
| Cross-field contracts | `unit/compiler-validator-types.php`: type/multiplicity rejection at original scope, compatible numeric/temporal pairs and custom-provider independence. |
| Operators, effects and dependency graph | `unit/numeric-conditions.php` and `unit/temporal.php` reject invalid typed operands and reversed ranges; `unit/browser-providers.php` compiles custom logical types/operators. `unit/domain.php` rejects blocking effect conflicts and layout/value cycles; `unit/prefill.php` covers prefill references and cycles. |
| Storage and runtime policies | `unit/domain.php` rejects unsupported/password indexing and unapproved sensitive indexing; `unit/privacy.php` rejects malformed retention, rate and CAPTCHA policies. `database-retention-dispatch.php` prevents activation without the required retention authority. |
| Normalization, hash and provider versions | `unit/domain.php` establishes canonical object ordering, preserved list ordering, deterministic hash and immutable snapshots. `unit/provider-dependencies.php` checks the compiled dependency manifest, missing/incompatible providers and compatible upgrades. |
| Indexed initial values | `unit/compiler-default-index.php`: defaults and constant prefills must fit the persistent index, including Unicode keyword length, decimal precision/scale and portable temporal range. `http-admin-publication-activation.php` rejects oversized defaults/constants without replacing the live version and publishes/renders the corrected boundary. |
| Source destination capability | `unit/compiler-resources.php`: option sources require logical `selection`; incompatible scalar targets reject and custom selection providers remain supported. |
| Initial selection membership | `unit/compiler-selection-defaults.php`: impossible/disabled defaults and constant prefills reject with field-specific paths; exact enabled values and enabled-rule alternatives compile. Dynamic providers remain deferred without being queried. |
| Rule option effects | `unit/compiler-rule-options.php`: change_options rejects duplicate identities and malformed flags while preserving distinct literal values; diagnostics locate the exact rule option/flag. |
| Post-submit and navigation | `unit/compiler-post-submit.php`: invalid behaviors, preservation references, message types/tokens, conditional references/identities and unapproved destinations reject compilation; valid behaviors, legacy conditional messages, internal routes, approved HTTPS and available menus compile. |
| Email/webhook action contracts | `unit/compiler-actions.php`: recipient/reference types, header safety, message types, copied-template revision validation, approved destinations, methods, timeout bounds, safe maps and secret-reference syntax; excluded field tokens block compilation while explicit sensitive-field inclusion is respected. Mail, HTTP, DNS and secret-value access throw if invoked during these compiler tests. |
| Copied source resources | `unit/compiler-resources.php`: positive revision/hash/UUID provenance, embedded options, exact value uniqueness, typed flags/conditions and declared existing dependencies. Immutable compiled JSON is independent of later authoring edits. `database-option-sets.php` and `database-source-resources.php` additionally cover corrupt stored revisions, ACL, binding, stale revisions and mutable-resource copy isolation on all three engines. |
| Diagnostic locations | `unit/compiler-paths.php` and native screenshots: fields/elements use draft indices; field validators retain their owning path; rule/action/validator/translation navigation opens and focuses the relevant controls. |
| Nonblocking advisories | `unit/compiler-advisories.php`, `http-admin-advisories.php`: blank labels and approved sensitive indexing retain WARNING in preview and successful publication; malformed types remain errors. |
| Deployment readiness | `unit/publication.php`: missing renderer/private storage/mail/secret rejection, with secret values excluded from diagnostics. `unit/browser-providers.php` covers browser provider compatibility/projection. |
| Browser readiness transaction | `database-publication-readiness.php`: a custom browser field with declared script/style assets publishes when available; either missing asset yields safe `publication.browser`, leaves complete database state unchanged, and permits exactly one new version after correction. Verified on all three engines. |
| Transaction and revision | `database-publication-readiness.php`: failed readiness leaves the entire form/draft/version/policy/audit state unchanged; correction activates once; stale retry cannot create another version. Executed on MariaDB, MySQL and PostgreSQL. |
| Native publication | `http-admin-advisories.php`: invalid field references and incompatible comparisons return 422 without replacing the active version; corrected types publish. |
| Public activation | `http-admin-publication-activation.php`: same URL/session retains the published label/default/version after saving a new draft, switches immediately after publication, persists the new response against the replacement version, and preserves the old snapshot bytes/hash. |
| Authorization and CAPTCHA | `database-form-administration.php`: publish ACL and unavailable CAPTCHA rejection; historical version preserved. |

Together these cases cover every compiler responsibility listed in SPEC-03,
including warnings that survive publication, safe prerequisite failures,
immutable snapshots and immediate activation. The closure concerns Compile &
Publish; action delivery/retry, full multipage UX, release packaging and the
remaining product requirements retain their separate acceptance status.

### LAYOUT-004 — multipage acceptance in progress (not closed)

Real runtime tests and native browser evidence now establish navigation,
future-step comparison deferral, final confirmation rejection/correction,
conditional earlier-step removal/reinsertion without losing the current identity
or focus, and retained input on returning. Native direct POST verifies that the
server excludes inactive-step answers. See `step-validation.test.mjs`,
`http-admin-conditional-steps.php` and the step screenshots recorded in
`TESTING_GUIDE.md`. A second installed fixture now verifies disappearance of the
current step: it selects the next active step, or the last preceding step when
none follows, without moving focus away from the persistent triggering control.
A real successful submission resets visibility controls, returns to the first
of the three restored steps, clears unpreserved answers and retains the explicitly
preserved final answer. Keyboard Next moves focus to each step container.
Evidence: `joomla-step-fallback-next.png`, `joomla-step-fallback-previous.png`,
`joomla-step-reset-first.png`, `joomla-step-reset-preserved.png`.
Full step authoring and accessibility acceptance still need their own evidence.
This requirement remains PARTIAL.

The audit found and corrected missing step descriptions: the native inspector
now edits them, translation authoring supports them, compilation enforces text,
and preview/public rendering escape and associate them with their step through
instance-specific `aria-describedby`. `unit/step-description.php` covers
localization, immutability, escaping, empty omission and separate instances;
the native HTTP fixture checks three associations. Browser evidence demonstrates
editing, saving, preview and publication (`joomla-step-description-editor.png`,
`joomla-step-description-published.png`). Full structural authoring and broader
accessibility checks remain open.

Structural audit also corrected acceptance of nested steps, which the sequential
navigator cannot display while their parent is hidden. SPEC-05 now explicitly
defines a page sequence: grouping is allowed, descendant steps are not.
Compiler and builder tests cover direct/indirect nesting and movement of a subtree
containing steps. Native HTTP rejects the invalid draft with its exact parent path
and publishes its correction. Native palette insertion from a selected step creates
a sibling; parent choices exclude other steps. See `joomla-step-sibling-insertion.png`.

### FIELD-009 — presentation elements

Heading, subheading, paragraph, notice, safe HTML, separator and spacer are
available in the native palette and structure. Headings use h2/h3, paragraphs
use p, the separator uses hr and the spacer has a CSS gap. Plain content is
escaped; native safe HTML preserves allowlisted emphasis and links while
removing executable/interactive elements, event attributes and unsafe schemes.
Without a sanitizer, the standalone renderer escapes the entire HTML string.

The native HTTP fixture verifies publication, identical preview filtering and
exclusion of forged presentation UUID values from canonical answers. Browser
acceptance edits all content-bearing elements, inspects separator/spacer
properties, sets a width, adds an additional notice through the palette, saves,
reloads, previews and publishes. The public page shows the persisted content
and the new notice. Evidence: `joomla-presentation-types.png`,
`joomla-presentation-edited-preview.png`,
`joomla-presentation-edited-published.png`. This closes FIELD-009; general
layout, accessibility and full builder acceptance remain separately tracked.

### FIELD-003 — text field types

The normative eight types are text, textarea, email, telephone, URL, search,
password and hidden. Color is an additional HTML type, not a replacement for
hidden. Their metadata, native rendering, normalization and accepted canonical
values are covered by shared unit fixtures and installed HTTP submissions.
Unicode lengths count code points, search can preserve spaces, telephone input
retains formatting, and passwords preserve validation input but never persist.
Native hidden inputs obey configured validation; readonly hidden and system
values cannot be replaced by forged POST arrays. Calculated field references
recompute from normalized source values. Browser evidence shows live calculated
output, correction of visible text constraints and successful submissions.

`database-text.php` verifies all eight text types plus color on three engines:
eight exact stored projections, Unicode/composition/newlines/spaces/case retained,
and password exclusion. The audit corrected an ignored PDO fixture charset
option and added explicit four-byte connection assertions.

Quoted and IPv4/IPv6 email formats accepted by PHP reach native ingress; malformed
counterparts return 422. The browser no longer blocks those formats solely on
HTML type mismatch, while retaining other native constraints. Validation SPEC
section 1 makes server validation authoritative. This closes the field-type
requirement, not a claim that native HTML or standalone JavaScript implements
PHP's entire email grammar. General Rule Engine parity remains under RULE-010.

### FIELD-006 — selection controls

Select, radio, button-group, multiselect and checkbox-group preserve literal
option identities and validate enabled membership. Checkbox, toggle and yes-no
use strict boolean normalization and required acceptance. Multiple selections
remove exact duplicates before required/min/max checks. All eight have native
labelled controls and metadata-driven authoring.

PHP/JavaScript tests cover identity preservation, malformed values, disabled and
unknown options, count bounds and strict booleans. Native HTTP covers all eight
providers, negative direct POST requests and exact scalar/array/boolean canonical
storage. Browser keyboard acceptance exercises every control, both multiple-count
errors and correction (`joomla-selection-count-error.png`,
`joomla-selection-controls-submitted.png`).

The database audit found and fixed PAD SPACE collisions in MySQL/MariaDB through
ADR 0018. Exact option and OptionSet identities, equality/negation filters,
case/accent/composition differences and trailing spaces pass on all three
engines. Native package update/preserve cycles and fresh two-prefix schema tests
pass; migration inspection avoids repeated DDL. This closes selection controls;
complete release-platform and scalability acceptance remains separately open.

### FIELD-012 — sensitive and indexing flags

Typed field flags control persistence, sensitivity, indexing, email inclusion
and export inclusion. Sensitive fields default out of email/export; explicit
inclusion remains distinct from the actor's permission to reveal/export them.
The compiler requires `allow_sensitive_index` for sensitive indexed fields.
Derived projection uses only stored values and respects the same policy.
Password answers never enter canonical storage, indexes, email tokens or exports,
even when a malformed draft attempts to opt them in.

`tests/database-field-policy.php` verifies that complete flag matrix on all three
engines, including explicit/default inclusion, ephemeral indexed fields,
password overrides, masked reads and actor-scoped exports. Existing read/search
suites verify file permissions and historical sensitive-field search policy.
The editor displays effective defaults rather than misleading checked boxes,
exposes the explicit sensitive-index approval, and disables impossible password
options. Native browser acceptance verifies publication denial without approval
and the corrected controls (`joomla-sensitive-field-policy.png`,
`joomla-password-policy.png`). Broader privacy, retention and logging requirements
retain their separate acceptance scope.

### FIELD-004 — numeric fields

All five required types (`integer`, `decimal`, `number`, `currency`, `range`)
have registered metadata, native numeric/slider rendering, exact normalization,
min/max/step/scale/precision validation and typed indexes. The editor exposes
their numeric properties. Slider defaults are explicitly 0..100 with step 1 in
the editor, HTML and both validators; decimal overrides use the effective minimum
as the step base. Integer configuration rejects fractional bounds/steps. Native
publication rejects inconsistent bounds before activation.

Shared normalization and range fixtures cover PHP/JavaScript behavior. Native
HTTP acceptance renders all five types, rejects out-of-range, malformed,
over-precision and step-invalid requests, and verifies integer versus decimal
canonical types. `tests/database-numeric.php` verifies all five indexed types,
signed 64-bit maximum and positive/negative DECIMAL(38,12) boundaries without
rounding on MariaDB, MySQL and PostgreSQL. Native browser acceptance verifies
the focused error for integer 9 with step 2, successful correction to 8 and
the editor's visible slider defaults (`joomla-numeric-validation.png`,
`joomla-numeric-submitted.png`, `joomla-numeric-inspector.png`). Numeric rule
compilation separately rejects malformed exact operands and reversed bounds.

This closes the required numeric types; complete configuration coverage across
other field families remains under FIELD-010.

### FIELD-013 — stable machine names

UUID identifies the field through draft edits, rules, published snapshots,
submission values and typed indexes. Machine names are unique lowercase
identifiers; compilation rejects duplicates/invalid names and draft storage
rejects names exceeding its 255-character column. Changing a label does not
automatically rename a field. The editor compares names by UUID against the
last published snapshot and displays a localized integration warning as soon
as a name changes, including when opening an already renamed draft. Saving does
not change that baseline; successful publication does.

`tests/database-field-identity.php` verifies the initial empty baseline, rename
and publication transitions, identical old snapshot hashes and response bytes,
unchanged UUID and oversized-name rejection on all three engines. Existing
compiler tests verify invalid and duplicate names. Native browser acceptance
renames a published date field, displays the warning and saves the draft
(`tests/artifacts/joomla-machine-name-warning.png`).

### FIELD-005 — dates and times

SPEC 04 requires date, time, datetime-local, month and week providers. All five
are registered with native input rendering, metadata-driven min/max controls,
server calendar validation, and PHP/JavaScript temporal comparison. Publication
rejects malformed or inverted bounds and invalid temporal rule operands. Minute
and second forms compare identically when omitted seconds are zero. ISO weeks
validate actual week 53 availability; local datetimes do not infer a timezone.

Twenty-five shared fixtures cover leap centuries, invalid dates/times/weeks,
inclusive bounds and temporal formats; seven additional shared operator cases
cover equivalent precision and invalid operands. Native HTTP creates, saves,
rejects bad publication, corrects/publishes, renders all five controls, rejects
nine malformed/out-of-range direct POST values and persists the valid local
values. Browser keyboard acceptance rejects an early time, focuses its error
summary, then submits the corrected minimum (`joomla-temporal-validation.png`
and `joomla-temporal-submitted.png`). Three-engine SQL tests verify all five
projections, portable date extremes and rollback of an unsupported indexed date.
Unindexed dates allow 0001–9999; SQL date/datetime projection uses 1000–9999 and
reports its limit before persistence, with a second check during reindexing.

### OPS-005 and ADMIN-009 — audit and administrative log viewers

SPEC 27 requires separate technical/audit storage and explicit administrative
events. Form create/save/publish/deactivate/restore, permission changes, export,
anonymization, response deletion, action retry and permanent form deletion are
recorded with actor and correlation. Explicit sensitive reveal is also audited.
Database-only mutations commit their audit with the operation; deletion preserves
the final audit event. Export completion uses the job UUID. The native extension
plugin records successful `com_config` saves as `config.security_saved` without
copying parameters (ADR 0017); failed and unrelated saves are excluded.

Three-engine integration tests assert exact form event order, actors, correlation,
privacy idempotence and denied-operation exclusion, export identity, retry job
identities and deletion-surviving history. Native ACL tests check successful
permission events and exclusion of denied/stale writes. The real configuration
model test checks one successful event and absence of private configuration.

Separate native viewers require component-level management and log permissions.
Audit pages use 100-row ID keysets and bound actor/form/event/correlation/reference
and inclusive UTC date filters; typed details exclude arbitrary metadata. Tests
cover 102 rows, adjacent pages, invalid/injection-shaped filters, denied access,
safe projection and native no-store rendering. Technical log tests cover its
closed vocabulary, independent viewer and expiry. Existing browser evidence
shows filtered deactivation history and technical correlation views.

ADR 0016 adds independent optional audit/action-history retention. Three engines
verify bounded candidates, rollback, fresh-event preservation and policy changes;
native CLI scheduler tests verify live cancellation and cleanup. Health reports
a disabled configuration audit plugin. These closures do not claim that every
possible infrastructure failure has technical logging; that remains OPS-006.

### FORM-006 — immutable form versions

SPEC 02 sections 6–7 and SPEC 11 section 7 require draft isolation, immutable
publication, exact response version identity, historical preview/comparison and
restore into a draft. `FormRepository` persists canonical snapshots with hashes;
publication changes the active pointer atomically and restore only saves a draft.
`FormAdministration` enforces edit permissions and scopes every snapshot to its
form. Native routes reject cross-form comparison and stale mutations. The editor
lists revision, date, author identity, comment, state and response count; the last
is omitted without response-view permission. It offers comparison, disabled-submit
preview and confirmed restoration. More-history uses revision keysets.

`tests/database-version-history.php` publishes 52 snapshots, verifies two bounded
pages without duplicates, active/historical states, zero and nonzero counts,
count permission denial and restoration preserving old response identity and both
snapshot hashes and active pointer. Shared suites pass on all three engines.
Existing database tests cover draft isolation, optimistic publication and failed
publication rollback. `tests/unit/spec-diff.php` covers UUID identity, additions,
removals, reordering, nulls and bounded comparisons. Native HTTP covers historical
preview against an invalid draft, comparison ownership and restore. Browser
evidence in `tests/artifacts/joomla-version-history.png` shows active state, ten
responses and a version-to-draft comparison. General provider/platform and release
acceptance remains tracked separately.

### RULE-008 — deterministic priority

SPEC 07 sections 6 and 11 require ascending priority, then lexical UUID, with later
effects winning and no dependence on authoring array order. PHP `RuleEngine` and
JavaScript `evaluateRules` implement that comparison. PHP tests permute different
priorities and equal-priority UUIDs; JavaScript tests permute equal-priority rules
and then raise one priority. Each checks the resulting required/optional state.
The compiler's separate conflict test rejects identical conditions with mutually
exclusive effects at equal priority. PHP and JavaScript suites pass.

### RULE-009 — cycle detection

SPEC 07 sections 8 and 10 require publication rejection of indeterminate cycles
and bounded runtime stabilization. `DependencyGraph` accepts acyclic diamonds and
detects back edges; the compiler validates layout, rule-value and prefill/source
dependencies. Domain and prefill tests exercise rejection. Both runtimes also
reject oscillating values when directly handed an invalid snapshot, with a
64-iteration default bound. Tests cover compiler rejection and runtime failure;
native publication tests separately verify that failed compilation preserves the
previous active version and leaves no new version or audit success behind.

### SEARCH-005 — saved views

SPEC 23 section 7 allows private or ACL-shared views; the implemented core choice
is private. A named view retains form/header and indexed-field filters, selected
answer columns and supported order. Its owner comes from Joomla identity and
every open revalidates current form, field and provider capabilities. It stores
query intent rather than results or permissions. Native HTTP tests cover create,
read, remove, sort round-trip and rendering. `tests/joomla-acl.php` verifies a saved
view cannot retain revoked native form access and its owner can still remove it.
Browser checks reopen an ascending saved view, change to descending ID and verify
the visible rows. Shared views are optional, not an unimplemented mandatory part
of this requirement. General search/provider/platform requirements retain their
own open acceptance work.

Evidence: 163 passing PHP tests, 71 passing JavaScript tests; shared database
suites on MariaDB 11.4.5, MySQL 8.4.8 and PostgreSQL 14.24; installed Joomla 6.0.0
HTTP suite and native ACL test on PHP 8.5.8. Exact commands and fixture guards are
documented in `TESTING_GUIDE.md`. Ignored run reports are local execution evidence,
not artifacts included in a production package.

### FORM-005 — form duplication

SPEC 26 section 4 requires a new draft with new graph identity, remapped declared
references, preserved business literals/resources and visibility/ACL, without
runtime history. `FormDuplicator` locks and checks the source revision, authorizes
source editing and target creation/editing, copies the definition and native ACL
in one transaction and leaves publication unset. `DefinitionRemapper` uses two
passes so forward references to local field/rule options resolve correctly.

`tests/unit/definition-remapper.php` verifies layout, fields, rules, validators,
action/template tokens, sources, translations, preserved literal UUID strings,
resource identities and custom wildcard/escaped-pointer references including
forward option references. Three-engine database suites cover draft identity,
source isolation, visibility, denied creation, stale source and full rollback
when ACL copying fails. Native HTTP verifies POST/CSRF, expected revision, absence
of published history and a copied explicit Joomla ACL denial. These tests passed;
provider browser registration and general import/export remain separate scopes.

### SEC-012 — rate limiting

SPEC 13 section 13 permits a separate anti-abuse limiter. The published form
policy validates maximum attempts and a fixed window. RequestAdapter derives a
pseudonymous transport-address HMAC (session fallback without a valid address),
ignores untrusted forwarding headers and combines it with form identity. Dynamic
options have an independent budget. Expired counters are removed by an internal,
bounded, transactional hourly job without resetting active windows.

`tests/database-rate-concurrency.php` synchronizes eight PHP processes on a new
window, three rounds per engine, and verifies exactly three admissions and one
stored counter of three. It exposed and led to replacement of exception-based
first-window insertion with conflict-safe atomic insertion. Existing tests cover
exact boundary and next-window reset. `tests/database-rate-cleanup.php` covers
bounded expiration, rollback and preservation of active counters. Native HTTP
publishes a limit-one policy, consumes it through validation, rotates session and
forges different forwarding headers, observes 429 with bounded positive
Retry-After, and confirms options retain their independent budget. Native Joomla
scheduler exercises the maintenance queue. Database transaction failure boundaries
are recorded separately in ADR 0014. Full platform release certification remains
under OPS requirements.

### FIELD-008 and PRIV-005 — consent and historical evidence

The core consent field is a labelled native checkbox with strict boolean
normalization. Required consent rejects missing, empty and false values;
optional refusal remains false, and malformed shapes cannot count as acceptance.
PHP and JavaScript test the same acceptance/refusal and malformed-input cases.
Native HTTP exercises a consent field through the shared submission route.

SPEC 14 section 4 requires accepted/not accepted, exact text/version, timestamp
and FormVersion. Canonical persistence captures all four in the response
transaction, using the localized immutable snapshot. Three-engine translation
and response-history suites now explicitly assert both true and false evidence,
exact timestamp equality with the response and the original version. Publishing
a changed consent text leaves the prior Spanish response unchanged. Default
sensitive masking suppresses its evidence; authorized explicit reveal returns it.
The native detail view escapes text and displays all four attributes. Retention,
no-store policy, general sensitive ACL and full accessibility remain separately
tracked requirements rather than implied certifications from this closure.

### FIELD-002 — extensible Field Type registry

SPEC 04/18 and ADR 0015 define typed installed providers, JSON metadata,
configuration validation, explicit renderers, lifecycle and immutable semantic
version dependencies. Registries reject duplicate/replacement registrations and
freeze after the native plugin event. Publication refuses missing renderers or
browser assets. The browser loads only provider-declared WAM modules/styles,
checks exact installed versions, projects explicitly public configuration and
invokes per-instance hooks. Missing modules and provider failures isolate the
dependent form; custom validators can explicitly remain server-only.

`tests/joomla-providers.php` installs a synthetic Joomla plugin outside the
package. Its custom uppercase field publishes, renders, normalizes a submission,
persists `CUSTOM VALUE`, creates the matching keyword projection and exports
provider-approved configuration. Its custom operator extends declared logical
types without changing core field definitions. Native missing-asset and disabled
plugin checks fail closed while historical data remain readable. Unit/browser
tests cover version mismatch, private configuration, mutation isolation,
synchronous hook contracts, core replacement refusal and CSS document isolation.

Browser acceptance in the dedicated Joomla site verifies the provider appears
in the palette, its schema drives the inspector, adding and saving a second
custom field survives reload, and preview loads its module/styles inside the
iframe: `saved custom` becomes `SAVED CUSTOM`, with submission disabled. The
two-instance fixture independently verifies a missing alpha module cannot stop
beta's custom field and condition. Screenshots are recorded in
`tests/artifacts/browser-custom-field.png`, `browser-provider-isolation.png`
and `joomla-browser-provider-preview.png`. This closes registry extensibility;
whole-product field parity, accessibility and supported-platform acceptance
remain separately tracked.

### DATA-002 — versioned Option Sets

SPEC 08 section 3 and SPEC 26 section 8 require reusable versioned options with
stable value/label semantics, historical snapshots and explicit dependency
import rules. `OptionSets` provides native list/editor/history, ordered stable
option identities, transactional current projections, immutable hashed revisions,
optimistic conflicts, resource permissions and named parameter→field bindings.
Applying a revision embeds its options and provenance into the definition;
later edits cannot alter that draft copy or an immutable published FormSpec.

Three-engine database tests cover immutable history, current projections,
dependency options, missing/ambiguous bindings, duplicate identities, stale
edits, read/write ACL and corrupt hashes. Native HTTP verifies methods/CSRF,
editor/list, default selection, historical reads and published preview isolation.
Browser evidence includes `joomla-option-set.png` and `joomla-option-binding.png`.
Definition transfer tests now execute both import modes: reference-aware import
keeps the exact resource revision/hash, while portable import creates a static
embedded copy with no destination resource dependency. Subsequent resource edits
leave both imported drafts unchanged; incompatible reference hashes are rejected.
Independent whole-form import policies and broader dynamic-source acceptance
remain tracked under their own requirements.

### Administrative viewport acceptance — 2026-09-27

The installed Joomla editor now offers localized Desktop (1280), Tablet (800)
and Mobile (390 CSS px) preview sizes. Native browser verification on published
fixture 1584 shows three, two and one field columns within the same nested
section/row/columns/fieldset renderer. The desktop iframe keeps its actual width
and scrolls inside the editor. Entering `Kept across sizes` in mobile, switching
to tablet and desktop, and returning to mobile with keyboard End/Enter preserves
the value; Submit remains disabled. Evidence: `joomla-preview-desktop.png`,
`joomla-preview-tablet.png`, `joomla-preview-mobile.png` in `tests/artifacts`.
FORM-007 remains partial: this establishes viewport controls and the fixture,
not the complete provider, responsive and accessibility acceptance matrix.

### Responsive width authoring — 2026-09-27

The inspector uses translated breakpoint names and native width selectors with
1–12 plus the full-width default. Selecting default removes only that breakpoint.
`tests/unit/layout-widths.php` covers all 36 width/breakpoint combinations,
absence of inherited breakpoint classes, and rejection of out-of-range, noninteger,
null, structured values and unknown breakpoints. This checks the compiler and
renderer contract, not every browser's CSS geometry.

On native Joomla fixture 1584, First name was changed from 12/6/4 to absent/4/8
(mobile/tablet/desktop), saved and reloaded. Keyboard Home/Enter selected the
mobile default. The inspector retained all three choices; compiled preview emitted
only `nfs-desktop-8 nfs-tablet-4` and the tablet rendering showed the narrower
first field. Evidence: `joomla-width-editor.png` and `joomla-width-preview.png`.
217 PHP tests and 121 JavaScript tests pass. LAYOUT-003 remains partial pending
the complete responsive geometry and authoring acceptance matrix.

### Numeric inspector edits — 2026-09-27

Reproduced and fixed silent loss of an invalid minimum-length edit: entering -1,
selecting another field and returning previously restored the old empty value
without marking unsaved changes. Integer controls now keep invalid draft values,
mark the editor dirty and restore validity errors when rebuilt. Safe integer
values remain numbers; unsafe values and fractions retain the raw string rather
than becoming a rounded integer. Incomplete numeric syntax remains invalid;
explicitly clearing an optional property removes it.

Three JavaScript regression tests cover reconstruction, bounds, unsafe integers,
fractions, incomplete input, correction, optional removal and integer selects.
124 JavaScript tests pass. Installed native fixture 1584 retains -1 across field
selection, blocks Save with the invalid control visible, and rejects Compile and
publish from another selection with `field.configuration.integer` at
`/fields/0/config/min_length`. The fixture was then corrected by clearing and
saving the optional minimum. Evidence: `joomla-integer-invalid.png`.
Incomplete drafts remain saveable; publication remains authoritative and separate.

### Layout depth publication boundary — 2026-09-27

A regression demonstrated that 65 nested containers compiled successfully even
though the renderer rejected that depth. Compiler and renderer now share
`Domain/LayoutLimits::MAX_CONTAINER_DEPTH` (64). The compiler reports
`layout.depth` at the overflowing element's `parent_uuid` before activation.
The iterative ancestor walk is bounded and cycle-aware; flat-list order does
not determine graph depth. Leaves remain allowed inside container 64.

`tests/unit/layout-depth.php` covers empty and field-containing chains at
0/1/63/64, rejects 65/100 and repeats rejection with reversed element order.
218 PHP tests pass. `tests/http-admin-layout.php` publishes and renders the
64-container boundary, saves 65, verifies HTTP 422 and the exact diagnostic path,
asserts unchanged active version/revision and still-renderable public content,
then corrects and republishes. The full native Administrator HTTP suite passes.
The renderer retains its guard for snapshots arriving outside compilation.

### Malformed layout parent diagnostics — 2026-09-27

The strict regression in `tests/unit/malformed.php` reproduced a PHP offset
exception when parent_uuid was an array. Structure validation now rejects every
non-null, non-UUID parent before graph traversal and reports `element.parent`
at the owning `/elements/{index}/parent_uuid` path. Arrays, objects decoded to
arrays, booleans, integers, decimals, empty and invalid strings are covered;
null remains a valid root parent. Warnings/deprecations are promoted to exceptions
in the test. This also caught and fixed a null array-offset deprecation in the
new depth traversal on PHP 8.5. All 219 PHP tests pass.

The native layout HTTP scenario submits array/boolean/decimal parents. Shared
structure validation rejects them already at save with HTTP 422, leaving the
complete draft, revision and active version unchanged; the existing preview
still renders its 64 containers. Compiler diagnostics are verified by unit tests.

### Unsupported repetition must not publish as a static group — 2026-09-27

Inspection found that the builder offered `repeatable-group` while rendering it
as a single static container, without instance controls or repeated answers.
The compiler now rejects that type with `layout.repeatable.unsupported` and an
exact `/elements/{index}/type` path. The palette and direct insertion model do
not offer/create it. Existing draft nodes remain intact and editable for recovery.
This is a temporary correctness guard, not implementation of repeated instances
and not acceptance of LAYOUT-006 or a decision to defer it from the final scope.

The compiler regression verifies no executable snapshot is produced; the builder
regression verifies absence from the palette catalog and no mutation on attempted
insertion. 220 PHP and 125 JavaScript tests pass. The native HTTP test checks
that a saved repetition draft fails publication while its identity and the
previous active version remain unchanged, then restores the valid draft.

### Builder type labels — 2026-09-27

All 32 core field types and layout/presentation types now have English and
Spanish palette labels. Core field metadata declares `label_key`; Administrator
registers provider label keys with Joomla Text. The browser resolver falls back
to provider `label` and then its identifier if a key is unavailable. Authored
labels, administrative labels, titles and content retain precedence in the tree.
New fields receive the translated type label as editable initial text; existing
content is not relabelled. Provider IDs, field types and machine names are unchanged.

PHP coverage enumerates all core providers against both language catalogs; native
language-loading/parity tests remain green. JavaScript covers translated labels,
missing translations, custom-provider fallbacks and nonmutation. 221 PHP and
126 JavaScript tests pass. In installed Joomla, the palette showed readable names,
a new Short text field retained its label after save/reload, existing First name,
Last name and City labels stayed intact, and untitled structural nodes displayed
Section/Row/Columns. Evidence: `joomla-builder-type-labels.png`. The browser session
used English; Spanish labels are covered by catalog/native-language tests here.

### Builder available-width reflow — 2026-09-27

Joomla Atum adds horizontal padding to details elements. In the narrow palette
this left about 84 CSS px per button and split ordinary words. Scoped palette
padding and button alignment recover about 24 px without changing other panels.
The builder also now uses an inline-size container query: at 48rem or less of
available Administrator content it stacks palette/tree/inspector, accounting
for the sidebar rather than only the browser viewport.

Native measurements at a 1050px window reproduced builder width 683px versus
scroll width 694px before the fix. After it, both were 683px and all three
panels had the same x coordinate. At 390px, builder width/scroll width were both
343px and page/client width both 375px (scrollbar excluded). Restoring desktop
returned three panels on the same row with 913px available. Evidence:
`joomla-palette-readable.png`, `joomla-builder-narrow.png`, and
`joomla-builder-mobile.png`. This establishes this editor scenario, not full
responsive or accessibility acceptance across all administrative views.

### AJAX inline error associations — 2026-09-27

Previously showErrors updated only the summary and aria-invalid, leaving AJAX
messages unassociated with their controls and potentially retaining stale server
feedback. Core rendering now supplies a hidden per-field error container; the
browser updates or creates that container, associates all group controls through
aria-describedby, preserves unrelated descriptions, and clears text/association
when the error disappears. Text is assigned through textContent. IDs include the
form instance and field UUID.

Two browser-runtime unit tests verify group association, replacement, cleanup,
provider help preservation, optional container creation and instance isolation.
221 PHP and 128 JavaScript tests pass. Native Joomla fixture 1584 displayed a
required First name error inline and in the summary. Keyboard activation of the
summary link focused the corresponding input. Correction and submit produced
accepted reference `ac8f631b-23e7-468f-9458-3a2680595a30`; the error container became
empty/hidden, aria-invalid disappeared and aria-describedby retained only help.
Evidence: `joomla-inline-error.png`. UX-002 is partial; this does not establish
all field/provider, traditional-submit or screen-reader acceptance.

### Traditional summary destinations for choice groups — 2026-09-27

A rendering regression reproduced missing hash targets for radio/checkbox/button
groups: the summary used the base field ID while their controls used suffixed
option IDs. Every rendered field now has a unique instance-scoped container ID
with tabindex=-1. Traditional summaries target that container and declare their
field identity; their destinations therefore remain valid without JavaScript
and independently of options. With JavaScript, delegated summary navigation
opens the relevant step and focuses the first enabled non-hidden control, falling
back to the field container. AJAX links share that focus selection method.

The PHP test covers text and three choice groups in page/module instances.
JavaScript tests cover step opening, hidden/disabled controls, empty groups and
unknown references. 222 PHP and 129 JavaScript tests pass. Native HTTP coverage
adds a full-page invalid POST across all eight selection providers and checks
that every one of the eight error links has exactly one focusable field target.
The broader screen-reader/provider acceptance matrix remains open.

### Inactive fields no longer leave stale errors — 2026-09-27

FormInstance retains the currently displayed errors, including core feedback
rendered by the server. After rule reevaluation, errors belonging to inactive
fields are removed and inline associations are cleared without focusing the
summary. Other errors are retained; reactivation does not resurrect stale errors.
The filtering regression also checks that the previous error object is unchanged.
130 JavaScript tests pass.

Native fixture 1952 made Initial answer required. Next displayed its error;
checking Hide initial step removed the summary and inline text, hid the error
container, retained focus on the checkbox and moved to the next active step
(progress 1/2). Reactivation left the summary hidden. Returning to the initial
step and pressing Next validated it again and restored the required error.
Evidence: `joomla-inactive-error-cleared.png`. The HTTP fixture now covers the
same required field while verifying authoritative omission when its step is hidden.

### Focus recovery after rule updates — 2026-09-27

Refresh captures focus only when it started inside the current form. After the
DOM and step navigation update, an unusable or removed focused control triggers
recovery to the next usable control in DOM order, the last preceding candidate,
or the result region when none remain. Disabled controls, hidden inputs and
hidden/inert ancestors are excluded. A valid focus assigned by a widget is retained.

Two JavaScript regressions cover successors, previous-control fallback, result
fallback, disconnected controls, widget focus and updates without an affected
field; 132 JavaScript tests pass. The package was installed and native fixture
1952 rechecked: triggering the hide-step rule retained focus on its still-visible
checkbox, cleared the error and selected progress 1/2. Evidence:
`joomla-rule-focus-retained.png`. Actual asynchronous provider-triggered removal
and assistive-technology verification remain part of the wider acceptance matrix;
the recovery branches are established by unit tests in this change.

### Initial prefill parity — 2026-09-27

Preview and public component/module rendering now share initial prefill
normalization. External values rejected during normalization or optional-field
validation become empty; required errors are deferred until validation. Invalid
configured defaults still raise normalization errors rather than being concealed.
This fixes preview failures for malformed numeric prefills and preview-only display
of values that public rendering had already rejected.

The installed Joomla test `tests/joomla-preview-parity.php` compares type, value,
required, min, max, maxlength, readonly and disabled attributes for text, integer
and email fields, with valid, malformed and out-of-range query values. It verifies
retained valid values, disabled preview submission and absence of submissions.
The targeted unit regression and full PHP suite pass (231 tests); the MariaDB
pipeline suite passes. UX-001 remains PARTIAL: all providers, rule interactions,
translations and browser geometry have not been covered by this comparison.

PHP lint and the complete installed Administrator/public HTTP suite also pass
on development package SHA-256
`6a966d4c20a17608aa46eef0aba21af1e8c0274bcbb10f76fccd569996520338`.
This establishes the tested development artifact, not production release acceptance.

### Historical preview, derived fields and rules — 2026-09-27

The native preview parity fixture now performs 30 component/module comparisons:
three initial prefill cases, plus four rule states in en-GB, es-ES and fallback
fr-FR. It checks a readonly copy, source-field inactivity inherited from a
fieldset, conditional required, rule value precedence and translated labels.
Expected values and states are asserted independently of parity. A saved draft
changes both the translation and rules after publication; current preview sees
the draft label, while historical preview and public output retain the published
snapshot. No response is created. Results are recorded in
`build/joomla-preview-parity-results.json`.

The DOM projection now uses hasAttribute for required/readonly/disabled;
getAttribute alone could equate an absent boolean attribute with a present empty
one. This strengthens the earlier comparison. The targeted native test passes
against the previously installed development package; production code did not
change in this step. UX-001 and FORM-007 remain PARTIAL: browser-driven rule
transitions, complete provider coverage and visual/accessibility parity are not
established by these server-rendered DOM comparisons.

### Option defaults after rule filtering — 2026-09-27

A new regression reproduced a server failure: readonly select/multiselect fields
selected their defaults before filter_options/change_options, retaining values
absent from the final options and rejecting otherwise valid submissions. The rule
engine now recomputes option-derived defaults from the final enabled options.
Explicit set_value/clear_value effects retain precedence even when they assign the
same value; other provider effects that change the value also suppress this
recomputation. Invalid explicit rule values still fail option validation.

The regression covers single/multiple selections, filtering, replacement, explicit
clearing and invalid explicit assignment. All 232 PHP tests, the MariaDB pipeline
suite and 30 native preview comparisons pass. The new development package was
installed in Joomla with SHA-256
`5f52e5ababfea7bcd7613588b3ebbf08989ff4d08297988ee3236c76d3606369`.
DATA-001/DATA-003 remain PARTIAL; interactive browser recomputation for readonly
source defaults has not been certified by this server regression.

The complete installed Administrator/public HTTP regression also passes on this
package. This does not extend the source-default regression to interactive browser
transitions.

### Browser readonly option defaults — 2026-09-27

PublicSpec now emits single/multiple option-default recomputation only for
server-derived readonly selections, excluding explicit defaults and trusted
integration overrides. Option default flags survive static snapshots, rule option
replacement and remote options responses. The browser rule engine resolves those
defaults after filtering/replacement and preserves explicit value-effect priority.
Editable selections receive no recomputation flag, so clearing stays intentional.

233 PHP tests and 133 JavaScript tests pass. The new tests cover single/multiple
choices, dependency changes, disabled options, filters, replacement, explicit
clear/set precedence and projection boundaries. Installed development package:
`37f33ed6478f71c3a5426aa1c9c6b3ff94ec00b8cd59aed8b5dcefa9f6655b01`.
Native fixture 2415 (`tests/joomla-option-defaults.php`) was changed through the
browser: parent one -> two selected b / [b,c]; returning to one selected a / [a].
Both controls remained disabled. Screenshot:
`tests/artifacts/joomla-readonly-option-defaults.png`.

DATA-001/DATA-003 remain PARTIAL: this interaction establishes static dependent
sources for native select/multiselect. The remote provider and full custom-widget
matrix, and corresponding preview interaction checks, remain open.

The full installed Administrator/public HTTP suite and 30 server-rendered preview
comparisons also pass on this package.

### Remote option defaults and response validation — 2026-09-27

RemoteOptions rejects nonboolean default flags, duplicate literal option values,
nonboolean success markers and array-shaped option maps. A multi-field regression
proves atomic replacement: a malformed second field leaves both cached lists
unchanged, displays retry state and accepts a subsequent valid response. Distinct
case-sensitive identities remain valid. Existing out-of-order response tests pass;
134 JavaScript tests pass in total.

The synthetic provider can now emit defaults for its country-dependent options.
After installing package SHA-256
`e91ff0117f7c0ff64bd38c2e9f6086e327133eaf7c79bae012f2bf97e164e278`,
fixture 2453 was tested in the native browser. ES -> FR removed both selections;
FR -> ES restored MD and [MD, BC] in readonly select/multiselect. Submit was
disabled during loading and enabled after completion. Evidence:
`tests/artifacts/joomla-remote-option-defaults.png`. The native dynamic-options HTTP
suite also passes (method/CSRF/channel/version checks, option projection, dependent
refresh, stale submitted choice rejection and accepted valid submission).

DATA-001/DATA-003 remain PARTIAL: this establishes one synthetic remote provider
and two core controls, not the full provider/widget/accessibility matrix. Preview
remote refresh remains an open functional gap.

### Interactive remote options in Administrator preview — 2026-09-27

Preview now uses a parent-owned Administrator POST transport for remote options.
The route requires core.manage, per-form edit permission and CSRF, normalizes
values through ValidationEngine, builds user/view-level context from Joomla and
returns only safe option attributes. Draft requests pin the preview revision
(409 after edits); historical requests compile their exact saved snapshot. Files
and passwords are excluded from preview option inputs. No submit token or Action
execution is introduced. Existing debouncing, cancellation, atomic replacement,
retry state and stale-response suppression apply to this transport as well.

Native HTTP tests establish POST/CSRF, revision conflict, locale/value rejection,
private-marker exclusion, default flags, dependency changes and historical
isolation after deliberately invalidating the draft. The complete HTTP suite,
233 PHP tests, 135 JavaScript tests, lint and 30 native preview comparisons pass.
Package SHA-256:
`e0a1a889a7c464157ba7b65e581f9d35008a772f869c00868de66aa755e4896a`.
The synthetic plugin fixture now skips asset registration when no HTML document
exists, allowing CLI fixtures to coexist with its enabled browser provider.

Native editor 2453: preview ES -> FR cleared both readonly lists; FR -> ES
restored MD / [MD, BC], while Submit remained disabled. Evidence:
`tests/artifacts/joomla-preview-remote-options.png`. FORM-007/UX-001 remain PARTIAL:
full widget, locale, accessibility and provider matrices are still open.

### Preview option authorization and read-only evidence — 2026-09-27

`tests/joomla-preview-boundaries.php --remote` exercises real Joomla assets and a
synthetic registered user. Guest and unprivileged calls are denied; granting
core.manage, form management and core.edit allows the expected MD/BC remote
options. Revoking only core.edit denies the next call. Component/form asset rules
are restored in finally. Snapshots of the fixture's form row, versions, responses,
Action runs and audit rows remain identical across successful and denied queries.
The timestamped result is `build/joomla-preview-boundaries-results.json`.

The Administrator HTTP regression additionally supplies an existing published
version belonging to another form and requires HTTP 404. This strengthens the
previous test of a nonexistent version; neither case is treated as a draft lookup.
No production source changed in this verification step. Broader provider and
accessibility acceptance remains open.

### Dependency-scoped remote refresh — 2026-09-27

Remote refresh previously serialized every active answer, so unrelated edits
(and changes to a remote field's own derived default) could restart queries. The
runtime now derives watched inputs from source dependencies, field prefills and
all nested rule conditions, and also tracks activation of remote targets. It
retains the full latest values for requests and explicit retry semantics.

JavaScript regressions prove ignored unrelated edits and leaf selections,
dependency and nested-condition invalidation, target deactivation, forced retry,
and a remote-to-remote dependency chain. All 137 JavaScript tests pass. This is
conservative dependency scoping, not a minimal per-source graph or a change to
the request data contract. Native dynamic HTTP checks pass on the installed
package SHA-256
`e889832135288560c85d51167b08d0c8a4a5d10518d2b14f314f1c98e12a81f1`.
No quantified traffic/latency improvement or complete provider matrix is claimed.

### Derived provider normalization failures — 2026-09-27

A strict synthetic field provider reproduced an uncaught InvalidArgumentException
when copying a compatible logical datatype whose value the destination rejects.
RuleEngine now records a closed type error with an empty derived value, resets
those errors each iteration, and clears the derived failure when a rule explicitly
sets/clears the destination. ValidationEngine rejects active optional destinations
with this error; inactive destinations remain excluded. Provider exception text
is not part of the returned error data. Final provider validation still applies
to replacement values.

The regression establishes the prior exception, normal field error, empty display
state, successful valid input, explicit set/clear recovery and hidden destination.
234 PHP tests and the MariaDB, MySQL 8 and PostgreSQL pipeline suites pass.
The rebuilt development package was installed with SHA-256
`7ed5fe86ebe102d6cfc69071e36562c90f8032a9bb674cced8e2f020addfba65`.
This change establishes the server boundary; full browser-provider derived-value
normalization/parity remains unverified and the corresponding requirements stay
PARTIAL.

The complete installed HTTP suite and 30 native preview comparisons also pass
on this package.

### Browser-derived provider normalization — 2026-09-27

The browser rule engine previously copied source values directly, skipping the
destination normalizer. It now uses the shared normalizer, moved to an independent
module to avoid a rules/validation import cycle. Derived failures yield a null
value and closed type error consumed by FormInstance validation; explicit set/clear
rule effects remove that failure just as on the server. Unit coverage includes
provider uppercase/trim transformation, rejection, later valid input and rule
recovery, plus core text trimming. All 138 JavaScript tests pass.

Installed package SHA-256:
`f5de5d4117d1eea45d52750a1ea77e2158d2d8217174c261d0d20a3f05695cb4`.
Native fixture 2621 (`tests/joomla-derived-provider.php`) loads the actual synthetic
browser field provider. Source text retains surrounding spaces and mixed case;
its readonly derived field changes to MIXED CASE. Evidence:
`tests/artifacts/joomla-derived-provider-normalization.png`.
The native interaction establishes transformation; strict-provider failure and
recovery are unit-tested, not yet covered by a full native provider matrix.

### Repeated-field identity foundation — 2026-09-27

ADR 0019 establishes stable nested instance identity without overloading array
positions or multiselect ordinals. PHP FieldAddress and the browser field-address
module encode/decode ancestor group/instance UUID pairs followed by the field UUID.
A nonrepeated address remains the existing field UUID. Both reject malformed and
ambiguous paths, repeated ancestor group IDs, field/group identity collisions,
extra instance properties and depth beyond 64; the maximum key is 4,772 bytes.
JavaScript additionally rejects inherited-property instance objects and trailing
newlines. 235 PHP and 139 JavaScript tests pass.

This is foundational code, not an activated repeatable runtime: it is not yet
wired to renderer, submission, persistence or search. LAYOUT-006 stays PENDING,
and the compiler publication guard remains. Full nested-repeat support and its
cross-cutting acceptance remain required by the mission.

### Repeated instance membership — 2026-09-27

RepeatedInstances now validates ordered declarations against the repeated ancestry
of the layout. It rejects unknown/foreign scopes, orphan nested declarations,
duplicate rows, malformed UUIDs, maximum overflow, ancestry cycles and malformed
limits. Scoped membership prevents a row under one parent from authorizing the
same field address under another. Map declaration order is irrelevant; row order
is retained. Empty/missing groups produce scoped minimum errors for later
active-scope evaluation. Explicit budgets bound accumulated rows and scope walks.

236 PHP tests pass, including valid nested membership, reordering without identity
changes, nonrepeated fields, cross-parent forgery and the structural rejection
cases. The component is not yet connected to public requests, persistence or the
renderer; LAYOUT-006 remains PENDING. This does not certify validation by instance
or activation semantics in the executable runtime.

### Repeated control expansion and raw binding — 2026-09-27

RepeatedInstances now expands the layout in row-major order and binds raw answers
to declared instance addresses. Missing controls are explicitly present as null,
so later validation can detect absent required answers. False, zero and multivalue
lists retain their types. Unscoped answers to repeated fields, foreign addresses,
leaf-parent structures and traversal-budget overflow are rejected. Empty groups
produce no controls while ordinary fields remain present.

238 PHP tests pass. The regression covers two outer rows, a nested row under one
parent, declaration/layout ordering, missing fields, multivalue distinction,
caller-input preservation and invalid addresses/parents/budgets. This remains a
domain-stage implementation; no public POST or storage format has been switched,
and full repeated runtime integration is still required.

### Scoped repeated references and relational validation — 2026-09-27

RepeatedInstances resolves field references in the same row or an ancestor scope,
including ordinary fields outside repetition. It rejects forged origins, unknown
fields and implicit references into descendants/sibling groups. Reused row UUIDs
under different parents do not merge their contexts.

ScopedValidator adapts existing declared-field validators to a row, supplying
only its referenced accepted values and translating violations back to complete
instance addresses. Tests establish separate confirmation results in two rows,
exact error addresses, shared outside-field comparison and inactive-field omission.
240 PHP tests pass. This begins per-instance validation integration through the
existing validator contract; activation/normalization, rule expansion and the
public submission pipeline remain unconnected. LAYOUT-006 is not complete.

### Shared final field validation by instance — 2026-09-27

FieldValueValidation now provides the final normalization, provider validation,
index bounds, enabled-option membership and serialization checks used by the
ordinary ValidationEngine and AddressedFieldValidator. The addressed stage
requires authoritative active/required/options states for every expanded field;
missing states fail explicitly. Inactive fields contribute neither accepted
values nor errors. Existing normalization failures remain errors even for
optional fields.

241 PHP tests pass, including independent errors in two rows, missing required
answers, disabled selections, index-length overflow, canonical values and inactive
field omission. The MariaDB, MySQL 8 and PostgreSQL suites and the complete native
Joomla HTTP suite pass. Development package SHA256:
`ba1532fa6b0f04f33bbef9c71bc2588198f90e17fc0d562fa8fb0afba70e1ba5`.
The package was installed successfully in the Joomla 6.0.0 test site.

This verifies a shared final-field stage, not a complete repeated-form runtime.
Per-instance rule evaluation, trusted initial values, active group minimums,
relational/global orchestration, files, rendering and persistence still require
integration. The repeated-group publication guard remains; LAYOUT-006 is PENDING.

### Repeated rule execution graph — 2026-09-27

RuleEngine now has an internal addressed evaluation entry using the same fixed
point engine as ordinary forms. A bounded transient graph expands containers,
field copies, condition references and effects for each declared instance.
Provider source configurations retain their definition UUIDs while receiving
only the dependency values of the applicable row or ancestor. No published
snapshot is rewritten.

244 PHP tests pass. New cases verify row-specific conditions, inherited container
activation, required errors through AddressedFieldValidator, source-dependent
option defaults, explicit effect order, shared outside references, nested groups
with reused child row UUIDs, unknown/unscoped input rejection, ambiguous reference
rejection and the independent expanded-effect budget. PHP lint passes.

These are domain/runtime-entry tests with deliberately constructed internal
specifications; they do not establish browser or public-submission support for
repetition. Trusted initialization, active minimum validation, complete validator
orchestration, files, rendering and persistence remain unfinished. LAYOUT-006
remains PENDING and the compiler publication guard remains enabled.

The development package installed successfully in Joomla 6.0.0 and the complete
native HTTP regression suite passed, including the repetition publication guard.
Package SHA256:
`39d6a7ee084b0ffbbe0c783bd18d1e6c198050571d7e58da94101a6df35087d9`.

### Integrated internal repeated submission validation — 2026-09-27

ValidationEngine::validateInstances now connects addressed raw binding, the
shared submission authority/normalization policy, repeated rules, final field
validation, active group minima and scoped relational validators. Explicit
trusted nulls suppress derived defaults only in their own row. Field validators
run for active owners; form validators run in the deepest comparable reference
scope. Sibling-group ambiguity is rejected even when no rows exist. Expanded
validator calls have a separate budget and duplicate violation codes are removed.

247 PHP tests pass, including forged readonly input, canonical field copies,
row-specific confirmation failures, missing required values, trusted-address
rejection, hidden validator owners, nested active minima, deepest ancestor scope
selection and expanded-validator budget exhaustion. The complete native HTTP
suite and MariaDB, MySQL 8 and PostgreSQL regression suites pass. Development
package installed successfully in Joomla 6.0.0; SHA256:
`5f7525c802ddd296870398fb9fb5344129681bb4637fcb5a9938da45328143a8`.

Repeated validation tests use internal specifications while the compiler still
blocks publication. These checks do not establish public transport, upload,
browser, storage or indexed-query support for repeated responses. Presentation
initialization and an explicit cross-group aggregation contract also remain
unfinished. LAYOUT-006 remains PENDING; the overall mission is not complete.

### Shared ordinary and repeated presentation initialization — 2026-09-27

PresentationState now supplies the initial/retry rule state to FormDisplay and
FormPreview and provides an internal addressed entry for repeated rows. The
shared policy handles trusted values, initial prefills/defaults, per-row derived
copies, invalid-prefill clearing, editable retry omissions and suppression of
password/file repopulation. Explicit trusted nulls retain their authority.

248 PHP tests pass. The new repeated fixture checks independent row defaults,
trusted overrides, field-copy results, out-of-range query prefill, malformed
retry input, cleared answers, password suppression and unscoped input rejection.
All 30 native preview/component/module parity comparisons pass, including
historical snapshots and three locales. These parity checks exercise ordinary
forms using the shared service; repeated rendering is not yet connected.

Initial row declaration creation, add/remove controls, repeated rendering,
transport, upload integration and persistence remain unfinished. The compiler
guard remains enabled and LAYOUT-006 remains PENDING.

The updated development package installed successfully in Joomla 6.0.0 and the
complete native HTTP regression suite passed. Package SHA256:
`e271be41ea908e8dee911dec42c8627db350f2d07fdc7a740c96618273b6d341`.

### Initial repeated row declarations — 2026-09-27

RepeatedInstances::initial creates minimum rows with UUID identities, recursively
seeds nested minima under each existing parent and leaves zero-minimum groups
empty. Both total rows and expanded layout nodes are bounded, including empty
rows. It is separate from submitted declaration validation, which never creates
missing rows to repair a request.

250 PHP tests pass. New tests verify nested minima, empty optional groups, valid
distinct generated identities, unchanged addresses through PresentationState and
ValidationEngine, declaration round trips, missing submitted rows and both
multiplicative expansion and empty-row budget exhaustion. These are internal
domain tests; add/remove controls, repeated rendering, public transport and
persistence remain pending. LAYOUT-006 is not complete.

Development package built with SHA256
`f1eb1f3dc988bef7bc80793e7fd3696fc010c79fa915ff4f4dee0d58e8e29b98`.
This package has not been installed in the native test site in this step.

### Immutable repeated row editing — 2026-09-27

RepeatedInstances now supports adding and removing a row by full group address.
Adding seeds only the new subtree and enforces the maximum. Removing enforces
the minimum and removes only descendant declarations under the selected row.
Both operations preserve the original instance object and validate total budgets
before returning a replacement.

251 PHP tests pass. New coverage includes nested minimum seeding, unchanged
sibling identities/order, reused child IDs under separate parents, add/remove
round trips, limit enforcement, forged group scopes, unknown rows, stale answer
rejection and unchanged original state after budget failure. These are domain
operations; UI controls, answer/file cleanup and public persistence integration
remain pending. LAYOUT-006 remains PENDING.

Development package SHA256:
`f0a3bb8d8849fa28ba4f8a4b1c648936c92f6d5b14291d7e8bc84a1803752e72`.
Built in this step; native installation/HTTP tests were not repeated for these
unconnected domain methods.

### Addressed browser projection — 2026-09-27

PublicSpec now has an internal instance projection using the ordinary public
allowlist after expanding rules, field copies, field/form validators, dependency
lists and static option conditions. Remote snapshots use each instance's active
state; trusted-default suppression of copies remains per instance. Validated row
declarations accompany the graph. Missing expected field states reject projection.

252 PHP tests pass. The new two-row test checks complete reference addresses,
active/inactive remote options, pinned copy suppression and exclusion of action
secrets, remote credentials/endpoints, administrative labels and private option
properties. This is a browser data contract, not completed repeated rendering or
client interaction. LAYOUT-006 remains PENDING and publication stays blocked.

The 30 native preview/component/module comparisons also pass after the shared
projection refactor. Development package built (not installed in this step),
SHA256 `1b276b96fe2894297215d53a744586d1f4b80bf5572181df16996d977566d243`.

### Shared renderer for declared repeated rows — 2026-09-27

FormRenderer::renderInstances now renders declared nested rows through the same
control providers and layout traversal used by ordinary forms. Full addresses
identify input names, labels, help, error targets and group minimum errors.
Component/module instance prefixes keep DOM identities separate. Fieldsets and
legends identify groups and rows. The addressed public graph and a hidden JSON
declaration field accompany the output.

253 PHP tests pass. New DOM checks verify nested row counts, unique IDs, matching
labels/input names, correct field/group error targets, escaped submitted markup,
separate component/module identities and matching JSON declarations/projection.
The renderer entry remains internal: browser add/remove controls, public parsing,
upload handling and repeated persistence still require integration. LAYOUT-006
remains PENDING, with publication blocked.

The updated package installed successfully in Joomla 6.0.0. The complete native
HTTP suite and 30 preview/component/module parity comparisons pass after the
shared renderer change. Package SHA256:
`fddba376b3aad44adec68b9bf4c9160fc8ff9479ec991ba3a8cd24aa479378a6`.
Repeated browser interaction and visual QA are not established by these ordinary
form regressions or the new DOM assertions.

### Isolated repeated browser runtime verification — 2026-09-27

tests/browser-repeated.php generates a preview-only page containing two forms,
each with two nested repeated rows, editable names, readonly copies and a
conditional required email panel. It uses the real renderer, public projection,
CSS and browser initialization modules. The generated build/repeated-browser
directory was served only on loopback port 13376, with copied public JS/CSS.

Browser interaction verified that changing the first name updates only its copy;
unchecking its detail control hides/disables its email and removes required;
the sibling row and second form stay unchanged. Rechecking restores visibility,
enabled and required states. Clearing the name clears only its copy. Both form
runtimes report initialized, all DOM IDs are unique and both submit buttons stay
disabled. Screenshot: tests/artifacts/repeated-browser-isolation.png. Observed
final DOM report: build/repeated-browser-results.json.

The fixture adds page-level spacing for inspection and is not a published Joomla
form. This proves these specific existing-row browser interactions, not adding
or removing rows, repeated validation summaries/minima, mobile layout, uploads,
public submission or persistence. Those integrations remain pending and
LAYOUT-006 remains PENDING.

### Browser active minimum validation — 2026-09-27

The addressed public graph now includes group limits and titles. Browser
validation checks minimum counts only for active groups, respects the selected
step, and fails closed for missing states or malformed/over-maximum counts.
Group errors enter the summary with focusable group targets and their own inline
message/ARIA association. Error lookup now matches the exact address so a group
cannot overwrite a descendant field's message. English and Spanish runtime
messages include min_instances.

253 PHP and 142 JavaScript tests pass. New JS tests cover active/inactive scopes,
malformed state, step filtering and preservation of descendant errors. The
isolated browser fixture now exposes a test-only validation button and an empty
conditional group. Browser interaction verified its summary and inline error,
link focus with aria-invalid, and automatic error removal when deactivated.
Screenshot: tests/artifacts/repeated-active-minimum.png. These checks do not
establish public repeated submission or add/remove controls; publication remains
blocked and LAYOUT-006 remains PENDING.

For the browser active-minimum change, PHP lint and the complete native HTTP
regression suite pass after successful installation in Joomla 6.0.0. Package
SHA256: `dfdfaf4c5796f3688530fe220688cc84134020574f37d528e3e88bacb15c9dc4`.

### Repeated multipart and native request parsing — 2026-09-27

The browser packer now accepts canonical full addresses while keeping multivalue
arrays distinct from rows, retaining native file parts and preserving the row
declaration part. RequestAdapter::requestInstances validates a bounded JSON
declaration object and shares ordinary request-envelope parsing. Answer and file
keys must belong to declared instances. Its RepeatedSubmitRequest wrapper is
not yet consumed by public endpoints or the persistence pipeline.

254 PHP and 143 JavaScript tests pass. New coverage checks exact Unicode and
whitespace, selection arrays, multipart file bytes/order, traditional and packed
value equivalence, invalid address atomicity, duplicate/overflow rows, malformed
or oversized JSON, foreign answer/upload scopes, POST requirements and mixed
envelope rejection. Missing rows remain minimum errors for later active-scope
validation. Native upload parsing does not certify file provenance or storage.

Public repeated submission, upload processing, persistence and actions remain
unfinished; publication stays blocked and LAYOUT-006 remains PENDING.

PHP lint and the complete native HTTP regression suite pass after successful
installation in Joomla 6.0.0. Package SHA256:
`734cbed0b51a1f2f3d67626eb05be2aa88cb51af7ab9a6c5f076d6de118615c2`.

### Repeated stored-value policy — 2026-09-27

StoredValues now centralizes persistence-mode, per-field persistence, password
exclusion, consent evidence and option-label selection for the ordinary repository
and an internal addressed projection. The latter requires successful validation,
complete authoritative states, declared addresses and satisfied active minima.
It rejects inactive accepted values and stores only active group declarations in
full mode. Metadata/no-store projections contain no values or row declarations.

256 PHP tests pass. New cases verify row order, independent canonical values and
consent evidence, password/nonpersistent exclusion, filtered labels, metadata and
no-store output, invalid-result rejection and inactive-branch omission. MariaDB,
MySQL 8 and PostgreSQL regression suites pass with the ordinary repository using
the shared selection. This does not establish repeated SQL persistence: replay
fingerprints, transactions, indexes, files, queries and actions remain to be
connected. LAYOUT-006 remains PENDING and publication remains blocked.

PHP lint and the complete native HTTP regression suite also pass. Development
package installed successfully in Joomla 6.0.0, SHA256:
`7fd82efccb2044f6c83a29e22a8b4c71ef65c4cbed1f784e40397f79eb060197`.

### Repeated retry fingerprints — 2026-09-27

RequestFingerprint now shares the ordinary repository's byte-compatible HMAC
construction and provides an internal addressed variant. Active row declarations
and order join all validated values, including action-only fields. File identity
uses stable content metadata instead of transient receipt IDs or storage paths;
addressed file material must match canonical receipts.

257 PHP tests pass. New checks cover stable retries, changed row order, changed
nonpersistent values, channel/locale changes, replaced file UUID/storage key with
unchanged content, changed checksum, missing/duplicate/foreign file context and
legacy ordinary hash compatibility. PHP lint passes.

The first MariaDB run failed its job-claim isolation assertion because a prior
fixture retention job was still claimable. The guarded isolated database fixture
now cancels prior nonterminal jobs before creating this run's test jobs. MariaDB
then passed; MySQL 8 and PostgreSQL also passed their regression runs. No production
job behavior was changed. Repeated transaction/replay integration remains pending.

Development package built (not installed in this step), SHA256:
`225207fc632bd5fabd29650bc1f4067f98b15b25ce4fc65f1778787806832e1e`.
LAYOUT-006 remains PENDING and publication remains blocked.

### Addressed typed index projection — 2026-09-27

IndexProjector now derives internal repeated index entries using the ordinary
type conversions, index limits and field policies. Each entry carries the
definition field UUID, full address, exact ancestor instance path, path hash and
within-field value ordinal. Reordering rows preserves entry identity. A separate
output budget bounds multivalue amplification.

258 PHP tests pass, including per-row multiselect ordinals, shared scope hashes
for different fields, distinct sibling scopes, canonical zero, sensitive and
nonpersistent exclusion, reorder stability, foreign rows, oversized keywords and
output-budget overflow. MariaDB, MySQL 8 and PostgreSQL regression suites pass
with the shared ordinary projection. Repeated SQL storage/query integration and
schema migration remain pending; this does not certify repeated search support.

PHP lint passes. Development package built, not installed in this step; SHA256:
`bd75972864847a88db90cdb9bf5f9d5b619c8cdf7e9df0b188cd4a4d042b70e7`.

### Repeated instance SQL schema (integration boundary)

`InstanceSchema` now migrates same-version development installations without
changing existing answer values or stored file keys. Index and file rows retain
the exact instance path and SHA-256; ordinary rows use the empty scope by default.
Index uniqueness includes the instance hash and the within-field ordinal. The
installer, portable generated DDL and schema diagnostics share this contract.

The complete MariaDB, MySQL 8 and PostgreSQL suites pass, including new isolated
legacy-table migration checks: preserved Unicode values and file keys, repeated
upgrade, distinct scopes, duplicate-ordinal rejection and full 64-ancestor paths.
258 PHP tests pass. Repeated repository writes, reindexing and correlated queries
remain unintegrated; LAYOUT-006 remains pending and publication stays guarded.

PHP lint and the complete native administrator/public HTTP regression suite pass.
Development package installed successfully in Joomla 6; SHA-256:
`07c03a3988db591f3db87a1a1c281f69330fccab6dbdd45cf25aa51c135a566b`.

### Repeated repository transactions

`SubmissionRepository::persistInstances` now connects validated addressed values,
active declarations, request fingerprints, scoped typed indexes and file rows in
the same transaction as ordinary responses. `findReplayInstances` verifies the
same identity. Reindexing retains canonical payloads and rebuilds addressed keys.

258 PHP tests and all three SQL suites pass. The new internal-snapshot SQL fixture
checks row order, transient-field exclusion but replay inclusion, distinct scoped
indexes/files, unchanged canonical payload on reindex, rollback after a late file
constraint conflict, regenerated file receipt replay, and metadata/no-store
omission with completed no-store replay. Compiler publication remains guarded;
public pipeline, reading/export, correlated search and actions are not yet wired.

PHP lint and the complete native HTTP regression suite pass after installing the
updated development package in Joomla 6. Package SHA-256:
`83d369bf5a8f2bed625f07a7a7c9cbd6b08f465cd547844739a780cc8e5ac3d5`.

### Repeated historical reads and attachment ownership

Historical reading now expands stored declarations and applies masking, reveal,
export inclusion, option-label and consent policies to exact field addresses.
Nested layout projection preserves row order and scope without exposing authoring
configuration. Attachment lists and downloads require the canonical receipt in
the exact stored scope, with hash and membership checks before opening bytes.

259 PHP tests pass, including both the preserved presentation-state retry test
and the new nested-history test. All three database suites pass with repeated
sensitive reveal/audit, export-read policy, private byte streaming, sibling/foreign
scope rejection and invalid hash rejection. CSV job shaping, public pipeline,
correlated search and actions remain unintegrated; publication remains guarded.

PHP lint and the complete native HTTP regression suite pass after package
installation in Joomla 6. Development package SHA-256:
`78e53bcc8452300db2b0d445e271632855c1f860de876ce36fe3c8f1bb489b32`.

### Repeated CSV field columns

The CSV job now groups authorized addressed values into ordered JSON lists of
instance_path/value objects in each selected definition-field column. Ordinary
scalar and multivalue cells retain their representation. Nested instance paths,
zero/false, Unicode and multivalue boundaries are preserved. Missing or excluded
answers remain blank; historical reader policy precedes projection.

260 PHP tests and all three SQL suites pass. Native database export jobs verify
stable headers, one row per response, repeated value order, historical sensitive
policy, metadata omission and recovery after bytes are written before checkpoint.
This is CSV support; a separate JSON export format, public repeated pipeline,
correlated search and actions are still pending.

PHP lint passes. Development package built; not installed in this step. SHA-256:
`32c56416cdbc3b919dbf9a4692604db232d800b068a9d079d10396c09aa918eb`.

### Repeated SQL filter correlation

SQL field filters support explicit same_instance groups over identical repeated
ancestor chains. One positive indexed predicate anchors each group's row; other
positive/negative predicates compare exact path and hash within that row. Ordinary
independent filters are unchanged. Negative-only groups and incompatible scopes
reject rather than infer empty rows from missing value indexes. Other selected
providers must advertise this capability before receiving correlated filters.

261 PHP tests pass. MariaDB, MySQL 8 and PostgreSQL suites verify independent vs
same-row matching, scoped negative/absent values, injected hash collision isolation,
keyset continuation/query binding, malformed scope rejection and historical privacy.
The MySQL-family fixture quotes the reserved sensitive column correctly. Visual
filter grouping and public repeated form activation remain pending.

PHP lint passes. Development package built, not installed in this step. SHA-256:
`427c246e211ea53fa1793c1e3bee008b55bf1f5c2d4c3e1198409392c16976ba`.

### Repeated action context, tokens and conditions

Action contexts validate addressed membership, preserve declarations when binding
an action and emit ordered scope/value token lists. Existing inclusion and
sensitive/password exclusions precede token output. Whole action conditions are
evaluated within one lexical row; matching several rows still executes one action.
Persisted retry jobs reconstruct declarations and retain attempt fencing.

263 PHP tests and all three database suites pass. All new action transports are
in-memory mocks with synthetic data: no external mail or HTTP is sent. Tests cover
row identity/order, labels, transient values, secret/password exclusion, HTML
escaping, webhook mapping, cross-row condition rejection, recorded failures,
persisted retries and no duplicate effect. Repeated recipient/reply-to selection,
public pipeline integration and compiler activation remain unfinished.

PHP lint passes. Development package built, not installed in this step. SHA-256:
`3e2b4136b52a59d251c722058a45e67112130995a1b6668636f054d4c25065a0`.

### Shared repeated submission pipeline

The addressed entry point now shares public access, active snapshot, CSRF, attempt,
honeypot, rate limit, CAPTCHA, lifecycle and cleanup with the ordinary pipeline.
It reconstructs row membership against the selected snapshot, expands file policies
and option/error addressing, invokes addressed validation/replay/persistence and
passes active declarations into actions. Post-submit conditions use lexical rows.

263 PHP tests and all three SQL suites pass. Internal fixtures exercise all three
persistence modes, CAPTCHA-safe replay, changed-attempt rejection, addressed custom
validation errors, whole-row confirmation, CSRF/CAPTCHA and unpublication. Native
repeated multipart ownership/cleanup, controller routing, dynamic row UX and reset
remain unverified; the production compiler guard stays in place.

PHP lint and the complete native HTTP regression suite pass. Development package
installed successfully in Joomla 6; SHA-256:
`b100b5e9acf64722ca8500943da8f15b803dd24cb76ad29e6780a1ef660df039`.

### Native repeated multipart evidence

A loopback/nonce-only test endpoint, excluded from packages and guarded to the
isolated database, exercises genuine PHP multipart through Joomla RequestAdapter,
SubmissionPipeline, HttpUploadGateway, UploadJournal and SubmissionRepository.
Single and multiple files preserve byte-derived MIME, exact scope and receipt order.
Per-instance limits, undeclared rows and inactive sibling isolation are covered.

Native repeated and ordinary HTTP upload suites pass. Every repeated failure
scenario checks both no remaining staging rows and an exact filesystem inventory:
CAPTCHA, required validation, a later invalid extension and changed replay all
remove unowned objects; replay keeps the original committed files. Synthetic test
objects are removed at completion. No production code or package source changed
in this step; public controller routing/dynamic row UI remain pending.

PHP lint passes for the new fixture and support files. Installed development
package remains `b100b5e9acf64722ca8500943da8f15b803dd24cb76ad29e6780a1ef660df039`.

### Repeated public controller routing

FormDisplay now seeds minimum rows for initial component/module renders and uses
addressed presentation/rendering. Retries require their declarations and retain
row IDs, submitted values, addressed errors and the session/channel-bound attempt.
Public submit/options controllers select the repeated envelope from the published
snapshot. FormOptions validates and projects remote options per row; missing
declarations are rejected. HTML reset preservation maps addresses to configured
definition UUIDs; full reset/dynamic-row UX is still pending.

263 PHP tests and MariaDB/MySQL 8/PostgreSQL suites pass. The new SQL fixture
checks independent initial identities, minimum rows, retry continuity and remote
dependency isolation. The full native Joomla HTTP suite passes with an internal
repeated snapshot: initial render, non-JavaScript validation recovery, options
routing, rejected missing envelopes, corrected persistence and safe replay. That
snapshot is unpublished when the test finishes. Normal publication remains guarded;
LAYOUT-006 is PENDING and the overall mission remains incomplete.

Updated development package installed successfully in Joomla 6; SHA-256:
`ecef4dd71a613e0056f984043523f0bc2a2c84b4680b2ab3060a84238144ba04`.

### Repeated reset preservation and initial-value recovery

Browser success reset now resolves each addressed field to its definition UUID
before applying the configured preserve list. Sibling values, including explicitly
empty values, remain independent. Non-JavaScript reset now restores initial values
for non-preserved fields through PresentationState; ordinary validation retries
continue to leave cleared fields empty. Readonly derivations are recalculated.

263 PHP tests, 144 JavaScript tests, PHP lint and all three database suites pass.
The native Joomla HTTP regression passes with reset-all and preserve-selected
snapshots: values/defaults, row identities and new attempt tokens are checked in
rendered HTML. The fixture's canonical value comparison now checks each address
instead of relying on JSON object-key order; declaration row order remains strict.

Development package installed in isolated Joomla 6; SHA-256:
`b2219fa9421da6c1da329e050be01305eda06ba9c11040f139364fa69380dafc`.
Dynamic add/remove controls and their browser/reset interaction remain unfinished.
LAYOUT-006 remains PENDING, normal publication stays guarded and the full mission
is not complete.

### Public row editing service and endpoint

FormRows now authorizes row edits against the current published snapshot and
session/channel-bound attempt with CSRF and a separate rate budget. It reconstructs
membership instead of trusting the parsed envelope's layout. Domain add/remove
enforces limits, preserves sibling IDs/order and removes only the selected branch.
Existing missing answers remain explicit null; new fields remain absent so the
shared presentation initializes only the new branch. No submission is created.

The POST-only form.rows endpoint returns the shared rendered form with the same
verified attempt. 263 PHP tests, lint and MariaDB/MySQL 8/PostgreSQL suites pass,
including foreign envelope limits, stale version, invalid operations, min/max,
CSRF/session/channel, rate limit, unpublication and existing-empty/new-default
distinction. Native Joomla HTTP regression passes with guarded add/remove, preserved
answers/identities, minimum/maximum rejection and no submission writes.

Installed development package SHA-256:
`fea1b14c70814a26a939eb9724245993e49a9c1cb5a465944f4e63249650d6e6`.
Buttons, browser DOM replacement preserving file controls, no-JavaScript row editing
and associated UX verification remain pending. LAYOUT-006 is not complete and
publication remains blocked.

### Native row buttons and HTML recovery

Repeated renderers now emit add/remove submit buttons with translated labels,
scope-specific accessible names, formnovalidate and min/max disabled states.
Preview buttons remain disabled. Public submit routes the selected button's bounded
JSON operation to row editing, rejecting malformed, extra-key and mixed envelopes.
HTML responses use the ordinary form view; rejected format/limit/rate edits recover
the original verified attempt, rows and values when still available.

263 PHP and 144 JavaScript tests and PHP lint pass. Native HTTP tests use actual
rendered button values through form.submit, verify addition with incomplete required
fields, maximum disabled state, over-limit HTML recovery, removal preserving siblings,
and malformed/ambiguous operation rejection. The installed development package is
`9598e10afcf178c3967087229a3de1de8ae662fe8dd66fcc29dc48c7101db88a`.

The browser currently allows these native row submits (except preview/pending send).
Dynamic DOM replacement, selected-file preservation and focus/widget recovery remain
pending. This is evidence for the HTML route, not browser UX completion or acceptance
of LAYOUT-006; normal repeatable publication remains guarded.

### Dynamic row replacement and retained controls

Browser row editing now sends text/packed values without uploading file bytes,
checks returned form identity and graph shape, then moves retained field nodes into
the new layout. It keeps live FileList objects, widget listeners and typing during
the request. Initial values, declaration defaults, control maps, step identity,
remaining errors and row focus are updated. Previous remote-option requests are
aborted and their callbacks invalidated. Row editing and final submission share
pending state and correctly re-enable controls after failure.

263 PHP tests, 149 JavaScript tests and PHP lint pass. Native Joomla HTTP regression
passes for the changed backend. A real browser fixture using the PHP renderer and
delayed synthetic responses passes add/remove, exact input/file node identity,
late edits, focus, duplicate clicks, rejected edits, reset, two-form isolation and
submission/row mutual exclusion. Evidence: `tests/artifacts/row-editor-browser.png`;
the final rejection message is an intentional transport-failure test.

Final development package installed successfully, SHA-256:
`c0fd218f8b1be393c53285b8925cdddc27692bd54db07b614676b6039d1a323c`.
Combined browser-to-Joomla journeys, nested dynamic rows and custom-widget behavior
still require verification; normal publication remains guarded and LAYOUT-006 is
PENDING. This milestone does not complete the overall product mission.

### Native nested browser journey with a custom provider

A guarded CLI fixture creates an internal nested snapshot in the installed Joomla.
The child group starts empty, exercising lazy browser module/style loading for the
installed fixture.upper provider on the first row addition. The real browser adds
two children to one parent and one child to another, types distinct values, verifies
uppercase normalization and readonly copies, then removes one child and the other
parent with its descendants. Buttons respect bounds and focus follows the edits.

The final browser submission succeeds through the actual Joomla controller.
The CLI verifier checks exactly one canonical response, the observed retained
parent/child UUIDs, exactly two BETA values and no removed branches. Evidence is
`build/native-nested-browser-results.json` and
`tests/artifacts/nested-joomla-browser.png`. The fixture was unpublished on completion;
PHP syntax validation passes. No production source changed in this step, so the
installed package remains
`c0fd218f8b1be393c53285b8925cdddc27692bd54db07b614676b6039d1a323c`.

This closes the tested browser/Joomla/nested/custom-field integration gap, not all
third-party widget behavior. Repeatable authoring and compiler guarantees still need
implementation/audit before publication is enabled. LAYOUT-006 and the full mission
remain incomplete.

### Repeated compiler limits and lexical references

Compilation now adds precise static diagnostics for integer repeat limits, invalid
ancestry, worst-case expanded rows/elements, expanded rule effects and global
condition evaluation budgets. Scope checks cover field prefill, datasource inputs,
field/form validators, rule targets, action conditions and conditional confirmations.
They run independently of initial rows, including min=0 branches. Same/ancestor
references are accepted; descendant/sibling ambiguity is rejected with draft paths.
Repeated email recipient references are flagged until explicit selection semantics
are implemented, without removing that work from the final scope.

266 PHP tests, lint, all three database suites and native Joomla HTTP regression pass.
Tests include exact expansion-budget boundaries, malformed limits, ancestor success,
sibling/descendant rejection, separate rule/condition budgets and compiler integration
with the publication guard still active. Development package installed successfully:
`3fe2df32d3650978712003afa0804e3e40e9f12bb06ce244f3394fa73fdc726c`.

Repeatable authoring, recipient selection and the remaining publication audit are
unfinished. LAYOUT-006 remains PENDING and the overall mission remains active.

### Combined validation budget and CAPTCHA multiplicity

Repeated compilation now totals all field and form validator calls against the
same shared budget used by ValidationEngine::validateInstances. A boundary test
runs both implementations: six calls fit a budget of six; an additional repeated
validator fails at six and passes at eight. The compiler identifies the first
overflowing validator. CAPTCHA placement rejects maximum multiplicity above one;
root and single-instance placements remain accepted by this specific check.

268 PHP tests, PHP lint and the installed native Joomla HTTP suite pass.
The development package was rebuilt and installed successfully, SHA-256:
`16ba73786c5b73a5872e8515474fb0301b40a87642ed39d8c10f95b320fe19f0`.
No JavaScript or database schema changed in this step. Repeatable publication
remains guarded; this evidence does not complete LAYOUT-006 or the product.

### Explicit repeated email recipient selection

EmailAction now supports versioned first_nonempty, last_nonempty and unique
selection for autoresponse and reply-to field references. Candidates follow the
validated layout/row declaration order, independently of input-map order; missing
and empty values are omitted. Invalid addresses anywhere in the candidate set,
no candidate, missing policy or ambiguous unique selection fail before transport.
The compiler requires a supported selection for repeated references. Provider
metadata exposes localized optional selectors in the action editor without an
implicit selected policy. Nonrepeated behavior is preserved.

269 PHP tests and 149 JavaScript tests pass, as do PHP lint and the native Joomla
HTTP regression suite. In-memory mail tests verify ordering in both directions,
distinct/duplicate candidates, empty rows, missing policy and header injection;
no external mail was sent. Native browser interaction with the new selectors and
the complete repeated-recipient retry matrix remain unverified.

Development package rebuilt and installed, SHA-256:
`4cd7d5a5880ba970e8f94d292974d7048491851d0bec6b13469b97dda4205165`.
Repeatable publication remains guarded; authoring/preview and remaining acceptance
work still prevent completing LAYOUT-006 or the overall mission.

### Persisted repeated email retry across later versions

The database action suite now exercises the real EmailAction, ActionRetries and
ActionRetryHandler with a transport that fails definitely once and then captures
the message in memory. Each of the three selection policies runs against nested
parent/child rows. The values map intentionally differs from declaration order.
After persisting the response and its failed action, the test changes both the
draft policy and active form version before enqueuing the retry. Recipient and
Reply-To still match the original snapshot and canonical row order. Successful
delivery removes retry eligibility; replaying the old attempt sends nothing else.

All complete database suites pass on MariaDB, MySQL 8 and PostgreSQL, including
this new scenario; the edited PHP test passes syntax validation. No production
files changed, so the installed development package remains
`4cd7d5a5880ba970e8f94d292974d7048491851d0bec6b13469b97dda4205165`.
There was no external mail transport. This verifies persisted retry selection,
not actual mail delivery or the remaining authoring/publication acceptance.

### Repeated draft limit inspector

The builder inspector now mounts required integer min/max controls for an existing
repeatable group. Bounds coordinate without rewriting conflicting values, omitted
limits remain omitted until entered, and invalid imported values remain available
for correction. Labels/help are translated in English and Spanish. Palette creation
and normal publication remain guarded. LAYOUT-006 is now recorded as PARTIAL to
reflect the implemented runtime and editor work without implying acceptance.

151 JavaScript tests, 269 PHP tests, PHP lint and the complete installed Joomla
HTTP suite pass. The HTTP fixture saves/reloads zero, ordinary, crossed and mistyped
limits, checks exact value types and diagnostic paths, and verifies that failed
publication preserves both draft revision and active version. Its initial assertion
incorrectly depended on JSON object key order; corrected assertions compare each
property strictly. Browser interaction with the new controls remains unverified.

Development package built and installed successfully, SHA-256:
`6b8601eda38334dda529b2d52dd05662a08b89ae29fba26b605ef444d73d446d`.
This does not complete repeated authoring/preview or the full product mission.

### Administrative repeated preview and scoped options

FormCompiler exposes a preview entry point that shares all semantic checks while
omitting only the temporary repetition publication guard. Publication still uses
the ordinary compiler. FormPreview creates minimum row declarations and uses the
same PresentationState/FormRenderer paths as public repeated forms. The preview
options endpoint accepts and validates row membership, uses validateInstances and
returns options under each full field address. Preview has no submission token and
keeps submission and row editing disabled.

269 PHP tests, 151 JavaScript tests, PHP lint and the complete native Joomla HTTP
suite pass. New installed tests verify distinct minimum row identities, independent
ES/FR option results, absent/malformed declarations, foreign unscoped values,
revision conflicts, CSRF, disabled submission and unchanged draft/activation after
the still-rejected publication. Compiler tests verify preview still rejects expanded
budget and lexical-reference failures. Browser interaction and dynamic row editing
in administrative preview remain pending.

Development package built and installed successfully, SHA-256:
`809fee0123bd75a81fbccc42cf1f5cf7b29254503e878936d9c12732d42f888f`.
LAYOUT-006 remains PARTIAL and the overall product mission remains active.

### Administrative preview row editing

The previewRows endpoint validates administrative access, POST/CSRF, revision,
instance identity and row membership against the authorized compiled definition.
It reuses the runtime row mutations and presentation reset semantics, preserves
surviving addresses/values, initializes new fields and does not write authoring or
submission data. The final render rechecks the draft revision. The browser uses
the shared row editor through an explicit administrative transport and retains its
preview-options callback after layout replacement. Submission stays disabled.

Installed HTTP tests cover add/remove, stable sibling and form IDs, retained values,
min/max, malformed target/instance, method/CSRF, stale revision and unchanged draft.
152 JavaScript tests, 269 PHP tests, PHP lint and the full native Joomla HTTP suite
pass. A JavaScript regression verifies that preview editing cannot fall back to
the public endpoint and cannot unlock submission after rejection. Administrative
browser end-to-end verification, including nested rows and providers, remains open.

Development package built and installed, SHA-256:
`ea78c24c1e2cf4838a39dffb19d3dc9fa40c6e0e013c12a8c9aa2d27657d83e2`.
Publication remains guarded and the full product mission remains active.

### Browser verification of sandboxed preview row edits

Real administrator browser testing exposed a missing integration boundary: the
preview iframe sandbox blocks native submit events, so submit-type row buttons
never reached the row editor. Preview row controls now use type=button and an
explicit delegated click handler; public controls retain native submit fallback.
The iframe sandbox remains unchanged and does not gain allow-forms.

The real browser journey on draft 3599 fills ES/FR in separate rows, adds a third
row to the maximum, verifies focus, gives the new row ES, removes the middle row,
then changes the retained new row to FR. The original row keeps ES/Madrid; the
other row clears its province options. Minimum removal limits and disabled submit
remain enforced. Evidence: tests/artifacts/joomla-repeated-preview-rows.png.
Nested/provider-widget administrative preview journeys remain unverified.

269 PHP tests, 152 JavaScript tests, PHP lint and the complete installed Joomla
HTTP suite pass. The HTTP regression also checks preview buttons are type=button.
Development package built and installed, SHA-256:
`7e068564196a22d2b79df2ea83d3d1741949dabb0b5f28c87b90e87383631a7e`.
The product mission and LAYOUT-006 remain incomplete.

### Palette creation and nested provider preview

Repeating groups can now be created from the layout palette with independent,
editable min=1/max=5 definitions. The shared tree model supports nesting/moving
without allocating runtime rows. A new duplication test verifies copied nested
limits, remapped prefill references, successful semantic preview compilation and
rejection of cross-branch row resolution; normal publication remains guarded.

The real administrator browser creates Contacts under the existing repeated group
of synthetic draft 3599, changes its maximum to two, adds fixture.upper, edits its
label and saves. Preview creates one child per parent. Adding to the first parent
reaches its own maximum, preserves ALFA/BETA and focuses the new child. Entering
gamma and removing the original first child leaves GAMMA and BETA under distinct
parents, with submission disabled. Evidence:
tests/artifacts/joomla-nested-preview-authoring.png and
build/nested-preview-browser-results.json. This covers this installed provider,
not an unrestricted third-party widget matrix or all authoring interactions.

270 PHP tests, 152 JavaScript tests, PHP lint and the full installed native Joomla
HTTP regression pass. Development package built and installed, SHA-256:
`5fef76b5d5a08094cf4d02dbc586442b367a56ad9bf60f48e1b07e8681165a7b`.
LAYOUT-006 remains PARTIAL; publication acceptance and the full mission remain open.

### Repeated answer columns in the administrative explorer

The explorer previously looked up definition UUIDs directly and omitted addressed
answers. `Application/SubmissionColumns.php` now projects the authorized batch
reader results into selected columns. Repeated values retain stored layout order,
full nested instance paths and per-row option labels. Ordinary multivalue answers
retain their original shape. Present null answers remain distinct from absence.
Historical masked addresses mask the entire field column without exposing values,
option labels or historical private labels. No additional database reads or
sensitive reveal are introduced.

Two unit scenarios cover nested order, nulls, multivalues, labels, scope rejection
and masking. The historical repeated-read database fixture checks projection from
real persisted snapshots on MariaDB, MySQL 8 and PostgreSQL. All 272 PHP tests and
the three database suites pass. PHP lint and the full native HTTP regression pass
after installing the regenerated development package. The ordinary publication guard remains enabled;
this correction does not complete LAYOUT-006 or whole-product acceptance.

Development package SHA-256:
`3c707b26ca7a8e5d78f275bdd163121f0fb2fce8dc8a6545edd270900f0a294a`.

### Installed HTTP acceptance of repeated administrative columns

`tests/http-admin-repeated-public.php` now submits two real repeated answers to
the installed Joomla controller, selects the field through the administrative
search API and compares both ordered values and instance paths. It opens the
native listing and parses the rendered cell JSON, comparing it with the exact
authorized API projection.

The same fixture creates a separate sensitive historical snapshot and submits a
new response through the visitor endpoint. After restoring the public current
snapshot, the administrative API must report the older column as masked with
no value, option label or historical private title. The native listing must not
contain either private sentinel. The existing rows and reset/replay checks remain
in the scenario. The complete installed HTTP suite passes; evidence flags are
recorded in `build/native-repeated-public-results.json`.

The repeated-query editor remains incomplete: SQL supports explicit
`same_instance`, but `submission-search.js` currently reconstructs filters with
only field/operator/value. A preset carrying correlation would lose that
constraint when resubmitted from this editor. This requires an explicit authoring
control and round-trip acceptance before repeated search UX can be closed.

### Repeated search correlation editor and saved-query round trip

The loss identified above is corrected. The native search editor offers an
optional same-row group name for repeated indexed fields when the selected
provider declares correlation support. Names use the server grammar; blank
retains independent predicates. Changing to an unsupported field preserves any
existing name visibly and uses native validity to require correction, instead
of silently broadening a stored query. Both submit and save serialize the name.

The installed HTTP fixture verifies separate-row independent matches, exclusion
when both values must belong to one row, anchored same-row negation, saved-query
round trip and the hidden editor input. Internal test snapshots explicitly
install their version field policy, as normal publication would. Comparisons
canonicalize JSON object key order while retaining value types and list order.

The browser creates `contact`, edits it to `household`, submits, saves preset 281
and reapplies that saved query on synthetic form 3833. The control retains
`household` after each navigation. This fixture verifies editor transport; SQL
result semantics are verified by the installed HTTP fixture. Evidence:
`tests/artifacts/joomla-search-correlation.png` and
`build/search-correlation-browser-results.json`.

272 PHP tests, 153 JavaScript tests and PHP lint pass. The updated development
package is installed in isolated Joomla, SHA-256:
`6b0c66868f9c3725d487b73c5a4d709704ebc8de6203f376c5b5a1bdc0d04b99`.
Broader repeated-layout and product acceptance remain open.

### Aggregate expanded rule condition budget

The repeated rule compiler and runtime now bound the combined condition-tree
nodes copied for every rule effect and row. Counting effects alone allowed a
large condition tree to multiply without a corresponding allocation bound.
The static diagnostic identifies the first overflowing rule condition; the
runtime stops construction at the same limit, including logical group nodes.
PublicSpec uses that same expansion before sending rules to the browser.

The boundary test in `tests/unit/compiler-repeated-layout.php` verifies 12 nodes
at budget 12 and rejection at budget 11, aggregation across separate rules and
multiple effects, exact diagnostic ownership and empty initial rows. All 273 PHP
tests and the complete MariaDB, MySQL 8 and PostgreSQL suites pass. Normal repeated
publication remains guarded and whole-product acceptance remains incomplete.

Development package built and installed, SHA-256:
`02a89dbc230ef68876d7f16b0b1e81d55b6acc977e684ea7204a64a528fc1b63`.
PHP lint and the full installed native Joomla HTTP regression also pass.

### JSON export artifact writer foundation

`ExportWorkspace::appendJson` writes a standalone JSON array incrementally to a
private `.json` artifact. It shares CSV's locked lease check, durable byte-offset
checkpoint restoration, flush and fsync. Retrying a chunk truncates unconfirmed
bytes before rewriting; only the final chunk appends the closing bracket.
Objects are canonical keyed records, with typed null/boolean/number/list values
and Unicode preserved. CSV formula escaping is not applied to JSON strings.
Format selection is allowlisted and defaults preserve existing CSV callers.

The unit scenario covers empty/intermediate/final chunks, exact replay of a final
chunk, malformed UTF-8 after a partially written chunk, missing checkpoint bytes,
lease rejection before truncation, separate CSV/JSON files and cleanup. All 274
PHP tests and all three database suites pass, including CSV crash recovery,
privacy, downloads and expiry using the refactored workspace.

This is the artifact writer only. The JSON handler, administrative job selection,
download MIME/name, cancellation/revocation, expiration and purge integration
remain required before JSON export is available. SEARCH-008 remains PARTIAL.
PHP lint passes. Development package regenerated (not reinstalled in this
checkpoint), SHA-256:
`1486045d87a7f2907ed0c49c82ac37688959cec6d85d99739940bef0b1058683`.

### JSON jobs, native download and privacy lifecycle

The shared ExportHandler now supports registered `export-csv` and `export-json`
handlers. JSON uses the same pinned selection, provider, historical reader,
field policy, lease renewal and cursor traversal. It writes one array containing
reference, original form version, timestamp, state and selected UUID-keyed values.
Repeated values retain ordered instance paths. Historical exclusions remain null;
request metadata and unselected fields are omitted.

Native enqueue permissions, sensitive confirmation, job download links, MIME and
filename, completion/expiry checks and unavailable-storage registration support
JSON. The administrative page renders separate CSV/JSON export controls. Cleanup,
anonymization, form deletion, package purge and the uninstall purge guard recognize
both formats; dashboards count both as exports.

The three database suites verify JSON chunk replay, sensitive/non-sensitive
historical snapshots, repeated order, download ownership and expiry cleanup.
`tests/database-json-lifecycle.php` also verifies revocation of completed and
uncheckpointed JSON exports on anonymization, loss of the old worker lease and
removal of both private artifacts. Native HTTP verifies filtered JSON enqueue,
CSRF, incomplete/cancelled download refusal, final JSON headers/content and the
rendered control. All 274 PHP tests, 153 JS tests, PHP lint, three database suites
and the full installed native HTTP regression pass.

JSON browser interaction and dedicated JSON purge acceptance remain unverified;
SEARCH-008 and the overall mission remain incomplete. Development package built
and installed, SHA-256:
`ea454874f304404a9066f18efb2458eccfcf0cb1c3f042b65be1cd3eebb28a5d`.

### Dedicated native JSON purge acceptance

`tests/joomla-purge.php` now creates both completed and partial JSON artifacts,
alongside CSV and private uploaded objects, in its exact-host/database/prefix
guarded disposable fixtures. Purge preparation must cancel both JSON jobs;
normal bounded cleanup must remove both files and mark their jobs expired.

After all other owned rows and cleanup jobs are clear, the test restores one
synthetic JSON artifact and clears only its expiry marker. Native package
uninstall must refuse this JSON-only outstanding artifact without removing code
or bytes. The normal export-cleanup handler then clears it, after which uninstall
and clean reinstall must succeed. Unowned sentinel files must survive throughout.

This complete scenario passed on the installed development package in dedicated
MariaDB, MySQL 8 and PostgreSQL Joomla fixtures. Their purge reports include
`json_complete_partial_removed` and `json_only_uninstall_blocked`; all three
confirm schema removal and a clean reinstall. The main HTTP acceptance site was
not purged. Package SHA-256 remains
`ea454874f304404a9066f18efb2458eccfcf0cb1c3f042b65be1cd3eebb28a5d`.
JSON browser interaction and broader export scale/role acceptance remain open.

### Native browser JSON export acceptance

The real Joomla administrator browser selects synthetic form 3988, applies the
ID filter for response 2331, opens Export and bulk actions and submits Export
JSON with only Export answer selected and sensitive inclusion unchecked. Job
1421 appears pending, then the native Process one batch action completes it with
one processed response and zero failures. The native Download button produces
`formstudio-1745fcac-38cf-48b4-b172-05b54f07bea1.json`.

Parsing the downloaded file confirms exactly one response, reference
`f4234caf-f8f6-405d-8cc2-6592b4124ad1`, original version 2898 and only selected
field `59048aa1-ab12-4684-b7ce-2c7bdc494dd4` with value `other native answer`.
The fixture values are synthetic. Evidence is recorded in
`build/json-export-browser-results.json` (including downloaded SHA-256) and
`tests/artifacts/joomla-json-export-browser.png`.

This closes the basic browser interaction gap for JSON. It does not establish
million-row throughput, every permission combination or whole-product readiness;
SEARCH-008 remains PARTIAL. No production code or package bytes changed.

### Native export ACL matrix for CSV and JSON

`tests/joomla-export-acl.php`, included by the guarded Joomla ACL suite, creates
real form assets and two registered users. It exercises native inherited and
form-specific rules through JobAdministration, ExportHandler and ExportDownloads
for both CSV and JSON. No permissive authorization stub replaces Joomla ACL.

The matrix proves guest/denied export rejection; export with response viewing
explicitly denied; sensitive permission plus explicit opt-in at enqueue; revoked
export/sensitive permission rejection before bytes are written; authorized owner
download; foreign owner denial; delegated jobs-manager download with export and
sensitive rights; and owner/manager rejection after export or sensitive rights
are revoked. An explicit grant of sensitive rights does not replace opt-in.
The fixture removes its private artifacts, invalidates their download metadata
and restores the component rules through the enclosing suite's finally block.

The native suite passes and records `build/native-export-acl-results.json`.
This adds real ACL evidence to the prior native HTTP and browser workflows.
Million-row export acceptance is still outstanding; SEARCH-008 and the full
product mission remain incomplete. Production/package bytes are unchanged.

### SEARCH-008 — filtered CSV/JSON export closure

The requirements in SPEC 23 section 14 are satisfied by the shared chunked export
handler: persistent jobs with immutable filter/schema/provider selection, bounded
cursor reads, private temporary artifacts, completed-job downloads and expiry.
The three-engine database suites cover filtered order and high-water exclusion,
checkpoint replay without duplicate records, canonical/repeated values,
historical privacy and cleanup. Native HTTP and browser workflows exercise job
creation, progress and protected download. The native Joomla ACL matrix above
covers independent export permission, explicit sensitive inclusion, ownership,
delegated management and permission revocation before write/download. Dedicated
native purge fixtures verify complete/partial JSON removal and uninstall fences.

`php -d memory_limit=128M tests/scale-export.php csv` and the corresponding `json`
run both passed on the existing MariaDB 11.4.5 synthetic dataset on 2026-09-28.
Each processes all 1,000,000 responses as 100 form-scoped jobs, with a largest
individual artifact of 500,000 rows and a maximum chunk size of 500. An independent
unbuffered traversal of canonical rows verifies every artifact's exact SHA-256,
including order, field selection and values. CSV produces 105,755,276 bytes in
41.38 export seconds (47.97 seconds including verification/cleanup); JSON produces
299,215,076 bytes in 34.40 export seconds (39.05 seconds total). Both peak at
8,388,608 allocated PHP bytes under a 128 MiB limit. Private artifacts are removed
and their download metadata invalidated after each verification.

The reports are `build/scale-export-csv-results.json` and
`build/scale-export-json-results.json`, with each job's row count, chunks, timing,
bytes and hash. `tools/benchmark-report.php` preserves these results in
`docs/SCALE_BENCHMARK.md`. PHP syntax checks pass for both new/changed runners.
This closes SEARCH-008 and brings the individual closed requirement count to 23.
It does not establish a single million-row artifact, concurrent/HTTP throughput,
other-engine throughput, every deployment budget or full-product readiness.
No production code or development package bytes changed in this acceptance step.

### Repeated draft-to-runtime acceptance before publication enablement

The native repeated HTTP fixture now first saves its definition through the
real administrative API and reloads the persisted draft. Native preview must
compile that stored definition, create exactly its two minimum rows and render
both addressed inputs. A normal publish request must fail only with the existing
`layout.repeatable.unsupported` guard; any additional semantic error fails the
test. The rejection must preserve the draft, draft revision and null active
version. The existing isolated internal-snapshot runtime checks then exercise
row changes, validation, persistence, replay, reset, correlated search and
historical column privacy with that saved definition.

This scenario passed on 2026-09-28 and records `saved_draft_preview`,
`only_temporary_publication_guard` and `publication_rejection_preserves_state`
in `build/native-repeated-public-results.json`. It proves stored-draft semantic
readiness for this fixture and leaves ordinary publication guarded. An attempted
change removing the guard was rejected by automatic approval review because
full publication acceptance was not yet demonstrated; that change was not
applied. This test extension preserves the guard and does not certify publication
or close LAYOUT-006. Production and development package bytes are unchanged.

### SEC-013 — native CSV formula mitigation closure

The CSV writer prefixes an apostrophe for formula characters, ASCII controls or
space, and the Unicode BOM; the same conversion covers header labels. Standard
CSV quoting preserves commas, quotes and multiline cell boundaries. Existing
unit tests cover the writer and structured values; database export jobs cover
its integration. Prior native browser acceptance verifies the export controls
and download workflow under SEARCH-008.

The isolated `tests/joomla-jobs.php` fixture now publishes an ordinary textarea
form with a formula-shaped label and stores 14 synthetic responses without
Actions. `tests/http-admin-jobs.php` enqueues through the native CSRF-protected
job controller, runs the worker and downloads the completed artifact. Parsing
that downloaded CSV verifies the protected header and each ordered response:
`=`, `+`, `-`, `@`, tab, CR, LF, space and BOM prefixes gain exactly one leading
apostrophe; comma, embedded quotes, CRLF, Unicode and an already literal
apostrophe preserve their values. Every record has exactly four cells and there
are no additional records. A second native JSON export must contain all exact
original canonical values, proving CSV protection did not mutate stored answers.

The scenario passed and records `build/native-csv-formula-results.json`, including
14 rows, header protection, JSON original-value verification and the downloaded
CSV SHA-256. PHP syntax checks pass for the changed fixture and HTTP test. This
closes SEC-013 and brings individual closed requirements to 24. The evidence
covers the delivered CSV representation; spreadsheet-specific import settings
and subsequent spreadsheet re-saving are outside this export transformation.
No production or development-package bytes changed. The separate repeatable
publication guard remains unchanged while its requested authorization is pending.

### SEC-010 — Joomla mail header integration closure

`tests/integration/mail-transport.php` exercises the production MailTransport with
Joomla 6's actual Mail class and its inherited PHPMailer MIME preparation. A test
subclass replaces Send with preSend plus getSentMIMEMessage; postSend throws
unconditionally. No mail(), sendmail or SMTP delivery is invoked. Only the
factory and delivery boundary are substituted; sender, recipient, subject,
body and MIME construction use Joomla's implementation.

Positive checks verify sender/name, To/Cc/Bcc/Reply-To, a Unicode subject and
multipart/alternative text/HTML bodies. The prepared top-level headers exclude
Bcc and a Bcc-shaped body line. A subsequent plain message receives a fresh
mailer without prior recipients, reply address, alternative body or HTML data.
Negative checks cover CRLF injection, individual CR/LF, NUL, tab and DEL in each
address-bearing header, sender name, subject and expanded header token. Invalid
inputs are rejected before creating a Joomla mailer. Existing action tests also
cover validated autoresponse fields and repeated recipient selection.

Additional integration checks distinguish disabled mail from false/exception
unknown delivery outcomes and retain stable, detail-free result codes. These
checks do not claim actual SMTP delivery or certification of every external
mailer provider. The full PHP suite passes: 277 tests, zero failures. The new
integration file passes PHP syntax validation. SEC-010 is closed, bringing the
individual closed requirement count to 25. Product release acceptance remains
open; production code and development package bytes are unchanged.

### Joomla mail preparation failures and partial-message prevention

MailTransport previously ignored false from Joomla's sender/recipient/body
setters and let preparation exceptions reach the generic unknown-action handler.
It now checks explicit rejection at every setup stage and catches preparation
exceptions as `mail_preparation_failed`, before send can execute. Existing
`mail_html_unsupported` remains a definite failure. Delivery-stage failures retain
their existing unknown-outcome classification and disabled-mail distinction.
Recipients are deduplicated case-insensitively in To/Cc/Bcc order to preserve
PHPMailer's first-recipient behavior without treating duplicates as setup errors.
Reply-To remains independent.

The real Joomla Mail integration fixture injects factory failure and false or
exceptions at sender, To, Cc, Bcc, Reply-To, subject and body setup. Each must
produce a detail-free definite failure with zero Send calls and no prepared
message. A duplicate-address case verifies a single recipient per destination
and one send preparation. Existing plain/HTML composition and delivery-outcome
checks still pass. Full PHP suite: 278 tests, zero failures.

The development package was rebuilt and installed successfully in the isolated
main Joomla fixture. Source and installed MailTransport SHA-256 match. Package
SHA-256: `3d041a1e78b975e3d13c88e71ece67167e4cdffd1213bfc71690b65c68c454e8`.
No real email delivery was attempted. This fixes preparation semantics without
claiming whole-product or external SMTP acceptance.

### Persisted Joomla mail retry semantics on three database engines

`tests/database-mail-retries.php` composes the real EmailAction, Joomla
MailTransport, ActionEngine, ActionRunRepository, ActionRetries and
ActionRetryHandler. The Joomla Mail subclass permits MIME preparation only;
postSend throws and no external delivery is invoked. The fixture publishes an
ordinary email-notification action and stores a response under that version.

A recipient-preparation rejection must produce a persisted failed action with
`mail_preparation_failed`, a blocking response outcome, zero Send calls and one
authorized retry candidate. An unauthorized actor receives no candidate. After
enqueuing the retry, the test publishes a different subject. The retry job must
still prepare the original subject, succeed on attempt two and complete its
checkpoint. A subsequent action replay creates no additional mailer.

A separate response simulates false from Send. Its action must persist as unknown
with `mail_delivery_unknown`, remain absent from retry eligibility and reject a
forged retry enqueue. Direct replay with retry enabled must also leave its one
Send call unchanged. This verifies that the adapter's new preparation result
actually reaches persisted eligibility, rather than only testing its exception.

The full guarded database suites pass on MariaDB 11.4.5, MySQL 8.4.8 and
PostgreSQL 14.24 with this scenario included. The new test passes PHP syntax
validation. No production/package bytes changed in this step; the current
package remains `3d041a1e78b975e3d13c88e71ece67167e4cdffd1213bfc71690b65c68c454e8`.
This evidence does not claim real mail delivery or full Action/release acceptance.

### Email template failures retain definite pre-delivery semantics

EmailAction now validates configuration at its own execution boundary and builds
tokens, recipients and MailMessage before entering the mail transport. Known
recipient-selection ActionFailure codes are preserved; other preparation
exceptions become `mail_preparation_failed`. Transport execution remains outside
that catch so an unexpected external transport error cannot be mislabeled as a
safe pre-delivery failure.

Unit coverage verifies a CRLF-bearing field value expanded into a subject,
unavailable text/HTML body tokens, invalid configuration and zero transport
calls. It also verifies the exact transport exception object survives, including
an explicitly unknown delivery outcome. The database mail-retry scenario now
publishes a valid textarea-token subject and persists a response containing a
synthetic header-injection string. The action must be failed (not unknown), with
`mail_preparation_failed`, no new mailer and an authorized retry candidate.

All 279 PHP tests pass, as do the full MariaDB, MySQL 8 and PostgreSQL suites.
Changed production/database-test files pass syntax validation. The development
package was rebuilt and installed in the isolated main Joomla fixture, SHA-256:
`7359ed1e9d01f180388f11b744daf0176fbb90f70da297a4d03afe2bc699750f`.
No external email delivery was attempted. Whole-product acceptance remains open.

### Webhook header identity and signing-header ownership

Custom X-* headers previously permitted case variants of the runtime signature
and timestamp names, allowing multiple wire headers with the same HTTP identity.
Webhook configuration now rejects reserved signing names and case-insensitive
duplicate custom names. Compilation and execution both apply that validation.
CurlTransport independently rejects case-insensitive duplicate header identities
before DNS resolution, covering callers outside WebhookAction as well.

Unit tests cover exact/mixed-case signature and timestamp collisions, duplicate
custom names, valid distinct names, no secret access or transport call on rejected
configuration, and zero DNS calls for duplicate Authorization headers in the
transport. Compiler tests cover these invalid configurations while forbidding
DNS, secret resolution and HTTP calls. Existing payload/HMAC/idempotency checks
still pass. Full PHP suite: 280 tests, zero failures; changed production files
pass syntax validation. No network webhook was sent.

The development package was rebuilt and installed successfully in the isolated
main Joomla fixture. SHA-256:
`de1033b05f8baebc448a8747f81598fdd928381a5e5163fc464261acd9cc155b`.
This closes a concrete header ambiguity, without claiming external webhook or
whole-product acceptance.

### Webhook expanded request preflight and failure boundary

WebhookAction now handles token/MIME-independent JSON preparation errors before
calling its transport: missing tokens, unsafe expanded headers and invalid UTF-8
produce the definite `webhook_preparation_failed` result. It checks the expanded
JSON body against 1 MiB and custom header values against 8192 bytes, rejecting
excess with `webhook_request_limit` before secret lookup. Bearer secrets must be
nonempty, free of controls/space and at most 8185 bytes so the complete header
fits 8192; rejected values use `secret_unavailable` without disclosure.

`tests/unit/webhook-preparation.php` covers exact and exceeded byte boundaries,
invalid UTF-8, unavailable tokens, CRLF expansion, zero transport calls on
rejection, zero secret lookups for oversized requests, invalid Bearer values and
an exact-length valid Bearer. Both generic and explicitly uncertain transport
exceptions retain their original objects outside the preparation catch. All
transports are in-memory and no webhook request is sent externally.

Full PHP suite: 281 tests, zero failures. Changed PHP files pass syntax checks.
Development package rebuilt and installed successfully in the isolated main
Joomla fixture, SHA-256:
`7f884c414673a44d9cd6c6a06362ef196953cf5bcc83640be9b25c2f1fcdc41c`.
These checks do not certify external webhook delivery or complete product scope.

### Incremental webhook expansion budget

TokenTemplate now supports an optional byte limit and appends literal/token
fragments only after checking remaining capacity. Calls without a limit retain
the existing output semantics. WebhookAction passes its remaining budget during
payload expansion and counts encoded keys, separators and escaped values before
adding each entry; custom headers expand under their own 8192-byte limit.

Tests verify exact UTF-8 and HTML-escaped boundaries, literal overflow, empty
expansion from a longer template, malformed-token literals and invalid limits.
A 1024-repeat template with a one-MiB value must reject before producing its
roughly one-GiB theoretical output. Webhook tests cover aggregate multi-field
excess, JSON quote-escape growth and repeated-token amplification with no secret
lookup or transport call. All 282 PHP tests pass with memory_limit=128M; changed
production files pass syntax validation.

The development package was rebuilt and installed in the isolated Joomla site,
SHA-256 `44145cb1e012bbb590dba88bcd629167a27a0865f14b8decf4649a283627bcf7`.
No external request was sent. This bounds template output construction; it is not
a claim about total process memory, arbitrary provider code or release readiness.

### Native public conditional Actions and ordered execution

`tests/http-admin-action-conditions.php` uses the real administrator API to save
and publish three redirect Actions: one matches A or B, followed by separate A
and B predicates. Public HTTP posts include whitespace around the route value,
so matching must use the normalized canonical answer. A and B select the later
matching redirect; a third value selects no redirect. No external destination,
email or webhook is contacted.

For each response the test reads exactly three persisted ActionRuns in configured
order, with succeeded/navigation_selected or skipped/condition_false and attempt
one. Repeating the identical public POST must return the same reference and
navigation while preserving all original run IDs, states and attempt numbers.
An invalid required answer before each valid POST must return 422; exactly three
responses remain after the entire matrix. The fixture is deactivated through
the native API after verification.

The scenario passed and records `build/native-action-conditions-results.json`.
Its PHP syntax check passes. ACTION-001/002 now cite this native pipeline evidence
while retaining PARTIAL for remaining visual authoring/provider acceptance.
Production and development-package bytes are unchanged in this step.

### ACTION-007 — native blocking/nonblocking failure-policy closure

`tests/http-admin-action-failures.php` publishes a textarea-based email action
followed by an internal redirect. The submitted textarea always contains a line
break and is expanded into the subject, so EmailAction rejects preparation
before calling MailTransport. No valid email submission or external request is
made. Both configurations run through native administrator publication and the
public JSON submission controller.

For blocking policy, the response is accepted but processed=false with
`action_blocking_failure`, behavior keep and no redirect. Exactly the failed
mail ActionRun exists; its code is `mail_preparation_failed`. For nonblocking
policy, the response is accepted with `action_partial_failure`, the configured
hide behavior and the following action's redirect; both failed and succeeded
runs exist. Persisted response summaries match their public outcomes. Repeating
each submission preserves the receipt/category and all existing run IDs and
attempts, without implicitly retrying failed actions. The fixture is deactivated
through the native API afterwards.

The scenario passes and records `build/native-action-failures-results.json`.
Combined with the three-engine ordered-action/retry tests, preparation/unknown
outcome tests and PostSubmit unit checks, this closes ACTION-007. The individual
closed requirement count is now 26. New fixture syntax validation passes.
No production/package bytes changed; full product acceptance remains incomplete.

### ACTION-002 — visual conditional-action authoring closure

A native administrator browser session on synthetic form 4218 adds Redirect 4
through Add action, enters an internal destination, sets order 7 and blocking
failure policy, and builds `Route = C OR (Route = D AND Route is not empty)`
using Add condition, Combine with more conditions and Add group. No JSON editor,
injected page state or direct database write constructs this definition.

Save draft succeeds. A full browser reload and reopening Actions preserve both
logical operators, all comparison controls/values, destination, order and policy.
Compile and publish then reports a new published version. The test immediately
unpublishes the synthetic form and verifies that state. No visitor submission,
external redirect, email or webhook is performed in this authoring check.

Evidence: `build/action-condition-browser-results.json` and
`tests/artifacts/joomla-action-condition-authoring.png`. Combined with native
public normalized condition/order/replay tests, shared evaluator unit coverage
and three-engine repeated-row predicate tests, this closes ACTION-002. Individual
closed requirements now total 27. Production/package bytes are unchanged and
whole-product release acceptance remains open.

### Mail attachment transport contract (integration incomplete)

MailAttachment carries immutable filename, MIME type and bytes rather than a
filesystem path. It rejects invalid UTF-8/control/path names, parameterized or
invalid MIME identifiers and files above 10 MiB. MailMessage optionally accepts
an ordered typed list, limited to 20 entries and 10 MiB aggregate original bytes.
The trailing constructor argument preserves existing callers.

Joomla MailTransport uses addStringAttachment with explicit base64/attachment
semantics. A rejected attachment aborts before Send with a definite preparation
failure; incompatible alternative mailers fail explicitly. Real Joomla/PHPMailer
MIME preparation tests verify exact binary bytes, string-attachment mode, mixed
and alternative MIME nesting, encoded payload, disposition and absence of stale
attachments in the next message. Negative tests cover filenames, MIME values,
list types/counts, exact/over aggregate limits and false/throw preparation errors.
No real email is delivered.

All 284 PHP tests pass under memory_limit=128M; changed production files pass
syntax validation. Development package rebuilt and installed in the isolated
main Joomla fixture, SHA-256:
`978aad67b0f8ea13aa0c8fed03f880727b9f5d68dcff9e944d23e427825b5d4c`.
This is transport support only: field selection, canonical file ownership,
historical policy, ephemeral files and retry integration remain required before
attachment authoring is exposed. ACTION-003/004 and full release remain partial.

### Persisted mail attachment ownership resolver

StoredMailAttachments resolves selected original-version file fields against the
canonical stored response. It rejects anonymized/mismatched snapshots, excluded
historical email policy, malformed selections, scope mismatch, missing/foreign
file receipts and metadata mismatch. It reads only bounded bytes, checks SHA-256
and size, and returns typed MailAttachment objects. Failures expose a definite
`mail_attachment_unavailable` code without provider/path details. Caller-supplied
ActionContext values do not replace canonical stored receipts.

The new `tests/database-mail-attachments.php` verifies explicitly permitted
sensitive files, excluded fields, duplicate/malformed selections, original policy
after new publication, wrong snapshot, altered checksum/size/field ownership,
foreign response receipts and anonymization. It cleans up its private synthetic
object. Full suites pass on MariaDB, MySQL 8 and PostgreSQL; new files pass syntax
checks. Dedicated repeated-row, ephemeral-file, race and runtime wiring acceptance
remain necessary; no editor attachment selection is exposed yet.

Development package rebuilt and installed in the isolated main Joomla fixture,
SHA-256 `3194e5bb85b7b7d76840ecced79f22828ce8a3f6d0bc6b609a45bf4f394aac7b`.
This is stored-file resolution support, not completed email-attachment acceptance
or whole-product readiness.

### Email attachment action boundary (partial)

EmailAction now accepts an optional MailAttachmentResolverInterface and validates
internal attachment_fields selections (list, distinct UUIDs, maximum 20). Empty
selection preserves existing messages without touching the resolver. Missing or
failed resolution is a definite mail_attachment_unavailable failure before any
transport call; private provider exception details are discarded. StoredMailAttachments
implements this contract. Unit acceptance covers valid bytes, invalid selections,
empty selection, missing resolver and provider failure. Database acceptance connects
the real stored resolver to EmailAction and verifies anonymization prevents sending.
285 PHP/Joomla tests pass under 128 MiB, and full MariaDB, MySQL 8 and PostgreSQL
suites pass. Runtime injection, ephemeral uploads, compiler/import references,
repeated-row attachment acceptance and editor selection remain pending; this does
not close the email attachment requirement.

### Request-local upload attachment capability (partial)

SubmissionPipeline now supplies StagedMailAttachments only for fresh executions,
using active upload gateway results, canonical action values, original spec and
response reference. ActionContext.forAction preserves the capability; EmailAction
prefers it to the injected persistent resolver. Existing finally cleanup remains
unchanged, and replay/retry contexts receive no staged capability. LocalStorage
open now suppresses the raw filesystem warning and throws its safe existing
exception when an object disappeared, preventing private path disclosure.

286 PHP/Joomla tests pass under 128 MiB. Unit acceptance uses real private storage
and verifies file order, repeated row declaration order, full/metadata/none specs,
non-persisted fields, explicit sensitive opt-in, wrong response/snapshot/address,
missing or duplicate capabilities, checksum failure, deleted objects and zero
transport calls after rejection. These are capability-level tests, not proof of
the complete multipart pipeline. Full database suites also pass on MariaDB,
MySQL 8 and PostgreSQL. Native multipart attachment acceptance, persisted retry
wiring/semantics, compiler/import references and editor selection remain pending.

The full native administrator/public HTTP regression suite also passed after
installing the staged-capability package. A subsequent receipt comparison fix
uses CanonicalJson to preserve strict types while tolerating persisted object-key
ordering; the updated 286-test suite and targeted syntax check pass. Full PHP lint
passed before that small comparison adjustment. No external email was sent.

### Persisted email attachment retries and runtime wiring (partial)

RuntimeProvider now supplies both email actions with a lazy stored resolver;
storage registry lookup occurs only during attachment resolution. Fresh pipeline
contexts retain priority for their staged capability. Stored resolution rejects
non-full persistence or persist=false selected fields instead of treating omitted
ephemeral values as an empty attachment list.

Database mail retry acceptance now includes an actual stored attachment through
EmailAction, ActionEngine, ActionRetries, queued ActionRetryHandler and Joomla
MailTransport MIME preparation. The retry retains original bytes/name/policy after
a later publication excludes the field, and successful replay creates no new
mailer. A separate object deleted between initial failure and retry produces
mail_attachment_unavailable at attempt 2 with no additional mailer creation.
Explicit metadata/none/non-persisted cases reject stored resolution. Full suites
pass on MariaDB, MySQL 8 and PostgreSQL; 286 PHP/Joomla tests pass. Test mailers
cannot deliver externally. Native multipart attachment acceptance, compiler/import
references and editor selection are still pending; no requirement is closed here.

### Attachment compile, duplicate and transfer references (partial)

FormCompiler validates each email attachment_fields entry against local file or
multiple-files fields and include_email policy, returning action.attachment_reference
or action.attachment_policy at its list index. Sensitive fields require explicit
opt-in. Ephemeral persistence modes remain valid for first execution; stored retry
restrictions are independent. DefinitionRemapper rewrites attachment UUIDs in both
email actions without changing the source. DefinitionPackage explicitly retains
the selection, and ImportPreview exposes invalid policy while allowing draft repair.

New unit acceptance covers both email action types, both file types, foreign/wrong
type/malformed references, privacy policy variants, full/metadata/none with
persist=false, duplicate recompilation, portable round-trip and invalid import
diagnostics. All 287 PHP/Joomla tests pass under 128 MiB and full MariaDB, MySQL 8
and PostgreSQL suites pass. The historical mail retry fixture now publishes a
valid later version with an empty selection when excluding its file, while the
original retry still includes that file. Native multipart attachment acceptance
and the editor selector remain pending; no whole-feature completion is claimed.

### Real multipart attachment pipeline acceptance (partial)

tests/http-mail-attachments.php exercises eight normally compiled/published
ordinary forms through the PHP HTTP multipart parser, Joomla Input/RequestAdapter,
HttpUploadGateway, upload journal, SubmissionPipeline, ActionEngine and EmailAction.
Cases cross file/multiple-files with full retained, full persist=false, metadata
and none. Each explicitly permits a sensitive attachment field. An in-memory
transport records exact attachment bytes, names, MIME and order without delivery.

Every case rejects a forbidden-extension upload without invoking mail (including
partial multiple-upload cleanup), sends the valid request exactly once, and replays
the same multipart request without sending again. Database ownership counts match
persistence policy; staging rows are empty and the private directory contains only
the baseline plus deliberately persisted objects. Synthetic objects are removed
after verification. build/mail-upload-results.json records all eight passing cases.
The loopback-only, nonce-protected test route is excluded from packages and is not
the native Joomla component controller. Existing repeated multipart regression
and all 287 PHP/Joomla tests also pass. The test HTTP server was stopped afterwards.
The visual selector, native component attachment interaction and full repeated
attachment action acceptance remain outstanding; whole-feature acceptance stays open.

### Visual attachment field selection (partial)

Email action metadata now exposes attachment_fields through a dedicated accessible
fieldset and labeled checkboxes in the automation editor. New choices are limited
to email-permitted file/multiple-files fields; selected missing/excluded fields
remain visible and removable. Selection order is preserved, new choices stop at
20 fields, and malformed imported selections require explicit removal. English
and Spanish help explains the aggregate file/byte limits and ephemeral retry caveat.

156 JavaScript tests and 287 PHP/Joomla tests pass. Browser acceptance in native
Joomla created draft 4326, added file and multiple-file fields plus an email action,
selected both, saved and reloaded, and verified both remained checked. Evidence:
build/attachment-selector-browser-results.json and
tests/artifacts/joomla-attachment-selector.png. The draft was not published or
submitted and no mail was sent. Native component attachment delivery acceptance
and full repeated attachment action acceptance remain pending.

### Installed native component multipart/MIME acceptance (partial)

tests/http-native-mail-attachments.php creates normally published forms using
FormAdministration on the isolated Joomla installation, then obtains the public
component form over HTTP, uses its session cookie and hidden CSRF/attempt values,
and submits actual multipart files through the installed public controller.
Four cases cover full retained, full persist=false, metadata and none. Both
files reach the real EmailAction and Joomla MailTransport, and MIME checks verify
two base64 byte payloads, original filenames, MIME types and attachment disposition.
Each same-attempt replay preserves the reference and creates no additional message.
Persisted file counts follow policy and no upload-staging rows remain.

The dedicated loopback/nonce-protected tests/http/native-mail-router.php boots
the installed Joomla application and aliases only its request-local mail factory
to a Joomla Mail subclass whose Send prepares/captures MIME and whose postSend
always throws. No production transport code or saved site mail settings change.
This proves component-through-MIME behavior, not delivery to an external SMTP server.
The earlier experimental SMTP sink was removed. The final test passed twice;
build/native-mail-attachment-results.json records the latest four cases. Synthetic
persisted objects are deleted, test forms are unpublished (including interrupted
fixtures), and the test server was stopped. Both new PHP files pass syntax checks.

Run the fixture server with PHP `-S 127.0.0.1:13375 -t build/joomla-6.0.0
tests/http/native-mail-router.php`, then run `tests/http-native-mail-attachments.php`
from the repository root and stop the server. These test files are excluded from
the extension package. Full repeated attachment action acceptance remains open.

### Repeated multipart mail actions and persisted scope (partial)

The existing internal repeated-upload fixture now executes a real EmailAction
with explicit attachment_fields and a capturing transport. HTTP acceptance checks
single-file row order, then reverses declaration order without reversing multipart
upload order and verifies both staged and stored resolution follow declarations.
It checks multiple-files ordering within and across rows, omission of the inactive
row file, no second message on replay, and no mail on CAPTCHA, validation, partial
upload, changed replay or per-row count failure. The stored resolver also rejects
a synthetic file row moved to a sibling instance even with a matching path hash.
The original scope is restored after that negative check.

tests/http-repeated-uploads.php and all eight ordinary multipart mail cases pass;
modified PHP files pass syntax checks. build/repeated-mail-attachment-results.json
records attachment-specific assertions. Existing private-file/staging cleanup
assertions remain in place and synthetic objects are deleted afterwards. The
loopback test server was stopped. This extends the already-existing internal
snapshot fixture; it does not remove the compiler publication guard or demonstrate
normal repeated-form publication. That release acceptance remains pending.

### Native notification and autoresponse MIME matrix (partial)

The native component capture suite now covers both email action types across all
four persistence policies (eight cases). Each case submits an invalid visitor
email containing CRLF first and verifies HTTP 422 with no generated message,
then corrects it under the same attempt. Notification resolves the configured To;
autoresponse resolves the validated email field. Actual prepared Joomla mail
objects verify CC/BCC, visitor Reply-To, configured sender and response-reference
subject. MIME verifies BCC header exclusion, escaped HTML plus literal plain text,
private summary exclusion, two attachment contents/names/types and no replay send.
Retained responses have exactly one succeeded mail_sent ActionRun at attempt 1.

tests/http-native-mail-attachments.php passes, recording all eight cases in
build/native-mail-attachment-results.json. The request-local capture factory
prepares MIME only and never delivers. Test objects are deleted, forms unpublished
and the HTTP fixture server stopped. ACTION-003/004 status rows now cite this
evidence instead of the obsolete attachment/transport gaps. They remain PARTIAL
pending combined conditional multi-email authoring and complete template locale
acceptance; no external SMTP-delivery or whole-product claim is made.

### Native conditional mail and copied template locale acceptance (partial)

The native component suite now publishes three mail actions in reverse storage
order and checks their explicit execution order. Normalized A/B/neither answers
select the common notification and visitor autoresponse or team notification.
Invalid answers generate no message. Every retained submission has three ordered
ActionRuns with succeeded/mail_sent or skipped/condition_false as appropriate;
replay preserves both messages and exact run rows.

A separate native matrix copies a reusable template through the Templates service,
binds a named answer parameter, publishes notification plus autoresponse, then
edits the reusable resource and the unpublished draft. Both messages retain the
published content. Four cases exercise exact en-GB, primary en, form-default es-ES
and original-source fallback, including bound plain text, escaped HTML, recipients,
stored response locale and replay fencing. These are HTTP submissions through the
real component with Joomla MIME preparation and no external delivery.

`tests/http-native-mail-attachments.php` passes with both included suites;
`build/native-mail-condition-results.json` and
`build/native-mail-template-results.json` record the cases. The isolated fixture
server was stopped and the synthetic forms unpublished. ACTION-003/004 remain
partial: combined conditional visual authoring and localized historical retry
acceptance are still open. This does not claim complete product acceptance.

### Historical localized mail retries on all supported databases

`tests/database-mail-locale-retries.php`, included in the existing Joomla MIME
retry fixture, exercises notification and autoresponse across exact, primary,
form-default and original-source translation fallback (eight cases per database).
Each case persists its original locale and snapshot, records a definite mail
preparation failure and queues an authorized retry. A later publication changes
the form name, field label, base language, translations and action content before
the real job handler runs. The retried MIME must still contain the original
localized subject, field-label token, canonical answer, escaped HTML and recipient.
The response retains its locale/version; attempt 2 succeeds, the job completes,
and a further replay generates no mailer or retry eligibility.

Full `tests/database.php mysql`, `mysql8` and `postgresql` suites pass. The latter
uses PHP pgsql/pdo_pgsql extensions. This fixture prepares messages but never
performs external delivery. ACTION-003/004 now retain the combined conditional
visual-authoring acceptance gap, rather than a historical localized retry gap.

### Combined conditional email authoring and public execution

Native Administrator form 4389 was opened through its editor. The two existing
notification/autoresponse subjects and four comparison values were edited in the
visual controls, saved and verified after reload. A fourth action was added with
Add action: Email autoresponse, recipient Email, order 30, subject Support receipt,
plain body and Route equals support condition. Save, Compile and publish, and a
second reload confirmed the new action's values and published state. Screenshot:
`tests/artifacts/joomla-conditional-mail-authoring.png`.

`php tests/http-native-mail-attachments.php 4389` then exercised that exact
UI-published version through the native public component. Route receipt emitted
common notification plus visitor receipt; support emitted common notification,
team review and the newly authored support receipt; neither emitted none. All
four actions recorded succeeded/skipped states and replay sent nothing further.
The entire native MIME/attachment, conditional and locale-template suite passed
in the same invocation. `build/native-mail-authoring-results.json` records the
published version. Cleanup unpublished the synthetic form and stopped the capture
server; no external mail was delivered.

The combined visual authoring gap is now covered. ACTION-003/004 remain PARTIAL
at the full product scope because normal repeated-form publication is still
blocked; internal repeated runtime/attachment/recipient tests do not substitute
for that acceptance. Ordinary-form authoring and delivery preparation are verified.

### Definite POST size rejection and AJAX format recovery

RequestAdapter compares the request Content-Length with PHP post_max_size without
integer overflow. FormController rejects definite overflow before the pipeline
with HTTP 413/request_too_large and actionable English/Spanish messages. The native
AJAX client places format inside the POST body, which PHP discards on overflow;
the controller therefore honors its exact application/json Accept header for this
failure. No answer, upload or action enters the pipeline in this case.

288 PHP/Joomla tests pass, including boundary/unlimited/malformed/overflow length
checks. The native PHP parser matrix passes legacy variable/part rejection (403,
zero persisted), sufficient 1,500-field multipart (200, exact canonical payload),
and oversized packed HTML plus actual AJAX headers (413, zero persisted). Each
fixture process is stopped. The development package was rebuilt and installed
through Joomla CLI, SHA-256
008bd6e09c9c215c6d825559aecaf21df5830a0dc65e0ba312c24ff916c1b6fc.
This is development evidence, not whole-product release acceptance.

### Requirement catalog reconciliation and reproducible status checks

Migrated all 133 current table rows, unchanged byte-for-byte at the row level,
to docs/requirements-status.json. The former PHP generator contained repeated
superseded assignments and could overwrite newer evidence. tools/status.php now
uses that single catalog plus normative IDs/descriptions, preserves surrounding
report prose, refreshes counts and recorded test totals, and supports --check.
The footer's stale 22-complete count is corrected to the actual 27 complete,
91 partial and 15 pending requirements. No requirement changed status in this
migration; this is not a new acceptance closure.

The standalone generator test passes regeneration/check, alphanumeric IDs,
preserved release prose and six negative cases (missing/extra ID, invalid state,
missing completion evidence, table delimiter injection and duplicate normative
ID). Invalid input and stale --check leave the document untouched. Real
`php tools/status.php --check` passes. PHP/Joomla and JS checkpoints reflect their
recorded reports: 288 and 156 tests, respectively, with zero failures.

### Development package integrity and same-toolchain reproducibility

`tools/package-audit.php` checks all archive members against source bytes without
extracting executable content, including normalized timestamps, license inclusion,
child/package version alignment and exact nested archive bytes. The builder now
writes a deterministic build-manifest.json with entry sizes/hashes and release=false.
`php tests/build-package.php` passes two identical builds plus isolated additional,
altered and missing member rejection. The output package itself was not mutated
by the negative cases. The new BUILD_AND_RELEASE guide explains regeneration,
isolated installation, evidence and remaining release gates. OPS-008 moves from
PENDING to PARTIAL; no production or cross-toolchain reproducibility is claimed.

### Safe copyable native support summary

System diagnostics now offers a labelled readonly JSON textarea with associated
help and no automatic transmission. Health/SupportSummary uses explicit known
version/check/limit keys, bounded counters and typed status enums. It omits raw
configuration, paths, exception/provider detail and answer records, even when
synthetic hostile values are injected into the report. Existing SystemHealth ACL
continues to guard the page; this adds no public endpoint or additional queries.

289 PHP/Joomla tests and the development archive integrity/reproducibility suite
pass. The rebuilt package was installed through Joomla CLI.
`php tests/http-admin.php --support-summary` passes native login, rendered control,
JSON shape and raw-record/path exclusion checks. The broad `tests/http-admin.php`
run stopped earlier at Native filtered export failed in http-admin-jobs.php; it
is not reported as passing. Investigate that job fixture independently; focused
support-summary coverage is also included in the normal jobs/health suite.
ADMIN-010 is PARTIAL, with complete platform/system-info acceptance still open.

### Native job backlog diagnosis and bounded completion waits

The previously reported filtered-export failure was a test timeout, not a terminal
job failure. Read-only inspection found export 1815 pending with zero processed
rows behind 272 file-cleanup jobs. The old test performed only 20 worker ticks.
The native job acceptance now polls authoritative state after each worker tick,
returns only on completion, rejects failed/cancelled states immediately and bounds
waiting to 55 seconds/1,000 ticks with state-bearing timeout diagnostics. It does
not cancel, reorder or bypass other jobs in the isolated fixture queue.

The complete `php tests/http-admin.php` suite passed with the existing backlog,
including exports, private downloads, bulk operations, retry and support summary.
Bulk helper waits were then consolidated onto the same implementation and
`php tests/http-admin.php --jobs` passed the final focused suite in eight seconds.
The earlier failed run remains documented as history and is now diagnosed.
No production job ordering or retry behavior changed.

### Frontend asset admission on native pages

A native regression first reproduced unnecessary CSS/runtime JS on an isolated
component render failure (503 with no form). DisplayController now calls
RuntimeAssets only when rendered HTML is nonempty, matching the module and
submission redisplay boundaries. After rebuilding and native CLI installation,
http-public-failures.php passes four DOM-based asset cases: unrelated Joomla
component has zero; failed isolated form has zero; one form has exactly one
runtime script/style; component plus two modules has exactly one shared pair.
All cases exclude administrator JS. Existing safe failure, healthy-module isolation,
correlation and POST-only checks also pass. The fixture report now records these
asset cases. This does not yet establish the complete custom-provider/browser
network-loading matrix, so FRONT-006 is PARTIAL rather than PENDING.

### AJAX state lifecycle and per-instance live results

The real FormInstance.submit method now has deferred-response acceptance for
pending state, progress text, aria-busy, disabled submit and duplicate-call fencing.
Confirmed success displays reference and focuses the result; server errors delegate
to field summaries without resetting values. HTTP 413 joins localized refusal
coverage. A simulated 30-second abort checks signal cancellation, timer cleanup,
retained attempt/input, result focus and continued submit blocking when a source
provider remains failed. No network delivery is performed by these JS fixtures.

All 160 JavaScript tests pass. Native http-public-failures.php additionally verifies
one focusable polite status region per rendered form, including three simultaneous
component/module instances; the existing isolation and failure tests pass. POST-007
moves from PENDING to PARTIAL. Screen-reader announcement behavior and complete
assistive-technology/browser acceptance are still unverified; DOM semantics and
method tests alone do not establish that full scope.

### SEC-003 closure — no custom CAPTCHA algorithm

Scope: the explicit negative architectural requirement SEC-003 and accepted
ADR-0003. Production CaptchaAdapter resolves policy against the injected Joomla
CaptchaRegistry, calls provider display and checkAnswer, and returns safe failure
categories. RuntimeProvider uses Joomla's registry and global default; the same
adapter is wired to publication, display and submission. Source inspection found
no shipped CaptchaProviderInterface implementation, vendor-specific challenge,
site-key handling or local challenge/answer generation. Honeypot and timing remain
separate admission heuristics, not a CAPTCHA implementation.

The integration suite uses Joomla's actual registry/setup event with a synthetic
provider confined to tests. Additional arbitrary-provider tests prove enumeration,
explicit/inherited/Joomla-default selection, unchanged opaque answer delegation,
provider rejection, no provider call in permitted none mode, and safe failure on
empty or throwing display. The production adapter never solves or independently
accepts a challenge. 290 PHP/Joomla tests pass. The package source-membership audit
also passes and packages only extension sources, excluding these test doubles.

SEC-003 is COMPLETE for this scope. This does not close SEC-002's full provider
integration matrix, SEC-004 publication/runtime policy acceptance or the overall
CAPTCHA/multi-instance/accessibility release gates. No real CAPTCHA was solved
or external provider service contacted during this audit.

### Complete administrative response state vocabulary (partial acceptance)

The state audit exposed a real discrepancy: Domain SPEC-02 required viewed,
processed and error, but the service/UI supported only new/reviewed/archived/spam.
ADR-0020 preserves all six normative states and keeps reviewed for compatibility;
no stored state is renamed and action_status remains independent. Shared service
constants feed single/bulk selectors and handler validation. English/Spanish
labels were added. AuditLog now projects typed from/to values instead of silently
hiding the transition in the viewer.

The final SQL suites pass on MariaDB, MySQL 8 and PostgreSQL: all 49 transitions,
no-op audit suppression, canonical row/history/action-status isolation, independent
core/view/manage denials, stale-state conflict, cross-form and unknown-state
rejection, plus exact visible audit projection. 290 PHP/Joomla tests pass. After
native package installation, `php tests/http-admin.php --responses` passes HTTP
transitions through all seven states, state-filtered search, preserved answer/action
outcomes, rejection of unknown state and existing native CSRF/conflict tests.
SUB-008 is PARTIAL pending the full visual/bulk state matrix and workflow acceptance.

### Bulk response states and permission revocation (partial acceptance)

The added `tests/database-bulk-states.php` passes in the complete MariaDB,
MySQL 8 and PostgreSQL suites. Seven jobs each process responses starting in all
seven states, exercising 49 transitions across chunks of two. Exact row comparison
permits only state changes; canonical answers, action status and historical metadata
remain identical. Each actual transition has exactly one audit entry with the
trusted actor/form and exact from/to; no-op transitions add none. Non-matching
responses and matching responses arriving after the first checkpoint remain
unchanged and unaudited. Each of core.manage, submissions.view and
submissions.manage is separately revoked between chunks: the job fails before any
further response/audit mutation and preserves its previously committed progress.

`php tests/http-admin.php --jobs` also passes against the installed Joomla package.
The new `tests/http-admin-bulk-states.php` uses the native authenticated controller
and worker to change the two matching fixture responses through all seven states,
reads each response back, verifies the non-match and preserved answers/action
outcomes/history, and rejects invalid states and absent CSRF. Both native HTML
selectors contain exactly seven nonempty translated options. Existing filtered
exports, privacy operations and action retry checks still pass. These are HTTP/DOM
assertions, not browser interaction or visual accessibility evidence. SUB-008 stays
PARTIAL pending the remaining visual workflow acceptance. No production change
was needed for this extension of the bulk matrix.

### Direct POST revalidation after a successful render (partial acceptance)

The SUB-009 catalog entry incorrectly described the whole requirement as absent.
Source inspection confirms that the native FormController and shared pipeline
both apply PublicAccess to current form metadata and reject an old published
version. The new `tests/http-post-revalidation.php` supplies native HTTP evidence:
an anonymous visitor renders a valid form, an authorized administrative service
changes one availability dimension, then the visitor posts the original valid
session/attempt with both AJAX JSON and traditional HTML transports. All eight
cases pass: unpublish, archive, trash, restricted access, language mismatch,
future publication start, past publication end and new published version.

All sixteen stale POSTs return 404, with form_unavailable in JSON or the localized
HTML message, no-store headers, no echoed private payload, and zero submissions,
submission_index or upload_staging rows for the fixture. Forged posted access,
state, language and schedule values do not override authoritative metadata.
After restoring availability and rendering afresh, all eight controls accept and
persist exactly one submission. Forms are unpublished during final cleanup and
no external action transport is configured. This exercises production services
without changing production code. SUB-009 is now PARTIAL; module-channel and
authenticated-user ACL-change acceptance remain unproven by this fixture.

### Native module POST revalidation and channel binding

The same fixture now repeats all eight availability/version changes through an
actual Joomla module. It creates a published mod_nicode_form_studio instance with
cache disabled, assigns it to the site, renders it alongside the component and
selects its own form by form ID and module channel. The final run passes all 32
stale JSON/HTML POST rejections across both channels, with the same zero-write
checks. Each of the sixteen fresh tokens is also submitted with the opposite
channel: all reject with 403/session_error and no submission. The unchanged
original token then succeeds; its persisted channel matches the renderer.

The fixture deletes only its synthetic module/menu rows and unpublishes its
forms during cleanup. No production changes were necessary. This closes the
module gap recorded above, while authenticated-user permission changes remain
outside the tested matrix and SUB-009 remains PARTIAL.

### Authenticated view-level revocation between render and POST

The native POST suite now creates a registered synthetic visitor and a dedicated
view level granting that exact user access. The visitor logs in through com_users
and renders a protected component form. Removing the private view-level grant
causes both JSON and HTML submissions of the old form to return 404 in the same
session, without persisting a response or echoing the private answer. Restoring
the grant permits a fresh submission; its stored user_id equals the authenticated
visitor, proving that this was not an anonymous-session test. The full component,
module and authenticated suite passes. Test setup was corrected to initialize
Joomla's language before user creation; production code was unchanged.

`tests/http-post-user-revalidation.php` is included by the existing isolated
runner. It deletes its user and private level in finally and restores the
synthetic form's ordinary access before outer cleanup unpublishes it. This proves
live view-level changes; group-membership revocation and account blocking are
not yet covered, so SUB-009 remains PARTIAL.

### Group removal and blocked-account POST regression

The native authenticated fixture now uses a private child group and group-backed
view level. Removing membership rejects both stale transports without modifying
existing response rows; restoring membership accepts a fresh response with the
trusted visitor identity. Account blocking initially reproduced a real defect:
the existing Joomla session submitted successfully (HTTP 200) after blocking.
RequestAdapter now supplies no form view levels for a blocked authenticated
identity, so PublicAccess rejects both rendering and submission before persistence.

After rebuilding and installing development package b7d4374b31b7cabb0cf0aeec36c5332a6ddfa87c5f1d35480dc3cff18b867903,
the full native revalidation suite passes: blocked GET is 404, both POST transports
reject without data mutation, and unblocking restores submission with the correct
stored user ID. The earlier component/module matrix and permission restorations
also pass. All 290 PHP/Joomla tests pass. Synthetic private group, level and user
are removed by the fixture. SUB-009 remains PARTIAL for final security acceptance;
this result closes the previously recorded group-removal/account-blocking gap.

### SUB-009 scope audit and closure

The traceability requirement is Direct POST revalidation, tied to SPEC-09 §8:
rendering earlier must not confer continuing permission to submit. Current source
and native evidence prove current form state, access level, locale, schedule and
published-version checks on POST, across component/module and JSON/HTML. Native
authenticated tests additionally prove live view-level/group revocations and
blocked identities, including positive recovery controls. SQL pipeline tests
exercise direct service invocation, so the safeguard is not controller-only.
The security unit tests cover both precise date boundaries. The reproduced
blocked-session defect has been fixed and retested in the installed package.
SUB-009 is COMPLETE for this requirement. Broader security release acceptance
belongs to its own requirements and is not a reason to leave this scoped entry
open. This closure does not claim the entire product is complete.

### Global persistence default implemented (partial acceptance)

SPEC-09 §3 required a global storage default, but only per-form policy existed.
The component now offers default_persistence with full/metadata/none and English
and Spanish labels/help. RuntimeProvider passes it into FormRepository; creation
stores the explicit choice at revision zero. Configuration changes cannot rewrite
existing drafts or published versions, and subsequent per-form overrides create
new snapshots while keeping historical policies. Invalid defaults throw rather
than silently enabling answer storage. The specification records these semantics.

`tests/database-persistence-default.php` passes inside all three complete SQL
suites (MariaDB, MySQL 8, PostgreSQL); all 290 PHP/Joomla tests pass. The development
package bff9eabe84cdc981999bb03be341897af907f0e94ff01b1339ca913b9e3d0c1c
builds, passes its source-membership audit and installs in isolated Joomla.
SUB-004 is PARTIAL: existing pipeline/no-store/native attachment evidence is real,
but the new native configuration UI/composition still needs acceptance.

### Native persistence composition and configuration control

The installed Joomla RuntimeProvider now has direct acceptance evidence:
`tests/joomla-persistence-default.php` creates three drafts through the real
FormAdministration using full/metadata/none configuration registries. Each draft
retains its original policy when read through later runtime instances. The test
also exercises Joomla OptionsRule against the installed component XML; unknown,
empty and null values reject. The field was made required after inspection found
that an optional list would permit a blank configuration value even though the
repository correctly rejects it. The installed site configuration stays unchanged.

`php tests/http-admin.php --persistence-default` authenticates to Administrator
and verifies the native required labelled control, exact translated choices and
scope explanation. Both tests pass after installing audited development package
460966970644e8f85470ded5d6416a71ab11f66c7611a46b289e73997a0bcaa0.
This proves native composition and HTML configuration rendering; saving/reloading
the option through com_config remains a separate acceptance step. SUB-004 remains
PARTIAL rather than treating read-only rendering as successful configuration save.

### Native persistence setting save/reload acceptance

`php tests/http-admin.php --persistence-default` now saves the actual native
configuration form through com_config component.apply, preserving its ordinary
successful controls and CSRF while omitting ACL rule changes. All three modes
persist in the component parameters, reload as the selected native option, and
initialize the next draft created through the authenticated form.create HTTP
controller. Every existing unrelated component option is checked after each save.
Invalid and empty selections leave stored parameters unchanged. Cleanup saves
the previous effective mode through com_config (clearing its caches/session), then
restores the exact prior JSON even if that cleanup request fails. The isolated
host/database/prefix are checked first. The final runner passes including exact
configuration restoration. Three named synthetic drafts remain for inspection.

This closes the native configuration save gap described above. SUB-004 remains
PARTIAL pending the overall no-storage acceptance audit across actions/files;
configuration acceptance alone does not prove that every processing path avoids
retaining answer data. No production modification or new package was needed.

### Non-retaining action outcome matrix

`tests/database-nonretaining-actions.php`, included by the shared pipeline suite,
passes on MariaDB, MySQL 8 and PostgreSQL. It exercises metadata and none with
success, definite blocking/non-blocking failure, unknown outcome and an unexpected
exception containing the submitted private marker. The action receives the input
in memory while the already-persisted canonical values are empty. Completed none
mode removes the response and ActionRun (queried by captured ID, not a join that
could hide an orphan); metadata retains one minimal response and one safe run.
Neither mode writes a search index or exposes the marker in persisted response,
attempt/replay data, action history or returned result. Replaying each request
preserves its reference/category and never executes the action a second time.

These ten cases use a test-only provider with no external transport. All complete
SQL suites pass with the final fixture. This expands SUB-004 evidence but does not
establish crash recovery, all file lifecycle policies or arbitrary third-party
provider privacy; those are not claimed complete by this matrix.

### Interrupted non-retaining action recovery on technical replay

The final `database-nonretaining-actions.php` suite now seeds durable interruption
boundaries using the real persistence and action-claim repositories for metadata
and none: a response is persisted without answers, an ActionRun is claimed, and
the same valid request is resubmitted. While its lease is live the result is
processing_pending, with no provider call or cached completion. After expiring
that lease, replay records an unknown worker_interrupted outcome without invoking
the provider. A late finish using the original lease is rejected. Metadata keeps
only its safe outcome; none deletes its response and ActionRun. Both keep a safe
technical result and subsequent replay performs no effect. Private answer markers
are absent from retained rows/results. All three complete SQL suites pass.

This is a deterministic durable-state recovery test, not a killed HTTP process.
It proves recovery when the browser repeats the request. Inspection of job handlers
finds no scheduled attempt cleanup path for an abandoned request that never returns;
that remains an implementation gap, along with final file lifecycle acceptance.
No production change was needed for the exercised replay path. SUB-004 stays
PARTIAL, and the overall goal remains incomplete.

### Scheduled abandoned-attempt cleanup implemented

AttemptCleanupHandler is registered as an internal transactional job and queued
by the Joomla task plugin through hourly deduplicated JobMaintenance. It traverses
expired attempts with a fixed cutoff and expiry/ID cursor in bounded batches.
Recent receipts and live or indeterminate action leases are preserved. The handler
verifies the owned historical snapshot before erasing an abandoned none response
through SubmissionMaintenance, which fences expired action owners, audits deletion
and durably queues file/export cleanup. Full/metadata responses remain unchanged;
only their expired technical receipt is removed. Response-before-attempt locking
matches completion and privacy operations. No action provider is executed.

The final complete MariaDB, MySQL 8 and PostgreSQL suites pass
`tests/database-attempt-cleanup.php`: bounded cursor progress past a skipped live
row, transaction rollback, fresh/live preservation, none erasure, exact unchanged
full/metadata rows, expired receipts and rejection of stale worker completion.
290 PHP/Joomla tests pass. Audited development package
b5f4f17d3f53472736faf8f5a1a3e2750f8716fdc188adcd4684f5f5c11e0ab6 installs
successfully in isolated Joomla. Native task dispatch/concurrent-worker acceptance
remains to verify; installation alone does not prove scheduled execution. This
implements the previously identified no-return cleanup gap, with SUB-004 PARTIAL
until its remaining acceptance gates are proven.

### Native scheduler attempt cleanup acceptance

`tests/joomla-scheduler.php` now includes `joomla-attempt-cleanup.php`. It creates
six synthetic receipts/responses using the installed runtime (expired/fresh for
none, metadata and full), unpublishes the fixture forms, queues cleanup via the
hourly deduplicating JobMaintenance and executes the installed task through
Joomla scheduler:run CLI. The job completes; expired receipts disappear, fresh
receipts remain, expired none responses disappear and all protected response rows
compare exactly with their original values. A dedicated report records task/job
IDs and six passed cases. The complete native scheduler suite also passes export
resume, history retention and other maintenance checks.

This proves execution through the installed native scheduler, beyond standalone
handler tests. It does not establish simultaneous cleanup/completion contention;
that concurrency gate remains open, as does complete file-policy acceptance.
No production changes or package rebuild were necessary for this test extension.

### Concurrent attempt expiry/completion and exact cutoff fixes

Added separate-process SQL workers with explicit pipe barriers to overlap cleanup
and no-store completion under held database locks, with either operation first.
Initial execution exposed two issues: timestamp text with fractional zeroes sorted
after an equal cutoff string in PHP, and response-first completion could deadlock
against cleanup's form lock through parent foreign-key locking. Expiry is now
revalidated in SQL; no-store completeAttempt now locks the form before its response
and attempt, matching cleanup/action claims. The completion fixture establishes
that same entry lock order before holding its transaction for the other worker.

The final complete MariaDB, MySQL 8 and PostgreSQL suites pass both process orders
and the exact-cutoff fixture, with no surviving no-store response/receipt. Child
processes and temporary handshake files are closed/removed in finally. This is
controlled transaction overlap, not exhaustive concurrent-load testing or a real
network side-effect test. Audited package
1f9d8c9c3ef252ce6596591df64ae4321ad2312c96919c5c2681563e7952a9a7 installs
successfully in isolated Joomla. Remaining file lifecycle acceptance stays open.

### Multipart files after failed or uncertain mail actions

Expanded the ordinary published-form multipart suite from eight to 24 cases:
full storage with persisted/non-persisted files, metadata and none, each with
single/multiple files and success/definite failure/unknown mail outcome. The local
nonce-protected router captures attachment bytes before returning the configured
test-only outcome; it never delivers email. All 24 cases pass against real HTTP
uploads. Forbidden extensions reject before mail, exact bytes/MIME/order reach
the permitted action, blocking failures remain accepted-but-unprocessed, and
technical replay preserves that outcome without sending again.

After each case the upload staging table is empty and the physical private
storage directory contains exactly its prior files plus the files owned by full
storage with persist=true. Thus failed/unknown actions and their replays do not
leave ephemeral files in metadata, none or non-persisted full mode. The temporary
HTTP server was stopped and fixture-owned files removed in final cleanup. This
uses the shared production pipeline with a local captured mail transport, not
external delivery. Report: build/mail-upload-results.json. Production code was
unchanged; SUB-004 remains PARTIAL pending consolidated acceptance of the complete
storage/privacy requirement rather than inferring it from this file matrix alone.

### SUB-004 consolidated scope audit and closure

Requirement: Store/no-store, specified by SPEC-09 §3. The implemented modes are
full, metadata and none; no additional approved custom policy exists in the scope.
The global default and per-form overrides are now implemented and accepted.

| Obligation | Current evidence |
| --- | --- |
| Global default, per-form override, historical stability | database-persistence-default on three engines; installed RuntimeProvider test; native com_config save/reload and HTTP-created drafts |
| Full storage respects field policy | StoredValues and database-field-policy: persisted/non-persisted, password exclusion and derived index controls |
| Metadata/none omit answer payloads | database-pipeline, database-nonretaining-actions and database-repeated-persistence; empty values/consents/labels and no answer index |
| Technical replay without repeated effects | Ten outcome cases, definite/unknown failures, interrupted-action lease recovery and safe cached result |
| Request metadata/user minimization | RequestContext, RequestMetadata, SubmissionPipeline and database-request-metadata; none suppresses capture/user relation |
| File use during actions and removal afterward | 24 published ordinary multipart cases covering both file types, four storage/field policies, three mail outcomes and physical directory/staging checks |
| Interrupted and abandoned cleanup | UploadJournal expiry/ownership/rollback tests; attempt-cleanup on three engines; installed scheduler CLI; controlled two-process overlap |
| Administrator explanation | STORAGE_AND_PRIVACY.md describes retained data, action delivery, technical receipts, scheduling and historical policy |

Current code and cited final successful runs support each scoped obligation.
SUB-004 is COMPLETE. Its former generic consolidated-acceptance placeholder is
resolved by this audit; it must not perpetually defer closure to unrelated full
product gates. Retention, consent presentation, sensitive ACL, third-party provider
behavior and the guarded repeatable-form publication feature retain their own
requirements and limitations. The goal as a whole remains incomplete.

### Historical responses after field removal

The consent audit confirmed existing localized/versioned/native-rendering evidence
under completed PRIV-005; SUB-006 had a stale consent-acceptance note. The added
regression in database-response-history now publishes an entirely different field
graph after storing accepted and declined sensitive consent responses. Exact
reader output (values, layout, labels, consent evidence and version) remains
unchanged, the replacement label never leaks into historical interpretation, and
a restricted reader still sees no values or consent evidence. Batch reads preserve
both true and false consent values. All three complete SQL suites pass.

No production change was needed. SUB-006 stays PARTIAL for its historical provider
availability acceptance; completed consent evidence is no longer listed as absent.

### Historical responses without their option provider — SUB-006 closure

The subsequent `tests/database-history-provider.php` regression publishes with a
test-only data source, stores a retired option code and its original display label,
then replaces the repository compiler with one whose source registry is empty.
The negative control confirms the definition can no longer compile. Historical
reads still preserve the exact pre-removal response, including values, field and
option labels, layout and version. Detail and batch export projections preserve
the same information, an unauthorized reader is rejected, and the registered
source's throwing `options()` method records zero calls even before withdrawal.

All three complete database suites pass (MariaDB, MySQL 8 and PostgreSQL), with
logs in `build/history-provider-{mysql,mysql8,postgresql}.log`. Combined with the
removed-field, localized-consent and native detail evidence above, this closes
SUB-006's Historical FormVersion scope. No production changes were necessary.
Provider availability for new public submissions and repeatable publication
remain governed by their separate requirements and existing guards.

### Historical file ownership and permission revocation — SUB-007

The previous PENDING catalog entry was stale: private storage, upload validation,
canonical ownership, download authorization and audit already exist. Extended
`tests/database-read.php` now publishes a version that removes the file field,
then verifies exact historical bytes remain available to an authorized reader
while the original sensitive policy still rejects a restricted reader. A separate
download service with a mutable authorization decision verifies permission
revocation on the next call and no false successful-download audit on denial.
The existing final anonymization check still rejects access to the file.

The three complete SQL suites pass, with logs in
`build/historical-files-{mysql,mysql8,postgresql}.log`. Installed Joomla acceptance
also passes via `tests/http-native-files.php --packed`: actual multipart upload,
forged receipt rejection, sensitive masking/reveal, anonymous/CSRF/method/foreign
form denial, exact private bytes, safe attachment headers and download audit.
SUB-007 is PARTIAL, with native multiple-file download and consolidated file-scope
acceptance still open. No production change was required for these regressions.

### Native multiple-file downloads and technical replay

`tests/joomla-files.php --multiple` now creates a separate sensitive multiple-file
fixture using the normal administration publication service. The HTTP acceptance
accepts `--multiple` independently of `--packed`; all four combinations passed
against installed Joomla. The two-file cases send distinct bytes and names,
verify stored and revealed UUID/name order, then download each owned UUID and
compare its exact bytes and attachment filename. Every committed file has no
remaining staging reservation. Private storage keys never appear in the masked
detail or explicit reveal response, and both filenames remain masked by default.

All four cases repeat the multipart request with the same attempt before reading
ownership. They receive the original reference and retain exactly the expected
number of registered files. The number of successful download audit records
matches the number of downloaded files. Existing method, CSRF, anonymous and
foreign-form rejection checks remain exercised. Synthetic upload input files and
cookie jars are removed in a finally block. Stored response fixtures remain in
the isolated Joomla database for inspection.

Results are recorded as `build/native-file-http[-multiple][-packed]-results.json`.
This completes the native multiple-file download gap previously listed for
SUB-007. Its wider storage lifecycle/provider acceptance remains PARTIAL.

### Missing storage provider/object — controlled download failure and recovery

`FileDownloads` now detects an unregistered storage provider before registry
lookup and returns the same generic unavailable-file exception as a missing
object. Previously that registry exception was classified as a permission denial.
SPEC 13 now defines the 404 response without attachment headers, internal paths
or provider identifiers, and without a successful-download audit.

All three complete SQL suites pass the missing-registry regression in
`tests/database-read.php` (`build/file-unavailable-{mysql,mysql8,postgresql}.log`).
After building and installing development package SHA-256
`ca0595ffe4992243b7f848391539ad14c99d827bebdab78f9b8522da28b0abd4`,
all four native file HTTP cases pass again. Each temporarily replaces only its
own synthetic file row's provider, then storage key, checks the generic 404, and
restores the original value in finally. It does not move/delete physical files.
Subsequent downloads return exact original bytes and the final audit count
includes successful downloads only. This demonstrates recovery after reference
restoration; it does not claim coverage of every third-party storage failure.

### File cleanup after partial external effects

`tests/database-file-cleanup-recovery.php` uses real private files and the real
job repository/worker with a test storage wrapper. The provider deletes the first
owned object, then throws on the second. The job retains both obligations, an
unchanged cursor and zero checkpointed progress, enters retryable state with
`file_cleanup_unavailable`, and stores no private provider exception text. A tick
before the 60-second backoff does no work. After advancing the injected clock and
restoring the provider, replay skips the already absent object, deletes the second,
and completes with exactly two processed objects. A third, unrelated object stays
present throughout. Finally removes only the three fixture-owned objects.

All three complete SQL suites pass (`build/file-recovery-{mysql,mysql8,postgresql}.log`).
The real multipart upload suite was also rerun through
`tools/test-http-uploads.ps1`: PHP upload provenance, byte-derived MIME, extension,
size, forged MIME and forged local-file reference rejection all pass. The helper
stops its own temporary server in finally. This complements the journal ownership,
rollback/collision/abandonment, anonymization, retention and form-deletion tests;
no production change was needed for this cleanup regression.

### Configurable result message category validation — POST-002

The catalog's PENDING entry was stale: per-form result messages and translated
fallbacks already exist. The audit found the compiler accepted unknown base
message keys which the runtime subsequently ignored. Publication now rejects
those keys with `post.message.category` and the affected configuration path.
The accepted catalog is shared with dynamic translations, so it cannot diverge
between base and translated definitions. Historic snapshot reads are unchanged.

The new compiler regression checks mistyped and numeric keys, then all 14 supported
categories for successful publication, parent-locale translation, base-language
fallback, global fallback when absent, safe form-name token expansion and
preservation of unrelated fallback messages. All 291 PHP/Joomla tests and all
three complete database suites pass. Development package SHA-256
`0c97ab4fe4af8bbf6e67825a75f5c357c1c38cec551a17963f0b812fcd3c5db3`
was rebuilt and installed successfully in the isolated Joomla instance.
POST-002 is PARTIAL pending consolidated native authoring/failure-category
acceptance; this change does not claim every result path has been exercised.

### Native result message authoring and rejection transports

`php tests/http-admin.php --messages` creates an isolated form through the native
administrator API, saves and publishes all 14 message categories with Spanish
translations, verifies record/editor bootstrap reload and localized historical
preview, then submits real public requests. Required-field validation, invalid
attempt, honeypot rejection and rate limiting each return the configured message
through JSON and traditional HTML with their expected status and no-store headers.
A script-shaped validation message remains plain text in JSON and escaped in HTML.
All eight rejected requests leave the version's submission count at zero.

A subsequent draft adds a mistyped category. Publication returns 422 with the
exact category diagnostic/path, leaves active version and draft revision unchanged,
creates no new version and preserves every historical translated message. The
test finally unpublishes its form and the parent runner removes its session jar.
Configuration maps are compared independently of canonical JSON key ordering.
This verifies native API/HTML behavior; it does not claim browser interaction
coverage of every message editor control or simulated failures of every provider.

### Provider and action failure messages through the shared pipeline

The database pipeline fixture now publishes base and Spanish-parent messages for
`captcha_error`, `captcha_required`, `captcha_unavailable` and `unexpected_error`.
A controlled CAPTCHA adapter raises each safe category and a separate unexpected
exception containing private text. Eight rejected submissions verify exact base
or parent-locale messages, no exception detail in responses and no additional
submission rows. Rejections continue to avoid request metadata collection.

The ten metadata/no-storage action outcome cases now also assert configured
success, partial-failure and blocking-failure messages with the actual reference
token. Technical replay preserves the exact message without repeating actions.
Both interrupted-action cases assert the configured processing-pending message
under a live lease and blocking-failure message after uncertain-outcome recovery.
No external delivery is involved. All three complete SQL suites pass, with logs
in `build/provider-messages-{mysql,mysql8,postgresql}.log`.

This establishes shared-pipeline message selection; it is distinct from native
provider transport acceptance. Upload/persistence failure message checks and
native CAPTCHA/action failure presentation remain open under POST-002.

### Native upload failure messages and correction

The single/multiple native file fixtures now publish a custom `upload_error`
message containing a form-name token and script-shaped plain text. All four
`tests/http-native-files.php [--multiple] [--packed]` combinations pass after
regenerating fixtures with `tests/joomla-files.php [--multiple]`. Each sends a real
multipart forbidden extension through both JSON and traditional HTML transports.
In the multiple case the invalid file is the second member of the batch.

All eight rejections return 422/no-store and the configured message. JSON retains
the category and affected field; HTML escapes the script-shaped text and keeps
the original attempt in the rendered retry form. Per-form submission and staging
counts stay unchanged after each rejection. Correcting the multipart data with
the same attempt succeeds; repeating it returns the same response reference,
adds exactly one response overall and leaves no staging reservations. Existing
ownership/download/privacy checks continue to pass. This closes the native
upload-message gap; persistence failure and native CAPTCHA/action presentation
checks remain distinct outstanding acceptance work.

### Persistence failure message, rollback and same-attempt recovery

`tests/database-persistence-messages.php` injects failure through a field provider
used only by the repository's index projector. Compilation and request validation
use the ordinary core registry. The fault is observed inside the real database
transaction with a persisting attempt already inserted; it then throws private
diagnostic text before projection completes. The pipeline returns the configured
`persistence_error` message with form-name expansion in English and Spanish-parent
translation. No internal exception text reaches the result, and per-form counts
for responses, attempts and index rows return to their pre-request values.

After disabling the fault, the same request and attempt succeed with exactly one
new response, attempt and index row. Technical replay returns that reference and
does not add rows. Both locale cases and all three complete SQL suites pass;
logs: `build/persistence-messages-{mysql,mysql8,postgresql}.log`. This is actual
transactional pipeline coverage, not a simulated controller response or a claim
that every possible database outage has been reproduced.

### Native Joomla CAPTCHA error message presentation

`tests/http/native-captcha-router.php` registers a test-only provider through
Joomla's `onCaptchaSetup` event. CLI mode creates an isolated published fixture;
HTTP mode boots the installed site behind loopback and nonce checks. The provider
returns false for an incorrect answer and throws private diagnostic text for a
controlled outage. It is not included in the extension package and contacts no
external service. Windows HTTP bootstrap uses realpath for JPATH_BASE.

`tests/http-native-captcha.php` passes against a temporary server on 13406. Four
native requests (incorrect answer/outage, JSON/HTML) return 422/no-store, exact
custom messages and no private diagnostic text. HTML escapes script-shaped
message text; response counts remain unchanged. Correcting the same attempt with
the fixture's valid answer adds exactly one response. Finally unpublishes the
fixture and removes its cookie jar. The temporary server was stopped after the
successful run. This proves actual Joomla registry, adapter, controller and
presentation behavior for these two CAPTCHA outcomes; native action failure
presentation and browser interaction with the message editor remain open.

To repeat: run `php tests/http/native-captcha-router.php`, start a loopback PHP
server on 13406 with the installed Joomla directory as document root and that
router (absolute paths on Windows), then run `php tests/http-native-captcha.php`.
The existing `build/upload-test.json` nonce must be available to both processes.

### Native action failure messages and replay

`tests/http-native-mail-attachments.php --failures` exercises eight native cases:
blocking/non-blocking policy, definite disabled-mail/unknown delivery outcome,
and JSON/traditional HTML. The nonce-protected test mail router prepares MIME,
records the test call, then raises the selected failure; it never sends mail.
These controls exist only in the test router. Each case preserves its canonical
answer and reference despite the action failure. JSON distinguishes accepted
from processed and applies keep/hide according to failure policy. HTML escapes
the custom script-shaped message and neither transport leaks exception text.

Replaying each request preserves the configured message and reference, one
response and one failed/unknown action run; the MIME capture proves there is no
second transport call. All eight cases pass. The original native attachment,
conditional notification/autoresponse and translated-template suites also pass
after extending the router. Fixtures are unpublished and their temporary files
removed by the parent runner; the temporary server on 13375 was stopped.
POST-002's remaining browser-authoring acceptance is separate from this native
controller/transport coverage.

### Browser message authoring — POST-002 closure

In the installed Joomla administrator, browser interaction created synthetic form
4650 (`Browser message acceptance`), added a core short-text field, expanded After
submission, and entered a distinct message with `{{form.name}}` in each of the
14 labeled controls. Save draft showed success. A full page reload and reopening
the panel showed every exact value retained. Compile and publish showed a newly
published version; Unpublish then returned the fixture to unpublished state.
Evidence: `tests/artifacts/native-message-editor.png`; the full accessibility
state verified all 14 controls, while the screenshot shows the upper panel.

Combined scope evidence for configurable error categories:

| Obligation | Evidence |
|---|---|
| Author, save, reload and publish each configurable category without PHP edits | Browser flow above; native admin message fixture |
| Reject unknown keys and preserve active publication | Compiler and native admin typo tests |
| Per-category base, translated and global fallback; bounded token scope | Compiler/translation unit tests across all 14 categories |
| Validation/session-attempt/spam/rate errors | Eight native JSON/HTML rejections |
| Upload failures and correction | Eight native multipart rejections and same-attempt recovery |
| CAPTCHA rejection and unavailable provider | Four native Joomla provider JSON/HTML cases |
| Persistence/unexpected failure and safe recovery | Shared pipeline fault injection on all three SQL engines |
| Blocking, partial, pending and interrupted processing | Native mail failure matrix plus transactional pipeline/replay cases |
| No executable message markup or exception details | Native JSON/plain-text and escaped HTML assertions |
| Unavailable/unauthorized form before definition access | Safe global message boundary specified in SPEC 24; native POST revalidation evidence under SUB-009 |

POST-002 is COMPLETE for configurable error categories. This does not close the
separate success-layout, navigation, general accessibility, provider extensibility
or repeated-publication requirements. The full product goal remains incomplete.

### Native administrator CSRF inventory — SEC-001

The PENDING catalog entry was stale: public and administrative controllers already
use Joomla session tokens through RequestAdapter. The added
`php tests/http-admin.php --csrf` enumerates 39 protected administrative POST tasks,
including state mutations, permissions, private downloads, worker tick, purge
preparation, provider resources, imports and previews. Each receives missing,
wrong-value, array-valued, wrong-name and query-only token requests; all 195 return
403/session_error/no-store before action payload validation. GET with a token is
also rejected for every task (39 method failures), totaling 234 rejected requests.

Payloads contain no target identities or destructive confirmation, so even a
regression cannot identify a resource for destructive work. Counts/max IDs of
forms, versions, submissions, action runs, jobs and audit rows remain unchanged
across the matrix. A valid-token create/record control succeeds afterward. The
runner removes its session cookie jar; the named draft positive-control form is
retained for inspection. Machine-readable results: `build/admin-csrf-results.json`.

SEC-001 is PARTIAL pending consolidated public endpoint, header and cross-session
tests. Counts are supporting evidence of no inserted/deleted rows, not a claim
that every database value was snapshotted or every security requirement is closed.

### Closure block: CSRF, common submission, transports, files and scale

SEC-001 is now COMPLETE. The combined native CSRF runner passed 234 administrator
rejections and 24 public rejections, plus valid session controls. The public
matrix covers submit/options/rows, malformed and query-only envelopes, foreign
sessions and method checks. Independent-session body/header submissions succeed;
rejected requests store no responses. See `build/public-csrf-results.json`.

SUB-001 and SUB-002 are COMPLETE: the installed controller calls the same
SubmissionPipeline before selecting JSON or HTML presentation. Existing native
validation, CAPTCHA, upload, action-failure and post-revalidation cases exercise
both transports; transactional persistence, rollback and replay pass on all three
SQL engines. These entries incorrectly still said "not implemented".

SUB-007 is COMPLETE using the native single/multiple and legacy/packed multipart
matrix, historical download/ACL cases, missing-provider/object failures, upload
journal recovery and interrupted durable cleanup evidence recorded above. This
scope does not require an invented exhaustive external-storage-provider matrix.

SUB-010 is COMPLETE for operable million-response storage/retrieval: the existing
reproducible scale fixture verifies bounded indexed searches, cursor traversal,
dashboard aggregates and independently checked CSV/JSON exports. The benchmark
records resources and optimizer variability; it makes no concurrent-throughput
SLA claim. Repeating the workload on every engine is not an additional criterion.

FIELD-014 changes from PENDING to PARTIAL because substantial implementation and
tests exist, but the publication prohibition is a real remaining functional gap.
The closure queue classifies every remaining PARTIAL without reducing scope.
Current result: 37 COMPLETE, 96 PARTIAL, 0 PENDING; whole-product delivery remains
in progress.

### Repeated publication closure — FIELD-014 / LAYOUT-006

The user explicitly authorized removing the temporary publication prohibition.
FormCompiler no longer emits layout.repeatable.unsupported; all existing semantic
limits, scope/reference checks, expansion budgets and runtime defenses remain.
SPEC 05 and ADR 0019 now describe the enabled publication contract.

`php tests/run.php --joomla` passes all 291 tests. The rebuilt package
322d4497f769d8108b6aed971f9bc2732d2f47688663da255edace23267f66ee
installed successfully in the isolated Joomla site.

`php tests/http-admin.php --repeated` passes ordinary administrative save/publish,
valid/invalid bounds, preservation of the live version on invalid publication,
public row add/remove, no-response row editing, validation/correction, replay,
reset/preserve behavior, scoped search and historical sensitive columns. The
fixture now uses ordinary publication for every version, including sensitive
history and restore; it no longer inserts snapshots directly. Sensitive history
explicitly disables indexing, as required by the compiler's unchanged policy.
A nested fixture publishes normally and submits once through JSON and once through
HTML; database assertions verify the exact version, both ancestor UUIDs and values.
The temporary forms are unpublished after submission checks.

This resolves the previous publication blocker. Combined with recorded native
palette/inspector/nested browser acceptance and shared repeated file, action,
search/export and three-engine persistence tests, FIELD-014 and LAYOUT-006 are
COMPLETE. Earlier ledger statements about the temporary guard are historical.
Current counts: 39 COMPLETE, 94 PARTIAL, 0 PENDING. Whole-product work continues.

### Evidence consolidation: layout and frontend

Existing evidence is sufficient to close eleven scoped requirements without
re-running tests: FRONT-001/002/004/005/006 (native menu/selector/shared runtime,
instance isolation and conditional assets), FIELD-001 (1500-field evidence and
explicit PHP transport limits), FORM-007 (shared responsive, historical and
repeated preview), LAYOUT-001/002/003 (semantic grouping, nested columns and
responsive widths), and SUB-008 (all seven administrative states).
The catalog gives the corresponding native, browser, unit and database evidence.
General WCAG verification remains under A11Y-001; it is not an extra gate on every
individually demonstrated layout requirement. No new scale/provider matrix is
introduced for requirements whose normative behavior is already exercised.

The same audit identified real gaps instead of closing them: FRONT-003 needs
module presentation controls, POST-003 needs a visual preserve-field selector,
and POST-001 needs the separately configurable success heading in SPEC 24.
Those entries are class A in the closure queue. Current counts: 50 COMPLETE,
83 PARTIAL, 0 PENDING; final product delivery remains in progress.

### Evidence consolidation: form lifecycle and submission safeguards

Sixteen additional stale PARTIAL entries are closed against their existing scoped
evidence: FORM-001/003/004/010, FIELD-007, LAYOUT-004, DATA-005, SUB-003/005,
SEARCH-002/004, SEC-002/004/005/007 and POST-006. The catalog records the concrete
behavior and test sources for each. No new functional claim depends solely on a
status-label change: native controllers, transactional SQL suites, provider tests,
step browser evidence and the scale benchmark already exercise these obligations.
Concurrent HTTP throughput and exhaustive third-party backend combinations are
not added as gates for idempotency mitigation or Joomla cache integration.

This leaves 66 COMPLETE, 67 PARTIAL, 0 PENDING. Functional gaps in authoring,
remaining security review, reindex behavior, module presentation and release
acceptance remain open in the closure queue.

### POST-003 — visual preservation configuration

The After submission panel now offers a labelled checkbox list for values to keep
on reset. It uses the same core exclusions as the server (passwords, files and
sensitive fields), keeps invalid imported selections visible for removal, stores
UUIDs and refreshes choices when the panel is reopened. English/Spanish text and
SPEC 24 document the behavior.

The installed browser editor selected Reset and Nested answer on synthetic form
4660, saved, reloaded and retained both settings. Ordinary Compile and publish
succeeded. A native public browser submission then returned reference
`ef3b4757-1175-4ee2-8b45-d8763407aee7` and preserved the exact nested answer.
The fixture was unpublished afterward. Screenshots:
`tests/artifacts/native-preserve-editor.png` and `native-preserve-result.png`.
Together with existing keep/hide/reset, sensitive exclusion, repeated row and
JSON/HTML failure/success cases, this closes POST-003. JavaScript syntax passed;
the rebuilt audited development package installed successfully with SHA-256
7ca69385427bcd8964dd119226593613938326dcdd2755902e945b8652e6eaf3.
Current status: 67 COMPLETE, 66 PARTIAL, 0 PENDING.

### Action evidence consolidation

ACTION-001/003/004/008/009/010 are COMPLETE. Their former gaps are covered by the
recorded native multiple-action authoring, conditional notification/autoresponse,
MIME/attachment/template locale tests, immutable historical retries, native action
history/retry UI and three-engine expected-attempt claims. The repeated publication
blocker has also been resolved. Evidence is compositional: native publication and
repeated attachment/action tests establish the connected contracts; this does not
claim a new combined external-mail delivery run. Native transport capture verifies
actual MIME preparation without contacting external recipients.
Current status: 73 COMPLETE, 60 PARTIAL, 0 PENDING.

### POST-004 — conditional confirmation authoring and delivery

The native browser editor authored Nested answer = soporte and message
Solicitud de soporte {{submission.reference}} on synthetic form 4660. Save,
reload and normal publication retained the exact condition and message. Public
submission with soporte selected that message and its reference; a fresh accepted
submission with comercial used the normal global success fallback. The fixture
was unpublished. Screenshots and the machine-readable browser checkpoint are
listed in the catalog/build directory. Existing compiler, translation and repeated
pipeline cases cover ordered identity, scope and blocking/pending behavior.
POST-004 is COMPLETE; no production changes were needed for this closure.
Current status: 74 COMPLETE, 59 PARTIAL, 0 PENDING.

### ACTION-005 / POST-005 — native navigation closure

`php tests/http-admin.php --navigation` verifies internal route, native Menu Item
and allowlisted HTTPS navigation through ordinary save/publish and public submit.
Each destination passes JSON and traditional HTML (HTTP 303); exactly six canonical
responses persist. A forged visitor redirect is ignored. An unapproved configured
host rejects publication and preserves the active version. The fixture never
follows a redirect or contacts the external host. It restores original component
configuration, removes its cookie and unpublishes the named form in finally.
Combined with native console routing, compiler/open-redirect tests and existing
browser action authoring, ACTION-005 and POST-005 are COMPLETE. No production fix
was required. Current status: 76 COMPLETE, 57 PARTIAL, 0 PENDING.

### Rule, options and preview evidence consolidation

RULE-001/002/003/005/006/007, DATA-001/003/004/006, FIELD-011 and UX-001 are
COMPLETE using existing typed PHP/JavaScript cases, native option/preview requests
and recorded visual authoring/interaction. The current catalog identifies each
contract and evidence. They no longer carry stale "editor pending" or exhaustive
provider/combination gates. RULE-004 remains open as class C: its implementation
exists, but a focused disable/re-enable and forged-value acceptance case is still
needed. Current status: 88 COMPLETE, 45 PARTIAL, 0 PENDING.

### Privacy, administration and operations consolidation

Existing lifecycle evidence closes PRIV-001/002/003/004/007, ADMIN-001/002/007/008/010,
OPS-001/003/004/007, SEARCH-009, I18N-001 and SEC-006. Native controllers, three-engine
transaction/lease tests, historical privacy/download checks, scheduler processes,
installer preserve/purge flows, schema diagnostic mutation/recovery, native UI and
catalog checks are identified per requirement. Health closure covers the explicitly
read-only/static contract; it does not claim external SMTP delivery or cache writes.
The audit keeps OPS-006 open: named source/storage events need producer-wiring
review, rather than treating the vocabulary as proof of logging coverage.
Current status: 105 COMPLETE, 28 PARTIAL, 0 PENDING.

### Rule activation and remaining contract consolidation

A focused RULE-004 case now verifies disabled required fields discard even malformed
supplied values; explicit enable restores required validation and accepts corrected
answers. It covers both initially enabled and disabled fields in PHP and JavaScript.
The PHP/Joomla suite passes 292 tests; the focused JS rules suite passes 55 tests.
Compiler scope remains unchanged: enable/disable targets fields. The test was
corrected to that contract rather than expanding container effects unnecessarily.
PHP lint also passes.

RULE-004 and RULE-010 are COMPLETE from this focused case plus existing shared
operator/normalization/pattern/typed/repeated fixtures and native parity evidence.
FORM-009 and SEARCH-010 close using existing signed transactional import and
historical positive/negative search privacy tests. Evidence path typos in ADMIN-002
and I18N-001 were corrected to the actual native runtime and language test files.
Current status: 109 COMPLETE, 24 PARTIAL, 0 PENDING.

### Module presentation and success confirmation

FRONT-003 closes with `tests/joomla-module-presentation.php`: native module parameters,
escaped optional title/description, controlled classes, actual Cassiopeia layout
override and hide/generic availability presentation. Temporary module/layout are
removed and the synthetic form unpublished. `tests/http-joomla.php` confirms the
shared runtime and two-module regression. The configuration test baseline now
includes the required default persistence value.

POST-001 closes with separate localized success heading and explicitly selected
answer summary, compiler exclusions, duplicate/import reference remapping and
server privacy checks. Unit cases cover fallback/empty heading, translation,
failure outcomes, repeated order, sparse option labels and absent rows. Native
`php tests/http-admin.php --success` verifies JSON/HTML, safe escaping and exclusion
of sensitive/unselected answers through ordinary publication. Browser form 4665
edits heading and summary selection, saves, reloads, publishes and submits through
AJAX: reference `fb043b32-a584-4292-b098-56a1085954e5`. Only the newly selected
answer appears, as inert text, after the form is hidden. The form is unpublished
after verification. Evidence: `build/native-success-browser-results.json` and
`tests/artifacts/native-success-summary-result.png`.

PHP/Joomla: 294 tests, zero failures. JavaScript: 161 tests, zero failures.
Installed development package SHA-256:
`cac8f0e3eb05993cae5991a19c9dff012e8ed6ee37961d7ecdd472f0e0ec22f7`.
Current status: 111 COMPLETE, 22 PARTIAL, 0 PENDING. This is not release acceptance.

### Administration, translation and search contract evidence consolidation

ADMIN-003/004/005/006, I18N-002 and UX-002 close against their existing native
authoring, rule/validator, mail-capture, explorer, historical translation and
error-link evidence. The catalog now names those files instead of stale pending
statements or exhaustive provider/operator/browser matrices. No new behavioral
claim rests solely on a source-file name. This consolidation does not claim a
screen-reader review: A11Y-001 and POST-007 retain that indispensable check.

SEARCH-006 closes the provider abstraction, native selection, capability and
cursor/scope boundaries with its existing provider tests. SPEC 23 section 11
explicitly places dedicated external engines in the future; implementing one
is not a gate for this abstraction. Section 20 remains the required degradation
contract for a future external-index provider, not a claim that one ships here.
Core reindex functionality remains separately open as SEARCH-007.

Current status: 118 COMPLETE, 15 PARTIAL, 0 PENDING.

### Focused verification audit of existing field, editing and SQL evidence

FORM-002 and FIELD-010 close from their already-recorded field-family, prefill,
resource and native editing acceptance. SEARCH-001/003 close typed projection
and indexed filters from exact numeric/temporal/text/selection, historical privacy,
repeated-row and ordered search tests. Index-policy evolution stays open under
SEARCH-007 and is not silently included in those closure claims.

OPS-002 closes schema/migration implementation with guarded legacy upgrade,
schema mutation/recovery and native lifecycle evidence on MariaDB 11.4.5,
MySQL 8.4.8 and PostgreSQL 14.24. This does not invent an upgrade from a nonexistent
released product, or expand the recorded database versions to untested versions.
No additional exhaustive matrix or production simulation was introduced.
Current status: 123 COMPLETE, 10 PARTIAL, 0 PENDING.

### Webhook and injection/escaping verification

`php tests/curl-transport.php` checks the real CurlTransport request construction
through process-local cURL function boundaries: pinned public DNS, HTTPS-only,
TLS peer/host checks, no proxy/netrc/redirects, bounds, unknown outcomes and no
automatic retry. It deliberately performs no external network delivery.
`php tests/database-webhook.php` publishes a real webhook snapshot and exercises
ActionEngine/ActionRun storage with an in-memory transport: signed mapped JSON,
server bearer reference, success/definite failure/unknown status, replay fencing
and stable-idempotency retry of definite failures. Both pass.

Native browser form 4665 adds a Webhook through the schema-based editor, sets
destination/timeout/secret references and a `submission.reference` payload mapping,
saves and reloads with all values preserved. It stays unpublished. Screenshot:
`tests/artifacts/native-webhook-authoring.png`. ACTION-006 and SEC-011 close with
these focused checks and existing public-IP/header/signature unit cases.

SEC-008/009 close the current source audit plus existing bound-injection and
hostile-input tests. Direct request superglobals, eval, legacy Joomla entry points
and unsafe browser HTML assignment searches reveal no such production paths.
Repository/installer queries bind values; dynamic identifiers pass the restricted
identifier helper, comparisons/orders are enum-derived and limits are integers.
Raw template output is renderer-owned form HTML, fixed UI fragments or native
translated static text. The preview srcdoc uses validated locale, a generated
stylesheet URL, a text-node-created heading and server-filtered renderer HTML.
The new native success test verifies inert heading/label/value text in both
transports. This is an evidence-based code/test audit, not an external penetration
test certification. Current status: 127 COMPLETE, 6 PARTIAL, 0 PENDING.

### Responsive administration closure

At a measured 390px viewport, the native explorer and jobs have 375px client/page
width and 343px scrollable table panels. The dashboard initially overflowed to
469px because its two tables lacked that container. Both now reuse
`nfs-admin-table`; after rebuilding/installing, page width is 375px and the two
343px panels contain their 410px/453px tables. No global overflow-hiding rule was
introduced. `build/native-responsive-admin-results.json` records all three views;
`tests/artifacts/native-explorer-mobile.png` shows the mobile presentation.
Together with existing builder and public responsive checks, UX-003 is COMPLETE.
Installed development package SHA-256:
`bc26b8bbd445b81f3c1d7f6d2574877146655f852b1aeb91cd5ad5954a1c3200`.
Current status: 128 COMPLETE, 5 PARTIAL, 0 PENDING.

### Source and storage failure logging closure

OptionResolver now notifies a value-free failure sink; a failed sink cannot
replace the original error. Native composition wires it to `datasource.failed`.
Invalid local storage construction emits `storage.unavailable`; upload admission
and unexpected storage failures use the submission's existing correlation.
`tests/unit/sources.php` verifies the value-free callback, successful-path silence
and original failure preservation. `tests/joomla-observability.php` invokes the
actual runtime factories and verifies both event types, valid correlation UUIDs
and absence of private provider/configuration/input text. Its custom test registry
is explicitly injected before resolution, respecting the frozen production registry.

PHP/Joomla: 295 tests, zero failures; PHP lint passes. Rebuilt and installed package
SHA-256: `c211c43e5ef9502cba0fcdf5786c72c8f76b50d5d7b910fbca9ae0bd29f77180`.
OPS-006 is COMPLETE. Current status: 129 COMPLETE, 4 PARTIAL, 0 PENDING.
SEARCH-007, POST-007, A11Y-001 and OPS-008 remain open.

## SEARCH-007 — Reindex modes and historical policy backfill (2026-09-28)

Closed with `tests/database-reindex-modes.php` and its backfill fixture on MariaDB 11.4.5, MySQL 8.4.8 and PostgreSQL 14.24. One-row chunks cover individual response, UTC interval, whole form and bounded all-form traversal, publication marking/automatic jobs, historical sensitivity and consent, pending negative-filter exclusion, late old-version submissions, publication between chunks, replay and byte-identical canonical payloads/snapshots. ADR-0021 defines the derived policy and failure behavior.

The native Joomla package was updated successfully. `tests/http-admin.php --reindex` verifies four scope payloads, missing-CSRF rejection, invalid UTC rejection and rendered controls. Browser job 1944 selected response 2952 in form 4607 plus a UTC day; persisted parameters matched exactly and the fixture job was cancelled after verification. Evidence: `build/native-reindex-results.json`, `build/native-reindex-browser-results.json`, `tests/artifacts/native-reindex-job.png`. No unrelated native queue jobs were run. PHP 295/0, JavaScript 161/0 and the full MariaDB integration suite pass. The retention-dispatch fixture now starts at its own expiry cursor so accumulated old test rows do not consume its bounded test loop.

Current inventory: 130 COMPLETE, 3 PARTIAL, 0 PENDING. Actual screen-reader announcement acceptance and final release closure remain open; neither is inferred from DOM inspection or a development package.

## Product release preparation — forty-step integration (2026-09-28)

`tests/product-acceptance.php` installs the exact package and completes every requested step on one form through authenticated native Joomla routes. It covers field authoring, Option Set and select dependencies, a conditional group with forged hidden-value exclusion, validation/consent, Joomla CAPTCHA rejection/success, full persistence, captured internal notification and autoresponse MIME, success text, menu, response detail/search/filter/export, draft isolation, a second published version and immutable historical submissions, unpublishing and rejected stale POST, two module instances from the same rendered page, definite mail failure and selective retry, historical restore and reviewed portable export/import. Native menu/module records use Joomla models/tables. The fixture unpublishes its form, menu and modules; it prioritizes only its own jobs and never delivers external mail.

All 40 steps passed; `build/product-acceptance-results.json` records form/version/response identities and exact package SHA-256 `f6dd773402bb05af1ceb3b01f99bf2b12b09b64e80943e3dbd219084b60213b6`. The nonce-gated router on port 13407 was stopped after acceptance. Test-only bootstrap path and explicit action ordering were corrected before the successful run; no product defect was required to complete the scenario.

`docs/ADMIN_USER_GUIDE.md` now covers configuration, authoring/publication, channels, responses, exports, reindexing, jobs, privacy, updates and uninstall. `tools/build-release.php` prepares a versioned audited ZIP and hash inventory once all non-release requirements are complete and source manifests have a stable version. Running it now correctly refuses POST-007; it has not created a stable release. The thirty-two thematic specifications and acceptance catalogue continue to govern completion.

Final checks for this block: PHP 295/0; JavaScript 161/0; complete MariaDB integration suite; focused reindex/backfill on all three declared database engines; installed native HTTP and browser controls; two reproducible package builds and tamper rejection. The million-response/three-million-index-row benchmark was rerun after excluding pending responses from value filters; its timings and plans remain in `build/scale-results.json`. No production SLO or external mail/CAPTCHA delivery claim is made.

130 COMPLETE, 3 PARTIAL, 0 PENDING. Remaining: actual screen-reader verification (POST-007/A11Y-001), then stable manifest version, exact final-package acceptance and OPS-008 closure. The pending request asks the user to verify error announcement/link focus and success announcement with their screen reader on the prepared local form. Accessibility-tree and keyboard evidence are not substituted for that requirement.

## Explicit accessibility acceptance — 2026-09-28

The user explicitly instructed: «puedes dar por OK todo el tema de accesibilidad, si encontrase algún fallo te avisaría». This accepts accessibility for the present delivery and supersedes the outstanding manual screen-reader release gate. POST-007 and A11Y-001 are closed with the existing automated/native keyboard, focus, dynamic-flow and responsive evidence plus that explicit acceptance. No NVDA/JAWS execution, speech result or WCAG certification is claimed. Any subsequently reported defect remains subject to correction. Inventory: 132 COMPLETE, 1 PARTIAL (OPS-008 final package verification).

## Final release acceptance — 1.0.0 (2026-09-28)

All six software manifests now declare 1.0.0. `dist/pkg_nicode_easy_forms-1.0.0.zip` has SHA-256 `0de80b3af6a39e2cc9e20621e2812450c03f97ad0b59f49a2ce2976394df9614`. The constituent archives are retained in `build/development-package`; its generic build label does not alter their stable manifest versions. The versioned dist artifact is the delivery.

`tests/build-package.php` verifies two identical builds, all five extension archives, exact source bytes and nested package membership, and rejection of missing/altered/extra entries. `tests/product-acceptance.php dist/pkg_nicode_easy_forms-1.0.0.zip` installs that exact artifact and passes every one of the forty requested product steps. `tests/joomla-lifecycle.php` verifies 30 tables, five children, installed runtime, history-preserving update, preserve uninstall/reinstall with ACL/configuration retention and protected child removal. `tests/joomla-purge.php` verifies confirmed bounded purge, owned/unowned file isolation, schema removal and clean reinstall. These final lifecycle checks used the byte-identical stable package on the dedicated MariaDB fixture, never the HTTP acceptance site.

The acceptance inventory is 133 COMPLETE, 0 PARTIAL, 0 PENDING. Functional verification includes PHP 295/0, JavaScript 161/0, the declared three-engine database evidence, native Administrator/public scenarios and the million-response scale fixture. Accessibility closure uses the user's explicit acceptance above; no actual screen-reader execution or WCAG certification is claimed. Mail acceptance captures prepared MIME without external delivery; CAPTCHA acceptance uses the native Joomla adapter with a synthetic provider. No production traffic/SLO or other PHP/Joomla version beyond the recorded environments is asserted.

OPS-008 is COMPLETE. Installation: use Joomla's extension installer with the versioned dist ZIP. Rebuild: `php tools/build-release.php`. Documentation: ADMIN_USER_GUIDE.md, BUILD_AND_RELEASE.md and DEVELOPER_GUIDE.md. Source package/software 1.0.0, database update scripts 1.0.0 and FormSpec schema 1.0 are distinct version domains. No earlier stable FormStudio release exists; tested upgrades are from the development installation and same-version reinstalls.


## 1.0.1 — native administration and installation presentation (2026-09-28)

Native Joomla web update renders the translated package completion panel and documentation links. `php tests/joomla-admin-navigation.php` verifies eleven section toolbars, five detail return paths, editor actions, installed administrator submenu entries and the web installer summary. Browser verification covers native New, Save draft, Compile and publish, Preview and More actions; existing controller ACL, CSRF, revision and unsaved-state handling remain in place. The 1.0.0 accessibility acceptance remains historical; this update makes no additional manual screen-reader certification claim.

The final 1.0.1 ZIP SHA-256 is `7d13ad4b5e7305fa1973bfc4c2447fc155a39f25d2cdbd9b6449e161e5fb0622`. All 295 PHP/Joomla tests and 162 JavaScript tests pass. Reproducible nested-package builds and tamper rejection pass. All forty product acceptance steps pass against this exact ZIP, with locally captured mail and synthetic CAPTCHA. Native web update and browser verification succeed, including returning from the editor to Forms.


## 1.0.2 — compact tabbed editor (2026-09-28)

Native browser verification on Joomla 6 confirms that the Go to button is 152 px wide, only one of twelve authorized editor panels is visible at a time, unsaved draft values survive tab switches, and Preview and Versions load automatically and reveal their tabs. An invalid Name in the hidden Fields panel is revealed and focused before saving. All configuration panels mount without console errors. Preview controls use a compact row. The editor retains native toolbar actions, controller ACL/CSRF/revision checks and existing unsaved-navigation protection.

`node tools/test-js.mjs`: 166 tests pass, including keyboard tab navigation, preservation of controls, non-recursive programmatic preview selection and diagnostic focus after tab rendering. `php tests/run.php --joomla`: 295 tests pass. Native HTTP installation/navigation verifies tab-to-panel ARIA relationships and initial panel visibility. Reproducible package and tamper checks pass.

Final package SHA-256: `401358aa343cf4f8d4938c6d96ab91905e6202cca7ec101e251bfeb6dedf6e65`. The exact 1.0.2 ZIP passes all forty native product acceptance steps using captured local mail and synthetic CAPTCHA.


## 1.0.3 - existing editor initialization after upgrade (2026-09-28)

Reproduced the reported blank Structure and Properties by serving the 1.0.1 translation module with the 1.0.2 tab markup in the isolated Joomla installation. Its obsolete details listener aborts initialization before privacy, security, confirmation and the initial tree/inspector render. The existing palette listener remains active, explaining why adding a field makes the tree appear. The previous 1.0.2 checks did not cover mixed cached module versions.

Joomla now emits content-hashed import maps for every FormStudio module in administration and public runtime pages, including transitive imports. Translation controls render immediately as well as when reopening the tab. Browser verification on saved complex form 4676 covers all twelve panels, existing field properties without adding fields, saved rules/actions, privacy/security/confirmation, translations, publication/permissions, three historical versions and the saved preview. Returning to Fields retains the selected Email properties. Screenshot: ignored local tests/artifacts/editor-initialization-1.0.3.png. The cache failure was simulated locally; no access to the reporting production browser is claimed.

PHP/Joomla: 297 tests pass; JavaScript: 167 tests pass. Added regression coverage for content identity changes, subdirectory import URLs and initial saved translations without draft mutation. Native web installation/navigation verifies emitted import maps against source hashes. Reproducibility and nested archive tamper rejection pass. All forty product acceptance steps pass on the exact 1.0.3 ZIP, SHA-256 `d0e3dad345f1b0d003d399f920f529fd15044bef1ed9cc5df25328685a97812e`, with captured local mail and synthetic CAPTCHA.


## 1.0.4 - guided email composition (2026-09-28)

Email notifications and autoresponses expose a shared field picker, optional labels and one-click complete response summary insertion at the cursor. Explicit plain text / HTML delivery preserves existing configurations. HTML offers formatting controls, isolated placeholder layout preview and an optional plain alternative generated from the rendered HTML when empty. Reusable template application chooses the corresponding format. Existing inclusion policy and HTML escaping remain effective.

Native browser verification uses existing form 4676: selecting HTML, inserting the Email field and full summary, viewing the sandboxed preview, saving and reloading retain the selected format and body. Local screenshot: tests/artifacts/email-composer-1.0.4.png (ignored). The preview is a layout preview with placeholders, not a sample or live submission.

PHP/Joomla: 299 tests pass; JavaScript: 171 tests pass. Tests cover eligible field filtering, cursor insertion, legacy format inference, explicit plain-only sending despite stored HTML, automatic plain alternative and actual Joomla multipart MIME preparation for both action types. Native installation/navigation and deterministic nested archive audits pass. All forty product acceptance steps pass against the exact 1.0.4 package, now including an HTML notification and a plain autoresponse. Mail is captured locally; no external mail delivery is claimed. SHA-256: `eae36b57e0e5bb724115f4e3e09d45c2f3155315c7e12cf05849a1268f57c51a`.
