# Nicode Form Studio

> A spec-driven, data-driven form platform for Joomla 6.

Nicode Form Studio is a modern form-building, submission-management and workflow platform designed specifically for Joomla 6.

Its goal is not simply to render contact forms.

Form Studio is designed as a reusable **form engine** capable of defining, publishing, processing, storing, searching and automating arbitrary forms without requiring custom PHP code for each use case.

Forms are defined as data.

The runtime provides the capabilities.

---

## Project status

> **1.0.4 — tabbed form editor**

Nicode Form Studio is being developed in public from day one.

The architecture and product behaviour are defined through a comprehensive specification located in [`/docs`](./docs/README.md). Implementation is expected to follow those specifications rather than allowing the codebase to become the de facto product definition.

The renamed product is **in development**, pending functional and visual review.
Build the current package with `php tools/build-development.php` and use
`build/development-package/pkg_nicode_form_studio.zip` on a clean, isolated Joomla site.
All features remain free under GPL-2.0-or-later. No paid edition is introduced.

This rename changes extension IDs, PHP namespaces, database tables, ACL assets,
URLs, language keys and browser assets. It is **not an automatic upgrade** from
Nicode EasyForms. The installer refuses legacy code or retained legacy tables.
Keep existing sites and data intact until a separate migration has been validated.
Historical ZIPs and acceptance records in `dist/` retain their original names and
bytes; they do not certify the renamed package. See [build instructions](docs/BUILD_AND_RELEASE.md).

The project is intentionally public during development to keep architectural decisions, implementation progress and technical trade-offs visible.

---

## Why Nicode Form Studio?

Joomla already provides strong foundations for content management, ACL, extensions, plugins, menus, modules, localization and site administration.

What Form Studio aims to provide on top of that is a proper **generic forms platform**.

Instead of building separate implementations such as:

```text
contact.php
support.php
registration.php
event.php
request.php
```

Form Studio provides a single runtime capable of interpreting a form specification.

A new form should normally require:

```text
configuration
```

not:

```text
new application code
```

That distinction drives the entire architecture.

---

# Core principles

## Spec-driven

Features are defined before they are considered implemented.

The repository contains explicit specifications covering:

* domain model;
* form definition;
* field types;
* validation;
* conditional logic;
* submissions;
* search;
* actions;
* security;
* ACL;
* privacy;
* accessibility;
* installation;
* upgrades;
* testing;
* operations;
* scalability.

Requirements are tracked through stable identifiers and acceptance criteria.

The specification lives in:

```text
/docs
```

Start with:

* [`docs/README.md`](./docs/README.md)
* [`docs/MASTER_SPEC.md`](./docs/MASTER_SPEC.md)
* [`docs/29_REQUIREMENTS_TRACEABILITY.md`](./docs/29_REQUIREMENTS_TRACEABILITY.md)

---

## Data-driven

Forms are data, not application branches.

Form Studio is explicitly designed to avoid patterns such as:

```php
if ($formId === 42) {
    // Special behaviour for this form
}
```

The runtime must not know that a particular form represents:

* contact;
* support;
* registration;
* surveys;
* event applications;
* internal requests;
* questionnaires;
* lead generation;
* administrative processes.

Those meanings belong to the form definition.

The engine only understands capabilities.

---

## Server-authoritative

Browser-side behaviour exists for user experience.

It is never considered authoritative.

The server independently evaluates:

* active fields;
* conditional rules;
* allowed options;
* required fields;
* validation;
* access;
* publication state;
* CAPTCHA;
* anti-abuse controls;
* submission policies.

Manipulating JavaScript or HTML must not make an otherwise invalid submission valid.

---

## Versioned by design

Published forms are immutable snapshots.

Editing a published form creates or modifies a draft.

Publishing creates a new form version.

Submissions remain permanently associated with the exact form version against which they were received.

This allows historical responses to remain meaningful even after:

* fields are renamed;
* fields are removed;
* options change;
* conditional logic changes;
* emails change;
* consent wording changes;
* layouts change.

---

## Built for scale

Form Studio is designed from the beginning for installations containing:

* hundreds of forms;
* millions of submissions;
* large historical datasets;
* high-volume exports;
* searchable custom fields.

Submission storage therefore separates:

```text
Canonical submission
        +
Typed searchable projection
```

