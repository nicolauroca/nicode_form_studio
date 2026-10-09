# Development guide

The normative documents in this directory define the complete product target.
The 1.0.0 package is generated under `dist`; release evidence and the explicit
user acceptance of accessibility are recorded in `REQUIREMENT_ACCEPTANCE.md`.

Runtime code is under `src/lib_nicode_form_studio/src`, namespace
`Nicode\FormStudio`. Its domain, compiler and validation layers do not depend on
Joomla. Browser modules live under `src/com_nicode_form_studio/media/js`.

Field provider contracts use stable identifiers, versioned metadata and explicit
normalization/validation. ProviderRegistry rejects duplicate registrations and can
be frozen before execution. Unknown providers fail closed. A `FormSpec` is a value
object, not evidence that publication has occurred: only a successful compiler
result may enter the application publication use case.

Compiler diagnostics use JSON paths. Logical references use UUIDs. Decimal inputs
are strings; binary floats are rejected. Current machine names are lowercase
identifiers. Unknown visitor fields never enter the validation result.

See ADR-0005 for canonical serialization. Repositories and selected Joomla adapters
now have database/registry integration tests. Native component/module routes and
the shared submission pipeline pass isolated HTTP tests, including CSRF and
full-page responses. The forty-step integrated product scenario also passes on
the installed stable package. The user accepted accessibility and waived the
outstanding manual screen-reader check; see `IMPLEMENTATION_STATUS.md`.

Actions claim their persistent run before external work, then record a safe result
code. Successful or uncertain deliveries are not automatically repeated. The
webhook transport accepts configured HTTPS hosts, validates every resolved address,
pins the connection address, and disables redirects and environment proxies.
Secrets are injected through SecretStoreInterface; FormSpec holds references.

File fields ignore raw visitor references. Only receipts supplied through the
trusted validation context may enter canonical values; storage keys remain out of
those values. The HTTP gateway separately requires genuine PHP-upload provenance.

`Infrastructure/Joomla/RuntimeProvider.php` composes request-scoped services using
Joomla DI, its native CAPTCHA registry, mailer factory, user factory and database.
Attempt, request-context, cursor and submission-fingerprint keys use distinct HKDF
purpose labels derived from Joomla's application secret. Rotating that application
secret invalidates outstanding tokens and technical replay fingerprints.

The initial deployment secret adapter resolves an uppercase reference such as
`CRM_TOKEN` from `NICODE_FORMSTUDIO_SECRET_CRM_TOKEN`. It cannot read arbitrary
environment variable names. The actual value never enters FormSpec, public JSON
or component parameters. A different SecretStoreInterface adapter can supply a
deployment-managed vault. Publication checks referenced webhook secrets, configured
Joomla mail and private storage before activation; it does not send test deliveries.

`FormAdministration` combines ACL, optimistic revisions, audit and asset updates.
Joomla asset writes use the transactional adapter documented in ADR-0008. The
bounded form listing filters joined assets before exposing metadata, with a cursor
bound to actor and filters. Draft preview compiles and renders without issuing a
submission token or executing Actions.

Administrator `form.*` endpoints resolve their actor from the native Joomla
identity. Writes accept POST form data containing the native CSRF token and a
`payload` JSON object bounded to 2 MiB and 64 nesting levels. IDs and revisions
are strict non-negative decimal integers (IDs must be positive). A concurrent
edit returns 409; compiler diagnostics return 422 without replacing the active
version. The visual editor consumes provider metadata and keeps unknown provider
configuration while changing layout. A draft save never publishes implicitly.

Full-page redisplay verifies and preserves the existing attempt unless the
pipeline explicitly issued `next_attempt`. This keeps a pending response associated
with its original submission rather than creating a duplicate on retry. An invalid
reused token is never echoed or silently replaced by the rendering service.

The native permission editor requires component `core.admin`, preserves unrelated
group rules and compares both the form revision and a hash of the native asset's
raw rules. This also detects changes made through another Joomla interface.
`Joomla\CMS\Access\Access` computes the effective group permission. All mutations
are audited without storing response values.

