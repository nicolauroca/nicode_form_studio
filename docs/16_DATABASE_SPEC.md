# 16 — Database Spec

ADR 0018 define identidad literal de opciones e índices keyword: MySQL/MariaDB
usan VARBINARY(1020) en option_value y value_keyword, preservando hasta 255 puntos
de código UTF-8 y distinguiendo espacios finales. La actualización inspecciona
y convierte las columnas anteriores; PostgreSQL conserva VARCHAR. La igualdad
de texto largo también distingue espacios finales mediante comparación binaria.

`installation_state` conserva checkpoints del instalador, configuración y
snapshots ACL para reinstalación. No es una tabla de respuestas ni un almacén de
secretos de proveedores. Sus claves únicas permiten restauraciones idempotentes.

`technical_log` almacena nivel, evento de vocabulario cerrado, fecha UTC, UUID
de correlación y referencias opcionales tipadas a formulario, versión, respuesta,
acción y job. Es independiente de `audit_log` y no contiene texto libre ni PII.
Sus índices cubren fecha, correlación y nivel; el visor usa paginación por ID.


> Proyecto: **Nicode Form Studio**  
> Estado del documento: **Especificación inicial normativa**  
> Plataforma objetivo: **Joomla 6.x**  
> Principios obligatorios: **SPEC-DRIVEN** y **DATA-DRIVEN**
>
> Convenciones: **MUST/DEBE** = requisito obligatorio; **SHOULD/DEBERÍA** = recomendado salvo causa documentada; **MAY/PUEDE** = opcional.  
> Todo cambio funcional deberá modificar primero o simultáneamente la especificación correspondiente y sus criterios de aceptación.


## 1. Objetivos

El modelo físico debe soportar simultáneamente:

- authoring flexible;
- runtime rápido;
- versionado;
- millones de submissions;
- búsquedas estructuradas;
- portabilidad razonable entre DB soportadas por Joomla;
- migraciones versionadas.

No se creará una tabla por formulario ni una columna por campo.

## 2. Tablas conceptuales

### `#__nicode_form_studio_forms`

Cabecera del Form.

Campos conceptuales:

- bigint ID;
- UUID;
- name;
- alias;
- state;
- access;
- language;
- created/created_by;
- modified/modified_by;
- publish_up/down;
- current_draft_revision;
- published_version_id;
- params/config JSON/text;
- asset_id si se usa ACL por item.

Índices:

- UUID unique;
- state;
- alias cuando proceda;
- published_version;
- modified.

### `#__nicode_form_studio_elements`

Árbol de Authoring.

- ID;
- UUID;
- form_id;
- parent_uuid/id;
- element_type;
- order;
- properties;
- state.

### `#__nicode_form_studio_fields`

Propiedades específicas del field.

- element reference;
- machine_name;
- field_type;
- logical data type;
- config;
- persistence flags;
- index/search flags;
- sensitive flag.

Unique:

`form_id + machine_name`.

### `#__nicode_form_studio_field_options`

Opciones locales.

### `#__nicode_form_studio_rules`

Rules.

### `#__nicode_form_studio_rule_conditions`

Árbol/estructura normalizada de conditions o definición estructurada.

### `#__nicode_form_studio_rule_effects`

Effects.

### `#__nicode_form_studio_actions`

Actions y configuración.

### `#__nicode_form_studio_option_sets`
### `#__nicode_form_studio_option_set_versions`
### `#__nicode_form_studio_option_set_items`

Se separará la identidad del recurso de sus revisiones cuando sea necesario para histórico.

### `#__nicode_form_studio_data_sources`

Configuración sin secretos en claro.

### `#__nicode_form_studio_email_templates`
### `#__nicode_form_studio_form_templates`

Recursos reutilizables.

### `#__nicode_form_studio_form_versions`

- ID;
- form_id;
- revision;
- formspec_schema_version;
- spec payload;
- hash;
- published_at;
- published_by;
- publication_comment.

Unique:

`form_id + revision`.

### `#__nicode_form_studio_submissions`

Cabecera de alto volumen.

Campos conceptuales:

- BIGINT ID;
- UUID;
- form_id;
- form_version_id;
- state;
- received_at;
- processed_at;
- user_id nullable;
- channel;
- locale;
- canonical_payload;
- payload_schema_version;
- action_summary/status;
- retention/anonymization flags;
- optional dedupe/attempt reference;
- created technical fields estrictamente necesarios.

Índices mínimos orientativos:

- UUID unique;
- `(form_id, received_at, id)`;
- `(form_id, state, received_at, id)`;
- `(form_version_id, received_at, id)`;
- `(user_id, received_at, id)` si se utiliza;
- `(action_status, received_at, id)` si la consulta operativa lo justifica.

### `#__nicode_form_studio_submission_index`

Proyección tipada SOLO de campos configurados/indexables.

Conceptualmente:

- submission_id;
- form_id;
- form_version_id;
- field_uuid;
- field_machine_name o field identity optimizada;
- value_type;
- value_keyword;
- value_text corto/normalizado;
- value_integer;
- value_decimal;
- value_boolean;
- value_date;
- value_datetime;
- ordinal para multi-value.

