# Testing guide

Responsive layout (2026-09-27): fieldsets now participate in the same 12-track
grid as other containers, while keeping native legend semantics. Step descriptions
span the full row; untitled layout containers no longer emit empty headings.
`http-admin-layout.php` publishes nested section/row/columns/fieldset with three
fields, verifies matching preview/public classes and legend, and rejects empty
headings in the rendered result. A test originally assumed CSS class order;
it now checks class membership, compatible with canonical serialization.
Native form 1584 was visually checked at 1280×850 (three columns), 800×850
(two columns with City on the next row) and 390×850 (single stacked column).
Evidence: `joomla-layout-desktop.png`, `joomla-layout-tablet.png`,
`joomla-layout-mobile.png`. The browser viewport override was reset afterward.
PHP: 215 passing tests; complete native Administrator HTTP suite passed.
Installed package SHA-256:
`fce04040ca65fa7e43f995b1b284d9f713585e1b9876310e224e1cc9bcd415c4`.

Builder branch duplication (2026-09-27): the inspector duplicates a selected
element and its descendants using the server's provider-aware DefinitionRemapper.
Copies receive new local identities and unique machine names; internal references
and translations follow the copy, external references and literal values remain.
Only rule effects targeting the copied branch are duplicated; global actions,
validators and post-submit policy remain unchanged. The operation saves a draft
through edit ACL, CSRF/POST and optimistic revision checks. PHP: 215 passing;
JavaScript: 121 passing. All three database suites and the complete native HTTP
suite pass. Native editor form 1470 duplicated Answer stage with Final answer and
Initial answer, selected the new step and displayed `final_answer_copy` for its
new field. Evidence: `joomla-builder-branch-copy.png`. Installed package SHA-256:
`25406cc914e0243b1feef40bfa03306cf54d65fb3f4c93356175a43c87a77fb1`.

Builder branch expansion (2026-09-27): branches with children have localized
keyboard buttons with `aria-expanded` and `aria-controls`. Expansion is local UI
state; redraws retain it without changing the draft. Selecting or moving a nested
element reveals its ancestors. The model test covers nested ancestors, unrelated
collapsed branches, immutable draft data, missing selection and malformed cycles.
PHP: 214 passing; JavaScript: 121 passing. Native editor form 1470 confirms Enter
collapses Answer stage, hides Final answer and leaves the dirty status empty.
Selecting Initial answer preserves the closed branch. Keyboard parent changes
into that branch expand it and show both fields. The resulting edit is saved.
Evidence: `joomla-builder-collapsed.png`, `joomla-builder-revealed.png`.
Installed development package SHA-256:
`68210940833131d26eb00d882bf782111406a2a1a8df189715569e08ff8a7687`.

Drag root correction (2026-09-27): destinations now apply the same validated
acceptance and marker in `dragenter` and `dragover`. The previously reproducible
nested-field-to-root gesture succeeds in the installed editor: Initial answer
moves out of Answer stage, retains its UUID/machine name and remains at Top level
after save/reload. Evidence: `joomla-builder-drag-root.png`. The regression asserts
that entering the root destination is accepted; all 120 JavaScript tests pass.
Together with before/after/inside, rejection and native keyboard cases, this
closes the scoped LAYOUT-005 audit. Installed development package SHA-256:
`7d41a7b9adce7e09d7dae4bb123f295fb2b767e9c84929b9235815f7d2eabfc8`.

Drag/keyboard follow-up (2026-09-27): native pointer before/after reordering and
cycle rejection passed; root-to-root append passed. Keyboard selection, parent
change to root, Move up/down and save passed on form 1470, preserving the field
UUID and machine name (`joomla-builder-keyboard-root.png`). Attempts to drag a
nested field to root did not apply in this browser session. No JavaScript error
was observed; the cause is not established. LAYOUT-005 remains partial pending
that native case and remaining accessibility acceptance. Added adapter tests
for root removal from a group and geometric before/after/inside routing; all
120 JavaScript tests pass. This turn changes tests/evidence only; installed
development package remains `f4d192b8b1a66c27c7b3fae17ed5b4dcccf6867f8dfefdc6659a7b48f278e30e`.

Builder drag/drop (2026-09-27): `builder-drag.js` adds delegated native tree drag
handling, destination markers and a root destination. The model supports before,
after and inside placement while preserving identities, descendant ownership,
provider configuration and rule references. It rejects cycles, nested steps and
invalid destinations before mutation; external payloads cannot select a source.
PHP: 214 passing tests; JavaScript: 119 passing. Development package installed:
`f4d192b8b1a66c27c7b3fae17ed5b4dcccf6867f8dfefdc6659a7b48f278e30e`.
Native pointer drag on editor form 1470 moved Initial answer from Contact details
into the middle Answer stage after Middle answer. Focus followed the moved row.
Save/reload retained the tree location and Container selection, with the same
UUID and machine name; `joomla-builder-drag-persisted.png` records the result.
All destination variants and full keyboard acceptance remain open under LAYOUT-005.

Step structure (2026-09-27): the compiler now rejects a step below another step,
including through an intermediate container, with `layout.step.nested` at the
original parent path. This prevents entering a page whose parent page is hidden
by the sequential navigator. General grouping remains supported. The builder
filters invalid parents for steps and subtrees containing steps, rejects invalid
mutations without changing the draft, and inserts a new step outside the selected
step. `unit/step-structure.php` and `js/builder.test.mjs` cover both boundaries,
indirect nesting, valid grouping and malformed ancestor cycle termination.
PHP: 214 passing tests; JavaScript: 117 passing. The full native HTTP suite passes,
including rejection of a nested saved draft and publication after correction.
Native editor form 1470 verifies selection of Contact details, exclusion of other
steps from its parent choices, insertion of sibling Review and successful save;
see `joomla-step-sibling-insertion.png`. Installed package SHA-256:
`402fa29c80da7b429a87489b4fc01669e4a60d5a50505bba25001bd08d84365e`.

Step descriptions (2026-09-27): the native inspector now edits the previously
missing SPEC-05 description. The compiler rejects non-string descriptions;
the translation catalogue and editor expose them; the shared renderer escapes
plain text and associates it using an instance-specific `aria-describedby` ID.
`unit/step-description.php` covers malformed values, localization without changing
the original snapshot, markup escaping, distinct instance IDs and empty omission.
PHP: 213 passing tests; JavaScript: 116 passing; PHP lint and the complete native
Administrator HTTP suite pass. The HTTP multipage fixture verifies all three
rendered descriptions and their references. Native browser form 1470 verifies
editing title/description, saved-draft preview, publication and identical public
text, including literal `<No HTML>`. Evidence: `joomla-step-description-editor.png`
and `joomla-step-description-published.png`. Installed development package SHA-256:
`2eca94f5ff985ab2762ff89b6d80b46acdb6e70676c302af3c0773341d4ef18e`.

Native multipage fallback/reset acceptance (2026-09-27): the complete
`tests/http-admin.php` suite passed and now publishes a separate fixture through
`http-admin-conditional-steps.php`. Browser verification on form 1440 used three
persistent visibility checkboxes outside the steps. Hiding the current middle
step selected the final step (2 / 2); hiding that final step selected the earlier
initial step with its typed answer intact. The triggering checkbox retained
focus. Restoring the final step and hiding the initial step left only the final
answer visible. Submission succeeded with reference
`37120687-0f2a-4290-9cba-069cbf71ed49`. The reset unchecked all visibility controls,
restored step 1 / 3, cleared the initial/middle answers and retained the final
answer explicitly listed in `post_submit.preserve`. Keyboard Next focused each
step container and reached the retained final answer at 3 / 3. Screenshots:
`joomla-step-fallback-next.png`, `joomla-step-fallback-previous.png`,
`joomla-step-reset-first.png`, `joomla-step-reset-preserved.png`.
This verifies the installed runtime without changing its package; complete step
authoring and broader accessibility acceptance remain open under LAYOUT-004.

Core `change_options` now rejects exact duplicate values and nonboolean `enabled`
or `default` flags before publication. Diagnostics identify the exact option/flag.
`compiler-rule-options.php` covers ten malformed flags, duplicates and preservation
of case/whitespace/numeric-looking identities with valid true/false flags. PHP
totals are 212 passing tests; shared suites pass on all three database engines.
Installed development package SHA-256:
`678bda60fe96d1113cea5513be49efbe0fcf8b928b97892bbd9a9c6a3676e43d`.

Selection defaults/constant prefills are now checked for possible membership before
publication. `Compiler/SelectionDefaults.php` uses enabled local or static-snapshot
values plus options from enabled core rules; it defers custom sources/effects and
never calls a dynamic provider. Exact identity (including spaces/case), disabled
choices, both scalar/multiple initial-value paths, rule alternatives and dynamic
deferral are covered by `compiler-selection-defaults.php`. Contextual membership
is still enforced at submission. PHP totals are 211 passing tests and all three
database suites pass. Installed development package SHA-256:
`dee5060ce301922edf72d749ca195df824c740f96a6e6bd6fbb8c3f2fe1119e8`.

Option-source assignment now checks the destination field's logical datatype at
compilation. Text, numeric, temporal and boolean fields cannot silently accept
unused option sources; `field.source.unsupported` points to the field's source.
Core choices and a registered custom `selection` provider remain supported.
`compiler-resources.php` exercises ten target types. PHP totals are 209 passing
tests, and all three database suites pass. Updated development package SHA-256:
`dc51b6826eaa11f46874d4bf03a9a4173d92e736dbdcdc8e4356a4c463badfd3`.