`Domain/SpecDiff` aligns identity-bearing collections by UUID and reports order
separately. Comparisons return at most 1,000 changes by default with an explicit
truncation flag. Historical preview resolves a version through its owning form.
The preview iframe allows same-origin access for the trusted parent runtime but
does not allow child scripts, form submission or navigation capabilities; the
parent attaches the same FormInstance class used by the public renderer, whose
preview guard also rejects submission. No submission attempt token is issued.

`SubmissionExplorer` builds its search scope from a native forms/assets join and
`Authorization::submissionCapabilities`; it does not reuse form-management ACL.
Headers contain no canonical payload. Detail reads use the submitted immutable
version, expose only allowed answers and project its historical grouping through
`SubmissionPresentation`. Sensitive values remain hidden by default, including
their stored option labels and file receipts. Reveal uses POST/CSRF and audit.
Native file downloads use a new authorization check on every request and stream
an attachment from private storage with no-store and nosniff headers.

`SubmissionAdministration` locks the owned response before changing its state or
adding a note. State writes compare the previously read state; notes are bounded
plain text with server identity/time. Audit records contain identifiers and state
transitions rather than note bodies. Neither operation changes canonical answers.
The response audit query uses `(form_id, submission_uuid, id)`; actions, notes and
audit history currently show a bounded latest-100 window with a truncation notice.
Field-filter metadata comes only from authorized indexed fields in the published
snapshot. UI query data never defines its own schema or permission scope.

The `version_field_policy` projection is regenerated from hash-verified snapshots
inside publication and in the bounded reindex job. Search checks historical
sensitivity through an indexed scalar lookup independently of value negation;
missing metadata evaluates to no match. See ADR-0009 for the privacy boundary and
the measured query-plan choice. Canonical selected columns are read in batches and
remain masked when an older snapshot classified the field as sensitive.

`SavedSubmissionViews` stores private query intent and column UUIDs for the native
actor. Opening a preset revalidates current form and field authorization. No
response values or ACL grants are cached in it; cursor positions are not saved.
The current SQL order is received-at descending with ID as a stable tie breaker.

## Background jobs and native options

`JobAdministration` is the HTTP boundary. It derives the creator and published
schema, forces one form scope and accepts only export, reindex and approved bulk
operations. Job status omits parameters, lease tokens and artifact paths.
Database-only bulk handlers implement `TransactionalJobHandlerInterface`; their
business writes and checkpoint share one transaction. External file deletion is
queued through internal cleanup jobs. CSV writes remain outside database
transactions, truncate to the last durable byte checkpoint on retry and preserve
the selected search ordering. Queries pin an upper response ID at first execution.

The package now includes `plg_task_nicode_form_studio`. Enable it in Joomla and
create a scheduled task of type **Nicode Form Studio jobs**. Run it through Joomla's
scheduler or `php cli/joomla.php scheduler:run --id=TASK_ID`. The worker uses the
original job creator's current permissions. Its batch and record limits are
validated again at runtime; a 20-second budget is checked between batches.
No hidden schedule is installed. Configured worker invocations enqueue bounded,
deduplicated retention and cleanup jobs. Dashboard, technical logs, audit records
and read-only diagnostics expose their operational state.

Component Options expose private upload/CSV paths, host allowlists and CAPTCHA
policy. Paths must be existing, writable absolute directories outside the web
root. Empty paths disable storage. Coordinate running jobs and move existing
objects before changing paths. Security configuration changes are audited and
system diagnostics report configuration and schema readiness.

Administrative action retries snapshot the eligible failed attempt numbers. The
claim compares that number while holding the response lock, so a retried worker
cannot retry its own newly failed attempt after losing a job checkpoint. Only
providers declaring definite-failure retry support participate. Successful and
unknown outcomes are preserved; a recovered blocking failure can resume later
unstarted actions. Claims reject anonymized responses even if their context was
loaded before anonymization. Native `submission.retry` is POST/CSRF protected;
response detail exposes individual/all-eligible controls and a safe audit event.

The shared confirmation dialog is an in-page modal with an accessible name,
description, initial Cancel focus, Escape cancellation and focus restoration.

