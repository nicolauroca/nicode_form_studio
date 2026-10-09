# 29 — Requirements Traceability


> Proyecto: **Nicode Form Studio**  
> Estado del documento: **Especificación inicial normativa**  
> Plataforma objetivo: **Joomla 6.x**  
> Principios obligatorios: **SPEC-DRIVEN** y **DATA-DRIVEN**
>
> Convenciones: **MUST/DEBE** = requisito obligatorio; **SHOULD/DEBERÍA** = recomendado salvo causa documentada; **MAY/PUEDE** = opcional.  
> Todo cambio funcional deberá modificar primero o simultáneamente la especificación correspondiente y sus criterios de aceptación.


## FORM

- **FORM-001** Crear múltiples formularios sin límite artificial.
- **FORM-002** Editar Form.
- **FORM-003** Publicar/despublicar.
- **FORM-004** Archivar/papelera/eliminar.
- **FORM-005** Duplicar.
- **FORM-006** Versionar.
- **FORM-007** Preview.
- **FORM-008** Compile & Publish.
- **FORM-009** Import/export.
- **FORM-010** Idioma/access/publication window.

## FIELD

- **FIELD-001** Fields ilimitados artificialmente.
- **FIELD-002** Registry extensible.
- **FIELD-003** Tipos texto.
- **FIELD-004** Numéricos.
- **FIELD-005** Fechas/tiempo.
- **FIELD-006** Selecciones.
- **FIELD-007** Files.
- **FIELD-008** Consent.
- **FIELD-009** Presentation elements.
- **FIELD-010** Propiedades min/max/step/precision/etc.
- **FIELD-011** Prefill.
- **FIELD-012** Sensitive/index flags.
- **FIELD-013** Machine name estable.
- **FIELD-014** Multi-value/repeatability compatible.

## LAYOUT

- **LAYOUT-001** Group/section/fieldset.
- **LAYOUT-002** Row/columns.
- **LAYOUT-003** Responsive.
- **LAYOUT-004** Multipaso.
- **LAYOUT-005** Builder drag/drop + alternativa accesible.
- **LAYOUT-006** Repeatable groups compatible.

## RULE

- **RULE-001** Mostrar/ocultar Field.
- **RULE-002** Mostrar/ocultar Group.
- **RULE-003** Required/optional dinámico.
- **RULE-004** Enable/disable.
- **RULE-005** Set/clear value.
- **RULE-006** Cambiar/filter options.
- **RULE-007** AND/OR nested.
- **RULE-008** Prioridad determinista.
- **RULE-009** Cycle detection.
- **RULE-010** Client/server equivalence.

## DATA

- **DATA-001** Local options.
- **DATA-002** Option Sets.
- **DATA-003** Dependent options.
- **DATA-004** Data Source Registry.
- **DATA-005** Cache/TTL.
- **DATA-006** Revalidación server.

## SUB

- **SUB-001** Pipeline único.
- **SUB-002** AJAX/traditional.
- **SUB-003** Canonical persistence.
- **SUB-004** Store/no-store.
- **SUB-005** Idempotency/double-submit mitigation.
- **SUB-006** Historical FormVersion.
- **SUB-007** Files.
- **SUB-008** States.
- **SUB-009** Direct POST revalidation.
- **SUB-010** Millions of submissions support.

## SEARCH

- **SEARCH-001** Search projection typed.
- **SEARCH-002** Filters by Form.
- **SEARCH-003** Filters by indexed Field.
- **SEARCH-004** Large dataset pagination/cursor.
- **SEARCH-005** Saved views.
- **SEARCH-006** SearchProvider abstraction.
- **SEARCH-007** Reindex.
- **SEARCH-008** Export by filter.
- **SEARCH-009** Massive operations as jobs.
- **SEARCH-010** Sensitive index policy.

## ACTION

- **ACTION-001** Multiple actions.
- **ACTION-002** Conditional Actions.
- **ACTION-003** Notification email.
- **ACTION-004** Autoresponse.
- **ACTION-005** Redirect.
- **ACTION-006** Webhook.
- **ACTION-007** Blocking/non-blocking.
- **ACTION-008** ActionRun.
- **ACTION-009** Retry.
- **ACTION-010** Tokenized templates.

## POST

- **POST-001** Configurable success message.
- **POST-002** Configurable error categories.
- **POST-003** Hide/reset/preserve Form.
- **POST-004** Conditional success.
- **POST-005** Redirect to Menu Item/approved URL.
- **POST-006** Reference code.
- **POST-007** Accessible AJAX state.

## FRONT

- **FRONT-001** Menu Item Type.
- **FRONT-002** Dynamic Form selector.
- **FRONT-003** Single generic module.
- **FRONT-004** Shared runtime.
- **FRONT-005** Multiple instances.
- **FRONT-006** Assets only when needed.

## CAPTCHA/SEC

- **SEC-001** Joomla CSRF.
- **SEC-002** Joomla CAPTCHA provider integration.
- **SEC-003** No custom CAPTCHA algorithm.
- **SEC-004** Provider availability validation.
- **SEC-005** Server validation.
- **SEC-006** ACL checks.
- **SEC-007** Safe uploads.
- **SEC-008** Parameterized SQL.
- **SEC-009** Contextual escaping.
- **SEC-010** Header injection prevention.
- **SEC-011** SSRF controls.
- **SEC-012** Rate limiting strategy.
- **SEC-013** CSV injection prevention.

## PRIVACY

- **PRIV-001** Sensitive Field.
- **PRIV-002** Retention.
- **PRIV-003** Anonymize.
- **PRIV-004** Delete.
- **PRIV-005** Consent version.
- **PRIV-006** IP/User-Agent opt-in policy.
- **PRIV-007** ACL sensitive.

## ADMIN

- **ADMIN-001** Dashboard.
- **ADMIN-002** Forms list/filter.
- **ADMIN-003** Builder.
- **ADMIN-004** Logic editor.
- **ADMIN-005** Actions editor.
- **ADMIN-006** Submissions explorer.
- **ADMIN-007** Detail.
- **ADMIN-008** Massive operations.
- **ADMIN-009** Logs.
- **ADMIN-010** System info.

## OPS

- **OPS-001** Package install.
- **OPS-002** Schema migrations.
- **OPS-003** Data-preserving uninstall option.
- **OPS-004** Jobs.
- **OPS-005** Audit.
- **OPS-006** Logs.
- **OPS-007** Health.
- **OPS-008** Reproducible release.

## UX/A11Y/I18N

- **A11Y-001** WCAG 2.2 AA target.
- **I18N-001** Joomla language files.
- **I18N-002** Dynamic translations.
- **UX-001** Preview same renderer.
- **UX-002** Errors linked to fields.
- **UX-003** Responsive layout.