No todos los motores requieren exactamente las mismas columnas; el diseño definitivo debe mantener una capa Repository/SearchProvider.

Índices compuestos se diseñarán alrededor de:

`form_id + field identity + typed value + submission_id`.

### `#__nicode_form_studio_submission_files`

- submission;
- field;
- storage provider;
- storage key;
- original filename saneado;
- media type;
- size;
- checksum;
- metadata;
- timestamps.

### `#__nicode_form_studio_action_runs`

- submission_id;
- action_uuid/type;
- attempt;
- state;
- started/finished;
- result code;
- safe diagnostics;
- next retry metadata si procede.

Índices:

- `(submission_id, action_uuid)`;
- `(state, created_at)` para fallos/jobs.

### `#__nicode_form_studio_audit_log`

Eventos administrativos.

### `#__nicode_form_studio_jobs`

Para exportaciones, reindexados, retención, acciones masivas y procesos que no deban vivir en una petición web.

### `#__nicode_form_studio_job_items` (opcional)

Solo si el Job subsystem necesita granularidad.

## 3. Payload canónico + índice

La fuente primaria de verdad de una Submission será un payload canónico asociado a la FormVersion.

El índice de búsqueda es una proyección regenerable.

Esto permite:

- reconstruir/mostrar la respuesta;
- reindexar;
- cambiar estrategia de búsqueda;
- evitar EAV indiscriminado para todo;
- indexar solo campos útiles.

## 4. Por qué no solo JSON

Un JSON completo es útil para preservación, pero no debe ser el único mecanismo de búsqueda si se esperan millones de filas.

No se diseñará el Administrator alrededor de:

`LIKE '%valor%'` sobre millones de payloads.

## 5. Por qué no EAV completo como única verdad

Persistir cada valor de todos los campos en EAV puede producir decenas/cientos de millones de filas y queries complejas.

Se adopta modelo híbrido:

- canonical payload = fidelidad;
- typed searchable projection = consulta;
- optional external search provider = crecimiento.

## 6. Compatibilidad DB

El core no debe depender de una característica exclusiva de MySQL si Joomla declara también MariaDB/PostgreSQL como motores soportados y decidimos declarar compatibilidad con ellos.

Optimisations específicas podrán existir detrás de adapter/provider y tests.

## 7. IDs

Para tablas masivas se usarán identificadores numéricos amplios adecuados y UUID para identidad externa/portable.

No se deben exponer IDs secuenciales cuando ello permita IDOR.

## 8. Fechas

No usar valores de fecha cero.

Guardar timestamps en representación consistente definida por la arquitectura Joomla/DB.

## 9. Migraciones

La auditoría consultada desde una respuesta utiliza el índice compuesto
`(form_id, submission_uuid, id)`, con lectura descendente y límite por página.
No debe recorrer el historial de todo un formulario para abrir una respuesta.

Cada cambio de schema:

- script versionado;
- idempotencia razonable;
- forward migration;
- estrategia de datos;
- índice creado de forma segura;
- pruebas sobre datasets representativos.

El historial de acciones de una respuesta usa `(submission_id, id)`;
notas y auditoría mantienen sus índices por respuesta y cursor. Las tres lecturas
usan límite de página más una fila y nunca OFFSET ni payloads de entregas.

El provider SQL usa `(form_id, id)` para la ordenación por ID dentro de un formulario;
la recepción usa los índices existentes con desempate por ID en ambas direcciones.

## Registro previo de archivos

`upload_staging` conserva propiedad antes de escribir bytes (ADR 0013): formulario,
proveedor/clave únicos, token privado, estado reservado/listo, fechas, tamaño y
checksum. Índices por caducidad/ID y formulario/ID permiten recuperación acotada.
No tiene FK al formulario: la obligación de limpieza sobrevive a su eliminación.
El esquema inicial de desarrollo contiene 30 tablas. Este cambio anterior a la
primera publicación no representa una migración desde una versión distribuida.

Las consultas propias siguen DatabaseInterface y parámetros enlazados. Para PDO,
el adaptador traduce errores de ejecución antes de la recuperación implícita del
driver, conserva rollback/savepoints y restaura inmediatamente la configuración
de statements de la conexión. Si rollback falla, esa instancia queda cerrada a
consultas/reintentos. Véase ADR 0014 y la prueba de integridad en tres motores.

Auditoría e intentos de Actions tienen índice `(created_at,id)` para los jobs de
retención de ADR 0016. La búsqueda del último intento usa la clave única existente
`(submission_id,action_uuid,attempt)`. El instalador completa los índices ausentes
en instalaciones de desarrollo de la misma versión; no reescribe datos ni cambia
el número de tablas. Los lotes de historial cuentan candidatos examinados y
conservan por separado el número eliminado.

Los nombres de objetos con alcance de esquema incluyen el prefijo Joomla:
constraints de FK en ambos dialectos e índices/constraints únicos en PostgreSQL.
Los índices y claves únicas de MySQL tienen alcance de tabla. Dos instalaciones
con prefijos distintos pueden compartir una base sin colisiones ni índices
omitidos por `IF NOT EXISTS`. La aceptación instala dos juegos completos y
comprueba sus claves únicas e índices antes de retirar solo esos juegos de prueba.