The scheduler creates an hourly bounded retention dispatcher. It reads the
original version policy and schedules version-scoped work under the publisher's
current permissions. Finite retention requires its privacy permission at publish
time. Privacy erasure fences expired action leases and protects anonymized status
against late workers.

System diagnostics use component-level `formstudio.logs.view` plus `core.manage`.
They return bounded operational counts and safe status codes without private
paths or exception messages. Unavailable configured CSV storage registers a
retryable placeholder for existing jobs, while rejecting new exports.

Technical logging accepts a closed event vocabulary and UUID/integer references.
It intentionally has no free-text metadata field. Database sink failures cannot
change submission outcomes. Native compiler/administrator errors return a
correlation UUID; action errors use the response reference and job errors use
the job UUID. The global viewer requires component-level logs permission.
Technical events expire after 30 days by default, configurable from 1 to 3650;
the scheduler's transactional cleanup preserves audit records.

The package preflight checks platform prerequisites before installing children.
Joomla SQL manifest selectors require `charset="utf8"`; the MySQL DDL itself
uses utf8mb4. Using utf8mb4 in the selector silently skips clean schema creation.
The component's native installer is self-contained because package uninstall
removes the library first. `installation_state` preserves configuration/schema
and ACL snapshots; reinstall rebuilds stable asset names and updates form IDs
without rewriting response payloads. Previously orphaned forms remain denied.
See ADR-0010. Purge authorization and cleanup are not implemented yet.

FormStudio plugins use the native `formstudio` group and Joomla's
`SubscriberInterface`. Subscribe to `onFormStudioRegisterProviders` and accept
`Nicode\FormStudio\Infrastructure\Joomla\ProviderRegistrationEvent`. Check
`$event->kind` and call `$event->registry->register($provider)`. Supported kinds
are fields, validators, operators, effects, sources, actions, storage, jobs and
search; renderers use `register($fieldTypeId, $renderer)`. Registries are shared
per runtime and freeze after the event, including when a listener fails. Duplicate
IDs are rejected; metadata must be canonical JSON data and versions semantic.
Core renderers are registered only for core field types. A missing custom
renderer blocks publication of forms using that type. The source-only example
under `tests/fixtures/plg_formstudio_providerfixture` is installed exclusively in
the disposable lifecycle/browser sites and never shipped in the product package.
The compiler now derives a canonical `provider_dependencies` map. Stable >=1.0
providers accept same-major upgrades without downgrade; pre-1.0 and prerelease
contracts require equality (build metadata does not affect compatibility).
Runtime render, submission and action dispatch validate the published contract;
historical snapshot reads do not depend on providers still being installed.
Draft comparison omits this derived deployment map; comparing published versions
retains it. Removing a provider reference from a draft removes its obsolete pin.
Runtime lifecycle events use typed `LifecycleEvent` listeners named
`onFormStudioBeforeFormRender`, etc., for the eleven phases in SPEC-18. Metadata
is immutable and excludes answers, request tokens and private paths. Before and
Resolve listeners can veto; After failures are logged as `extension.failed`
without changing completed results. Custom transformations belong in provider
contracts. CSV events describe chunks and can recur when a worker resumes;
listeners must not assume exactly-once external delivery. Custom browser assets
use the provider asset contract described in SPEC-18.

The native `search_provider` option selects a registered search engine for the
explorer, exports and bulk jobs. Providers declare keyset, historical privacy,
high-water and operator capabilities. `SelectedSearch` binds cursors to the
provider/version, scope, query and previous row boundary, checks ordering and
strips undeclared result columns. Jobs pin the provider/version. Missing engines
fail affected searches without blocking retention/cleanup handler registration.
Providers remain responsible for authoritative historical sensitive-filter
semantics and their index synchronization; the wrapper cannot infer correctness
of an external engine's index.