The canonical payload preserves the complete historical response.

The searchable projection provides efficient filtering and querying of selected fields.

The architecture does not rely on scanning arbitrary JSON blobs for every Administrator search.

---

# Target platform

The project targets:

```text
Joomla 6.x
PHP 8.3+
```

Compatibility with supported database engines will be explicitly tested and documented as implementation progresses.

The architecture avoids dependency on Joomla legacy APIs and the Backward Compatibility Plugin.

---

# Package architecture

Nicode Form Studio is designed as a Joomla package:

```text
pkg_nicode_form_studio
│
├── com_nicode_form_studio
├── mod_nicode_form_studio
└── lib_nicode_form_studio
```

## `com_nicode_form_studio`

Main Joomla component.

Responsible for:

* Administrator interface;
* form management;
* form builder;
* rules;
* actions;
* resources;
* submissions;
* searching;
* exports;
* configuration;
* diagnostics;
* frontend form pages;
* submission controllers.

---

## `mod_nicode_form_studio`

Generic frontend module.

A single module can render any published form.

The module selects the form to display but does not duplicate its configuration.

---

## `lib_nicode_form_studio`

Shared runtime and domain library.

Expected responsibilities include:

```text
FormSpec
FormCompiler
FormRenderer

FieldTypeRegistry
ValidatorRegistry
RuleOperatorRegistry
RuleEffectRegistry
DataSourceRegistry
ActionRegistry
SearchProviderRegistry
StorageProviderRegistry

RuleEngine
ValidationEngine
SubmissionEngine
ActionEngine

CaptchaAdapter

Repositories
Domain entities
Value objects
Contracts
```

Both component and module use the same runtime.

---

# Form publishing

A form can be exposed through multiple Joomla-native channels.

## Joomla menu item

Form Studio provides a menu item type conceptually equivalent to:

```text
Nicode Form Studio
└── Form
```

The menu configuration selects an existing Form Studio form.

---

## Joomla module

The generic module allows an administrator to select any available form and place it in any module position supported by the active Joomla template.

---

## Future channels

The architecture is designed to support additional consumers such as:

* content plugins;
* API endpoints;
* third-party integrations;

without duplicating the form runtime.

---

# Form builder

The Administrator form builder is designed around three main areas:

```text
┌────────────────┬─────────────────────────────┬────────────────────┐
│ Element palette│          Canvas             │ Properties         │
│                │                             │                    │
│ Text           │ Personal information        │ Label              │
│ Email          │ ├─ Name                     │ Required           │
│ Select         │ ├─ Email                    │ Validation         │
│ Radio          │                             │ Visibility         │
│ Group          │ Request                     │ Search             │
│ Section        │ ├─ Type                     │ Privacy            │
│ ...            │ └─ Message                  │ ...                │
└────────────────┴─────────────────────────────┴────────────────────┘
```

The specification includes:

* drag and drop;
* accessible reordering controls;
* tree view;
* element duplication;
* nested groups;
* sections;
* rows;
* columns;
* fieldsets;
* panels;
* responsive layout;
* multi-step forms.

---

# Field types

Form Studio is based on an extensible Field Type Registry.

Initial field families include:

### Text

* text;
* textarea;
* email;
* telephone;
* URL;
* search;
* password;
* hidden.

### Numeric

* integer;
* decimal;
* number;
* currency;
* range.

### Date and time

* date;
* time;
* datetime;
* month;
* week.

### Selection

* select;
* multi-select;
* radio;
* checkbox;
* checkbox groups;
* toggle;
* yes/no;
* button groups.

### Files

* single file;
* multiple files.

### Functional fields

* consent;
* calculated/read-only values;
* system values;
* CAPTCHA integration.

### Presentation

* headings;
* text;
* notices;
* separators;
* safe administrative HTML;
* structural elements.

New Field Types should be addable without creating new database columns or changing existing form definitions.

---

# Validation

Validation is declarative and runs on both client and server.

Server validation is authoritative.

Supported validation concepts include:

* required;
* data type;
* minimum length;
* maximum length;
* pattern;
* minimum value;
* maximum value;
* numeric step;
* decimal precision;
* date ranges;
* time ranges;
* selection limits;
* file extensions;
* MIME types;
* file sizes;
* file counts.