Compiler defaults and constant prefills now use the submission pipeline's portable
index limits when the field is both persistent and indexed. This rejects keyword
overflow (including Unicode code points), DECIMAL(38,12) overflow and pre-1000
date/datetime initial values before publication. Nonindexed or nonpersistent values
retain their existing range. `compiler-default-index.php` checks both initial-value
paths, invalid and boundary values, exact diagnostic paths and exemptions for five
cases. PHP totals are 208 passing tests; the three database suites also pass.
The rebuilt package installed on the primary Joomla fixture has SHA-256
`d89d78a8739f2512b22d73d7d8d199f4a19bd9e6508ebc77aea0e66f9e2eb307`.
Native HTTP rejects both oversized initial-value paths while the same public URL
continues rendering the previous version, then publishes and renders the corrected
255-character constant.

`tests/unit/compiler-resources.php` adds compiler acceptance for copied source
provenance and pinned OptionSet snapshots: twelve invalid revision/hash/identity,
option and dependency cases reject, while valid copies compile without a mutable
resource lookup and preserve the original compiled JSON across authoring edits.
The existing three-engine OptionSet and source-resource suites separately verify
stored hash integrity, ACL, typed binding, revision conflicts, disabling and copy
isolation. The PHP/Joomla unit suite passes 207 tests.

`tests/unit/compiler-actions.php` exercises registered email notification,
autoresponse and webhook providers through FormCompiler. Invalid recipient
references, header injection, unsafe tokens, copied-template revision, destination,
method, timeout, map and secret-reference configurations cannot compile. Valid
actions compile; sensitive field tokens require explicit inclusion and excluded
field tokens remain unavailable to webhook payloads. The synthetic mail/HTTP/DNS
and secret-value adapters throw if compilation attempts external work, separating
this contract test from deployment readiness and runtime delivery. The complete
PHP/Joomla unit suite passes 206 tests.

Compiler post-submit acceptance now includes 22 rejecting configurations and six
valid configurations in `tests/unit/compiler-post-submit.php`: behavior, reference
visibility, preserved UUIDs, plain messages, safe tokens, conditional field/UUID
references, legacy conditionals and redirect menu/URL policy. Rejections carry
diagnostics at the relevant post-submit/action path, and missing-menu exception
details are not exposed. This is publication-contract coverage, not outbound
delivery or a complete post-submit UX audit. The PHP/Joomla unit suite passes
204 tests with zero failures.

Browser deployment readiness now has transactional integration coverage in
`tests/database-publication-readiness.php`. A custom browser field declares a
script and stylesheet, with separately controlled asset resolution. Each missing
asset returns `publication.browser` at `/provider_dependencies` without exposing
the resolver's private exception. Exact form, draft, version, field-policy and
audit rows are unchanged after both failures. Restoring the assets activates one
new version and audit event while preserving the original snapshot hash. Shared
database suites pass on MariaDB 11.4.5, MySQL 8.4.8 and PostgreSQL 14.24. The asset
resolver is a controlled integration fixture; actual browser module loading is
covered separately by the installed provider/browser acceptance tests.

`tests/http-admin-publication-activation.php` verifies a full native draft/active
version transition using the exact same public URL and visitor session, with no
cache-busting query. Saving changed labels/defaults retains the original public
version; publishing exposes the replacement label/default/version on the next
request. A subsequent submission stores the replacement version ID, and the old
snapshot's exact JSON and hash remain unchanged. Labels are selected through the
field input's `for` association, excluding the unrelated honeypot label.

Native conditional-step identity acceptance is now recorded on form 1231 with
package `a0949945a10611b1ab03a5aad7ea1019573905e8c40739c984abb008f052a82d`.
From step two, toggling Hide initial step changes progress from 2/3 to 1/2 and
back without leaving the checkbox or losing focus. Previous restores the first
step with its exact entered answer. Advancing with the first step inactive reaches
the final step and submits successfully (reference
`31241ca8-4a01-4a53-9014-bedc25a11bec`). Evidence is in
`joomla-conditional-step-identity.png`, `joomla-conditional-step-answer-retained.png`
and `joomla-conditional-step-submitted.png` under `tests/artifacts`.
`tests/http-admin-conditional-steps.php` adds three-step publication/rendering and
direct POST verification that forged input for the inactive step is omitted while
the active values persist exactly. The full administrator HTTP suite passes.

Conditional step refresh now retains the current DOM step identity when earlier
steps disappear or return. If the current step becomes inactive, it selects the
next active step in document order, falling back to the last earlier step. Success
reset clears that identity so newly active earlier steps can become the first
page again. The real `FormInstance.refresh` and `updateSteps` methods are exercised
in `step-validation.test.mjs` for removal, reinsertion, disappearance of the current
and following steps, zero active steps, recovery and reset. All 116 JavaScript
tests pass. The native browser acceptance above supplements this runtime evidence.

Multipage navigation now defers a relational validator when its declared
`config.fields` includes a field in a later active step. Same-step and earlier-step
comparisons remain enforceable; final submission evaluates all relations.
`tests/js/step-validation.test.mjs` exercises the real `validateValues` method for
boolean confirmation, heterogeneous-step presence counts, correction, same-step
errors and inactive counterparts. JavaScript totals are 115 passing tests.
The full native HTTP suite passes on package SHA-256
`818eec71d3f3389536cd3c1bdc0c926309ae0d75b1c07dcf5e9874f5e74e5797`.
Native browser form 1203 advances from checked First choice to the second step,
rejects the unchecked Confirmed choice on Submit, and accepts after checking it
(reference `0d99bd51-b1e7-4fd3-b709-faf00cc5aafd`). Evidence:
`tests/artifacts/joomla-step-confirmation-rejected.png` and
`tests/artifacts/joomla-step-confirmation-submitted.png`.

Boolean cross-field equality/confirmation no longer skips false operands. Presence
counts still exclude false, while numeric zero counts as present. The shared
`tests/fixtures/relations.json` corpus covers 31 cases across all twelve core
relational validators in PHP and JavaScript, including exact large numbers,
temporal equivalence, ordered selection equality and inactive/empty counterparts.
Totals are 202 PHP and 112 JavaScript tests, all passing. The full native HTTP
suite passes with `http-admin-boolean-comparison.php`: both true/false mismatches
return 422 with errors on both fields and zero persisted responses; correction
to false/false succeeds and both canonical values remain JSON booleans.
The installed development package for this verification has SHA-256
`bcee28ae1e8afc3fefc66fbc118b7dde475649e51b49bd093d24a1d06bd108c2`.

Core relational validators now reject incompatible field datatypes/multiplicity
at compilation, including mixed numeric/text confirmation, temporal type mismatch,
ordered arrays and file comparisons. Numeric integer/decimal pairs and same-type
temporal ranges remain valid; heterogeneous presence counts are supported.
`tests/unit/compiler-validator-types.php` exercises 16 combinations at both form
and field scope and verifies that custom validators using a core identifier keep
their own contract. PHP totals are 201 passing tests; JavaScript totals are 111.
Native HTTP verifies rejection with `validator.compatibility` at the owning field,
preservation of the active version and successful publication after correcting
the type. This is compiler acceptance evidence, not a claim that FORM-008 or the
full extension is complete.

Field-scoped validator reference diagnostics now retain their owning path instead
of acquiring an index in the merged form-validator list. A regression combines
form and field validators and requires `/fields/1/validators/0`; correcting the
reference restores successful compilation. PHP totals are 199 passing tests.
Native HTTP rejects the corresponding fixture without replacing its published
version, and native browser Show element opens the field validator and focuses
Field 1 inside `/fields/0/validators/0`. Evidence is
`tests/artifacts/joomla-field-validator-diagnostic-focus.png`; the full native
administrator HTTP suite passes. This supplies the previously missing native
field-scoped validator navigation evidence.

Compiler path audit found four semantic diagnostics using UUIDs in list positions:
missing layout parents, orphan field elements, prefill validation and missing source
dependencies. They now use the actual fields/elements list index. The regression
test `tests/unit/compiler-paths.php` resolves each emitted path against its malformed
draft and verifies that it identifies the affected node; PHP totals are 198 tests,
zero failures. This fixes navigation metadata without changing domain UUIDs.

Diagnostics display translated severity text (Error/Warning/Information), code,
message and path. The administrator error status uses the theme text color with
a red border instead of low-contrast dark red text. Native evidence
`joomla-diagnostic-severity.png` verifies an actual publication error.

Core compiler advisories now include an explicitly blank visible label and
explicitly approved indexing of a persistent sensitive field. Both carry WARNING
and preserve successful compilation; missing index approval remains a blocking
error. `tests/unit/compiler-advisories.php` covers codes, paths, severity and hidden
field exclusions. `publishDetailed` returns diagnostics from the exact activated
compilation, preserving the existing integer-returning publish service contract.
Database suites on all three engines verify nonblocking activation. Native HTTP
`tests/http-admin-advisories.php` verifies both warnings in preview and publication
and checks the active version identity. Browser acceptance confirms that an empty
label warning survives publication, and correcting it removes the warning;
`joomla-advisory-corrected-publication.png` records the corrected result. PHP 195,
JavaScript 110, all three database suites, native administrator HTTP and PHP lint
pass. This addresses the previously recorded advisory gap; FORM-008 remains
partial pending its full compiler and editor acceptance audit.