Non-embedded sources are queried through the protected native `form.options`
POST endpoint. The browser sees only resolved values/labels/enabled flags, never
provider configuration. Responses are bound to the published version and signed
form instance. Changing parents cancels obsolete requests and rejects stale
responses; failures block submission until an explicit retry succeeds.
Data source `dependency_parameters` metadata lists configuration properties that
contain field UUIDs; the compiler requires them in the declared dependency graph.
Source output and cached entries are validated before use, including boolean
flags and UTF-8 text. Cache keys include the requested TTL as well as provider
version, configuration, inputs and declared trusted context.

The native category/article sources use fixed queries against Joomla content,
an explicit approved category scope and visitor access/language checks. They
also check ancestor visibility and article dates, and do not share cached results.
The builder's category catalogue is paginated and requires form editing/manage
permissions; selected category titles are resolved with the same access checks.

`DefinitionRemapper` uses provider `reference_paths` and `template_paths` JSON
pointers (including `*` members), plus source `dependency_parameters`, to
duplicate identities without rewriting literal business values. Providers can
implement `PortableProviderInterface` to return credential-free configuration;
central export redaction still applies. Without that contract, opaque custom
configuration is omitted with a review note. Sensitive defaults and known
sensitive condition/assignment literals are removed; affected conditional options
are disabled rather than made unconditional.

`FormExchange` runs behind native administrator `definition.export`,
`definition.preview` and `definition.import` endpoints. Preview and import are
POST/CSRF protected. Import binds the canonical analysis, actor and choices to a
15-minute HMAC token, repeats checks in a transaction and saves a draft. Updates
retain the destination alias, permissions and published pointer. Option Set
bindings carry a `resource_hash` so reference-aware imports can reject mismatched
destination resources; portable exports embed options and remove resource pins.

### Initial values

`Field/Prefill` validates declarative initial-value sources. `RequestContext`
contains separate approved user properties, bounded query values and trusted
extension-supplied `prefillContext`; adapters must never construct the latter from
untrusted POST input. `ruleContext()` forwards this information to the runtime.
Editable prefill runs only on initial display. Read-only field copies join the
dependency graph and recompute from active values on both client and server;
other read-only sources are resolved on the server. Public specifications omit
private source configuration and user context. Query prefill is never allowed as
read-only authority. The inspector exposes the source and its explicit key or
reference, while providers continue to declare whether they support prefill.

### Reusable templates

Reusable data sources use `Application/DataSources` and the native `datasource.*`
endpoints. Capture exports a saved field through the portable-definition boundary,
removes credentials, checks provider configuration and requires every referenced
input to be declared. Dependencies become named parameters with datatype and
cardinality; application requires an explicit compatible destination for each.
`DefinitionRemapper::source()` remaps declared references without changing literal
option values. UUID/revision/hash provenance accompanies the copied configuration.
The mutable resource is consulted only during application; disabling it prevents
new applications but preserves existing drafts and published versions. Resource
updates use optimistic revisions and native resource permissions. The builder
offers capture and application, and the resource view edits its name and enabled
state. Configuration changes are made through a form's provider editor and captured
again, preserving one provider authoring contract.

Reusable resources are managed by `Application/Templates` and native `template.*`
endpoints. Email templates declare named `input.<name>.<part>` parameters; binding
requires exactly those names and eligible fields in a saved draft. Text and
UUID/revision/hash provenance are copied into the action or its language
catalogue. Runtime never consults the mutable template row. Form templates store
a portable definition package, use the existing signed import review and always
create an independent draft with remapped identities. Resource editing requires
`formstudio.resources.manage`; applying a resource also checks form permissions.
The resource tables retain the current revision; immutable FormVersions preserve
the copies used by historical submissions.

### Versioned translations

`Translation/DefinitionTranslations` validates a presentation-only catalogue and
derives an ephemeral localized definition. Never use that projection's hash for
storage ownership: `SubmissionPipeline` retains the original FormSpec for
persisting and executing actions. `ActionContext::definition()` localizes email
configuration and labels using the stored response locale. Consent text and
selected option labels are captured at ingress; historical readers use the
original version and locale. Translation maps and their field token references
are remapped by duplication/import. All localized variants pass the compiler's
normal configuration and token validation. The native editor saves translations
inside the draft and previews a selected locale without publishing it.
