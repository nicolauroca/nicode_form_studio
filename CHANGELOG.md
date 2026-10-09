# Changelog

## 1.0.4 - 2026-09-28

- Add guided email composition to notifications and autoresponses: insert individual fields, labels or all answers at the cursor.
- Offer explicit plain text / HTML delivery, formatting controls and an isolated HTML preview. Preserve saved templates and generate a plain text alternative when HTML has none.


## 1.0.3 - 2026-09-28

- Fix incomplete editor initialization after upgrading: Joomla import maps version every JavaScript module by content, including transitive imports.
- Initialize saved translations on page load and retain refreshes when reopening tabs. Verify saved fields, properties and all editor panels.

## 1.0.2 — 2026-09-28

- Widen the native Go to button and replace the form editor's stacked configuration disclosures with keyboard-accessible top tabs.
- Automatically activate and reveal Preview and Versions when requested from the toolbar or tab strip, with compact preview controls.
- Preserve draft controls across tab changes and reveal the correct tab before focusing invalid fields or compiler diagnostics.

## 1.0.1 — 2026-09-28

- Replace the development installation text with a translated completion panel: version, setup steps, package contents, system diagnostics, documentation, source repository and issue links.
- Add Joomla administrator submenus and native toolbars across all sixteen component views, with permission-aware actions, contextual Back, section navigation, Options and Help.
- Move builder actions into the native toolbar, retain JSON/CSRF/concurrency handling and unsaved-change protection, and connect resource saves and form creation to native buttons.
- Correct stable extension descriptions and web asset version metadata. Preserve the published 1.0.0 archive.

## 1.0.0 — 2026-09-28

- Initial stable package. Accessibility accepted explicitly by the user; the outstanding manual screen-reader check was waived without claiming it was executed.

- Add an administration guide, a gated stable-package builder and the integrated forty-step native Joomla acceptance scenario.

- Add response, UTC-period, form and full-index reconstruction controls, bounded resumable cursors and native permission checks.
- Backfill newly indexed historical fields without changing payloads or snapshots; retain historical privacy, exclude pending answer filters and handle publication changes and late submissions.

- Add explicit package purge preparation, public/write admission blocking, resumable owned-file cleanup, native uninstall guards and empty reinstall verification on three database engines.
- Queue failed staged-upload cleanup in the durable outbox and reject linked local objects during cleanup.

- Add confirmed permanent form deletion with bounded resumable jobs, admission fences, transactional native asset removal and durable owned-file cleanup.

- Validate the shared database integration suite and native Joomla install/update/preserve lifecycle on isolated PostgreSQL 14.24, MySQL 8.4.8 and MariaDB 11.4.5.
- Isolate module rendering failures, return safe correlated component errors and enforce POST-only submission with HTTP 405.
- Keep read-only choices, toggles, ranges, colours and uploads disabled through browser rule refreshes; preserve explicit trusted prefill overrides in the public projection.
- Align the PostgreSQL installer minimum with the accepted ADR-0006 requirement of version 14.
- Add a separate permission-protected audit viewer with stable-reference filters, inclusive UTC dates and keyset pagination.
- Expose recoverable archive/trash transitions in the editor with native permission gates, confirmation and optimistic concurrency.
- Show saved-draft field counts and permission-scoped response counts in the paginated form list using grouped indexed queries.
- Add reusable data-source capture, typed named bindings, optimistic revisions and disabling, with portable credential redaction and independent copies in drafts and published versions.
- Resolve Joomla menu destinations through the site menu factory when actions run in console workers.

- Add declarative initial values from approved Joomla user properties, named URL parameters, authorized context, compatible fields and selection-source defaults.
- Recompute read-only values on the server, check prefill dependency cycles and preserve visitor edits to editable fields.

- Add provider-schema-based cross-field validation editing with ordered field references, cardinality controls and repairable missing references.
- Verify confirmation failures and corrected submissions through native HTTP and the browser.

- Add reusable email templates with explicit field bindings, language-specific application and revision/hash provenance copied into drafts.
- Add portable form-template capture and reviewed creation of independent drafts, guarded by native resource/form permissions and optimistic concurrency.

- Add versioned dynamic translations, per-property language fallback, native draft translation editing and language-specific previews.
- Preserve original version hashes and the response locale for consent evidence, historical labels and email retries; validate translated configurations before publication.
- Bundle component language folders so previews and modules can load Spanish strings before the corresponding Joomla language pack is installed.

- Connect the configured native search provider to the explorer, filtered exports and bulk jobs, with capability metadata, provider-bound cursors and job version pins.
- Reject out-of-scope or unordered provider results and preserve unrelated cleanup/retention when the selected engine is unavailable.

- Add eleven typed Joomla lifecycle events with immutable metadata, pre-operation vetoes and isolated observer failures after completed operations.
- Verify installed plugin lifecycle delivery, persistence replay suppression and definite action failures before provider execution.

- Add transactional form duplication with remapped graph identities, preserved native ACL and visibility, and a separate draft.
- Add portable/reference-aware definition JSON export, credential redaction, signed import previews, UUID collision policies and draft-only imports.
- Verify reference-aware Option Set revisions against destination hashes and require explicit review of removed configuration and Joomla bindings.

- Add CSRF-protected dynamic option resolution with per-instance loading, stale-response rejection, accessible retry and authoritative submit validation.
- Add approved Joomla category/article sources with access, language, ancestor and publication-window checks.
- Integrate Joomla source caching with context/version/TTL isolation, exact expiry and request-local outage fallback.