Advisory edge cases now pass with PHP warnings promoted to exceptions: null,
boolean, numeric and nested label values remain blocking schema diagnostics.
A Spanish-only blank translation produces the locale-qualified warning path
`/translations/es-ES/fields/0/config/label`, leaves the base Answer label intact,
and disappears when corrected. PHP totals are 197 tests, zero failures. The
diagnostic path identifies the locale. Navigation now opens that existing locale,
maps indexed fields/elements/actions to translation UUIDs and focuses the relevant
control; unresolved paths focus the locale input without modifying the draft.
Native acceptance starts on French with the panel closed, locates the Spanish
blank-label diagnostic, and verifies es-ES plus the exact field UUID/label focus.
Evidence: `joomla-diagnostic-translation-focus.png`. Correcting to Respuesta removes
the warning on preview. JavaScript tests total 111 with no failures.

The native dark-theme review found white Joomla labels over the condition
editor's fixed pale background. `.nfs-condition` now uses Joomla's paired
`--body-bg` and `--body-color` variables, with a light fallback. Native computed
styles show white labels over `rgb(15, 21, 29)`; the panel is visually readable in
`tests/artifacts/joomla-condition-dark-contrast.png`. This is evidence for this
specific dark-theme regression, not a complete accessibility or theme audit.

Diagnostic navigation now resolves rules, actions and form/field validators as
well as elements. It opens the associated panel and focuses a control after the
editor redraw; changes clear stale diagnostics. `tests/js/diagnostic-target.test.mjs`
checks exact path boundaries, missing targets, field-validator precedence and
toggle/focus ordering. All 110 JavaScript tests pass. Native keyboard acceptance
on the installed editor rejects an incomplete webhook, opens its action card via
Show element, focuses Enabled, and clears the diagnostic after removing the
action. Evidence: `tests/artifacts/joomla-diagnostic-action-focus.png`.
Native form-validator acceptance also rejects an absent comparison reference,
opens the closed panel and focuses Field 1 (`joomla-diagnostic-validator-focus.png`).
An invalid rule pattern produces `/rules/0/when`; locating it revealed an
intermittent focus race. Focus now runs on the next animation frame after toggle
redraw, and a fresh native reload confirms focus inside `/rules/0`
(`joomla-diagnostic-rule-focus.png`). Removing either item clears its diagnostic.
Field-scoped validator routing remains unit-tested, without separate native proof.

`tests/database-publication-readiness.php`, included in the three database suites,
verifies a failed deployment prerequisite through the actual publication service.
A missing renderer yields a field-specific diagnostic and leaves the full form,
draft, immutable versions, field-access policies and audit rows byte-for-byte
unchanged. Restoring the renderer activates exactly one new version without
changing the previous snapshot; a stale repeat leaves the entire accepted state
unchanged. MariaDB 11.4, MySQL 8.4 and PostgreSQL 14 suites pass. This strengthens
FORM-008 transaction evidence; it does not close all compiler/UI acceptance.

Multipart packing now retains files that share a part name with a string from a
custom control. Previously, deleting that string's FormData key also deleted its
files. `tests/js/submission-data.test.mjs` checks exact bytes, filenames and order
for scalar and array part names, unchanged CSRF, and no mutation on ambiguous
scalar/selection rejection. The JavaScript suite passes 108 tests.

`php tests/http-request-limits.php` uses the latest native 1,500-field fixture
from `tests/http-admin.php`, starts temporary PHP web servers on loopback port
13375, and stops each process in `finally`. Real multipart requests preserve DOM
order. With `max_input_vars=1000`, or `max_multipart_body_parts=1000`, the trailing
identity/CSRF envelope is dropped: the request returns 403 and stores nothing.
At 2,000 variables with the derived multipart budget, all 1,500 exact values
persist through the non-JavaScript HTML path. A packed request over a 16 KiB POST
budget also returns 403 without persistence. Results are recorded in
`build/native-request-limits-results.json`. The current rejection is a session
error because PHP discarded the security envelope; its reload advice does not
identify or fix host capacity. Deployment must size the PHP/proxy limits.

AJAX now displays a server refusal's localized message, preserves entered values
and the attempt, focuses the outcome and releases the pending state. It applies
reset/hide/redirect/next-attempt only to an explicitly accepted response. Network
or unreadable responses retain the unconfirmed-outcome message. The real submit
method is covered by `tests/js/submission-outcome.test.mjs`, including 403/429/422,
literal text rendering and a non-JSON proxy failure.
Native browser evidence `tests/artifacts/joomla-ajax-rate-rejection.png` confirms
the real 429 message, retained answer, focused outcome and re-enabled submit
control on the installed component. The complete native administrator HTTP suite
also passes after installation; JavaScript totals are 106 tests with no failures.