Cross-field validation is also part of the design.

Examples:

```text
start_date < end_date

email = email_confirmation

minimum <= maximum

at least one of:
    phone
    email
```

---

# Conditional logic

Form Studio contains a generic Rule Engine.

A rule follows the model:

```text
WHEN
    conditions
THEN
    effects
```

Example:

```text
WHEN
    country = "ES"
AND
    customer_type = "professional"

THEN
    show company_details
    make tax_id required
```

Conditions can be nested using:

```text
AND
OR
```

Effects can include:

* show;
* hide;
* enable;
* disable;
* required;
* optional;
* assign value;
* clear value;
* show/hide groups;
* show/hide steps;
* change options;
* filter options.

The engine is designed to detect:

* circular dependencies;
* incompatible rules;
* non-converging rule chains.

---

# Dynamic and dependent options

Form Studio supports the architecture required for dependent selections such as:

```text
Country
   ↓
Province
   ↓
City
```

or:

```text
Category
   ↓
Family
   ↓
Product
```

Options may originate from:

* local field options;
* reusable Option Sets;
* registered Data Sources.

Dynamic values are always revalidated on the server during submission.

---

# Reusable resources

Administrator resources include:

## Option Sets

Reusable versioned option collections.

Examples:

```text
Countries
Departments
Specialties
Business units
Yes / No
```

## Data Sources

Registered dynamic data providers.

## Email Templates

Reusable notification and autoresponse templates.

## Form Templates

Reusable starting points for creating new forms.

A Form Template creates an independent form; it does not introduce hidden inheritance between production forms.

---

# Submission management

Submission management is a first-class feature.

Form Studio is not intended merely to email form values and discard them.

Depending on form policy, submissions may be persisted and managed directly from Joomla Administrator.

The submission explorer is designed to provide:

* form filtering;
* date ranges;
* states;
* user filtering;
* processing status;
* Action status;
* custom field filtering;
* sorting;
* configurable columns;
* saved views;
* detailed response inspection;
* bulk operations;
* exports.

---

# Scalable submission storage

A central architectural decision is the separation between:

## Canonical payload

The historical source of truth.

It preserves the complete normalized response associated with the exact FormVersion.

## Typed search projection

A regenerable search representation containing only fields configured for indexing.

Values may be indexed according to their logical type:

```text
keyword
text
integer
decimal
boolean
date
datetime
multi-value
```

This design avoids two problematic extremes:

```text
JSON-only search
```

and:

```text
every value from every field stored exclusively as EAV
```

See:

[`docs/23_SUBMISSION_SEARCH_SCALE_SPEC.md`](./docs/23_SUBMISSION_SEARCH_SCALE_SPEC.md)

---

# Search architecture

Search is abstracted behind a Search Provider.

The core implementation is expected to provide database-backed structured searching.

The architecture allows future dedicated search providers without changing the Form or Submission domain model.

Supported concepts include:

* filters by form;
* filters by indexed field;
* ranges;
* exact matches;
* text search where supported;
* typed comparisons;
* combined filters;
* efficient pagination;
* reindexing.

Deep browsing should not depend exclusively on increasingly expensive SQL offsets.

---

# Background jobs

Large operations must not assume that everything can execute safely inside a single HTTP request.

A Job subsystem is designed for operations such as:

* large exports;
* reindexing;
* retention;
* anonymization;
* bulk deletion;
* Action retries;
* cleanup;
* large data migrations.

Jobs are expected to support states such as:

```text
pending
running
completed
failed
cancelled
```

and preserve progress so resumable processes can continue safely.

---

# Exports

Initial export targets are:

```text
CSV
JSON
```

The architecture can later support formats such as:

```text
XLSX
NDJSON
```

Large exports must be processed incrementally rather than loading an entire dataset into PHP memory.

---

# Post-submit workflow

A form definition does not end at its final field.

Form Studio also defines what happens after submission.

Possible behaviour includes:

* persist the response;
* display a success message;
* display contextual validation errors;
* send internal notifications;
* send autoresponses;
* execute conditional Actions;
* call webhooks;
* redirect;
* hide the form;
* reset the form;
* preserve selected values;
* display a confirmation reference.

---

# Action Engine

Post-submit behaviour is handled by an extensible Action Engine.