- Add native versioned option resources, parameter binding in the form builder and immutable published option snapshots.
- Apply ordered option defaults on initial rendering, preserving explicit prefill and server revalidation of submitted values.
- Add typed Joomla plugin registration events and freeze initialized provider and renderer registries.
- Pin referenced provider versions in canonical FormSpecs and fail closed on missing or incompatible runtime dependencies, while preserving historical reads.

- Add audited individual/all-failed action retry jobs with expected-attempt fencing and revalidation after anonymization.
- Verify native retry UI through an accessible in-page confirmation dialog and preserve successful/uncertain action outcomes.
- Queue deduplicated hourly CSV cleanup from the configured Joomla scheduler worker and verify physical expiry cleanup.

- Add filtered CSV exports and confirmed bulk response operations to the native administrator, with private job status, cancellation and CSRF-protected CSV downloads.
- Commit database-only bulk mutations and job checkpoints atomically; expired workers roll back both.
- Add an installable Joomla task plugin and verify bounded export resume across native scheduler CLI processes.
- Add component options for private storage, destination policies and CAPTCHA, with native validation rejecting public or unavailable storage directories.

- Add private saved searches and bounded canonical answer columns with historical sensitivity checks.
- Add ADR-0009 and a regenerable per-version field-policy projection to prevent inference of sensitive historical answers through filters.
- Rebuild historical policies during publication/reindexing and repeat the million-response SQL benchmark after query-plan tuning.
- Verify native installed multipart uploads and exact private-file downloads with CSRF, ownership, safe headers and auditing.

- Add native response explorer with trusted Joomla ACL scope, typed field filters, keyset navigation and historical answer layouts.
- Add explicit audited sensitive reveal, private download route, optimistic response states and internal notes.
- Keep response-reader permissions independent of form editing, bound audit history reads and improve selector accessibility.

- Add immutable canonical FormSpec values, compiler diagnostics and dependency graph.
- Add field provider registry, scalar normalization and exact decimal validation.
- Add server and browser condition/effect evaluation with shared operator fixtures.
- Add server extraction, inactive-field handling and local selection validation.
- Add malformed-definition regressions and reproducible documentation aggregation.
- Add relational authoring, immutable publication, canonical submissions and typed search projection.
- Add leased jobs, resumable reindexing and database integration tests on isolated MariaDB.
- Add private upload storage, trusted file receipts, CAPTCHA adapter and public access/attempt policies.
- Add ordered action claims, selective retries, mail templates, webhook transport and post-submit behavior.
- Add shared HTML rendering and per-instance browser interaction; Joomla UI integration remains in progress.
- Add shared published-form display, session/channel-bound attempts and native Joomla request decoding.
- Add audited form administration, publication CAPTCHA checks and transactional Joomla ACL assets.
- Add privacy erasure, private downloads, resumable CSV export, retention and cleanup handlers.
- Add full/metadata/no-store pipeline checks, real multipart upload tests and the local million-response SQL benchmark.
- Tighten browser projections and verify independent instances and accessible error summaries.
- Add native Administrator form listing, visual field/layout editing, guarded mutation endpoints, publication diagnostics, history and draft preview.
- Verify native Administrator HTTP authentication, CSRF, optimistic conflicts and failed-publication isolation.
- Preserve verified attempt identity during full-page retries and field edits during immediate saves.
- Add visual condition groups/effects and provider-driven action configuration, with browser verification of conditional validation and internal redirects.
- Add privacy, CAPTCHA/rate-limit, confirmation-message and live publication-setting controls; verify persistence after reload.
- Add audited native group permissions with external-edit conflict detection, stable-UUID version comparison and interactive/historical previews without submission.

An explicitly marked development package can be installed for isolated integration.
- Add native menu metadata and a common ACL-scoped published-form selector for menus and modules; verify Itemid rendering and independent module instances.
- Correct the Joomla SQL charset selector and verify clean creation of all 29 tables without the compatibility plugin.
- Add native prerequisite checks and preserve-mode reinstall of configuration and ACL rules, including existing orphan isolation.
- Add separate typed technical logging, correlated native viewer and configurable scheduled expiry without deleting audit records.
- Add guarded native system diagnostics and isolate unavailable CSV storage from other job services.
- Schedule bounded retention using each submission's original policy and publisher permissions; fence expired action workers before privacy erasure.
No product release has been produced; administrative and release acceptance remain incomplete.

- Added bounded, atomic form selection actions with explicit review, per-form ACL,
  optimistic revisions and complete rollback on compiler or concurrency failure.
- Added independent keyset navigation for response actions, notes and audit;
  restricted detail audit metadata to the global viewer's safe projection.
- Displayed recorded consent text, decision, timestamp and historical version,
  including explicit audited reveal for sensitive consent fields.
- Moved source-cache cleanup before purge DDL so a cache failure remains retryable.

- Added declared ascending/descending response date and ID ordering, stable tied
  cursors, private preset persistence and matching export/bulk job order.
- Added the form/ID index and a million-response ordering benchmark with plans.

### Administrative response states

- Added the normative viewed, processed and error states, preserving reviewed and
  existing stored responses. State remains independent of action delivery status.
- Exposed safe from/to transition details in the audit viewer.
- Added 49-transition SQL acceptance and native state-filter/API verification.

- Deny form view levels to blocked authenticated Joomla identities, including
  existing sessions. Native regression covers blocked rendering/submission,
  unblocking recovery and live group-membership revocation.

- Add a global full/metadata/none storage default for new forms, captured in the
  initial draft without changing existing form policies or published history.

- Add hourly internal attempt cleanup: expire technical replay receipts and erase
  abandoned no-storage responses after verifying their historical policy, while
  preserving active actions and full/metadata responses.

- Compare attempt expiry in SQL at exact timestamp boundaries; align no-store
  completion and cleanup lock order to avoid parent/response lock inversion.