System diagnostics now lists an allowlist of effective PHP request/resource
limits from the web runtime (CLI values can differ). The ACL-protected report
does not expose other INI configuration or paths, and does not treat arbitrary
capacity values as failed health checks. Native health and HTTP administrator
tests verify the allowlist, runtime values, translated table and access denial.
For deployment, test legacy multipart under the configured `max_input_vars`
and multipart budget, and both paths under total POST/upload and proxy limits.
Enhanced JSON packing does not bypass byte, upload-count or infrastructure limits.
The native administrator HTTP suite and PHP lint pass for this change;
`tests/artifacts/joomla-php-request-limits.png` records the installed view.
Directive semantics follow the [PHP core configuration manual](https://www.php.net/manual/en/ini.core.php)
and the [PHP Foundation source audit](https://thephp.foundation/assets/files/24-07-1730-REP-V1.4_temp.pdf)
for the derived multipart-part limit.

The 1,500-field native editor loads all structure nodes, edits the final field,
saves and reloads with all 1,500 nodes and the changed label intact. This exposed
an unbounded structure panel that pushed the inspector far away; the tree now
shares the palette/inspector's 65vh scroll budget. Native visual evidence
`joomla-large-editor-scrolling.png` shows the final field and its adjacent
properties. This is a functional large-editor check, not a rendering-time SLO.

Run `php tests/http-native-files.php --packed` for the enhanced multipart path,
or omit the flag for legacy multipart. Both pass native upload persistence,
staging reservation consumption, anonymous denial, sensitive masking/reveal,
method/CSRF/ownership enforcement, exact private download bytes, safe headers and
download audit. Packed mode first supplies forged file metadata without a real
upload and requires a 422 field error; JSON cannot substitute upload provenance.
Reports are `build/native-file-http-packed-results.json` and
`build/native-file-http-results.json`.

The large-form fixture saves, publishes and renders 1,500 required fields, then
posts their exact Unicode/whitespace values in the new `nfs_values` JSON form
part. Malformed JSON, non-object input and mixed legacy/packed maps return 422.
Native browser evidence `joomla-large-form-submitted.png` and an independent
database check confirm all 1,500 values, including the last field edited in the
browser. The JavaScript packing test retains repeated selections, file parts,
CAPTCHA and CSRF; Joomla adapter tests cover packed multipart file extraction.
`tests/http-admin-large-form.php` is part of the native administrator suite.

The enhanced path avoids PHP's per-variable parser cap without changing the
server validation pipeline. Legacy non-JavaScript forms remain subject to host
`max_input_vars`, multipart and POST byte budgets. Packed values have a 2 MiB
parser budget and depth 64. This proves a concrete large-form workflow, not
unlimited hosting resources or complete large-editor acceptance.

Presentation acceptance covers heading, subheading, paragraph, notice, safe HTML,
separator and spacer. `tests/http-admin-presentation.php` publishes all seven,
checks semantic tags, escaped plain text, preserved allowed emphasis/links,
removed script/image/SVG/input/event/style markup and unsafe link schemes.
Administrator preview uses the same sanitizer. A forged POST containing every
presentation UUID produces only the actual answer field in canonical storage.
`tests/unit/rendering.php` also proves fail-closed escaping when no sanitizer is
injected. `joomla-presentation-types.png` records the native public rendering.
Native visual authoring now verifies editing content, separator/spacer properties,
width configuration, palette notice insertion, saving, reloading, preview and
publication. Evidence is `joomla-presentation-edited-preview.png` and
`joomla-presentation-edited-published.png`; FIELD-009 is closed in the ledger.

`tests/http-admin-derived-fields.php` verifies native hidden rendering, configured
hidden validation, readonly/system resistance to forged POST arrays and
calculated references recomputed from normalized source input. Browser evidence
`joomla-derived-updated.png` and `joomla-derived-submitted.png` records live output
updates and successful submission. `database-text.php` now includes hidden too:
eight normative text providers plus color, eight exact projections and password
exclusion pass on all three engines. FIELD-003 closure and its boundaries are
recorded in `docs/REQUIREMENT_ACCEPTANCE.md`.

Special email ingress now covers accepted quoted local parts, IPv4 literals and
IPv6 literals, with exact canonical values, plus malformed counterparts returning
422. The text fixture has a 100-attempt test budget because its expanded matrix
exceeds the normal 30-attempt policy; the separate native rate-limit suite still
verifies the configured production behavior. Browser artifacts
`joomla-special-email-error.png` and `joomla-special-email-submitted.png` show
authoritative rejection followed by a successful quoted-address correction.
The JavaScript native-validity test verifies that only HTML type mismatch for
special core email syntax defers to the server; all other validity flags and
custom browser providers retain their constraints. This resolves an HTML-only
block on valid server input, without claiming a complete JavaScript mail parser.

The text integration fixture now publishes all eight text controls. Native HTTP
checks input types and autocomplete/inputmode, rejects required/length failures,
preserves Unicode and deliberately untrimmed search text, validates password
spaces without trimming, and excludes passwords from canonical storage.
Browser artifacts `joomla-eight-text-errors.png` and
`joomla-eight-text-submitted.png` show telephone/search/password correction.
`tests/database-text.php` checks seven exact typed projections and password
exclusion on MariaDB, MySQL and PostgreSQL, including emoji, combining marks,
line breaks, spaces and case.

This test exposed a fixture connection error: Joomla's PDO MySQL driver takes
`charset => utf8mb4`; the old `utf8mb4 => true` option was ignored. All affected
test/helper constructors now use the supported option. Existing test tables
were already `utf8mb4_bin` and required no schema conversion. The database suite
also asserts client/connection/result character sets before proceeding. All
three suites pass after correction. Earlier ASCII-only scale measurements do
not establish four-byte Unicode coverage; the new text tests do.

Email policy fixtures now cover qualified ASCII/punycode domains, invalid DNS
labels and numeric TLDs, consecutive/boundary local dots, Unicode and header
injection. PHP and JavaScript also exercise the exact 64-character local,
63-character label and 254-character total limits for unquoted addresses.
Native browser evidence `joomla-email-policy-error.png` and
`joomla-email-policy-submitted.png` records local-domain rejection and corrected
submission on the installed package. Native administrator HTTP regression passes.
These checks supplement native validity; quoted local parts and address literals
still require a complete browser/server grammar audit before FIELD-003 closes.

Cross-field validation is covered by domain and JavaScript parity tests and
`tests/http-admin-validators.php`. The latter publishes a saved confirmation
validator, rejects mismatched direct POST values and accepts their correction.
Browser checks created the validator from the builder and exercised the public
error summary followed by a successful corrected submission. Evidence is saved
as `joomla-validator-editor.png`, `joomla-validator-errors.png` and
`joomla-validator-success.png` in `tests/artifacts`.

Prefill tests include `tests/unit/prefill.php`, a client rule parity case and the
native HTTP validation fixture. They cover approved profile keys, guest behavior,
query/read-only rejection, dependency cycles, sensitive-flow rejection, removal
of sensitive constants from exports, allowed/invalid URL values and persisted
server copies despite forged POST values. The HTTP test signs into the isolated
frontend, verifies the authenticated profile and rejects a forged profile value;
the same field remains empty for the anonymous visitor. Browser evidence is saved as
`joomla-prefill-editor.png` and `joomla-prefill-readonly.png`.

Reusable templates are covered by `tests/unit/reusable-templates.php`,
`tests/database-templates.php` and `tests/http-admin-templates.php`: explicit field
binding, private-field exclusion, revision conflicts, portable capture, signed
review, independent draft creation and native ACL/CSRF/method checks. Browser
evidence is saved as `joomla-template-created-form.png` and
`joomla-email-template-applied.png` under `tests/artifacts`; the latter records a
saved draft only and does not demonstrate email delivery.

Dynamic content languages are covered by `tests/unit/definition-translations.php`,
`tests/database-translations.php` and `tests/http-admin-translations.php`.
These verify fallback, forbidden semantic overrides, original version identity,
consent text, historical locale isolation, Spanish native preview controls and
escaped translated descriptions. Browser evidence is saved as
`tests/artifacts/joomla-translated-preview.png`.

Dynamic sources: `php tools/prepare-dynamic-options.php` prepares an authored
synthetic provider and form in the guarded local Joomla fixture. Then run
`php tests/http-dynamic-options.php` for native CSRF, channel/version binding,
safe output, stale-choice rejection and accepted submissions. Browser acceptance
also changes the parent, checks clearing/rebuilding select/radio/checkbox choices,
and uses `php tools/toggle-dynamic-fixture.php disable` followed by `enable` to
verify blocked submission and successful retry. These commands target only the
named disposable local fixture. The provider is never part of the product package.
`php tests/joomla-sources.php` tests approved entity queries using transactionally
rolled-back categories/articles; `tests/integration/source-cache.php` covers
cross-instance cache expiry and backend failures.

Requirements: PHP >= 8.3 with json, mbstring and bcmath; Node >= 22.

Run from the repository root:

```powershell
php tools/lint.php
php tests/run.php
node --test tests/js/*.test.mjs
```

The PHP runner exits nonzero on any assertion/exception failure and does not rely
on PHP's configurable `assert()` behavior. Node uses the built-in test runner.
Shared operator fixtures are `tests/fixtures/operators.json`; both languages must
execute them. Security regressions include malformed authoring structures,
forged selection values, over-posting, hidden fields, readonly overrides and
nonconvergent rules.

These commands cover unit tests, not evidence of production readiness. Additional
integration commands use the official Joomla distribution and an isolated database:

```powershell
php tests/run.php --joomla
php tests/database.php
php tools/install-test-joomla.php
php tests/joomla-acl.php
```

`tools/start-test-database.ps1` uses the downloaded portable MariaDB under `build`;
the scripts require loopback port 13367 and dedicated database names. They refuse
the ordinary Joomla/user database configuration. Credentials and generated data
are ignored. The database tests exercise actual Joomla DatabaseInterface queries,
publication, replay, indexed search, action claims/retries, rate limits, leased
jobs and resumable reindexing. Test-only providers never ship with the extension.

The official Joomla site is installed in `build/joomla-6.0.0`, with its separate
`formstudio_joomla` database; CLI startup has been verified. Package installation,
the complete supported-engine matrix, concurrent-process races, full browser UI, live mail,
and scheduler remain unverified as complete product acceptance. The separate
ACL test boots installed Joomla and exercises real Asset tables and user-group
inheritance. It verifies per-form denial, independent export permission and
missing-asset denial. It does not establish that future controllers enforce ACL.

PostgreSQL 14.24 is also executed through the actual Joomla driver. The official
Windows ZIP linked by [EDB](https://www.enterprisedb.com/download-postgresql-binaries)
was downloaded from `https://sbp.enterprisedb.com/getfile.jsp?fileid=1260500`;
its recorded SHA-256 is `469f628e5740ea4aa22053fff4ef89334232b68a453691e329374b3eed2e8127`.
Extract it under ignored `build/postgresql-14` and start the loopback-only cluster
with `tools/start-test-postgresql.ps1`. It uses port 13368, SCRAM authentication
and an independent workspace data directory, without a Windows service.

```powershell
php -d extension=pgsql -d extension=pdo_pgsql tests/database.php postgresql
php -d extension=pgsql -d extension=pdo_pgsql tools/install-test-joomla.php --postgresql
php -d extension=pgsql -d extension=pdo_pgsql build/joomla-postgresql/cli/joomla.php extension:install --path=build/development-package/pkg_nicode_form_studio.zip
php -d extension=pgsql -d extension=pdo_pgsql tests/joomla-lifecycle.php --postgresql
```

Disable the Joomla behaviour compatibility plugin on that dedicated fixture
before the lifecycle test; the test refuses enabled compatibility fallback.
All shared repository/service scenarios passed, followed by native installation
of 29 tables, installed-library publication/persistence, same-version update,
preserve uninstall/reinstall, explicit ACL denials and configuration restoration.
Reports are `build/database-test-postgresql-results.json` and
`build/postgresql-lifecycle-results.json`.

MySQL 8.4.8 runs the same suites on port 13373. Its official archive is
`https://cdn.mysql.com/archives/mysql-8.4/mysql-8.4.8-winx64.zip`, with recorded
SHA-256 `53685bb61a1efc5ecc1b35c1b6bd0d30ba799f4943a1ef2c34df464f5a774c7f`.
Extract into `build/mysql` and run `tools/start-test-mysql.ps1`. The script
initializes an independent data directory and assigns a random root password
before accepting connections; remove `build/mysql-init.sql` after authenticating.
Use `php tests/database.php mysql8`,
`php tools/install-test-joomla.php --mysql8`, install the development package in
`build/joomla-mysql8`, disable the compatibility plugin there, and run
`php tests/joomla-lifecycle.php --mysql8`. All shared database and native lifecycle
scenarios passed. Reports are `build/database-test-mysql8-results.json` and
`build/mysql8-lifecycle-results.json`. Other PHP versions, minimum engine patch
versions, PostgreSQL/MySQL scale and the full release matrix remain separate
acceptance requirements.

The ACL test also verifies transactional creation/rename and rollback of actual
Joomla assets, including unchanged nested-set boundaries after injected failure.
See [ADR-0008](adr/0008-transactional-joomla-assets.md).

`tools/test-http-uploads.ps1` verifies real multipart upload bytes, MIME/extension/
size checks and forged metadata rejection. `tests/scale.php --seed` exercises an
isolated synthetic SQL dataset with one million responses; the recorded query
plans and limitations are in [SCALE_BENCHMARK.md](SCALE_BENCHMARK.md).

The local `tests/http/form.php` browser fixture has been checked for two independent
instances, required-field errors, step navigation/value preservation and conditional
email confirmation. It has no production submit handler. The screenshot is an
ignored test artifact, not an extension delivery or complete browser acceptance.
`node tools/test-js.mjs` runs the JavaScript suite and records counts for the status
generator instead of embedding a manually maintained test count.

## Installed development HTTP checks

`php tools/build-development.php` creates an explicitly non-release package in
`build/development-package`. Install it in the isolated Joomla instance with its
native `extension:install --path=...` CLI command. `php tests/joomla-runtime.php`
creates a synthetic form through the real Joomla DI composition and records its
IDs. `php tools/prepare-http-modules.php` creates two corresponding native modules.

Serve the isolated site on loopback port 13371. Set only that test site's
`live_site` to `http://127.0.0.1:13371`: Joomla 6.0.0 treats PHP's `cli-server` SAPI
as CLI when deriving its base path, so the explicit fixture URL is necessary.
`php tests/http-joomla.php` checks native CSRF, required-field rejection, JSON and
full-page success, retries, fingerprint tampering, canonical persistence and module
channel binding. It refuses other database and URL targets. All input is synthetic;
no mail or webhook actions are configured. Local browser verification additionally
confirmed independent state in a component page plus two module instances.

The development package has been installed and updated over pre-existing fixture
tables. Clean-schema install, complete admin operation, uninstall modes and supported
upgrade-path acceptance are still pending. No production release has been built.

`php tests/http-admin.php` signs into that isolated site's native Administrator
using the generated test account, then checks the installed form list and editor,
POST-only writes, missing CSRF rejection, trusted actor identity, draft persistence,
stale revision HTTP 409, successful publication, disabled-submit preview, rejected
publication preserving the previous version, history, restore and deactivation.
It deletes its own cookie jar after the run and never prints credentials. It does
not establish all role combinations or completion of the entire Administrator.

The installed builder has also been exercised in the browser: create a form, add a
required text field, change its label, save, reload, publish and inspect the saved
draft preview. A regression found during this check led to updating text properties
on input so an immediate save does not lose the latest value. The JavaScript suite
checks layout reordering, cycle rejection, subtree removal and provider-data
preservation. A visual rule making a second field required for a matching first
answer was published and verified on the public form, including rejected empty
details and successful completed submission. An internal redirect created through
the action editor also navigated after a successful response. Message overrides,
alias and UTC publication end settings were saved and checked after reload.
These checks do not cover every rule/action/provider combination or all editor
areas. In particular, no external email or webhook delivery was performed.

Administrator HTTP checks additionally cover UTC schedule validation, native ACL
read/write with CSRF and rule-hash conflicts, historical preview, stable-UUID
comparison and rejection of a version belonging to another form. The native ACL
suite verifies a non-administrator cannot edit permissions, unrelated rules stay
intact, inheritance can be restored, and external native ACL changes cause a
conflict without consuming the form revision. Browser checks verified computed
permissions, historical original labels and an interactive draft preview that
changes conditional required state while retaining a disabled submit control.

Database scenarios also cover private-file cleanup after anonymization, sensitive
detail/export masking, scoped downloads, export recovery after uncheckpointed
writes, expiration, and revocation of exports containing erased responses.

`tests/http-admin.php` includes `http-admin-submissions.php`: it checks header and
indexed-field searches, canonical detail, cross-form rejection, malformed dates,
POST/CSRF requirements for reveal and download, optimistic state conflicts, note
ownership and native list/detail rendering. The runtime fixture records a stable
submission ID so concurrent public HTTP tests cannot change which answer is read.
Response-only Joomla ACL is checked independently from form-edit permissions.
The browser verified masked/revealed synthetic data, state changes, note
persistence and a contains filter reducing four fixture rows to one. A private
saved view preserved this filter and the selected answer column after reload.
HTTP tests cover saving/loading/removing private presets; native ACL tests verify
that revoking access prevents a saved view from granting stale access.
Historical grouping has a regression test covering ordering, masking, password
exclusion and omission of authoring configuration. Notes and state changes preserve
canonical bytes and fail across form boundaries; anonymized responses reject notes.

`php tests/joomla-files.php` prepares an isolated file form and configures private
storage under ignored `build/native-private-files`, outside the Joomla web root.
`php tests/http-native-files.php` uploads through the installed public component,
then logs into the installed administrator and checks masking, explicit metadata
reveal, anonymous/CSRF/method/cross-form denial, exact attachment bytes, no-store,
nosniff and the download audit event. All content is synthetic. The fixture script
refuses any site outside the named loopback database; it does not change user sites.

Historical privacy regressions exercise a field that changes from sensitive to
public between versions. Positive and negative filters still exclude old sensitive
answers without the permission; absent policy metadata fails closed. Reindexing
rebuilds the metadata before answer projections. An old sensitive answer also
stays masked when shown under a currently public selected column.
For existing pre-release local fixtures, run
`php tools/rebuild-development-search-policy.php` before their first query against
the new policy schema. This is not a production upgrade command.

Joomla 6.0.0 official full distribution has been downloaded for integration work
into ignored `build/joomla-6.0.0.zip`. SHA-256:
`cbf61cbb5e0eacd9db1aed0da93a532d6accbb7d54c36662f3f7432a3e4b573d`.
Source: https://github.com/joomla/joomla-cms/releases/tag/6.0.0 .

## Native jobs and configuration checks

`php tests/http-admin.php` now prepares a fresh synthetic jobs fixture and checks
filtered CSV creation, private download headers and CSRF, safe job status,
cancellation, filtered state/anonymize/delete, export revocation and reindexing.
It also renders the installed component options and jobs screens.

`php tests/joomla-scheduler.php` enables only the installed FormStudio task plugin
in the isolated test site and creates a manual-only task. It launches the real
Joomla scheduler CLI repeatedly and verifies durable export resume with a
one-record chunk and two-batch limit. It does not create an automatic schedule.
`php tests/joomla-config.php` loads the installed native configuration form and
checks the namespaced rule against private, public, relative and missing paths.
The ignored JSON reports are `admin-jobs-http-results.json` and
`native-scheduler-results.json`. Browser evidence is
`tests/artifacts/joomla-admin-jobs.png`.

Action retry tests seed a definite failure for a local navigation action; no mail
or webhook is sent. Native HTTP checks enqueue it with CSRF, process its job and
verify attempt 2 succeeded and completed actions cannot be retried again. The
browser also confirms this flow through the in-page modal, with evidence at
`tests/artifacts/joomla-admin-action-retry.png`. Database tests simulate losing the
checkpoint after another failure and prove the same job does not create attempt
3; a new authorized request is necessary. Another test anonymizes after context
validation and verifies the action claim rejects the stale context.

`php tests/joomla-retention.php` exercises the native scheduler against two
historical policies, proving expired rows follow their original anonymize/delete
policy while future rows survive. Database tests cover live action deferral,
expired-lease fencing and late summary writes after anonymization.
`php tests/joomla-health.php` verifies diagnostics ACL, bounded counts, private
path suppression and an unavailable CSV workspace that leaves other handlers
usable. Native HTTP checks render the installed diagnostics view.

`tests/database-technical-log.php` verifies typed safe inputs, debug defaults,
denied readers, a 102-event cursor and retention that preserves current events
and audit rows. Native HTTP checks verify compiler failures appear in the log
viewer; browser filtering by the returned UUID is captured in
`tests/artifacts/joomla-admin-logs.png`. The diagnostics screenshot is
`tests/artifacts/joomla-admin-health.png`.

Lifecycle acceptance uses a separate official site and `formstudio_lifecycle`
database. Prepare it with `php tools/install-test-joomla.php --lifecycle`.
`php tools/reset-lifecycle-package.php` destructively resets **only disposable
FormStudio data in that named fixture**, disables the compatibility plugin and
verifies native package installation from zero to 29 tables. It never resets the
HTTP fixture. `php tests/joomla-lifecycle.php` uses installed library classes,
publishes/persists a form, verifies same-version update and runs native preserve
uninstall/reinstall. It verifies restored ACL denials and configuration, unchanged
canonical history, inaccessible orphan forms and blocked direct child removal.
Reports are `build/lifecycle-clean-install.json` and `build/lifecycle-results.json`.
Purge mode, other DB engines and released-version upgrade paths remain unverified.

`php tests/joomla-selectors.php` loads the installed menu metadata and module
manifest through Joomla Form, verifies the common selector and masks titles for
unauthorized identities. `tests/http-joomla.php` prepares a menu item through
Joomla's administrator model and checks `Itemid` resolves the correct form with
both modules. Native module selector evidence is
`tests/artifacts/joomla-module-selector.png`.

Option resources are covered by `tests/database-option-sets.php` and native
`tests/http-admin-optionsets.php` (included in the administrator HTTP suite).
They verify immutable hashes, resource ACL, optimistic writes, revision cursors,
CSRF, dependency binding and published-form isolation after resource edits.
Initial local/source defaults have dedicated tests for disabled choices,
dependent single/multiple fields, explicit defaults, read-only recalculation,
trusted prefill and cleared/tampered submissions. Browser evidence includes
`joomla-option-set.png`, `joomla-option-binding.png` and
`joomla-dependent-options.png` in `tests/artifacts`: create/save a revision,
bind its country parameter in the builder, publish, and clear Madrid when the
selected country changes from Spain to France.

`php tests/joomla-providers.php` installs the current development package and an
authored source-provider plugin in `formstudio_lifecycle`, enables it only for the
test, and invokes the installed plugin through native Joomla import and the
typed registration event. It verifies resolver output, shared registries,
renderer/source freeze and core search registration, then disables the fixture
plugin. It writes `build/native-provider-results.json`; it does not contact an
external service. Framework integration tests also exercise listener failure and
ensure no writable partially initialized registry escapes.
The same native test publishes a form using the fixture source, disables the
plugin and starts `tests/joomla-provider-unavailable.php` in a new process. The
historical FormSpec remains readable while render and submit fail before any
response row is written. Unit checks cover missing, downgraded, incompatible
major, pre-1.0 and prerelease versions, undeclared dependencies and removal of
obsolete pins while editing a draft.

Definition transfer is covered by `tests/unit/definition-package.php`,
`tests/unit/import-preview.php`, `tests/database-form-exchange.php` and
`tests/http-admin-transfer.php`. Checks include canonical round trips, size and
schema rejection, central credential redaction, sensitive rule literals, expiry,
actor/choice/revision binding, immutable activation, native CSRF, collisions and
destination resource hashes. `joomla-import-draft.png` records a browser download,
file selection, review acknowledgement and successful new draft on Joomla 6.
Duplication has separate domain/database/native HTTP tests and the browser proof
`joomla-duplicate-draft.png`. These checks do not replace the complete release
acceptance matrix.

`tests/integration/lifecycle-events.php` covers the eleven typed event names,
immutable context, before/after failure semantics and cached-source vetoes.
`tests/database-lifecycle-events.php` verifies actual persistence ordering,
completed replay suppression, no write after a veto, and no provider call after
a pre-action rejection. The installed fixture plugin in `tests/joomla-providers.php`
observes render, source resolution, validation, persistence and definition export
through the native runtime. It also implements the custom portability contract.

`tests/unit/selected-search.php` checks provider/version/query/scope-bound cursors,
cross-page ordering, rejected result shapes and job pins.
`tests/database-search-selection.php` exercises wrapped SQL pages and legacy SQL
cursor verification. The installed fixture plugin supplies `fixture.search`;
`tests/joomla-providers.php` selects it in an isolated native runtime, runs the
response explorer, verifies the queued job pin and checks that a missing engine
does not prevent retention handler registration. `joomla-search-provider.png`
shows the selector in Joomla's component options. No external search service is
claimed tested.

### Reusable data sources

`tests/database-source-resources.php` verifies portable credential redaction,
undeclared-input rejection, typed parameter binding, reference-only remapping,
optimistic revisions, disabled-resource rejection, copy isolation and resource/form
ACL. `tests/http-admin-source-resources.php` exercises native methods, CSRF,
resource views, capture, binding and immutable published preview after disabling.
Browser evidence `joomla-source-resource-captured.png` and
`joomla-source-resource-applied.png` records capture/application through the field
inspector, disabled completion controls and subsequent draft saving on Joomla 6.

`tests/joomla-console-navigation.php` boots the actual console application and
verifies menu destinations use the site menu/router and reject a missing menu.

The native composition test also checks page-scoped field/response aggregates,
zero-field drafts, one persisted response after an idempotent replay and omission
of response counts when the capability is denied. Browser evidence
`joomla-form-list-counts.png` verifies the filtered list and wrapped controls;
the wide table scrolls independently and its region is keyboard focusable.

`tests/http-admin.php` checks archive, trash and recovery to unpublished with
CSRF denial, stale-revision conflicts and preserved draft/version ownership.
Browser evidence `joomla-form-trashed.png` and `joomla-form-untrashed.png` verifies
the editor confirmations and recovery without deleting historical data.

### Global audit viewer

`tests/database-audit-log.php` verifies component log ACL, typed filters,
inclusive UTC day boundaries, 100-row keyset pages without overlap and the
exclusion of arbitrary metadata. Only allowlisted integer references/counts and
known states are projected as details. `tests/http-admin.php` verifies the native
filtered view, cache protection and malformed-filter rejection. Browser evidence
`joomla-audit-viewer.png` follows archive, trash and restoration by form ID.

Read-only native controls that do not implement HTML readonly (choices, toggles,
range, colour and files) stay disabled during rule refreshes. The validator HTTP
fixture checks disabled initial markup and ignores forged selection/boolean POST
values. Browser evidence `joomla-readonly-choices.png` and
`joomla-readonly-choices-submitted.png` verifies that changing other fields does
not unlock the controls and submission still succeeds. Prefill unit coverage also
checks that an explicit trusted override suppresses browser field-copy prefill.

### Public failure boundaries

`tests/http-public-failures.php` publishes a synthetic source provider that exists
only in its authoring process, then verifies a safe correlated 503 for the
component and independent healthy instances beside an unavailable module. The
fixture module is disabled in finally. It also verifies submission GET returns
405 with Allow: POST. `joomla-public-unavailable.png` records the safe public
message. Provider identities, labels and exception details are not exposed.

Permanent form deletion is covered by `tests/database-form-deletion.php` on
MariaDB, PostgreSQL and MySQL, and `tests/http-admin-form-deletion.php` through
native authenticated Joomla endpoints. Cases include trash-only admission,
permission/revision/confirmation rejection, competing job cancellation, blocked
publication/new submissions/actions, revoked permissions, running-action waits,
bounded deletion, lost-checkpoint rollback and owned-file outbox execution.
`tests/joomla-acl.php` verifies rollback of the real nested-set asset tree;
`tests/joomla-lifecycle.php` verifies actual asset removal on all three fixtures.
The browser confirmation and cancellation are recorded in
`tests/artifacts/joomla-form-deletion-review.png`; final deletion is exercised by
the native HTTP suite. Package-wide purge remains a separate acceptance item.

Package purge acceptance runs with `php tests/joomla-purge.php`, plus
`--postgresql` (with both PHP pgsql extensions) and `--mysql8`. These commands
purge only their guarded dedicated lifecycle databases, never the shared HTTP
fixture. They create registered private files/CSV artifacts and unowned sentinel
files, confirm preparation, verify immediate public/new-form denial and native
uninstall rejection, inject a missing cleanup provider, repair that test fixture
and resume through the real administrative service. Readiness waits for physical
cleanup. Native package uninstall removes all 29 extension tables; reinstall
starts empty. The unowned sentinels survive. All three engines passed.
Reports are `build/joomla-lifecycle-purge-results.json`,
`build/joomla-postgresql-purge-results.json` and
`build/joomla-mysql8-purge-results.json`.

`tests/http-admin-purge.php` checks the native review, method/CSRF boundaries and
incorrect confirmation phrase without purging the shared HTTP site. Browser
review evidence is `tests/artifacts/joomla-package-purge-review.png`.
`tests/unit/storage.php` checks staged-object cleanup fallback and outbox failure;
failed synchronous discards retain server-owned provider/key references in jobs.

### Visible-page form selection and response history

`tests/database-form-selection.php` checks bounded unique identities, per-form
trash permission, stale revisions, atomic compilation rollback (including earlier
version and audit inserts) and publish/archive/trash/unpublish results. It runs
on MariaDB, MySQL and PostgreSQL through the shared database suite.
`tests/http-admin-form-selection.php` checks the installed route, CSRF, method,
conflicts and controls. Native browser evidence in
`tests/artifacts/joomla-form-selection.png` shows two explicitly selected synthetic
forms successfully unpublished and the selection reset.

`tests/database-response-history.php` checks three independent 100-row histories,
105-row exact coverage, concurrent inserts, ACL and cross-form boundaries, strict
cursors, filtered technical metadata and sensitive consent reveal.
`tests/http-admin-response-history.php` follows the installed older-records link;
`tests/http-admin-validators.php` submits consent and verifies escaped historical
text, decision, version and date in the native response detail. Browser evidence:
`joomla-consent-history.png` and `joomla-response-history-page.png`.
The fresh-install schemas include `(submission_id, id)` for action history.
`tools/migrate-history-test-index.php` adds it only to guarded pre-release fixtures;
it is not a production migration or evidence of a released-version upgrade.

### Response ordering

`tests/database-search-order.php` exercises all four declared SQL orders with tied
and backdated timestamps, signed cursor/order mismatch and high-water exclusion.
The shared database suite also exports ascending filtered CSV and mutates an
ascending filtered response selection across checkpoints. Provider unit tests
reject undeclared orders and inconsistent ascending results.
`tests/http-admin-search-order.php` checks the installed API, private sort presets,
native selected control, `aria-sort`, and export-query propagation. Native browser
`joomla-response-order.png` shows the two fixture rows after changing to descending
ID order.

`tests/scale-order.php` reuses the guarded million-response MariaDB fixture and
measures global, large-form and small-form queries for each order (20 iterations).
The latest 2026-09-27 local rerun reported p95 686.52–697.47 ms for global
orders and 0.36–0.48 ms for selected-form orders. An earlier same-day run
reported 0.36–4.88 ms; optimizer variability is discussed in the scale report.
Detailed plans and timings are
in ignored `build/scale-order-results.json`. This is local synthetic performance,
not a production SLA or MySQL/PostgreSQL timing claim. Fresh schemas include
`(form_id, id)`; the guarded pre-release index helper updates existing fixtures.

### Operational dashboard

`tests/database-dashboard.php` checks independent form/response scopes, immediate
permission revocation, UTC window boundaries, failure and registered-file byte
counts, private job ownership, ten-row lists and safe definition diagnostics on
MariaDB, MySQL and PostgreSQL. Synthetic queued jobs are cancelled in `finally`
so repeated suites do not interfere with worker fixtures.
`tests/joomla-acl.php` adds native response-only and revoked-form coverage.
`tests/http-admin-dashboard.php` verifies the installed default route, no-store,
numeric metrics, compiler/provider alerts and escaping. The native screenshot is
`tests/artifacts/joomla-operations-dashboard.png`.

`tests/scale-dashboard.php` reads the existing million-response/100-form MariaDB
fixture and compiles its 100 drafts. The observation clock follows the fixture's
latest response so date-window counts are exercised. On 2026-09-27, five local
observations initially had a maximum of 585.50 ms after replacing a slow
form-name join and conditional full-row aggregates with indexed counts. The
latest post-migration rerun reached 1,475.38 ms, with 6 MiB PHP peak memory.
The report is `build/scale-dashboard-results.json`. This is a synthetic local
measurement, not a production SLO or another-engine performance claim. Cleanup
backlog reports durable jobs; it does not establish orphan-file reconciliation.

`tests/js/initialize.test.mjs` checks constructor failure isolation, exactly-once
initialization for Joomla updates, blocked submissions, safe localized failures,
and navigation recovery after a transient rule error. The test-only URL
`http://127.0.0.1:13370/form?broken=1` intentionally corrupts the first definition.
Browser verification left that instance unavailable while the second accepted
input and advanced by keyboard to its contact step. Evidence:
`tests/artifacts/browser-instance-failure-isolation.png`. This fixture is excluded
from the extension package and is not a submission acceptance endpoint.

Durable upload recovery (ADR 0013): `tests/database-upload-journal.php`, included
in all three database suites, verifies ownership before bytes, independent
transactions, consumed-object protection, attachment/cleanup rollback, expiration,
exclusive-create collision preservation and a second-connection purge lock fence.
`tests/joomla-purge.php` additionally creates an interrupted owned upload, resumes
a paused writer after purge activation, verifies cleanup before native uninstall,
preserves unknown sentinels and reinstalls a clean schema on all three engines.
`tests/http-native-files.php` checks that a real committed multipart upload has
no remaining staging reservation. PHP 163 and JavaScript 74 tests passed.

Transaction and limiter acceptance: `tests/database-rate-concurrency.php` launches
three synchronized rounds of eight isolated PHP processes for each database
engine. `tests/database-transaction-integrity.php` verifies constraint/savepoint
rollback, outer commit, original connection identity, restored PDO statement
configuration and refusal to reuse a driver after rollback loss. The standalone
PDO probes document the original failure and the scoped mitigation (ADR 0014).
Do not run `tests/joomla-scheduler.php` concurrently with `tests/http-admin.php`:
both deliberately consume the same main-fixture job queue, invalidating each
other's batch-count assertions. Different dedicated lifecycle databases may run
in parallel. Latest unit counts: PHP 164, JavaScript 74, zero failures.

Browser provider acceptance (ADR 0015): `tests/unit/browser-providers.php` and
`tests/js/browser-providers.test.mjs` cover explicit public configuration,
version pins, custom field normalization/validation, operators/effects, mutation
isolation, unavailable modules, core replacement refusal and per-document styles.
`tests/joomla-providers.php` installs a real synthetic plugin and verifies WAM
module/CSS resolution, custom logical-type compatibility, and publication refusal
after removing its asset. The plugin is never included in the product package.
`http://127.0.0.1:13370/form?browser=1&missing=1` exercises two rendered instances:
alpha blocks its missing module while beta normalizes a custom field to uppercase
and evaluates the custom suffix condition to reveal step-two fields. Evidence:
`tests/artifacts/browser-provider-isolation.png` and `browser-custom-field.png`.

For native browser authoring, run `tests/joomla-providers.php
--keep-browser-fixture` only against its guarded disposable lifecycle database.
It writes synthetic identities to `build/native-provider-browser.json`; the
fixture plugin remains enabled for that explicit browser pass. A normal run of
the same test disables it in `finally`. Native Administrator acceptance verifies
palette insertion, metadata inspector, save/reload and two custom fields in the
preview iframe (`tests/artifacts/joomla-browser-provider-preview.png`). The native
test also checks canonical custom-field persistence, keyword indexing and portable
configuration. Latest unit counts: PHP 168, JavaScript 80, zero failures.

Operational history retention is checked on all three database engines by
`tests/database-history-retention.php`: bounded candidate scans, transactional
rollback, live policy changes, fresh audit preservation, latest/running action
markers, duplicate delivery rejection and monotonic retry numbers.
`tests/joomla-scheduler.php` includes `tests/joomla-history-retention.php` to
exercise installed handlers across native CLI processes, policy cancellation,
hourly deduplication and cleanup without losing action idempotency.

`tests/joomla-config-audit.php` saves configuration through Joomla's real
`com_config` model. It checks the installed extension plugin, one successful
event, actor/correlation, empty safe metadata, and exclusion of unrelated or
failed saves. `tests/joomla-health.php` checks the warning when the plugin is
disabled. Native lifecycle tests now require five package children and ensure
updates preserve an administratively disabled audit plugin.

`tests/schema-prefix.php mysql|mysql8|postgresql` installs two independently
prefixed sets of all 30 tables in the same guarded disposable database, checks
independent uniqueness and PostgreSQL index counts, then removes only its random
test prefixes. It reproduced the former PostgreSQL schema-name collision and
passes after prefixing schema-scoped names in both dialects.

Temporal acceptance uses 25 cases in `tests/fixtures/temporal.json` shared between
PHP and JavaScript, plus seven temporal operator cases. Native HTTP coverage in
`tests/http-admin-temporal.php` rejects invalid publication, impossible dates,
out-of-range input and unsupported indexed years. `tests/database-temporal.php`
checks all five SQL projections and rollback on each supported engine. Browser
evidence: `joomla-temporal-validation.png` and `joomla-temporal-submitted.png`.
Current unit totals: PHP 191 and JavaScript 96, zero failures.

Selection normalization preserves literal spaces, tabs and leading zeroes.
Shared normalizer fixtures and PHP/JavaScript runtime tests cover all five
selection providers, exact duplicate removal, disabled/unknown option rejection
and min/max selection counts. Optional empty lists remain valid unless required;
count bounds apply to non-empty distinct selections. These checks do not alone
close the full selection-provider browser acceptance matrix.

Native `tests/http-admin-selections.php` now covers select, radio, button-group,
multiselect, checkbox-group, checkbox, toggle and yes-no. It rejects altered,
unknown and disabled option identities, insufficient/excessive selections and
malformed or required-false booleans, then checks exact scalar/array/boolean
canonical values. Browser keyboard acceptance exercises all eight controls,
both count errors and the corrected submission, with artifacts
`joomla-selection-count-error.png` and
`joomla-selection-controls-submitted.png`. Per-engine projection and search
identity comparisons remain separately subject to audit.

That audit is now covered by `tests/database-selection-identity.php`: field and
OptionSet identities differing by case, accents, Unicode composition and trailing
spaces coexist and yield exact equality/negation results on all three engines.
Long-text equality preserves trailing spaces too. ADR 0018 migrates the three
MySQL/MariaDB identity columns to VARBINARY(1020); a repeated migration issues
only three metadata queries, no DDL. Native update/preserve cycles and clean
two-prefix schema creation pass. FIELD-006 acceptance is recorded in the ledger.

Native `tests/http-admin-selections.php` now covers select, radio, button-group,
multiselect, checkbox-group, checkbox, toggle and yes-no. It rejects altered,
unknown and disabled option identities, insufficient/excessive selections and
malformed or required-false booleans, then checks exact scalar/array/boolean
canonical values. Browser keyboard acceptance exercises all eight controls,
both count errors and the corrected submission, with artifacts
`joomla-selection-count-error.png` and
`joomla-selection-controls-submitted.png`. Per-engine projection and search
identity comparisons remain separately subject to audit.

Selection normalization preserves literal spaces, tabs and leading zeroes.
Shared normalizer fixtures and PHP/JavaScript runtime tests cover all five
selection providers, exact duplicate removal, disabled/unknown option rejection
and min/max selection counts. Optional empty lists remain valid unless required;
count bounds apply to non-empty distinct selections. These checks do not alone
close the full selection-provider browser acceptance matrix.

Selection normalization preserves literal spaces, tabs and leading zeroes.
Shared normalizer fixtures and PHP/JavaScript runtime tests cover all five
selection providers, exact duplicate removal, disabled/unknown option rejection
and min/max selection counts. Optional empty lists remain empty and valid unless
required; count bounds apply to non-empty distinct selections. These checks do
not by themselves close the full selection-provider browser acceptance matrix.

`tests/database-field-policy.php` exercises persistence, sensitive defaults,
explicit index/email/export inclusion, mandatory password exclusion and masked
versus authorized reads on all three engines. Native browser evidence
`joomla-sensitive-field-policy.png` and `joomla-password-policy.png` verifies
the effective editor defaults, explicit index approval and immutable password
exclusions. Publishing without sensitive-index approval was rejected by the
native editor. FIELD-012 acceptance is recorded in the requirement ledger.

`text-length.json` defines code-point length behavior for astral emojis,
combining marks, joined emoji sequences and newlines. PHP validates using
explicit UTF-8; the renderer omits native UTF-16 length attributes so valid text
is not truncated before validation. Native HTTP tests reject over-limit text and
preserve one emoji exactly in both a text input and textarea. Browser artifacts
`joomla-unicode-length-error.png` and `joomla-unicode-length-submitted.png` show
the two-emoji error and corrected single-emoji submission.

Shared `text-types.json` cases check color syntax and URL protocol/ASCII policy
in PHP and JavaScript. Native `tests/http-admin-text.php` rejects malformed
email, unsupported URL schemes, non-ASCII URLs and invalid colors through direct
POST, then verifies exact accepted values in canonical storage. Browser evidence
`joomla-url-policy-validation.png` shows the focused protocol error before
submission; the corrected URL succeeds. Runtime messages include explicit
English/Spanish email, URL, color and temporal type errors. Full email grammar
equivalence remains unverified and is not claimed by these cases.

Common field properties are covered by `tests/unit/common-field.php` and the
native HTTP validator scenarios: both description and help are escaped and
rendered, administrative labels remain private, allowed CSS classes are scoped
to `nfs-custom-*`, and input hints reject markup. Native browser acceptance
saves an administrative label, description and help and verifies the saved draft
preview (`tests/artifacts/joomla-common-properties.png`). The shared
`tests/fixtures/range.json` cases verify default slider bounds 0..100 and step 1,
explicit decimal overrides and null fallbacks in PHP and JavaScript. Invalid
implicit bound combinations fail publication and rendered attributes explicitly
match those defaults.

`tests/http-admin-range.php` verifies these slider limits through native
publication, HTML rendering, rejected direct POST requests and successful
canonical storage. `tests/unit/numeric-conditions.php` rejects malformed numeric
rule literals, arrays in scalar comparisons, floating JSON numbers and reversed
between bounds during compilation, while retaining exact large decimals and
explicit null equality/membership semantics.

The native numeric HTTP fixture also covers integer, decimal, number and
currency with min/max/step/scale/precision failures and exact canonical types.
`tests/database-numeric.php` verifies five typed projections and signed 64-bit /
DECIMAL(38,12) boundaries on all three engines. Browser artifacts
`joomla-numeric-validation.png`, `joomla-numeric-submitted.png` and
`joomla-numeric-inspector.png` show correction, successful submission and visible
slider defaults. FIELD-004 acceptance is recorded in the requirement ledger.

`tests/database-field-identity.php` verifies published machine-name baselines,
stable UUIDs, historical immutability and storage-length rejection on all three
engines. Browser evidence `joomla-machine-name-warning.png` shows the native
inspector warning after saving a renamed draft of a published field.

The ADR 0018 scale rerun preserves 100 forms, one million responses, three
million index entries and one million synthetic action runs. `tests/scale.php`
applies the identity migration before measuring, asserts each query's result
cardinality and 5,000 distinct cursor IDs, and records a separate MariaDB
executed plan for each query. Planning and row-access time are reported
separately with the InnoDB buffer size in `docs/SCALE_BENCHMARK.md`; the observed
global planning slowdown remains documented rather than being hidden by the
fast row-access plan. The first migration took 34,576.59 ms; the subsequent
idempotent inspection took 19.9194 ms. No production latency guarantee follows
from this synthetic run.

Run `php tests/joomla-preview-parity.php` against the configured isolated Joomla
site after installing the current package. It creates a synthetic form, publishes
prefill and rule fixtures, then changes its draft to compare historical preview
with component/module output. Thirty comparisons cover initial malformed/valid
prefills, derived copies, inactive ancestors, conditional requirements, rule
precedence and three locales. Boolean attributes are tested by presence. The
fixture retains its synthetic form but creates no responses. The timestamped
result is `build/joomla-preview-parity-results.json`; it is a server DOM parity
check, not an interactive browser or accessibility audit.

`php tests/joomla-option-defaults.php` publishes a named synthetic browser fixture
with a text parent and readonly single/multiple dependent selections. Change the
parent from one to two and back; the expected canonical selections are a/[a],
b/[b,c], then a/[a]. Controls stay disabled and the changes require no submission.

For remote defaults, run `php tools/prepare-dynamic-options.php` in the named
isolated site to install/enable the synthetic provider, then
`php tests/joomla-option-defaults.php --remote`. The fixture initializes an HTML
document for provider browser-asset registration. In its published form, ES -> FR
clears the readonly selections and FR -> ES restores MD / [MD, BC]. This mode
uses the authenticated options endpoint through ordinary public session tokens;
run `php tests/http-dynamic-options.php` for HTTP boundary regressions.

`tests/http-admin-preview-options.php`, included by the Administrator HTTP suite,
prepares the isolated provider fixture and checks draft/historical preview option
queries. The provider fixture tolerates CLI web-application bootstraps without a
document. For browser acceptance, open the remote readonly fixture in the editor,
choose Preview, change ES to FR and back, and verify option restoration with the
preview Submit control continuously disabled.

`php tests/joomla-preview-boundaries.php --remote` verifies native preview service
ACL and read-only storage boundaries using the installed synthetic provider. It
creates a fixture form and registered user, temporarily changes isolated fixture
asset rules and restores those rules in finally. It compares form/version,
submission, Action and audit state before and after calls. Results are written to
`build/joomla-preview-boundaries-results.json`. Prepare the provider first with
`php tools/prepare-dynamic-options.php`.

`php tests/joomla-derived-provider.php` publishes a text source and a readonly
fixture.upper target with a field prefill. Prepare the synthetic provider first.
In the browser, change Source text to mixed case with surrounding spaces; the
source stays unchanged and Derived uppercase displays the trimmed uppercase value.
The strict-provider normalization failure/recovery matrix is in the browser
provider and PHP prefill unit tests.

## Requirement status maintenance

Maintain requirement status, implementation and evidence in
`docs/requirements-status.json`. Requirement IDs and descriptions come from
`docs/29_REQUIREMENTS_TRACEABILITY.md`; the generator refuses missing/unknown IDs,
invalid states, empty evidence and broken Markdown cells. A COMPLETE entry still
requires a scoped human/agent acceptance audit; structural validation cannot
prove that the cited implementation meets the requirement.

Run `php tools/status.php` to refresh the table, counts and available PHP/Joomla
and JavaScript test checkpoints while preserving the report's surrounding release
qualifications. Run `php tools/status.php --check` for a read-only drift check.
`php tests/status-generator.php` verifies regeneration, exact IDs including A11Y,
prose preservation, stale-check non-mutation and six invalid catalog cases. Its
synthetic files remain under ignored build/status-generator-* for diagnosis.
After documentation edits, run `php tools/docs.php` to refresh the manifest.

`php tests/http-admin.php --support-summary` authenticates on the isolated native
site and checks the copyable diagnostic JSON, readonly labelled control and
exclusion of raw records/paths. It does not create forms or enqueue jobs.
The same assertions run from the complete administrator jobs/health suite.

`php tests/http-admin.php --jobs` runs the native job/export/bulk/retry and health
checks after authenticating, without unrelated editor acceptance. It prepares
synthetic forms and executes the ordinary shared worker. Completion waits poll
the target job and allow existing fixture backlog, bounded by 55 seconds and
1,000 ticks. Pending is not reported as failed; timeouts include the observed
state. No jobs are cancelled or reordered to make the test pass.

`php tests/http-post-revalidation.php` uses the installed isolated Joomla site at
127.0.0.1:13371 and verifies its database/host before preparing synthetic forms.
It renders with an anonymous cookie session, changes availability through the
administrative service, and submits the old form as JSON and traditional HTML.
Cases cover unpublished/archived/trashed, restricted access, different language,
future start, expired end and replacement published version. Each must return
404/form unavailable without response/index/staging writes; each also has a
restored fresh-render positive control. Client-posted publication/access assertions
are deliberately forged and ignored. The eight cases run for both isolated
component rendering and a real published module selected by form ID and channel
from a page containing both. Before each fresh control, the same token is posted
with the opposite channel and must fail with 403/session_error without persistence;
the original token then succeeds and stores its verified channel. Fixture forms
are unpublished, synthetic modules/menu assignments deleted, and the temporary
cookie file removed in finally. Authenticated-user ACL-change coverage remains
separate from these anonymous visitor checks.

The same command includes `http-post-user-revalidation.php`: it creates a
registered fixture account and a private view level granting that user access,
logs in through com_users, renders the protected form, then removes the grant.
Both JSON and HTML stale POSTs must fail in the same authenticated session with
no response persisted. Restoring the grant allows a fresh submission and its
stored user_id must be the authenticated visitor. The private level and account
are removed in finally; this fixture does not send registration email because
its setup uses SiteApplication. User-group removal and account blocking require
separate acceptance cases.

The authenticated fixture additionally tests a private group-backed view level:
remove membership after render, reject both POST transports, restore and accept.
It then blocks the account while its session remains active, checks GET and both
POST transports are denied without response mutation, unblocks and verifies a
fresh accepted submission. The private group is removed during cleanup. This
regression reproduced HTTP 200 before the blocked-identity context correction.

`php tests/joomla-persistence-default.php` checks installed RuntimeProvider
composition for all three defaults and Joomla option validation using private
configuration registries; it leaves three named synthetic drafts and does not
change installed component parameters. `php tests/http-admin.php --persistence-default`
checks the native configuration control and saves/reloads all three modes through
com_config. It creates three synthetic drafts via HTTP, verifies their stored
policy, rejects invalid/empty options, and restores the exact original component
parameters in finally. Other component options are checked after every save.

The native scheduler suite also includes `joomla-attempt-cleanup.php`, exercising
six expired/fresh receipt cases across none/metadata/full via the installed CLI
task. It verifies hourly enqueue deduplication and exact preservation of protected
responses. Its synthetic forms are unpublished and its compact evidence is saved
as build/native-attempt-cleanup-results.json.