Initial Action concepts include:

* submission persistence;
* email notification;
* email autoresponse;
* redirection/navigation;
* HTTP webhook.

Actions support:

* ordering;
* conditions;
* configuration;
* failure policies;
* execution history;
* retry policies where applicable.

---

# Action reliability

Form Studio distinguishes between:

```text
Submission accepted
```

and:

```text
Every external Action succeeded
```

For example:

```text
Submission     ✓ persisted
Admin email    ✓ delivered to mail subsystem
CRM webhook    ✗ failed
```

The webhook failure should not erase the valid submission.

Each execution is represented by an Action Run and can be inspected independently.

Retryable Actions can be retried without blindly repeating Actions that already succeeded.

---

# Email

Email delivery uses Joomla's mail infrastructure.

Forms can configure multiple email Actions containing:

* To;
* CC;
* BCC;
* Reply-To;
* subject;
* HTML content;
* plain-text content;
* templates;
* attachments;
* conditions;
* response tokens.

Visitor-provided addresses must be validated before being used in email headers.

Form visitors never control the sender identity directly.

---

# CAPTCHA

Form Studio deliberately does **not** implement its own CAPTCHA algorithm.

It integrates with Joomla's CAPTCHA infrastructure and installed CAPTCHA providers.

A form may inherit:

```text
Joomla global CAPTCHA configuration
```

or select an appropriate available provider when supported.

If a form requires a CAPTCHA provider that is no longer available, the system must fail safely rather than silently removing CAPTCHA protection.

See:

[`docs/25_JOOMLA_CAPTCHA_ANTISPAM_SPEC.md`](./docs/25_JOOMLA_CAPTCHA_ANTISPAM_SPEC.md)

---

# Security

Security is part of the architecture rather than an optional feature.

The specification explicitly covers:

* CSRF;
* server-side validation;
* ACL;
* SQL injection;
* XSS;
* parameter tampering;
* option tampering;
* over-posting;
* malicious file uploads;
* path traversal;
* insecure direct object references;
* email header injection;
* SSRF;
* unsafe redirects;
* CSV formula injection;
* log leakage;
* secret leakage;
* replay/double submission.

No arbitrary PHP, JavaScript or SQL is intended to be executable from form configuration.

---

# File uploads

Uploads are treated as private application data.

The design includes:

* extension allowlists;
* MIME validation;
* maximum size;
* maximum count;
* generated internal names;
* protected storage;
* access-controlled downloads.

Storage is abstracted so future providers can support systems such as private object storage.

---

# Privacy and retention

Privacy behaviour is configurable per form.

Possible policies include:

* store submissions;
* do not store submissions;
* user association;
* IP storage;
* User-Agent storage;
* retention period;
* deletion;
* anonymization;
* sensitive-field handling.

Fields can be marked as sensitive.

Sensitive fields may be excluded from:

* logs;
* search indexes;
* emails;
* exports;

and protected by additional ACL permissions.

---

# Consent

Consent is treated as a dedicated semantic field.

A stored consent response is associated with:

* the submitted value;
* submission timestamp;
* FormVersion;
* consent wording/version.

This preserves what the user actually accepted at the time of submission.

---

# ACL

Form Studio uses Joomla ACL.

Permissions are designed to distinguish between capabilities such as:

```text
manage forms
publish forms
view submissions
manage submissions
export submissions
delete submissions
view sensitive fields
manage resources
view logs
manage configuration
manage jobs
```

Per-form authorization is also part of the architecture.

---

# Accessibility

The project targets **WCAG 2.2 AA**.

The specification covers:

* associated labels;
* fieldsets and legends;
* keyboard operation;
* accessible errors;
* focus management;
* `aria-describedby`;
* `aria-invalid`;
* dynamic visibility;
* multi-step navigation;
* non-color-only feedback.

The Administrator builder should also provide alternatives to pointer-only drag-and-drop interaction.

---

# Internationalization

Form Studio distinguishes between:

## Extension UI translations

Managed through Joomla language files.

## Dynamic form content

Including translations for:

* titles;
* labels;
* placeholders;
* help;
* options;
* validation messages;
* success/error messages;
* email templates;
* consent wording.

Historical FormVersions preserve the interpretation of their published content.

---

# Observability

The platform distinguishes between:

## Technical logging

For operational failures.

## Audit logging

For significant administrative actions.

Correlation identifiers are designed around entities such as:

```text
request
form
form version
submission
action
job
```

Sensitive response payloads should not be copied into technical logs by default.

---

# System diagnostics

Administrator diagnostics are expected to expose non-sensitive information such as:

* Form Studio versions;
* Joomla version;
* PHP version;
* database driver;
* database schema version;
* FormSpec versions;
* mail availability;
* storage status;
* filesystem health;
* cache status;
* available CAPTCHA providers;
* search provider;
* job state;
* index backlog.

---

# Repository structure

The final structure will evolve during implementation, but conceptually:

```text
.
├── administrator/
├── build/
├── component/
├── docs/
│   ├── adr/
│   ├── 00_PRODUCT_VISION.md
│   ├── 01_ARCHITECTURE.md
│   ├── ...
│   ├── 29_REQUIREMENTS_TRACEABILITY.md
│   ├── 99_REFERENCES.md
│   └── MASTER_SPEC.md
├── library/
├── module/
├── tests/
├── tools/
├── CHANGELOG.md
├── CONTRIBUTING.md
├── README.md
└── ...
```

The actual package layout is defined by the implementation and build system.

---

# Documentation

The complete product specification is maintained under [`docs/`](./docs/README.md).

Important documents include:

| Document                                                                          | Purpose                            |
| --------------------------------------------------------------------------------- | ---------------------------------- |
| [`00_PRODUCT_VISION.md`](./docs/00_PRODUCT_VISION.md)                             | Product vision and principles      |
| [`01_ARCHITECTURE.md`](./docs/01_ARCHITECTURE.md)                                 | Package and runtime architecture   |
| [`02_DOMAIN_MODEL.md`](./docs/02_DOMAIN_MODEL.md)                                 | Domain entities and lifecycle      |
| [`03_FORM_SPEC.md`](./docs/03_FORM_SPEC.md)                                       | Runtime FormSpec contract          |
| [`07_RULE_ENGINE_SPEC.md`](./docs/07_RULE_ENGINE_SPEC.md)                         | Conditional logic                  |
| [`09_SUBMISSION_SPEC.md`](./docs/09_SUBMISSION_SPEC.md)                           | Submission pipeline                |
| [`10_ACTION_ENGINE_SPEC.md`](./docs/10_ACTION_ENGINE_SPEC.md)                     | Post-submit Actions                |
| [`13_SECURITY_SPEC.md`](./docs/13_SECURITY_SPEC.md)                               | Security model                     |
| [`16_DATABASE_SPEC.md`](./docs/16_DATABASE_SPEC.md)                               | Persistence model                  |
| [`23_SUBMISSION_SEARCH_SCALE_SPEC.md`](./docs/23_SUBMISSION_SEARCH_SCALE_SPEC.md) | High-volume submissions and search |
| [`24_POST_SUBMIT_UX_SPEC.md`](./docs/24_POST_SUBMIT_UX_SPEC.md)                   | Post-submit behaviour              |
| [`25_JOOMLA_CAPTCHA_ANTISPAM_SPEC.md`](./docs/25_JOOMLA_CAPTCHA_ANTISPAM_SPEC.md) | Joomla CAPTCHA integration         |
| [`29_REQUIREMENTS_TRACEABILITY.md`](./docs/29_REQUIREMENTS_TRACEABILITY.md)       | Requirement tracking               |
| [`MASTER_SPEC.md`](./docs/MASTER_SPEC.md)                                         | Consolidated specification         |

Architecture decisions are documented separately under:

[`docs/adr/`](./docs/adr/)

---

# Development philosophy

The expected workflow is:

```text
Requirement
    ↓
Specification
    ↓
Acceptance criteria
    ↓
Domain/API contract
    ↓
Tests
    ↓
Implementation
    ↓
Verification
```

Not:

```text
Implementation
    ↓
whatever behaviour happens to emerge
```

---

# Contributing

Contributions will be expected to follow the specifications and architectural principles of the project.

Before implementing a substantial change:

1. read the relevant document under `/docs`;
2. identify the affected requirement IDs;
3. determine whether an architectural decision is required;
4. add or update an ADR when appropriate;
5. update specifications when behaviour changes;
6. add appropriate tests;
7. implement the change;
8. verify the affected requirements.

A change that alters behaviour without updating the corresponding specification is incomplete.

See `CONTRIBUTING.md` as the contributor workflow becomes available.

---

# Architecture Decision Records

Important technical decisions are documented as ADRs.

Examples already specified include:

* separation between Authoring Model and runtime FormSpec;
* hybrid submission storage;
* Joomla-based CAPTCHA integration;
* package structure using Component + Module + Library.

ADR directory:

[`docs/adr/`](./docs/adr/)

---

# Testing strategy

The specification requires several testing layers.

## Unit

Examples:

* compiler;
* normalizers;
* validators;
* Rule Engine;
* FormSpec migrations;
* search query generation;
* Action policies.

## Integration

Examples:

* Joomla database;
* ACL;
* mail;
* CAPTCHA;
* cache;
* storage;
* HTTP providers.

## Functional

Examples:

* Administrator;
* Form Builder;
* publish/version workflow;
* frontend rendering;
* module rendering;
* submission;
* search;
* exports;
* Actions.

## Security

Examples:

* CSRF;
* XSS;
* SQL injection;
* ACL bypass;
* option tampering;
* upload attacks;
* SSRF;
* CSV injection.

## Performance

The project specification includes tooling for representative large datasets, including scenarios on the order of:

```text
100 forms
1,000,000 submissions
millions of indexed values
```

---

# Building

Build instructions will be maintained in:

```text
docs/BUILD_AND_RELEASE.md
```

The intended final artifact is:

```text
pkg_nicode_form_studio-<version>.zip
```

installable through Joomla's extension installer.

Until the build system is committed, the repository should be considered development source rather than a release artifact.

---

# Installation

Stable installation instructions will be published with the first tagged release.

The intended installation model is a single Joomla package containing all required Form Studio extensions.

Do not install arbitrary development snapshots on production systems unless the corresponding release explicitly states that production use is supported.

---

# Versioning

Nicode Form Studio follows Semantic Versioning for software releases:

```text
MAJOR.MINOR.PATCH
```

The project separately tracks:

```text
Software version
Database schema version
FormSpec schema version
Form revision
```

These values describe different compatibility boundaries and must not be treated as interchangeable.

---

# Roadmap

The authoritative roadmap is derived from the specifications and requirement traceability document rather than from a reduced feature checklist in this README.

Broad development areas include:

```text
Package foundation
        ↓
Domain + FormSpec
        ↓
Form Builder
        ↓
Renderer
        ↓
Validation + Rule Engine
        ↓
Submission Engine
        ↓
Action Engine
        ↓
Search and indexing
        ↓
Jobs and exports
        ↓
Privacy and retention
        ↓
Hardening and performance
        ↓
Stable release
```

Progress should be reported against actual requirement IDs.

---

# What Form Studio is not

Nicode Form Studio is not intended to become:

* a general-purpose page builder;
* a CRM;
* a BI platform;
* a universal BPM engine;
* an arbitrary PHP execution environment;
* an arbitrary SQL query builder;
* its own CAPTCHA provider.

Where appropriate, Form Studio should integrate with those systems rather than reproduce them.

---

# Naming

**Nicode Form Studio**

Package identifiers use the `nicode_form_studio` namespace where appropriate.

Examples:

```text
pkg_nicode_form_studio
com_nicode_form_studio
mod_nicode_form_studio
lib_nicode_form_studio
```

---

# Security reports

Please do not disclose suspected security vulnerabilities through a public issue before a responsible disclosure process is available.

A dedicated `SECURITY.md` should be used for vulnerability reporting once the project establishes the corresponding contact channel.

---

# License

The repository being publicly accessible does not by itself grant permission to use, copy, modify or redistribute the software.

A formal software license should be added in a `LICENSE` file before the project is presented as open-source or before third-party reuse is encouraged.

Until that license is explicitly defined, the applicable rights remain those granted by copyright law and any explicit repository terms.

---

# Project direction

The design target can be summarized in one sentence:

> **A form should be something an administrator defines, not something a developer has to program.**

And the engineering constraint behind it is equally important:

> **The system must remain understandable, secure and operational when that becomes hundreds of forms and millions of submissions.**

That is the standard Nicode Form Studio is being built against.
