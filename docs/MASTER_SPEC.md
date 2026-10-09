# Nicode Form Studio — MASTER SPEC
> Documento agregado para consulta integral. Los documentos temáticos de `docs/` siguen siendo la fuente normativa por materia.


---

<!-- SOURCE: 00_PRODUCT_VISION.md -->

# 00 — Product Vision


> Proyecto: **Nicode Form Studio**  
> Estado del documento: **Especificación inicial normativa**  
> Plataforma objetivo: **Joomla 6.x**  
> Principios obligatorios: **SPEC-DRIVEN** y **DATA-DRIVEN**
>
> Convenciones: **MUST/DEBE** = requisito obligatorio; **SHOULD/DEBERÍA** = recomendado salvo causa documentada; **MAY/PUEDE** = opcional.  
> Todo cambio funcional deberá modificar primero o simultáneamente la especificación correspondiente y sus criterios de aceptación.


## 1. Producto

**Nombre:** Nicode Form Studio.

Nicode Form Studio será un sistema integral de creación, publicación, procesamiento, consulta y administración de formularios para Joomla 6.

No será una colección de formularios programados. Será un **motor declarativo de formularios**.

> El código implementa capacidades; los datos definen cada formulario.

## 2. Objetivos

El producto DEBE permitir:

- crear una cantidad no limitada artificialmente de formularios;
- editar, duplicar, desactivar, publicar, archivar, enviar a papelera y eliminar formularios;
- construir cada formulario con una cantidad no limitada artificialmente de elementos;
- utilizar campos primitivos, elementos de presentación, contenedores, grupos, secciones y pasos;
- definir validaciones simples y entre campos;
- definir lógica condicional de visibilidad, obligatoriedad, habilitación, valores y opciones;
- definir dependencias entre listas y fuentes de datos;
- publicar cualquier formulario como una página seleccionable desde un elemento de menú Joomla;
- publicar cualquier formulario mediante un único módulo configurable;
- mostrar varias instancias del mismo o de distintos formularios en una misma página;
- almacenar de forma íntegra las submissions cuando la política del formulario así lo indique;
- consultar cómodamente cientos o millones de submissions desde Administrator;
- buscar, filtrar, ordenar, segmentar, inspeccionar y exportar respuestas;
- configurar qué ocurre después de un envío: persistencia, mensajes, emails, autorespuestas, redirecciones, webhooks y futuras Actions;
- mantener histórico de versiones del formulario;
- preservar el significado histórico de las respuestas;
- importar y exportar definiciones;
- funcionar con ACL, idiomas, assets, base de datos, mail y demás servicios Joomla mediante APIs modernas;
- integrar CAPTCHA mediante los mecanismos de Joomla y proveedores instalados.

## 3. Principio SPEC-DRIVEN

Toda capacidad significativa DEBE definirse antes de considerarse implementable:

1. requisito;
2. contrato de datos;
3. estados y transiciones;
4. permisos;
5. validaciones;
6. errores;
7. criterios de aceptación;
8. pruebas.

Los requisitos tendrán identificadores estables como `FORM-001`, `FIELD-001`, `SUB-001`, `RULE-001`, `ACTION-001`, `SEC-001`.

## 4. Principio DATA-DRIVEN

No existirán implementaciones como:

- `soporte.php`;
- `contacto.php`;
- `inscripcion.php`;
- `registro_evento.php`.

Existirá un único runtime capaz de interpretar la definición publicada de cualquier formulario.

También serán datos:

- campos;
- estructura;
- opciones;
- reglas;
- validaciones;
- acciones;
- emails;
- mensajes;
- comportamiento post-submit;
- política de persistencia;
- política de privacidad;
- comportamiento de publicación.

## 5. Declarativo, no ejecutable

DATA-DRIVEN no autoriza a guardar código arbitrario en base de datos.

El producto NO DEBE aceptar como funcionalidad estándar:

- PHP arbitrario;
- `eval`;
- JavaScript arbitrario;
- SQL arbitrario escrito en el Builder;
- rutas de archivo arbitrarias;
- plantillas capaces de ejecutar código.

Las expresiones admitidas deberán pertenecer a un lenguaje declarativo limitado y validable.

## 6. Escala

El diseño DEBE contemplar desde el primer día:

- cientos de formularios;
- formularios con cientos de campos si el caso lo requiere;
- millones de submissions;
- decenas o cientos de millones de valores indexables en escenarios extremos;
- exportaciones que no caben razonablemente en una única petición HTTP;
- búsquedas que no dependan de recorrer el payload completo de cada respuesta.

La escala no implica que la primera release deba incluir un clúster externo de búsqueda. Sí implica que el dominio y las interfaces no pueden impedir añadirlo.

## 7. Experiencia objetivo

Para un administrador funcional, crear un formulario debe parecer una operación de configuración.

Para un desarrollador, ampliar el sistema debe hacerse mediante contratos y registries, sin introducir excepciones por `form_id`.

Para soporte, una submission debe ser trazable desde su recepción hasta cada Action ejecutada.

Para auditoría, debe poder saberse qué versión de formulario y qué textos estaban vigentes en el momento del envío.

## 8. Calidad

La extensión DEBE perseguir:

- seguridad por diseño;
- accesibilidad WCAG 2.2 AA como objetivo;
- compatibilidad con Joomla 6.x declarada y probada;
- independencia razonable del motor de base de datos soportado por Joomla;
- ausencia de dependencia de APIs legacy;
- rendimiento predecible;
- trazabilidad;
- observabilidad;
- capacidad de migración futura.


---

<!-- SOURCE: 01_ARCHITECTURE.md -->

# 01 — Arquitectura


> Proyecto: **Nicode Form Studio**  
> Estado del documento: **Especificación inicial normativa**  
> Plataforma objetivo: **Joomla 6.x**  
> Principios obligatorios: **SPEC-DRIVEN** y **DATA-DRIVEN**
>
> Convenciones: **MUST/DEBE** = requisito obligatorio; **SHOULD/DEBERÍA** = recomendado salvo causa documentada; **MAY/PUEDE** = opcional.  
> Todo cambio funcional deberá modificar primero o simultáneamente la especificación correspondiente y sus criterios de aceptación.


## 1. Unidad de distribución

Nicode Form Studio se distribuirá como un **Joomla Package**:

`pkg_nicode_form_studio`

Constituyentes iniciales:

### `com_nicode_form_studio`

Componente principal.

Responsabilidades:

- Administrator;
- Builder;
- formularios y versiones;
- submissions;
- recursos;
- configuración;
- Menu Item de frontend;
- controllers de envío;
- endpoints administrativos;
- reporting operativo;
- import/export.

### `mod_nicode_form_studio`

Módulo de Site.

Responsabilidad principal:

- seleccionar un formulario;
- aportar parámetros estrictamente de presentación del módulo;
- pedir al runtime compartido que lo renderice.

El módulo NO DEBE duplicar reglas, validación o procesamiento.

### `lib_nicode_form_studio`

Librería compartida.

Contendrá contratos y servicios reutilizables:

- FormSpec;
- Form Compiler;
- Field Type Registry;
- Layout Registry;
- Validator Registry;
- Rule Engine;
- Data Source Registry;
- Renderer;
- Submission Engine;
- Action Engine;
- Storage abstractions;
- Search abstractions;
- servicios comunes.

## 2. Extensiones futuras

La arquitectura DEBE permitir añadir sin rediseñar el núcleo:

- plugin de contenido para insertar formularios;
- Field Types adicionales;
- Validators adicionales;
- Data Sources adicionales;
- Actions adicionales;
- Storage Providers;
- Search Providers;
- integraciones REST;
- integraciones CRM/ERP;
- comandos CLI;
- tareas programadas.

## 3. Capas

### Domain

Entidades, value objects, políticas y contratos puros.

### Application

Casos de uso:

- crear formulario;
- guardar borrador;
- compilar;
- publicar;
- recibir submission;
- ejecutar Action;
- buscar submissions;
- exportar;
- eliminar/anonimizar.

### Infrastructure

Adaptadores:

- Joomla;
- base de datos;
- mail;
- filesystem;
- CAPTCHA;
- ACL;
- cache;
- HTTP;
- logs.

### Presentation

- Administrator;
- Site component;
- module;
- JSON endpoints.

No se exigirá una interpretación dogmática de Clean Architecture, pero una plantilla PHP NO DEBE contener consultas de negocio ni lógica de dominio.

## 4. Authoring Model y Runtime Model

La arquitectura separará:

### Authoring Model

Modelo editable y relacional:

- forms;
- elements;
- fields;
- rules;
- options;
- actions;
- translations.

### Runtime Model

Snapshot publicado, validado e inmutable:

`FormSpec`

El frontend deberá cargar normalmente una versión compilada, no reconstruir el formulario mediante docenas de queries.

## 5. Flujo de publicación

Borrador editable  
→ validación estructural  
→ resolución de referencias  
→ detección de ciclos  
→ validación de Actions  
→ compilación  
→ snapshot FormSpec  
→ versión publicada inmutable  
→ invalidación de cache correspondiente.

## 6. Flujo de ejecución

Request  
→ Joomla Controller  
→ autorización/contexto  
→ CSRF cuando corresponda  
→ anti-spam/CAPTCHA  
→ carga FormSpec exacto  
→ normalización  
→ Rule Engine servidor  
→ resolución de opciones  
→ validación  
→ procesamiento de archivos  
→ persistencia  
→ Action Engine  
→ Post-submit Response.

## 7. Joomla moderno

La implementación DEBE basarse en:

- MVC de Joomla;
- Dependency Injection;
- DatabaseInterface;
- ACL Joomla;
- Web Asset Manager;
- APIs actuales de formularios cuando sean adecuadas;
- sistema de plugins/eventos;
- servicios de mail;
- cache y logging de Joomla cuando sean adecuados.

No deberá depender de `JFactory` ni de APIs legacy como requisito de funcionamiento.

## 8. Independencia entre canales

Component page, module y futuros canales DEBEN utilizar:

- el mismo FormSpec;
- el mismo Renderer;
- el mismo Rule Engine;
- la misma validación;
- el mismo Submission Engine;
- el mismo Action Engine.

Solo podrá variar el contexto de renderizado.

## 9. Registro de capacidades

Los conceptos extensibles se resolverán mediante registries:

- FieldTypeRegistry;
- ValidatorRegistry;
- RuleOperatorRegistry;
- RuleEffectRegistry;
- DataSourceRegistry;
- ActionRegistry;
- StorageProviderRegistry;
- SearchProviderRegistry.

El core deberá conocer interfaces, no todas las implementaciones futuras.


---

<!-- SOURCE: 02_DOMAIN_MODEL.md -->

# 02 — Modelo de dominio


> Proyecto: **Nicode Form Studio**  
> Estado del documento: **Especificación inicial normativa**  
> Plataforma objetivo: **Joomla 6.x**  
> Principios obligatorios: **SPEC-DRIVEN** y **DATA-DRIVEN**
>
> Convenciones: **MUST/DEBE** = requisito obligatorio; **SHOULD/DEBERÍA** = recomendado salvo causa documentada; **MAY/PUEDE** = opcional.  
> Todo cambio funcional deberá modificar primero o simultáneamente la especificación correspondiente y sus criterios de aceptación.


## 1. Entidades principales

### Form

Raíz funcional de un formulario.

Propiedades conceptuales:

- `id`;
- `uuid`;
- `name`;
- `alias`;
- descripción administrativa;
- título visible;
- descripción visible;
- estado;
- idioma;
- access level;
- owner/created_by;
- fechas;
- versión publicada;
- política de almacenamiento;
- política post-submit;
- política de privacidad;
- política anti-spam;
- metadatos de presentación.

### FormVersion

Snapshot inmutable publicado.

Incluye:

- `id`;
- `form_id`;
- número de revisión;
- `formspec_schema_version`;
- FormSpec completo;
- fecha;
- autor;
- comentario de publicación;
- hash de integridad;
- estado histórico.

### Element

Nodo del árbol estructural.

Puede ser:

- field;
- container;
- presentation element;
- step.

### Field

Elemento que representa un valor o capacidad de entrada.

Tiene:

- UUID estable;
- machine name;
- Field Type;
- propiedades;
- validaciones;
- políticas de persistencia e indexación.

### Container

Elemento estructural que contiene otros elementos.

Ejemplos:

- section;
- fieldset;
- row;
- columns;
- panel;
- step;
- repeatable group.

### Option

Valor seleccionable.

Distingue `label` y `value`.

### OptionSet

Colección reutilizable y versionable de opciones.

### Rule

`WHEN conditions THEN effects`.

### Condition

Predicado evaluable contra valores y contexto autorizado.

### Effect

Cambio declarativo aplicado cuando una Rule se cumple.

### DataSource

Proveedor de valores dinámicos.

### Validation

Regla de aceptación de un valor o conjunto de valores.

### FormAction

Operación posterior a una submission válida.

### Submission

Cabecera de un envío.

### SubmissionPayload

Representación canónica íntegra de valores normalizados y metadatos permitidos.

### SubmissionIndexValue

Proyección tipada destinada a búsqueda/filtrado, no fuente primaria de verdad.

### SubmissionFile

Metadatos y referencia a fichero.

### ActionRun

Ejecución concreta de una Action sobre una Submission.

### EmailTemplate

Plantilla reutilizable.

### FormTemplate

Punto de partida para crear un formulario; no herencia viva.

### AuditEvent

Evento administrativo relevante.

## 2. Identidad

Las referencias internas entre entidades lógicas DEBEN utilizar UUID o identificadores internos estables, nunca labels.

Cambiar:

`Provincia` → `Provincia de residencia`

no puede romper reglas.

El machine name es una identidad amigable para integraciones, pero no sustituye al UUID.

## 3. Machine names

Ejemplos:

- `email`;
- `provincia`;
- `tipo_usuario`.

DEBEN ser únicos dentro de un formulario.

Cambiar un machine name ya publicado DEBE generar una advertencia por posible impacto en:

- exportaciones;
- emails;
- webhooks;
- integraciones;
- filtros guardados.

## 4. Estados de Form

Como mínimo:

- draft;
- published;
- unpublished;
- archived;
- trashed.

Podrá disponer de:

- inicio de publicación;
- fin de publicación;
- access level;
- restricciones adicionales.

Despublicar DEBE impedir tanto renderizado autorizado como POST directo.

## 5. Estados de Submission

Inicialmente:

- new;
- viewed;
- processed;
- spam;
- archived;
- error.

La arquitectura permitirá estados adicionales o workflows futuros.

## 6. Versionado

Editar un formulario publicado DEBE modificar un borrador, no la versión activa.

Publicar DEBE:

1. compilar;
2. validar;
3. crear una nueva FormVersion;
4. activar esa versión.

Restaurar una versión histórica crea un nuevo borrador; no reescribe el snapshot antiguo.

## 7. Integridad histórica

Toda Submission DEBE conservar referencia a la FormVersion exacta usada.

Una respuesta antigua debe seguir siendo interpretable aunque posteriormente se:

- renombren labels;
- eliminen campos;
- cambien opciones;
- cambien emails;
- cambien reglas;
- cambien textos de consentimiento.

## 8. Concurrencia de versiones

Si un visitante carga v7 y durante la cumplimentación se publica v8:

- el POST identificará la versión de origen;
- el servidor decidirá según política si v7 continúa admitida;
- si se acepta, validará contra v7;
- registrará la Submission contra v7.

La política predeterminada debería permitir una ventana razonable para completar formularios ya iniciados, salvo que v7 haya sido revocada por seguridad.

## 9. Eliminación

Papelera y eliminación definitiva son fases distintas.

Antes del borrado definitivo se evaluarán:

- submissions;
- Menu Items;
- módulos;
- recursos;
- referencias;
- archivos.

No habrá borrado destructivo silencioso.


---

<!-- SOURCE: 03_FORM_SPEC.md -->

# 03 — FormSpec

> Proyecto: **Nicode Form Studio**  
> Estado del documento: **Especificación inicial normativa**  
> Plataforma objetivo: **Joomla 6.x**  
> Principios obligatorios: **SPEC-DRIVEN** y **DATA-DRIVEN**
>
> Convenciones: **MUST/DEBE** = requisito obligatorio; **SHOULD/DEBERÍA** = recomendado salvo causa documentada; **MAY/PUEDE** = opcional.  
> Todo cambio funcional deberá modificar primero o simultáneamente la especificación correspondiente y sus criterios de aceptación.

## 1. Propósito

`Nicode Form Studio FormSpec` será el contrato lógico, portable y versionado que describe un formulario ejecutable.

No será un volcado de tablas SQL.

Servirá de frontera entre:

- Authoring Model;
- Compiler;
- Renderer;
- Rule Engine;
- Validation Engine;
- Submission Engine;
- import/export;
- histórico.

## 2. Versiones diferentes

El campo derivado `provider_dependencies` conserva las versiones de los providers
usados al compilar. Forma parte del hash canónico y nunca procede de tablas SQL
mutables durante el runtime. Su política de compatibilidad se define en SPEC-18.

Se distinguirán siempre:

1. versión de Nicode Form Studio;
2. versión del schema FormSpec;
3. revisión/version del formulario.

Ejemplo:

- FormStudio `1.4.0`;
- FormSpec schema `1.1`;
- Form revision `27`.

## 3. Contenido mínimo

Un FormSpec deberá poder representar:

- metadata;
- publication/access policy;
- layout tree;
- elements;
- fields;
- Field Type properties;
- validators;
- local options;
- OptionSet references/version;
- Data Source references;
- rules;
- conditions;
- effects;
- actions;
- post-submit behavior;
- messages;
- presentation;
- privacy;
- retention;
- upload policy;
- anti-spam/CAPTCHA policy;
- submission storage/index policy;
- translations/references;
- compatibility metadata.

## 4. Propiedades del contrato

FormSpec DEBE ser:

- determinista;
- serializable;
- validable;
- versionable;
- migrable;
- independiente del HTML final;
- independiente del ID DOM;
- independiente del canal de renderizado;
- inmutable una vez publicado.

## 5. Form Compiler

El Compiler deberá:

- verificar estructura;
- comprobar identificadores;
- comprobar unicidad de machine names;
- resolver referencias;
- validar capacidades de cada Field Type;
- comprobar validators;
- comprobar Option Sets;
- comprobar Data Sources;
- comprobar Rule operators;
- comprobar Rule effects;
- detectar dependencias circulares;
- detectar conflictos bloqueantes;
- comprobar Actions;
- comprobar post-submit;
- comprobar políticas de almacenamiento;
- comprobar CAPTCHA configurado;
- emitir warnings de accesibilidad/seguridad;
- normalizar el contrato;
- producir hash;
- generar snapshot.

Los avisos propios no bloqueantes incluyen `a11y.field_label` cuando una etiqueta
explícita de un campo visible está vacía y `security.sensitive_index` cuando se
habilita explícitamente la indexación de un campo sensible persistente. Ocultos
y campos de sistema no necesitan etiqueta visible; contraseñas no se indexan.
Estos avisos conservan código, ruta, mensaje y severidad WARNING tanto en preview
como en la respuesta de publicación. No alteran el snapshot ni sustituyen los
errores bloqueantes de política.
Las rutas de diagnósticos sobre listas de elementos y campos emplean el índice
real del borrador; los UUID identifican el dominio, pero no sustituyen al índice
de una lista en una ruta de navegación.

Los defaults y prefills constantes de campos persistentes indexados deben caber
en la proyección SQL portable. El compilador aplica los mismos límites de longitud,
precisión y fechas que el envío. Sin persistencia o sin índice, estos límites de
proyección no restringen el valor; siguen vigentes las restricciones del Field Type.

Los controles vivos de publicación (`alias`, `access`, `language`, `publish_up`,
`publish_down`) pertenecen al registro Form. Sus cambios consumen la misma revisión
optimista del editor y requieren permisos de edición de estado. Las fechas se
guardan en UTC y el final debe ser posterior al inicio. No reescriben snapshots
históricos. La página y el módulo comprueban estos controles antes de renderizar;
el submit los comprueba de nuevo. Despublicar, archivar o enviar a papelera conserva
el histórico; restaurar una versión solo modifica el borrador y no la activa.

## 6. Severidad del diagnóstico

- `ERROR`: impide publicar.
- `WARNING`: permite publicar con confirmación/política.
- `INFO`: recomendación o información.

Ejemplos de ERROR:

- referencia inexistente;
- dependencia circular no resoluble;
- `min > max`;
- Action obligatoria incompleta;
- machine name duplicado;
- OptionSet requerido inexistente;
- tipo de dato incompatible con una Rule.

## 7. Migraciones

Cada versión de schema FormSpec deberá tener una estrategia de migración.

Una actualización del package no deberá convertir silenciosamente un FormSpec antiguo en algo semánticamente diferente.

## 8. FormSpec y secretos

FormSpec portable NO DEBE incluir secretos en claro.

Las referencias a:

- credenciales;
- API keys;
- passwords;
- tokens;

deberán resolverse mediante configuración protegida o referencias a secret/config providers.

## 9. Contrato de implementación 1.0

ADR-0005 fija el contrato de serialización: UUID de Form, `schema_version`, `name`,
listas ordenadas `elements`, `fields`, `rules`, `actions`; cada Field comparte UUID
con su Element. Los nodos usan `parent_uuid` nullable. Las propiedades de Field
residen en `config`; sus opciones locales en `options`. Las políticas adicionales
se incorporan a bloques del snapshot sin almacenar secretos. El hash SHA-256
se calcula sobre JSON canónico con keys de objetos ordenadas y orden de listas
preservado. El Compiler devuelve diagnósticos con code/path/message/severity.

## Eliminación definitiva

La papelera es reversible. La eliminación definitiva requiere confirmación,
permisos independientes sobre formulario y respuestas, y revisión vigente.
Transiciona al estado operativo `deleting`, que bloquea nuevas publicaciones,
ediciones y respuestas. Un job reanudable elimina lotes acotados, conserva la
outbox de limpieza de archivos y retira el asset nativo al terminar. No elimina
recursos reutilizables ni auditoría mínima. Véase ADR-0011.

## Renombrado de identificadores publicados

El editor compara cada nombre de máquina con la última versión publicada por
UUID. Si cambia, muestra una advertencia localizada junto al control: las
integraciones externas que usan ese nombre pueden necesitar actualización.
Guardar sigue siendo posible; la UUID y los históricos permanecen intactos.
Publicar establece la nueva referencia para avisos posteriores. Los nombres son
identificadores únicos en cada formulario y caben en la columna de 255 caracteres.


---

<!-- SOURCE: 04_FIELD_TYPE_REGISTRY.md -->

# 04 — Field Type Registry


> Proyecto: **Nicode Form Studio**  
> Estado del documento: **Especificación inicial normativa**  
> Plataforma objetivo: **Joomla 6.x**  
> Principios obligatorios: **SPEC-DRIVEN** y **DATA-DRIVEN**
>
> Convenciones: **MUST/DEBE** = requisito obligatorio; **SHOULD/DEBERÍA** = recomendado salvo causa documentada; **MAY/PUEDE** = opcional.  
> Todo cambio funcional deberá modificar primero o simultáneamente la especificación correspondiente y sus criterios de aceptación.


## 1. Objetivo

La capacidad de admitir "todos los inputs actuales y futuros" no se resolverá con una lista rígida.

Cada Field Type será una capacidad registrada.

Un Field Type deberá declarar:

- identifier;
- categoría;
- schema de propiedades;
- tipo lógico de valor;
- renderer;
- normalizador;
- serializer;
- validator;
- operadores de Rule admitidos;
- capacidades de indexación;
- soporte de default/prefill;
- soporte de readonly/disabled;
- soporte de múltiples valores;
- configuración administrativa;
- requisitos de assets;
- compatibilidad de exportación.

## 2. Field Types estándar

### Texto

- text;
- textarea;
- email;
- telephone;
- url;
- search;
- password;
- hidden.

### Numéricos

- integer;
- decimal;
- number;
- currency;
- range.

### Fecha y tiempo

- date;
- time;
- datetime-local;
- month;
- week.

### Selección

- select;
- multiselect;
- radio;
- checkbox;
- checkbox group;
- toggle;
- yes/no;
- button group.

### Archivos

- file;
- multiple files.

### Otros HTML

- color.

### Funcionales

- consent;
- captcha placement/system captcha element;
- anti-spam marker cuando proceda;
- calculated/read-only value;
- hidden/system value.

### Presentación

No son campos de entrada, pero se gestionarán en el mismo Builder:

- heading;
- subheading;
- paragraph/text;
- safe HTML;
- separator;
- spacer;
- notice/help block.

## 3. Campos compuestos

Conceptos como:

- nombre completo;
- dirección;
- contacto;
- rango de fechas;

DEBERÍAN modelarse como presets que crean varios campos primitivos cuando eso facilite búsqueda, reglas y exportación.

## 4. Propiedades comunes

- UUID;
- machine name;
- label;
- admin label opcional;
- description;
- help;
- placeholder;
- default;
- required;
- readonly;
- disabled;
- autocomplete;
- inputmode;
- width/layout;
- CSS class controlada;
- initial visibility;
- persist value;
- include in notification;
- include in exports;
- searchable/indexed;
- sensitive;
- translatable properties.

## 5. Restricciones específicas

Cuando corresponda:

- min/max length;
- regex/pattern seguro;
- min/max numeric;
- step;
- decimal scale/precision;
- min/max date;
- min/max time;
- allowed MIME;
- allowed extension;
- max file size;
- max files;
- min/max selections.

## 6. Prefill

Fuentes permitidas de valor inicial:

- constante;
- usuario Joomla;
- contexto autorizado;
- query parameter explícitamente permitido;
- otro campo;
- Data Source.

Todo prefill se normaliza y valida.

`field.prefill` declara `type` (`constant`, `user`, `context`, `query`, `field`
o `source`) y su valor, propiedad, clave o UUID según corresponda. Las propiedades
de usuario permitidas son `id`, `name`, `username` y `email`; usuarios anónimos
no reciben datos de perfil. El contexto lo construye el adaptador del servidor,
nunca se acepta del POST. Un parámetro URL debe estar nombrado explícitamente
en el campo y solo inicia campos editables; los parámetros inválidos se descartan.
Los valores editables no se reponen después de que el visitante los vacíe.

Los campos de solo lectura se recalculan al enviar. Las referencias a otros
campos participan en el grafo de dependencias, exigen tipos compatibles y no
pueden convertir datos sensibles en no sensibles. Si el origen está inactivo,
no se copia su valor. `source` usa las opciones marcadas como predeterminadas en
la fuente configurada, con revalidación de opciones al enviar. Las reglas tienen
prioridad sobre el valor inicial. Las constantes sensibles se eliminan de las
exportaciones portables con una nota de revisión.

Un input `hidden` editable sigue siendo entrada no confiable del visitante:
se normaliza y valida igual que el resto. Ocultarlo no le concede autoridad.
Para valores determinados por el servidor se usa `readonly`, `system` o
`calculated`; el POST no sustituye esos valores, incluso con tipos manipulados.

Los controles nativos que no admiten `readonly` (selecciones, booleanos, rango,
color y archivos) se presentan deshabilitados también después de reevaluar reglas.
Esto no cambia su estado semántico activo: el servidor obtiene su valor autorizado
sin depender de que el navegador lo envíe. Un valor explícito aportado por una
integración autorizada tiene prioridad sobre el prefill y no se sustituye en el
navegador por una copia derivada de otro campo.

## 7. Indexabilidad

Cada Field Type declarará cómo puede indexarse:

- keyword;
- text;
- integer;
- decimal;
- boolean;
- date;
- datetime;
- multi-value;
- non-indexable.

El Builder podrá permitir elegir si un campo debe participar en búsquedas/filtrado, dentro de las capacidades del tipo.

## 8. Extensibilidad

Añadir un nuevo Field Type NO DEBE exigir:

- alterar `forms`;
- crear columnas en `submissions`;
- modificar cada formulario;
- añadir `if field_type == ...` dispersos por todo el core.

Debe registrarse una implementación que cumpla el contrato.

El renderer y los hooks de navegador de un campo externo se registran junto a
su provider. `BrowserProviderInterface` declara el módulo y los estilos de WAM,
la versión y las claves públicas (ADR 0015). El navegador invoca read, normalize,
validate y update por instancia; no aplica silenciosamente el comportamiento de
un input de texto a un widget desconocido. El servidor conserva la validación
autoritativa y la extracción por UUID/nombres HTML nativos.

Las propiedades comunes se exponen en el inspector mediante metadata compartida:
etiqueta pública y administrativa, descripción, ayuda, visibilidad inicial,
readonly/disabled y clases CSS. La etiqueta administrativa solo identifica el
campo dentro del constructor; nunca sustituye la pública ni se envía al runtime.
Descripción y ayuda se muestran juntas, escapadas y asociadas al control.
Los campos textuales admiten autocomplete/inputmode y trim explícito (excepto
password, que conserva sus espacios). Las clases personalizadas son hasta ocho
tokens `nfs-custom-...`, con sufijo ASCII de 1–48 caracteres; no aceptan CSS libre
ni nombres que sustituyan clases reservadas del layout o del framework.

El campo `range` aplica mínimo 0, máximo 100 y paso 1 cuando se omiten o son
nulos. Estos valores se muestran en el inspector y se aplican por igual en
HTML, validación de navegador, publicación y envío servidor. Las sustituciones
explícitas admiten decimales exactos; el paso usa el mínimo efectivo como base.
Una configuración cuyo mínimo efectivo supera al máximo impide publicar.
En campos `integer`, mínimo, máximo y paso deben ser números enteros; se rechazan
fracciones para impedir una base de paso HTML incompatible con valores enteros.

El inspector refleja los valores efectivos de privacidad: correo y exportación
están desactivados por defecto para campos sensibles, conservando una elección
explícita del autor. Al activar indexación de un campo sensible muestra la
autorización adicional `allow_sensitive_index`; sin ella no se puede publicar.
En password, almacenamiento, correo y exportación se muestran desactivados y
no editables, conforme a las exclusiones obligatorias del servidor.

Si un provider rechaza la normalización de una copia derivada, el motor conserva
un error de tipo asociado al destino y presenta un valor vacío. El envío rechaza
ese campo incluso si es opcional, sin propagar el mensaje de excepción del
provider. Los errores se recalculan en cada iteración; una asignación o vaciado
explícitos por regla sustituyen el intento fallido y pasan por la validación final.
Un destino inactivo no aporta errores ni respuestas aceptadas.

La copia derivada en JavaScript usa el normalizador del campo destino, incluidos
providers de navegador. El normalizador compartido reside en field-normalization.js
para evitar dependencias circulares entre reglas y validación. Los rechazos
producen errores de tipo asociados al destino, conservados hasta validar aunque
el campo sea opcional; una asignación/vaciado explícitos por regla pueden recuperar
el valor, con validación posterior. La entrada original no se modifica.


---

<!-- SOURCE: 05_LAYOUT_SPEC.md -->

# 05 — Layout y estructura


> Proyecto: **Nicode Form Studio**  
> Estado del documento: **Especificación inicial normativa**  
> Plataforma objetivo: **Joomla 6.x**  
> Principios obligatorios: **SPEC-DRIVEN** y **DATA-DRIVEN**
>
> Convenciones: **MUST/DEBE** = requisito obligatorio; **SHOULD/DEBERÍA** = recomendado salvo causa documentada; **MAY/PUEDE** = opcional.  
> Todo cambio funcional deberá modificar primero o simultáneamente la especificación correspondiente y sus criterios de aceptación.


## 1. Tipos estructurales

El formulario podrá contener:

- section;
- group;
- fieldset;
- row;
- columns;
- panel;
- step;
- repeatable group;
- presentation elements;
- fields.

## 2. Árbol

La estructura será un árbol ordenado.

Cada nodo tendrá:

- UUID;
- parent;
- type;
- position/order;
- propiedades;
- reglas de visibilidad si corresponden.

Se permitirá anidación cuando el tipo de container la soporte.

`parent_uuid` ausente o `null` indica el nivel superior. Cualquier otro valor
debe ser un UUID válido antes de recorrer el grafo. Tipos o UUID malformados
producen `element.parent` en la propiedad concreta; nunca se utilizan como
claves de búsqueda ni se convierten implícitamente a otro tipo.

El compilador y el renderer comparten un máximo de 64 contenedores anidados.
Un campo u otro elemento hoja puede estar dentro del contenedor número 64;
un contenedor número 65, aunque esté vacío, se rechaza antes de publicar con
`layout.depth` en su `parent_uuid`. El orden de los nodos en la lista plana no
altera este límite. El renderer mantiene la defensa para snapshots externos.

## 3. Responsive

Los elementos podrán definir anchuras por breakpoint conceptual:

- desktop;
- tablet;
- mobile.

El FormSpec no debe almacenar clases Bootstrap obligatorias. El Renderer traduce el layout a HTML/CSS compatible con el frontend.

El renderer base emplea 12 unidades por contenedor, incluidos fieldset, filas y
columnas. Cada ancho se aplica a su intervalo: mobile por debajo de 40rem,
tablet desde 40rem hasta antes de 64rem, desktop desde 64rem. Un ancho ausente
equivale a 12 en ese intervalo; no hereda el valor de otro breakpoint. Los títulos,
legend y descripciones de pasos ocupan la fila completa. Los fieldsets conservan
su elemento legend y la agrupación semántica de sus controles.

El inspector ofrece un selector traducido por breakpoint con valores de 1 a 12
y «Ancho completo (predeterminado)». Esta última opción elimina la propiedad de
ese breakpoint; no altera los otros tamaños. Los valores explícitos se guardan
como enteros y se validan también en el compilador.

## 4. Builder

El Builder deberá ofrecer:

- drag & drop;
- insertar;
- mover;
- reordenar;
- duplicar;
- copiar/pegar cuando se implemente;
- eliminar;
- contraer;
- vista árbol;
- inspector de propiedades;
- selección múltiple futura.

El arrastre dentro del árbol mueve un elemento con sus descendientes. La zona
superior/inferior de una fila coloca antes/después; el centro de un contenedor
coloca dentro, al final. Un destino de nivel superior permite sacar elementos
de su grupo. Se muestra el destino durante el arrastre y se rechazan ciclos,
padres no contenedores y pasos anidados sin modificar el borrador. Se conservan
UUID, propiedades y referencias. Subir/bajar y el selector de contenedor ofrecen
la alternativa de teclado. El arrastre solo acepta elementos del mismo editor.

Cada rama con hijos puede contraerse con un botón de teclado que expone
`aria-expanded` y `aria-controls`. El estado de expansión es local al editor,
no modifica el borrador ni la publicación y se conserva al redibujar el árbol.
Seleccionar un elemento por diagnóstico, inserción o movimiento expande sus
ancestros para mostrarlo. Contraer no elimina ni reordena descendientes.

Duplicar un elemento copia su rama al mismo contenedor con UUID nuevos y nombres
de campo únicos. Copia propiedades, opciones locales, validadores de campo,
traducciones y los efectos de reglas que apuntan a la rama. Las referencias
internas apuntan a las nuevas identidades; las externas se conservan. No duplica
acciones, validadores globales ni política post-submit. Los providers usan sus
referencias declaradas para remapear; los valores literales no se sustituyen.
La operación guarda el borrador con revisión optimista y nunca publica la copia.

## 5. Agrupación semántica

`fieldset`/`legend` deberán utilizarse cuando exista agrupación semántica de controles, especialmente radio/checkbox groups.

Un grupo visual no debe confundirse necesariamente con un fieldset semántico.

## 6. Formularios multipaso

Un formulario podrá tener pasos.

Los pasos forman una secuencia de páginas: un Step no puede ser descendiente
de otro Step, tampoco a través de grupos intermedios. Sí puede contener grupos
y puede pertenecer a un contenedor general. El compilador rechaza la anidación
de pasos con `layout.step.nested`. El inspector excluye padres incompatibles,
también al mover un grupo que contiene pasos. Insertar un paso desde otro paso
o sus descendientes lo añade al contenedor exterior de ese paso.

Cada Step:

- title;
- description;
- rules;
- validation boundary;
- previous/next;
- progress metadata.

El título y la descripción del Step son texto plano traducible. El inspector
permite editar ambos; preview y frontend muestran la descripción escapada y la
asocian al contenedor mediante `aria-describedby`, con ID exclusivo por instancia.
Una descripción vacía se omite. El compilador rechaza descripciones no textuales.

Antes de avanzar se validarán los campos activos del paso según política.

Al pulsar Siguiente se validan los campos del paso actual. Una validación entre
campos con referencias declaradas a pasos activos posteriores se aplaza hasta
alcanzarlos, para no impedir acceder al campo que permite corregirla. Las
referencias a pasos anteriores o a campos fuera de pasos sí pueden evaluarse;
solo se muestran errores del paso actual durante esa navegación. El envío final
valida todos los campos activos y todas las relaciones, también las aplazadas.
Los validadores personalizados pueden declarar sus dependencias mediante
`config.fields` para participar en esta política de navegación.

Una Rule podrá ocultar un Step completo.

Una reevaluación conserva la identidad del paso actual si continúa activo,
aunque cambie su índice al mostrar u ocultar pasos anteriores. Si desaparece,
se selecciona el siguiente paso activo en el orden del formulario, o el último
anterior si no existe uno posterior. Un reset tras envío vuelve al primer paso
activo de la definición reevaluada.

## 7. Repeatable groups

La arquitectura contemplará grupos repetibles:

- mínimo de repeticiones;
- máximo;
- botón añadir/quitar;
- validación por instancia;
- identidad de cada instancia;
- serialización inequívoca.

Si no entran en la primera release, el FormSpec no debe bloquear su incorporación.

El tipo `repeatable-group` se ofrece en la paleta con mínimo 1 y máximo 5,
editables en el inspector. Se puede anidar, mover, duplicar y previsualizar como
borrador. La publicación conserva su semántica de filas repetibles y exige
límites válidos, referencias compatibles y un árbol dentro del presupuesto.
Un borrador importado conserva los nodos inválidos para corregirlos antes de
publicar; nunca se convierten silenciosamente en grupos estáticos.

## 8. Preview

La Preview administrativa DEBE utilizar el mismo Renderer.

Modos de viewport:

- desktop;
- tablet;
- mobile.

No deberá existir un renderer paralelo "solo de preview".

El selector administrativo ofrece 1280, 800 y 390 píxeles CSS, respectivamente.
El iframe conserva su anchura real dentro de un área con desplazamiento
horizontal si no cabe en el editor. Cambiar el tamaño no recarga la vista ni
descarta los valores introducidos durante la prueba. El selector tiene etiqueta
localizada y admite teclado; estas dimensiones no simulan hardware ni agentes
de usuario de dispositivos reales.

El selector administrativo ofrece 1280, 800 y 390 píxeles CSS, respectivamente.
El iframe conserva su anchura real dentro de un área con desplazamiento
horizontal si no cabe en el editor. Cambiar el tamaño no recarga la vista ni
descarta los valores introducidos durante la prueba. El selector tiene etiqueta
localizada y admite teclado; estas dimensiones no simulan hardware ni agentes
de usuario de dispositivos reales.

La identidad base de una respuesta repetida se define en ADR 0019: UUID del campo
más pares ordenados {group, instance} para sus ancestros repetibles. Cada fila usa
un UUID estable, independiente de su posición; anidar grupos añade pares a la
ruta. Sin repetición se conserva la clave UUID existente. Los codecs validan
sintaxis y profundidad, no sustituyen la comprobación de pertenencia al snapshot.
Este contrato está integrado en renderer, validación por fila, persistencia,
archivos, lectura histórica, búsqueda y contexto de acciones. Los grupos repetibles
válidos se publican por el mismo servicio de compilación y publicación que los
demás formularios, sin insertar snapshots por vías especiales.

El registro de instancias valida las declaraciones de filas contra la ascendencia
repetible del árbol. Sus listas conservan orden; campos ajenos, filas huérfanas y
máximos excedidos se rechazan. Los mínimos se reportan por scope para aplicarlos
según activación de reglas. Esta validación de pertenencia está conectada al
transporte y al pipeline compartido, incluidos los controladores públicos.

El compilador valida límites enteros `0 <= min <= max <= 10000` y calcula el coste
máximo del árbol, incluidas filas y elementos expandidos. La suma debe caber en el
presupuesto de 10000 del runtime, aunque los mínimos sean cero. Los efectos de
reglas se cuentan por instancia de su destino. Los árboles de condiciones
copiados por los efectos están acotados: la suma de nodos
de todas las condiciones de reglas expandidas no puede superar 10000. Se cuentan
tanto grupos lógicos como hojas, por cada efecto y cada instancia de su destino.
El runtime aplica el mismo límite mientras construye el grafo, antes de evaluarlo
o enviarlo al navegador. Las condiciones globales y los
validadores usan su contexto repetido más profundo para comprobar el presupuesto.
Las llamadas de todos los validadores de campo y de formulario se suman en un
único presupuesto de 10000. CAPTCHA no puede ubicarse en una rama cuya expansión
máxima produzca más de una instancia, ya que su ámbito es el formulario.
Estos diagnósticos siguen siendo obligatorios al publicar y no sustituyen la
defensa del runtime. Un error conserva el borrador y la versión activa.

El inspector permite corregir los límites de un grupo repetible ya presente en
un borrador. Ambos valores son obligatorios, enteros y acotados; cambiar uno
actualiza la restricción del otro sin sobrescribir valores inválidos ni crear
filas. La publicación exige que ambos valores y el árbol expandido sean válidos.

La previsualización usa la misma compilación semántica. Crea las filas mínimas
con RepeatedInstances y ejecuta PresentationState y FormRenderer compartidos.
Las consultas de opciones llevan sus declaraciones de filas y se validan contra
el borrador/revisión o la versión histórica autorizada. No hay token de envío ni
activación del formulario. Las filas de preview se añaden y quitan mediante un
POST administrativo con CSRF, revisión y pertenencia verificadas. Se conserva la
identidad del formulario y de las filas restantes; solo los campos nuevos reciben
sus valores iniciales. La operación no guarda borradores ni respuestas y el envío
permanece deshabilitado incluso si falla el cambio de filas.
El iframe conserva el bloqueo de envíos de su sandbox. Por ello, los botones de
filas de preview usan type=button con eventos administrados; los públicos siguen
usando submit para el fallback HTML.


---

<!-- SOURCE: 06_VALIDATION_SPEC.md -->

# 06 — Validación y normalización


> Proyecto: **Nicode Form Studio**  
> Estado del documento: **Especificación inicial normativa**  
> Plataforma objetivo: **Joomla 6.x**  
> Principios obligatorios: **SPEC-DRIVEN** y **DATA-DRIVEN**
>
> Convenciones: **MUST/DEBE** = requisito obligatorio; **SHOULD/DEBERÍA** = recomendado salvo causa documentada; **MAY/PUEDE** = opcional.  
> Todo cambio funcional deberá modificar primero o simultáneamente la especificación correspondiente y sus criterios de aceptación.


## 1. Principio

La validación de navegador mejora UX; la validación de servidor decide.

Cada valor seguirá:

raw input  
→ canonical input extraction  
→ normalization  
→ Rule evaluation/context  
→ type validation  
→ field validators  
→ cross-field validators  
→ accepted canonical value.

El envío mejorado agrupa los valores escalares y selecciones en una parte JSON
`nfs_values` del multipart para evitar truncamientos por `max_input_vars`.
Archivos, CSRF, CAPTCHA e identidad mantienen sus partes originales. El servidor
admite también `nfs` tradicional para formularios sin JavaScript, pero rechaza
mezclar ambos mapas. El mapa JSON debe ser un objeto UTF-8 válido de hasta 2 MiB;
este presupuesto protege el parser, no impone un número comercial de campos.
Los valores siguen sin ser confiables y recorren la misma validación autoritativa.
El empaquetado conserva todas las partes de archivo, sus bytes y su orden por
nombre incluso si un control aporta texto con el mismo nombre multipart. Solo
las cadenas se trasladan al mapa JSON; este nunca otorga procedencia de subida.
Una mezcla ambigua de valor escalar y selección para un UUID se rechaza antes de
modificar el multipart original.

## 2. Validadores estándar

- required;
- type;
- min length;
- max length;
- pattern;
- min;
- max;
- step;
- integer;
- decimal precision/scale;
- date min/max;
- time min/max;
- min selections;
- max selections;
- allowed file extension;
- allowed MIME;
- max size;
- max file count.

## 3. Validación entre campos

Debe soportar:

- A = B;
- A != B;
- A > B;
- A < B;
- A >= B;
- A <= B;
- fecha A antes de B;
- fecha A después de B;
- al menos uno en conjunto;
- exactamente N;
- rango coherente;
- confirmación de valor.

El constructor permite añadir, ordenar, editar y eliminar estas validaciones,
seleccionando explícitamente los campos en orden de evaluación. Los parámetros
del editor proceden del schema del provider: referencias de campo, cardinalidad
y número de valores exigidos. Una referencia eliminada permanece visible para
su reparación; nunca se reasigna silenciosamente a otro campo. Guardar conserva
el borrador y previsualizar/publicar ejecuta la validación autoritativa del schema.

Al compilar, los validadores core de dos campos exigen el mismo datatype lógico
y multiplicidad; integer y decimal son compatibles entre sí. La igualdad y
confirmación admiten selecciones múltiples con comparación ordenada de valores.
Los archivos no admiten comparación de valores. Las comparaciones de orden y
rango requieren valores escalares numéricos o temporales compatibles; before y
after requieren el mismo tipo temporal. Los recuentos at_least_one y exactly_n
admiten tipos heterogéneos. Una incompatibilidad produce `validator.compatibility`
en la ubicación original del validador e impide publicar. Esta política pertenece
a los providers core; los validadores personalizados conservan su propio contrato.

En comparaciones de pares, `false` es un valor booleano válido: confirmar true
contra false falla y confirmar false contra false pasa. Solo null, cadena vacía
y lista vacía omiten la comparación opcional. Los recuentos de campos cumplimentados
conservan otra semántica: false no cuenta, mientras que el cero numérico sí cuenta.

En comparaciones de pares, `false` es un valor booleano válido: confirmar true
contra false falla y confirmar false contra false pasa. Solo null, cadena vacía
y lista vacía omiten la comparación opcional. Los recuentos de campos cumplimentados
conservan otra semántica: false no cuenta, mientras que el cero numérico sí cuenta.

Al compilar, los validadores core de dos campos exigen el mismo datatype lógico
y multiplicidad; integer y decimal son compatibles entre sí. La igualdad y
confirmación admiten selecciones múltiples con comparación ordenada de valores.
Los archivos no admiten comparación de valores. Las comparaciones de orden y
rango requieren valores escalares numéricos o temporales compatibles; before y
after requieren el mismo tipo temporal. Los recuentos at_least_one y exactly_n
admiten tipos heterogéneos. Una incompatibilidad produce `validator.compatibility`
en la ubicación original del validador e impide publicar. Esta política pertenece
a los providers core; los validadores personalizados conservan su propio contrato.

## 4. Mensajes

Cada validador podrá tener:

- mensaje por defecto traducible;
- override a nivel de formulario;
- override a nivel de campo/regla.

No se expondrá información técnica sensible.

## 5. Campos inactivos

Después de ejecutar el Rule Engine servidor:

- un campo no activo no debe considerarse requerido;
- por defecto, valores enviados para campos inactivos se ignorarán y no persistirán;
- una política futura podría permitir conservarlos explícitamente, pero deberá ser consciente y documentada.

## 6. Valores de selección

El servidor DEBE comprobar que los valores recibidos pertenecen a:

- opciones estáticas válidas;
- OptionSet exacto;
- resultado válido de Data Source para el contexto;
- conjunto permitido por reglas.

Un `<select>` manipulado no puede introducir valores arbitrarios.

## 7. Normalización

Ejemplos:

- strings: política de trim definida;
- email: forma canónica conservando el valor válido;
- integer/decimal: conversión tipada;
- date/datetime: representación canónica;
- boolean: representación inequívoca;
- multi-value: array normalizado;
- files: metadata controlada.

No se aplicará un "sanitizado genérico" como sustituto de validación por tipo.

Los límites de longitud cuentan puntos de código Unicode después de normalizar,
sin componer ni descomponer texto: un emoji astral simple cuenta como uno y una
letra con marca combinante cuenta como dos. PHP usa UTF-8 explícito y JavaScript
itera puntos de código. El renderer no usa minlength/maxlength nativos, que
cuentan unidades UTF-16 y pueden truncar una entrada válida; ambos validadores
aplican los límites y muestran el error sin truncar el valor del visitante.

## 8. Errores

La respuesta de validación estructurada deberá contener:

- error code;
- field UUID/machine name cuando proceda;
- mensaje de usuario;
- severidad;
- metadata no sensible.

El frontend podrá mostrar:

- resumen;
- mensaje junto al campo;
- foco en primer error.

## Comparación temporal exacta

Los tipos date, time, datetime-local, month y week validan calendario real y
límites min/max al compilar. Usan años de cuatro dígitos entre 0001 y 9999;
las semanas siguen ISO 8601. Hora y datetime local admiten minutos o segundos,
sin zona horaria ni fracciones. Para límites y operadores, los segundos omitidos
son cero: 12:00 y 12:00:00 representan el mismo valor. Un límite mal formado o
invertido impide publicar. PHP y JavaScript usan los mismos casos de aceptación,
incluidos bisiestos, semana 53 y límites inclusivos; no delegan esta semántica
exclusivamente en la validación HTML del navegador.

La proyección SQL de fechas y datetime se limita al intervalo portable 1000–9999.
El servidor y el navegador rechazan fuera de ese rango con `index_date` antes de
persistir; los campos no indexados conservan soporte desde el año 0001. El
proyector aplica la misma defensa al reconstruir índices históricos.

## URL y color

El campo URL conserva la cadena aceptada y admite URLs absolutas ASCII HTTP o
HTTPS. Dominios internacionales requieren punycode y caracteres no ASCII del
path requieren percent-encoding. La validación de navegador comprueba protocolo
y ASCII además de la sintaxis nativa; PHP conserva la decisión autoritativa.
El campo color admite `#RRGGBB` con seis dígitos hexadecimales, sin abreviaturas
ni canal alfa. Los casos compartidos cubren estas políticas en ambos lenguajes.

Los controles HTML y `FILTER_VALIDATE_EMAIL` no comparten exactamente la misma
gramática. La validación de correo autoritativa conserva la política de PHP
(sin dominios sin punto, plegado ni comentarios); la comprobación HTML mejora
la UX y no acredita por sí sola equivalencia de todos los casos especiales.
La comprobación JavaScript anticipa también los rechazos por dominio sin punto,
etiquetas DNS inválidas o mayores de 63 caracteres, TLD numérico, caracteres
no ASCII y CR/LF. En partes locales sin comillas comprueba puntos consecutivos
o en los extremos y los límites de 64 caracteres locales y 254 totales.
Los casos compartidos verifican esas fronteras. Las partes locales con comillas
y los literales de dirección siguen sujetos a la gramática autoritativa PHP;
estas comprobaciones no afirman equivalencia completa con el parser de correo.
Para partes locales con comillas o dominios entre corchetes, el frontend permite
que el servidor decida el error nativo HTML `typeMismatch`. No omite required,
pattern, errores personalizados ni otras restricciones, ni altera proveedores
de correo personalizados. Esto evita impedir el envío de direcciones que PHP
acepta; una dirección especial mal formada continúa rechazándose en el servidor.
Referencias: [filtros PHP](https://www.php.net/manual/en/filter.constants.php) y
[controles HTML](https://html.spec.whatwg.org/dev/input.html).


---

<!-- SOURCE: 07_RULE_ENGINE_SPEC.md -->

# 07 — Rule Engine


> Proyecto: **Nicode Form Studio**  
> Estado del documento: **Especificación inicial normativa**  
> Plataforma objetivo: **Joomla 6.x**  
> Principios obligatorios: **SPEC-DRIVEN** y **DATA-DRIVEN**
>
> Convenciones: **MUST/DEBE** = requisito obligatorio; **SHOULD/DEBERÍA** = recomendado salvo causa documentada; **MAY/PUEDE** = opcional.  
> Todo cambio funcional deberá modificar primero o simultáneamente la especificación correspondiente y sus criterios de aceptación.


## 1. Modelo

Una Rule sigue:

`WHEN <condition tree> THEN <effects>`

Las condiciones admiten:

- AND;
- OR;
- grupos anidados;
- negación controlada cuando corresponda.

## 2. Fuentes de condición

- valor de campo;
- estado de campo;
- usuario autenticado;
- idioma;
- fecha/hora;
- canal/contexto;
- otros contextos registrados.

No se permite acceso genérico a variables PHP.

## 3. Operadores

Según Field Type:

- equals;
- not equals;
- contains;
- not contains;
- starts with;
- ends with;
- in;
- not in;
- empty;
- not empty;
- selected;
- not selected;
- greater than;
- less than;
- greater/equal;
- less/equal;
- between;
- before;
- after;
- safe pattern match.

El Field Type Registry determina compatibilidad.

## 4. Effects

- show field;
- hide field;
- show group/container;
- hide group/container;
- show/hide step;
- enable;
- disable;
- required;
- optional;
- set value;
- clear value;
- change/filter options;
- change default;
- activar/desactivar Action cuando sea parte del modelo de Action condition.

## 5. Opciones dinámicas

Una regla podrá provocar que un campo:

- cambie OptionSet;
- aplique filtro;
- envíe parámetros a Data Source;
- se vacíe si su valor deja de ser válido.

## 6. Prioridad

Cada Rule tendrá:

- enabled;
- priority;
- deterministic order.

Se definirá una semántica explícita para efectos múltiples sobre el mismo target.

## 7. Conflictos

El Compiler debe detectar conflictos inequívocos.

Ejemplo bloqueante:

- misma prioridad;
- misma condición;
- mismo target;
- `required`;
- `optional`.

Otros conflictos pueden ser warnings si el orden los hace deterministas.

## 8. Dependencias circulares

Se construirá un grafo de dependencias.

Ciclos que hagan indeterminada la evaluación impedirán publicación.

## 9. Cliente y servidor

Habrá dos evaluadores semánticamente equivalentes:

### Client Rule Engine
UX inmediata.

### Server Rule Engine
autoridad.

Manipular JavaScript no permitirá eludir reglas.

## 10. Estabilización

Las Rules que cambian valores/opciones pueden provocar nuevas Rules.

Las opciones de `change_options` conservan el contrato de identidad literal:
no se permiten valores duplicados dentro de la lista y los flags `enabled` y
`default`, si aparecen, deben ser booleanos. El compilador rechaza los valores
mal formados con una ruta hasta la opción o flag afectado, antes de publicar.

El Engine deberá evaluar hasta estado estable con:

- orden determinista;
- límite de iteraciones;
- detección de ciclo/no convergencia.

Una no convergencia será error de configuración.

## 11. Semántica de evaluación 1.0

Se evalúan las Rules por prioridad ascendente y UUID lexicográfico como desempate.
Cada iteración lee los valores activos de la iteración anterior y reconstruye
efectos sobre el estado inicial; una condición que deja de cumplirse retrae sus
efectos. Mayor prioridad se aplica después. Los ancestros ocultos/inactivos hacen
inactivos a sus descendientes. Valores inactivos se leen como null y no salen en
el resultado aceptado. Los cambios de valor/opciones requieren estabilización;
un estado repetido o el límite de 64 iteraciones generan error de configuración.
Los operadores numéricos usan decimales exactos; igualdad de texto no convierte
`01` en `1`. Los fixtures compartidos PHP/JS forman parte del contrato.

La compilación valida los operandos numéricos antes de publicar: admite enteros
JSON y cadenas decimales exactas, y rechaza flotantes JSON, exponentes, booleanos,
objetos y listas donde corresponde un escalar. `between` exige dos límites
ordenados. La igualdad y pertenencia conservan la posibilidad de comparar con
null; las comparaciones ordenadas requieren números. `empty` y `not_empty` no
requieren un operando numérico.

Los operadores externos pueden declarar `datatypes` para su compatibilidad con
campos existentes. Compiler y Builder suman esa compatibilidad a la declarada
por el Field Type; no modifica las capacidades de operadores core. Operadores
y efectos externos requieren el módulo de navegador versionado de ADR 0015.
Sus hooks reciben copias y se ejecutan dentro del mismo límite de estabilización;
un resultado inválido o no convergente deja indisponible esa instancia.


---

<!-- SOURCE: 08_OPTIONS_DATASOURCES_SPEC.md -->

# 08 — Options y Data Sources


> Proyecto: **Nicode Form Studio**  
> Estado del documento: **Especificación inicial normativa**  
> Plataforma objetivo: **Joomla 6.x**  
> Principios obligatorios: **SPEC-DRIVEN** y **DATA-DRIVEN**
>
> Convenciones: **MUST/DEBE** = requisito obligatorio; **SHOULD/DEBERÍA** = recomendado salvo causa documentada; **MAY/PUEDE** = opcional.  
> Todo cambio funcional deberá modificar primero o simultáneamente la especificación correspondiente y sus criterios de aceptación.


## 1. Option

Cada opción tendrá:

- ID/UUID;
- internal value;
- label;
- order;
- enabled;
- default flag;
- metadata opcional.

`label` y `value` nunca deben confundirse.

El valor de opción es una identidad literal: select, radio, button-group y sus
variantes múltiples conservan espacios, tabulaciones y ceros iniciales. No se
aplica trim ni conversión numérica; las selecciones múltiples eliminan únicamente
duplicados exactos. La pertenencia se comprueba contra las opciones habilitadas.

El flag `default` se aplica al render inicial si no existe un valor predeterminado
explícito del campo ni un valor confiable de prefill. Se elige la primera opción
habilitada y coincidente en campos simples, y todas las coincidentes en campos
múltiples, respetando el orden. Las dependencias se resuelven hasta converger.
No se repone automáticamente una selección que el visitante vació ni se corrige
un valor manipulado durante el submit. En campos de solo lectura, el servidor
recalcula ese valor inicial confiable. Los efectos de reglas conservan prioridad.

Los defaults explícitos y prefills constantes de selección se comprueban al
compilar contra los valores habilitados conocidos de opciones locales o snapshots
estáticos. Se incluyen opciones que puedan aportar reglas declarativas activadas.
Un valor imposible en todos esos conjuntos impide publicar. Esta comprobación
no demuestra disponibilidad en cada contexto: el servidor vuelve a validar la
pertenencia al enviar. Fuentes y efectos personalizados que calculan opciones
conservan esa validación de pertenencia en runtime.

Los defaults explícitos y prefills constantes de selección se comprueban al
compilar contra los valores habilitados conocidos de opciones locales o snapshots
estáticos. Se incluyen opciones que puedan aportar reglas declarativas activadas.
Un valor imposible en todos esos conjuntos impide publicar. Esta comprobación
no demuestra disponibilidad en cada contexto: el servidor vuelve a validar la
pertenencia al enviar. Fuentes y efectos personalizados que calculan opciones
conservan esa validación de pertenencia en runtime.

Ejemplo:

- label: `España`;
- value: `ES`.

## 2. Opciones locales

Adecuadas para listas propias de un formulario.

## 3. Option Sets

Recursos reutilizables:

- países;
- provincias;
- departamentos;
- especialidades;
- sí/no;
- clasificaciones internas.

Serán versionables.

Un FormSpec publicado debe apuntar a una versión determinada o incorporar snapshot suficiente para conservar semántica histórica.

Cada guardado de un Option Set crea una revisión inmutable con hash canónico y
control optimista de concurrencia. Sus opciones actuales son una proyección de
esa revisión. Los administradores con `formstudio.resources.manage` pueden
modificar recursos; quienes solo tengan `formstudio.forms.manage` pueden
consultarlos para componer formularios. Ambos requieren `core.manage`.
Las condiciones reutilizables usan nombres de parámetros; al aplicar una revisión
a un campo se enlazan explícitamente con UUIDs del formulario y se incorpora el
snapshot. Una revisión posterior del recurso no modifica ese snapshot.

## 4. Dependencias

Debe soportarse:

- País → Provincia;
- Provincia → Municipio;
- Categoría → Familia → Producto.

Una fuente puede depender de uno o varios campos.

Las fuentes de opciones se asignan a campos con datatype lógico `selection`,
incluidos los providers personalizados que declaren ese tipo. Los campos booleanos
simples, de texto, numéricos, temporales y de archivos no consumen una lista de
opciones: el compilador rechaza una fuente asignada a ellos con
`field.source.unsupported`, sin consultar la fuente. El prefill de valores escalares
conserva su contrato independiente.

Las fuentes de opciones se asignan a campos con datatype lógico `selection`,
incluidos los providers personalizados que declaren ese tipo. Los campos booleanos
simples, de texto, numéricos, temporales y de archivos no consumen una lista de
opciones: el compilador rechaza una fuente asignada a ellos con
`field.source.unsupported`, sin consultar la fuente. El prefill de valores escalares
conserva su contrato independiente.

## 5. Data Source Registry

Cada Data Source Provider declarará:

- identifier;
- config schema;
- input parameters;
- dependency parameters;
- output schema;
- value mapping;
- label mapping;
- cache capability;
- TTL;
- timeout;
- failure mode.

## 6. Fuentes iniciales

- static/local;
- OptionSet;
- entidades Joomla aprobadas;
- provider personalizado;
- HTTP/API provider futuro.

No habrá un textarea de SQL libre como funcionalidad estándar.

Las fuentes `joomla.categories` y `joomla.articles` consultan exclusivamente
categorías de `com_content` y artículos. Su alcance es una lista explícita de
categorías aprobadas: padres directos para categorías, categorías contenedoras
para artículos. Un parámetro opcional `category_field` elige una de ellas y debe
declararse como dependencia. Solo se devuelven entidades publicadas y accesibles
para los niveles de acceso e idioma del visitante, comprobando también sus
categorías antecesoras y el calendario de los artículos. El valor es el ID estable
de la entidad y la etiqueta su título. Nunca se exponen usuarios ni tablas libres.
El límite configurable de opciones es operativo: si se supera, la fuente falla
sin truncar silenciosamente. Estas fuentes se consultan de nuevo en cada petición
y no usan caché compartida, para revalidar cambios de acceso y publicación.

## 7. Seguridad

Un valor devuelto al navegador no se convierte por ello en confiable.

Al submit, el servidor deberá revalidar la opción contra la fuente correspondiente o contra snapshot/política válida.

Las fuentes no embebidas se resuelven mediante `form.options`, exclusivamente
por POST y con CSRF nativo, token de instancia ligado a sesión/canal y la versión
publicada vigente. Se comprueban acceso, idioma, calendario y dependencias de
providers antes de consultar. La respuesta no se cachea públicamente y contiene
solo valores, etiquetas y habilitación de campos activos; nunca configuración
privada del provider. El límite operativo de consultas es 120 por minuto y
formulario/contexto de visitante, independiente del límite de envíos.

## 8. Caché

Cada provider declara si admite cache y qué elementos del contexto forman parte de la cache key.

No se cachearán resultados dependientes de información sensible de distintos usuarios bajo una clave compartida incorrecta.

La clave incluye provider, versión, configuración, entradas declaradas, contexto
declarado y TTL. El adaptador nativo usa el backend configurado en Joomla y respeta
su activación; conserva una caché de petición si el backend no está disponible.
Cada entrada contiene su caducidad en segundos y se rechaza exactamente al vencer,
aunque la limpieza física del backend sea posterior. Solo se almacenan valores JSON.

La aceptación del backend nativo de archivos incluye procesos PHP independientes:
lectura/escritura en ambos sentidos, borrado visible para una instancia nueva y
rechazo en el segundo exacto de caducidad. La prueba usa claves sintéticas aisladas;
no equivale a aceptación de todos los backends admitidos por Joomla.

## 9. Errores

Políticas posibles:

- fail closed y mostrar error;
- lista vacía;
- fallback definido;
- retry limitado para llamadas remotas.

La semántica deberá configurarse explícitamente.

## 10. Recursos Data Source

Una fuente configurada en un borrador guardado puede capturarse como recurso
reutilizable. La captura aplica el contrato de portabilidad y elimina credenciales
antes de guardarla. Sus dependencias se presentan como parámetros nombrados y
se vinculan explícitamente a campos compatibles del formulario destino. Solo se
remapean referencias declaradas por el provider; los valores literales no cambian.

Aplicar una revisión copia la configuración validada y su procedencia al borrador.
No consulta el recurso mutable al renderizar ni modifica formularios ya publicados.
Actualizar o desactivar el recurso requiere permisos de recursos y control de
revisión. Desactivar impide nuevas aplicaciones, conservando la semántica de las
copias existentes. Las configuraciones se editan con el editor de su provider en
un formulario y se recapturan como nueva revisión del recurso.

Los defaults de opciones se eligen sobre el conjunto final tras aplicar filtros o
sustituciones por reglas. Un efecto que asigna o vacía el valor conserva su
prioridad, incluso si coincide con el valor anterior; no se repara mediante un
default una asignación explícita inválida. Esta evaluación también se aplica al
recalcular campos de solo lectura en el servidor. La verificación del navegador
para cambios interactivos posteriores se registra separadamente.

El runtime público recibe `option_defaults` únicamente para selecciones derivadas
de solo lectura sin override confiable. Las marcas `default` viajan en snapshots
y respuestas de opciones. El motor del navegador recalcula esos valores con las
opciones finales, respetando filtros, dependencias y efectos explícitos de valor.
Los campos editables no reciben esta instrucción y conservan su vaciado manual.

Las respuestas de opciones remotas se aplican de forma atómica para todos los
campos: una lista inválida conserva el estado anterior y exige reintento. El
cliente exige marcas `default` booleanas si están presentes y rechaza valores
de opción duplicados por identidad literal; distingue mayúsculas y minúsculas.
Las respuestas de peticiones anteriores no sustituyen el estado más reciente.

El disparo de consultas remotas observa las dependencias declaradas por fuentes,
los campos referenciados en condiciones de reglas y prefills de campo, y la
activación de los destinos remotos. Cambiar una respuesta ajena a ese conjunto
no inicia una nueva consulta. Se observan conservadoramente todas las condiciones
para conservar la semántica de reglas; el reintento explícito no exige cambios.
Esta selección afecta al disparo de consultas, no redefine el payload normalizado
ni las comprobaciones autorizadas que realiza el servidor.


---

<!-- SOURCE: 09_SUBMISSION_SPEC.md -->

# 09 — Submission Engine


> Proyecto: **Nicode Form Studio**  
> Estado del documento: **Especificación inicial normativa**  
> Plataforma objetivo: **Joomla 6.x**  
> Principios obligatorios: **SPEC-DRIVEN** y **DATA-DRIVEN**
>
> Convenciones: **MUST/DEBE** = requisito obligatorio; **SHOULD/DEBERÍA** = recomendado salvo causa documentada; **MAY/PUEDE** = opcional.  
> Todo cambio funcional deberá modificar primero o simultáneamente la especificación correspondiente y sus criterios de aceptación.


## 1. Responsabilidad

El Submission Engine recibe una petición y decide si genera una Submission válida, consistente, persistida según política y procesada por Actions.

## 2. Pipeline normativo

1. resolver Form y FormVersion;
2. comprobar disponibilidad;
3. comprobar access level/usuario;
4. comprobar origen/contexto;
5. comprobar CSRF cuando proceda;
6. ejecutar política CAPTCHA/anti-spam;
7. comprobar límites/rate policies;
8. extraer payload permitido;
9. normalizar;
10. ejecutar Rule Engine servidor;
11. resolver opciones activas;
12. validar campos;
13. validar relaciones entre campos;
14. validar y almacenar temporalmente uploads;
15. generar idempotency/attempt semantics;
16. persistir Submission si procede;
17. persistir índice de búsqueda si procede;
18. finalizar archivos;
19. ejecutar Action Engine;
20. calcular respuesta post-submit;
21. registrar observabilidad/auditoría necesaria.

## 3. Modos de persistencia

Cada formulario podrá definir:

- almacenar submission completa;
- no almacenar respuestas tras procesamiento;
- almacenar solo metadata mínima;
- política especial aprobada.

El valor predeterminado del producto se definirá en configuración global y podrá sobrescribirse por Form.

La opción global `default_persistence` admite full (predeterminado), metadata y
none. Se copia al borrador al crear un formulario. Cambiarla no modifica borradores
existentes ni versiones publicadas; cada formulario conserva su elección explícita
y el editor permite cambiarla antes de publicar una nueva versión. Duplicaciones
e importaciones conservan la política de la definición copiada. Un valor global
inválido se rechaza, sin convertirlo silenciosamente en almacenamiento completo.

## 4. Submission canónica

Cuando se almacene, debe preservar:

- Submission UUID;
- Form;
- FormVersion;
- timestamp;
- valores normalizados;
- labels/metadata necesarios o resolubles desde snapshot;
- archivos;
- contexto permitido;
- resultado de procesamiento;
- Action status.

## 5. Idempotencia

El sistema debe mitigar dobles envíos accidentales.

Cada render/attempt podrá incorporar un token/identifier.

La idempotencia debe distinguir:

- retry técnico de la misma petición;
- una segunda submission legítima.

## 6. AJAX y no AJAX

Ambos modos usarán el mismo pipeline.

AJAX solo cambia el transporte/presentación de la respuesta.

Un rechazo conocido del servidor debe mostrar su mensaje localizado también en
AJAX (por ejemplo, sesión caducada o límite de frecuencia). No debe convertirse
en un error genérico de red. El cliente conserva los valores y el attempt para
permitir la recuperación; solo un envío aceptado aplica reset, hide, redirect o
next_attempt. Una respuesta ilegible o un fallo de transporte mantiene el mensaje
de resultado no confirmado.

Un rechazo conocido del servidor debe mostrar su mensaje localizado también en
AJAX (por ejemplo, sesión caducada o límite de frecuencia). No debe convertirse
en un error genérico de red. El cliente conserva los valores y el attempt para
permitir la recuperación; solo un envío aceptado aplica reset, hide, redirect o
next_attempt. Una respuesta ilegible o un fallo de transporte mantiene el mensaje
de resultado no confirmado.

## 7. Estado y Action status

El éxito de persistencia y el éxito de las Actions son dimensiones diferentes.

Ejemplo:

- Submission guardada: sí.
- Email notificación: falló.
- Webhook: correcto.

La UI debe representar esa diferencia.

## 8. Formularios despublicados

El POST siempre vuelve a comprobar estado y permisos.

Haber renderizado anteriormente un formulario no concede derecho perpetuo a enviarlo.

Una cuenta bloqueada no recibe niveles de acceso para formularios, aunque su
sesión Joomla siga abierta. El contexto vuelve a consultar la identidad vigente
y el POST rechaza el formulario antes de persistir. La misma restricción se
aplica al renderizado y a los servicios públicos que reutilizan ese contexto.

## 9. Límites

Políticas futuras/initiales configurables:

- unlimited;
- one per user;
- one per session;
- global maximum;
- date window;
- optional custom limiter provider.

## 10. Contexto de canal

Se registrará de forma controlada si la submission vino de:

- component page;
- module;
- future content plugin;
- API futura.

Nunca se confiará en un channel enviado libremente por el navegador sin validación.

## Límites de tamaño del transporte

Si Content-Length supera el post_max_size efectivo de PHP, el controlador rechaza
el envío antes del pipeline con HTTP 413 y request_too_large. El mensaje indica
que la respuesta no se guardó y propone reducir archivos/texto o contactar con la
administración. No expone límites internos ni datos enviados. El envío mejorado
recibe JSON según su cabecera Accept aunque PHP haya descartado el campo format;
el envío ordinario recibe HTML. La comprobación de tamaño no autoriza la petición
ni sustituye CSRF. Los recortes por variables o partes sin evidencia suficiente
para clasificarlos conservan el rechazo de sesión y no persisten datos.


---

<!-- SOURCE: 10_ACTION_ENGINE_SPEC.md -->

# 10 — Action Engine


> Proyecto: **Nicode Form Studio**  
> Estado del documento: **Especificación inicial normativa**  
> Plataforma objetivo: **Joomla 6.x**  
> Principios obligatorios: **SPEC-DRIVEN** y **DATA-DRIVEN**
>
> Convenciones: **MUST/DEBE** = requisito obligatorio; **SHOULD/DEBERÍA** = recomendado salvo causa documentada; **MAY/PUEDE** = opcional.  
> Todo cambio funcional deberá modificar primero o simultáneamente la especificación correspondiente y sus criterios de aceptación.


## 1. Concepto

Después de una Submission válida pueden ejecutarse cero, una o múltiples Actions.

Cada Action tendrá:

- UUID;
- type;
- enabled;
- order;
- condition;
- config;
- failure policy;
- retry policy cuando sea compatible.

## 2. Actions iniciales

- persist submission;
- email notification;
- email autoresponse/acknowledgement;
- redirect/post-submit navigation;
- webhook HTTP.

La persistencia puede modelarse como fase del pipeline y exponerse conceptualmente como Action/configuración; la implementación final deberá mantener atomicidad y semántica claras.

## 3. Actions futuras

El Registry permitirá:

- CRM;
- ERP;
- newsletter;
- Slack/Teams;
- user creation;
- content creation;
- ticketing;
- external API;
- custom providers.

## 4. Conditions

Una Action puede ejecutarse solo si se cumplen condiciones.

Ejemplo:

- si `tipo = comercial`, email a ventas;
- si `tipo = soporte`, email a soporte;
- si país = ES, webhook A;
- si país = PT, webhook B.

Las condiciones reutilizarán semántica compatible con Rule Engine sin permitir lógica arbitraria.

## 5. Failure policy

### Blocking

El fallo afecta al resultado global según transacción/política.

### Non-blocking

La Submission puede considerarse recibida; el fallo queda registrado y reintentable.

Cada tipo de Action debe declarar qué modos admite.

## 6. ActionRun

Cada ejecución registra:

- action;
- submission;
- attempt;
- started_at;
- finished_at;
- status;
- result code;
- mensaje técnico saneado;
- retry eligibility.

## 7. Reintentos

Debe permitirse reintentar una Action fallida compatible sin repetir las que ya finalizaron correctamente.

La administración ofrecerá:

- retry individual;
- retry de fallidas de una Submission;
- acciones masivas controladas futuras.

Los reintentos administrativos se encolan con los UUID de acción y números de
intento fallido observados. La reserva comprueba ese intento dentro del bloqueo
de la respuesta: repetir un job tras perder su checkpoint no crea otro intento
del mismo fallo. Solo participan proveedores que declaran compatibilidad con
reintentos de fallos confirmados. Resultados correctos o inciertos no se repiten.
Tras recuperar una acción bloqueante pueden ejecutarse acciones posteriores que
aún no empezaron, respetando el orden original. La autorización se comprueba al
encolar y al ejecutar; la auditoría guarda identificadores, nunca los valores de
la respuesta. La reserva de una Action vuelve a rechazar respuestas anonimizadas,
incluso si la anonimización ocurrió después de cargar el contexto.

## 8. Email Action

Configurable:

- to;
- cc;
- bcc;
- reply-to;
- subject;
- HTML;
- plain text;
- template;
- campos incluidos;
- attachments permitidos;
- conditions.

El remitente debe proceder de una configuración segura, no de una dirección arbitraria del visitante.

Un email de usuario validado podrá usarse como Reply-To.

El transporte de adjuntos recibe bytes mediante MailAttachment, nunca una ruta
de sistema de archivos ni una clave de almacenamiento suministrada por el
visitante. El nombre no admite separadores de ruta ni controles, debe ser UTF-8
y ocupar como máximo 255 bytes; el tipo MIME es un identificador sin parámetros.
Cada MailMessage admite hasta 20 adjuntos y 10 MiB de bytes originales sumados,
antes de la codificación MIME. Joomla los incorpora como adjuntos base64; un
fallo al prepararlos impide cualquier envío. Un transporte alternativo que no
soporte esta capacidad falla explícitamente. La selección del editor y la
autorización de archivos por campo usan los contratos descritos a continuación.

Para archivos persistidos, StoredMailAttachments resuelve la respuesta por su
referencia y exige el hash de su versión original y que no esté anonimizada.
Solo admite campos de archivo seleccionados explícitamente cuya política
histórica permita include_email; un campo sensible requiere ese permiso explícito.
El recibo canónico determina la identidad y el orden de archivos, incluida la
instancia repetida. La fila de almacenamiento debe pertenecer a esa respuesta y
dirección, y coincidir en nombre, MIME y tamaño. Se lee como máximo el tamaño
declarado más un byte y se verifica SHA-256 antes de devolver los bytes. Fallos
de propiedad, integridad, límites o almacenamiento producen un código seguro
`mail_attachment_unavailable`. Los archivos efímeros usan la capacidad de
ejecución StagedMailAttachments descrita a continuación.

EmailAction acepta internamente `attachment_fields`, una lista de hasta 20 UUIDs
distintos, y un MailAttachmentResolverInterface inyectado. Una selección vacía
no consulta el resolver. Una selección no vacía sin resolver o con fallo de
resolución produce `mail_attachment_unavailable` antes de invocar el transporte.
El contrato solo entrega bytes autorizados, sometidos también a los límites de
MailMessage. RuntimeProvider inyecta el resolver persistido de forma diferida
para evitar inicializar almacenamiento durante el descubrimiento de acciones.
El compilador exige referencias locales a file/multiple-files con política
include_email permitida, con diagnóstico en cada posición de la lista.
La duplicación remapea esos UUID y la exportación/importación conserva su orden.
Una importación con política incompatible sigue siendo editable como borrador,
pero no es una definición válida para publicar. El esquema de correo expone
attachment_fields mediante un selector de casillas con etiquetas de campo.
Solo ofrece nuevas selecciones de archivo permitidas por include_email. Las
selecciones eliminadas o excluidas siguen visibles como no disponibles y pueden
retirarse explícitamente; el editor no las descarta al abrir el borrador.
El límite de 20 campos se aplica al añadir, y el servidor verifica además el
límite real de archivos y bytes cuando resuelve los recibos.

En una ejecución nueva, SubmissionPipeline añade a ActionContext una capacidad
StagedMailAttachments construida con los archivos recibidos por HttpUploadGateway,
los valores validados y la referencia de la respuesta. Esta capacidad conserva
el orden canónico de recibos y filas, verifica propiedad, política histórica,
tamaño y SHA-256, y se transmite a cada acción con forAction. EmailAction usa
esa capacidad antes del resolver persistido. No se reconstruye a partir de datos
del visitante ni se crea para replays o reintentos. Los archivos siguen sujetos
a la limpieza del finally del pipeline; no se prolonga su retención para correo.
El recorrido multipart ordinario se verifica tanto en un fixture HTTP aislado
como en el componente público Joomla instalado, con sesión y token nativos,
publicación normal, pipeline y acción real. La fábrica de correo del proceso de
prueba prepara y captura el MIME; nunca entrega mensajes externos. El selector
visual también se verifica al guardar y recargar. Los adjuntos repetidos se
verifican mediante el fixture interno existente: orden de declaraciones incluso
al invertir filas, orden de archivos dentro de cada fila, exclusión de campos
inactivos, replay sin reenvío y rechazo de propiedad entre filas. Esta evidencia
no habilita la publicación normal de grupos repetibles, que conserva su bloqueo.

Un reintento solo resuelve adjuntos de campos persistidos en modo full. En modo
metadata/none o con persist=false, la ausencia de datos no demuestra que el
mensaje original careciera de archivos: se rechaza con
`mail_attachment_unavailable` antes del transporte. La pérdida del objeto
persistido también produce ese fallo definido; nunca se envía silenciosamente
un mensaje al que le falten los adjuntos seleccionados. La versión y política
de la respuesta original gobiernan la recuperación, aunque cambie la publicación.

Para campos email repetidos, `email_field_selection` y
`reply_to_field_selection` seleccionan explícitamente `first_nonempty`,
`last_nonempty` o `unique`. El orden es el de las declaraciones de filas del
snapshot de la respuesta, incluidos sus ancestros. Se omiten valores ausentes,
nulos o vacíos; cualquier otro valor inválido impide el envío. `unique` exige una
sola dirección distinta mediante comparación exacta. Sin candidatos, con varias
direcciones bajo `unique` o sin política explícita se registra un fallo de Action.
Cada Action envía un solo mensaje y selecciona una sola dirección de ese campo;
no se escoge una fila implícitamente ni se envía un mensaje por fila. Los campos
email sin repetición conservan su comportamiento. La política queda versionada en
la configuración de la Action y se aplica también al reintentar.

## 9. Tokens

Las plantillas usarán tokens declarativos:

- form;
- submission;
- date;
- user;
- field value;
- selected option label;
- response summary.

No PHP ejecutable.

## 10. Webhook

Debe contemplar:

- URL permitida/configurada;
- method;
- headers seguros;
- payload mapping;
- timeout;
- firma opcional;
- retry;
- bloqueo SSRF;
- logging sin secretos.

Las cabeceras personalizadas `X-*` tienen nombres únicos sin distinguir
mayúsculas. `X-FormStudio-Signature` y `X-FormStudio-Timestamp` están reservadas al
firmado del runtime, incluso cuando una acción no configura firma. El compilador
y la validación previa a ejecutar rechazan esas colisiones. El transporte HTTP
también rechaza nombres duplicados sin distinguir mayúsculas antes de resolver
DNS o iniciar una conexión.

La preparación de un webhook limita el cuerpo JSON resultante a 1048576 bytes y
cada valor de cabecera expandido a 8192 bytes. El exceso produce
`webhook_request_limit` antes de consultar secretos o invocar el transporte.
Tokens no disponibles, controles en cabeceras o datos que no puedan codificarse
producen `webhook_preparation_failed`. Un secreto Bearer debe ser no vacío, sin
controles ni espacios y caber en la cabecera junto a su prefijo; su rechazo usa
`secret_unavailable` sin revelar el valor. Los errores del transporte permanecen
fuera del bloque de preparación y conservan su semántica de resultado incierto.

## 11. Orden

Las Actions se ejecutan en orden definido y la política decidirá si un fallo blocking detiene el resto.

La semántica debe ser visible en Administrator.

## 12. Email Templates reutilizables

Un Email Template contiene nombre, idioma, asunto y cuerpos de texto/HTML. No incorpora destinatarios, credenciales ni acciones ejecutables. Usa los tokens globales del motor y parámetros de campo nombrados, por ejemplo `{{input.contact.value}}`, `{{input.contact.label}}` y `{{input.contact.option_label}}`. Al aplicarlo a un formulario, el administrador vincula cada nombre a un campo incluido en correo; nunca se permiten contraseñas ni campos sensibles excluidos.

Aplicar una plantilla copia sus textos y convierte los parámetros a UUID de campo. La copia conserva procedencia (UUID, revisión y hash del recurso), sin consulta viva durante el envío. Editar el recurso no modifica borradores ya configurados ni versiones publicadas. El idioma permite aplicar el contenido como texto base o como traducción explícita de la acción. Las revisiones del recurso usan concurrencia optimista y la aplicación comprueba la revisión seleccionada antes de devolver la copia.

## 13. Preparación y entrega de correo

El adaptador Joomla comprueba el resultado de configurar remitente, destinatarios,
Reply-To, asunto y cuerpo. Un rechazo explícito o una excepción antes de invocar
send aborta el mensaje completo con `mail_preparation_failed`, como fallo
conocido sin intento de entrega. No se envía un mensaje parcialmente preparado.
Los destinatarios duplicados, comparados sin distinguir mayúsculas como en
PHPMailer, conservan su primera aparición en To, Cc y Bcc. Reply-To es independiente.

Una vez invocado send, false o excepción mantiene `mail_delivery_unknown` y no
se convierte en fallo definitivamente reintentable. La excepción específica de
correo deshabilitado conserva `mail_disabled`. Los códigos no incluyen detalles
de configuración, direcciones ni mensajes de excepción del proveedor.

EmailAction aplica la misma separación antes de invocar el transporte: valida
la configuración y prepara tokens, destinatarios y MailMessage. Una excepción
durante esa preparación se registra como `mail_preparation_failed`, conservando
los códigos específicos de selección de destinatario. La llamada al transporte
queda fuera de ese bloque: un error de un transporte externo no se reclasifica
como fallo previo al envío.

Los límites se comprueban durante la expansión: TokenTemplate acepta un límite
opcional en bytes y rechaza cada fragmento antes de concatenarlo. WebhookAction
contabiliza incrementalmente el JSON con claves, separadores y valores escapados;
no acumula todos los valores expandidos antes de descubrir un exceso. UTF-8 se
mide por bytes y el contenido escapado de JSON también consume presupuesto.


---

<!-- SOURCE: 11_ADMIN_UX_SPEC.md -->

# 11 — Administrator UX

> Proyecto: **Nicode Form Studio**  
> Estado del documento: **Especificación inicial normativa**  
> Plataforma objetivo: **Joomla 6.x**  
> Principios obligatorios: **SPEC-DRIVEN** y **DATA-DRIVEN**
>
> Convenciones: **MUST/DEBE** = requisito obligatorio; **SHOULD/DEBERÍA** = recomendado salvo causa documentada; **MAY/PUEDE** = opcional.  
> Todo cambio funcional deberá modificar primero o simultáneamente la especificación correspondiente y sus criterios de aceptación.

## 1. Menú principal

`Nicode Form Studio`

- Panel de control
- Formularios
- Envíos
- Recursos
- Registros
- Configuración
- Información del sistema

## 2. Dashboard

Debe mostrar información operativa, no solo accesos:

- formularios publicados;
- formularios despublicados;
- borradores;
- submissions recientes;
- submissions 7/30 días;
- spam;
- errores;
- Actions fallidas;
- emails fallidos;
- webhooks fallidos;
- formularios inválidos;
- últimas modificaciones;
- volumen de almacenamiento;
- jobs/exportaciones en curso o pendientes cuando exista subsystem de jobs.

Alertas:

- Action incompleta;
- Data Source desactivada;
- CAPTCHA seleccionado no disponible;
- versión con warning;
- ficheros huérfanos;
- errores repetidos;
- índice de búsqueda pendiente.

El panel es la entrada predeterminada del componente. Los recuentos y las diez
respuestas recientes abarcan únicamente formularios con permiso de consulta de
respuestas; los estados y las diez modificaciones recientes requieren gestión
del formulario. Los diagnósticos de borrador requieren además edición. Las cifras
de 7/30 días usan UTC y una fecha de corte común. El volumen corresponde a bytes
de archivos registrados, no al espacio libre del servidor. Los jobs siguen el
mismo alcance que su listado (propios o todos con permiso de gestión). Los errores
técnicos y recursos globales exigen sus permisos respectivos.

Los agregados no leen payloads ni nombres de archivos. Se recorren metadatos de
formularios en lotes de cien y se conserva un máximo de diez filas por lista.
La comprobación de definiciones usa el compilador, CAPTCHA y prerrequisitos de
publicación actuales, sin ejecutar Actions ni consultar servicios externos. Un
diagnóstico que no puede completarse aparece como no disponible, nunca como sano.
El panel muestra el instante de observación: sus consultas independientes no
pretenden ser una instantánea transaccional de toda la instalación.

Un trabajo en curso con lease caducada (o ausente) se señala como bloqueado para
revisar su recuperación. Tres o más eventos técnicos ERROR del mismo tipo en los
últimos siete días producen una alerta de repetición (hasta diez tipos). Las
alertas de almacenamiento, cron, retención y proveedores reutilizan los health
checks autorizados y enlazan a Información del sistema; omiten rutas y detalles
internos. Los fallos de Actions cuentan intentos fallidos, incluidos los que
posteriormente se reintentaron, y no se presentan como entregas únicas perdidas.

## 3. Formularios — listado

Columnas mínimas:

- selección;
- state;
- name;
- alias;
- ID;
- published version;
- field count;
- submission count;
- modified;
- author;
- language;
- access.

El recuento de campos corresponde al borrador guardado. El recuento de respuestas
cuenta filas persistidas, sin duplicar reintentos idempotentes, y se oculta si el
actor no tiene `formstudio.submissions.view` en ese formulario. Ambos se agregan
solo para los formularios autorizados de la página visible mediante índices por
`form_id`; no se leen payloads de respuestas para construir el listado.

Filtros:

- search;
- state;
- language;
- access;
- author;
- tag/categoría administrativa futura.

Acciones:

- new;
- edit;
- publish;
- unpublish;
- duplicate;
- archive;
- trash;
- export;
- import.

Archivar y mover a la papelera son estados recuperables: desactivan los envíos
públicos y conservan borrador, versiones, respuestas y archivos. El editor ofrece
ambas acciones con una confirmación que explica el efecto y la recuperación;
papelera exige además `core.delete`. Despublicar recupera un estado inactivo
ordinario; compilar y publicar activa una nueva versión. Toda transición compara
la revisión leída para evitar sobrescribir cambios concurrentes.

La selección del listado se limita a la página visible (máximo 100 formularios).
Publicar, despublicar, archivar y enviar a la papelera admiten selección múltiple;
la confirmación enumera los nombres e IDs afectados. El lote incluye la revisión
leída de cada formulario y es atómico: un permiso denegado, borrador inválido o
revisión obsoleta revierte todo el lote. No hay borrado permanente masivo ni
selección implícita de otras páginas. El servidor limita y valida la selección,
bloquea filas en orden de ID y reutiliza compilación, permisos y auditoría de las
operaciones individuales. Tras un éxito se actualizan estado, versión y revisión
visibles; ante un conflicto se mantiene la selección para revisar o recargar.

## 4. Editor de formulario

Pestañas/áreas:

- General
- Constructor
- Lógica
- Acciones
- Datos y privacidad
- Presentación
- Publicación
- Permisos
- Versiones
- Vista previa
- Validación/diagnóstico

## 5. Constructor

Tres áreas principales:

### Paleta
Tipos disponibles.

### Canvas
Estructura.

### Inspector
Propiedades del elemento seleccionado.

Las ediciones numéricas inválidas se conservan como cambios pendientes, también
al cambiar de selección y reconstruir el inspector. No se restaura en silencio
el valor anterior. Los enteros seguros se guardan como números; las fracciones,
valores no representables y entradas incompletas conservan un valor inválido
identificable, sin redondearlos a otro entero. Vaciar explícitamente un control
opcional elimina la propiedad. Los controles visibles inválidos bloquean las
operaciones; guardar un borrador incompleto desde otra selección sigue permitido,
pero el compilador debe impedir su publicación y localizar la propiedad afectada.

Debe haber vista árbol para formularios complejos.

El builder adapta sus columnas al ancho disponible dentro de Administrator,
incluido el espacio ocupado por el menú lateral. Con 48rem o menos de espacio
útil apila paleta, árbol e inspector. Los botones de la paleta aprovechan el
ancho de su panel y permiten saltos de línea sin heredar márgenes internos
excesivos de los grupos desplegables de la plantilla.

## 6. Publicación

`Publish` ejecuta `Compile & Publish`.

Los errores de compilación se presentan con:

- código;
- descripción;
- elemento afectado;
- enlace/navegación al elemento cuando sea posible.

Localizar abre la tarjeta correspondiente para diagnósticos de reglas, acciones
y validadores, incluidos validadores de campo. El foco se coloca después de que
el panel haya reconstruido sus controles. Cambiar el borrador retira los
diagnósticos anteriores para evitar enlaces a índices que ya no corresponden.
Para rutas traducidas, Localizar abre el idioma existente afectado y el control
traducible identificado; si no se puede resolver el control, enfoca el selector
de idioma sin crear ni modificar traducciones.

## 7. Versiones

Listado:

- revision;
- date;
- author;
- comment;
- state;
- submission count;
- compare;
- preview;
- restore.

`Restore` crea nuevo draft.

## 8. Envíos — diseño de alto volumen

La vista de submissions debe estar pensada para cientos o millones de filas.

Debe permitir:

- selector de Form;
- date range;
- state;
- processing/action state;
- user;
- channel;
- free search cuando el SearchProvider lo soporte;
- filtros por campos indexados del formulario;
- filtros guardados;
- columnas configurables;
- orden por columnas autorizadas;
- navegación eficiente;
- selección de filas;
- detalle en panel/página;
- exportación según filtro;
- acciones masivas seguras.

No debe intentar cargar todas las respuestas ni construir una tabla con todos los campos de todos los formularios simultáneamente.

## 9. Vista contextual por formulario

Al seleccionar un formulario:

- la tabla puede mostrar columnas relevantes de ese Form;
- aparecen sus campos indexables como filtros;
- se usan labels de la versión/contexto actual con indicación histórica cuando haga falta;
- se puede abrir la Submission conservando la interpretación de su FormVersion.

## 10. Detalle de Submission

Áreas:

- Overview
- Respuestas
- Archivos
- Actions
- Historial
- Notas
- Datos técnicos autorizados

Debe mostrar:

- Submission ID/UUID;
- Form;
- FormVersion;
- received_at;
- estado;
- usuario si procede;
- canal;
- respuestas agrupadas según layout histórico;
- ActionRuns;
- errores;
- auditoría.

Actions, notas y auditoría disponen de paginación independiente por ID descendente,
con 100 registros por página. Los cursores siempre se aplican a la respuesta y
formulario autorizados, sin OFFSET; navegar un historial mantiene la posición de
los otros. Volver a los más recientes no revela automáticamente datos sensibles.
Los detalles de auditoría usan la misma lista de propiedades técnicas permitidas
que el visor global. El consentimiento muestra decisión, texto, fecha y versión
almacenados con la respuesta; respeta el enmascaramiento y la revelación auditada.

## 11. Acciones masivas

El estado administrativo de respuesta usa `new`, `viewed`, `reviewed`, `processed`,
`error`, `archived` y `spam` (ADR-0020);
es independiente de `action_status`. Cambiarlo exige gestión de respuestas y
compara el estado leído bajo bloqueo transaccional: un cambio concurrente
devuelve conflicto y nunca sobrescribe silenciosamente el estado actual.
Las notas internas son texto plano (máximo 16000 bytes UTF-8), con autor y fecha
del servidor. Se audita su identificador, nunca su contenido. No se añaden notas
a una respuesta anonimizada. Cambios de estado y notas exigen POST y CSRF nativo.

Como mínimo:

- cambiar estado;
- archive;
- export;
- anonymize cuando proceda;
- delete conforme ACL/política.

Para volúmenes grandes, una acción masiva deberá poder ejecutarse como job por criterio/filtro, no enviando millones de IDs en el navegador.

## 12. Recursos

Subsecciones:

- Option Sets;
- Data Sources;
- Email Templates;
- Form Templates.

Email Templates permite editar textos e idioma, declarar tokens de campos por nombre y aplicar una revisión concreta con vínculos explícitos a campos. Form Templates captura una definición portable desde un borrador guardado y crea formularios independientes mediante la misma revisión previa y remapeo de identidades que la importación. Editar o aplicar recursos respeta respectivamente `formstudio.resources.manage` y los permisos del formulario. Una plantilla no contiene respuestas, historial de envíos ni credenciales, ni publica automáticamente el nuevo formulario.

## 13. Registros

Separar:

- technical logs;
- audit log;
- Action failures;
- job history.

## 14. Configuración

Secciones:

- General
- Submissions
- Search/index
- Security
- CAPTCHA/anti-spam defaults
- Email
- Files
- Retention
- Logs
- Performance
- Development/diagnostics

## 15. Información del sistema

Mostrar:

- FormStudio package/component/module/library version;
- DB schema version;
- supported FormSpec versions;
- Joomla version;
- PHP version;
- DB driver;
- mail availability;
- upload storage;
- cache;
- CAPTCHA providers detectados;
- filesystem permissions;
- cron/scheduler/CLI capabilities si se usan;
- health checks.

## Eliminación definitiva desde papelera

El editor ofrece eliminación definitiva sólo a quien puede borrar el formulario
Y sus respuestas. La revisión previa muestra identidad, respuestas, archivos y
bytes aproximados. La confirmación consume la revisión y crea un job irreversible;
no se ofrece cancelación del job. El estado `deleting` queda visible en el listado
y abre un editor bloqueado con acceso a reanudar la eliminación si falló. La
confirmación puede cancelarse antes de crear el job sin modificar datos. Los
archivos se limpian mediante jobs independientes que sobreviven al formulario.

El historial pagina por revisión (50 filas) y cuenta respuestas sólo para las
versiones de la página. El contador requiere permiso de lectura de respuestas;
un autor sin ese permiso sigue pudiendo comparar, previsualizar y restaurar.
Activa identifica la versión seleccionada de un formulario publicado; las demás
son históricas, salvo revocación explícita. Despublicar no borra snapshots.

La preview y el render público del componente/módulo comparten la normalización
inicial del prefill. Un valor externo mal formado o fuera de las restricciones se
presenta vacío; la obligatoriedad se comprueba al validar. Los errores de
normalización de defaults configurados no se silencian. La comparación nativa de
texto, entero y correo cubre valores y atributos de restricciones; la matriz
completa de proveedores y presentación continúa pendiente (UX-001).

La preview puede actualizar opciones remotas mediante POST administrativo con
CSRF y permiso de edición sobre el formulario. Cada consulta fija revisión de
borrador o versión histórica y locale; una revisión obsoleta devuelve conflicto.
El contexto de usuario y niveles de acceso procede de Joomla. Los valores del
iframe se normalizan y se recalculan los campos de solo lectura antes de resolver
fuentes; no se ejecuta el pipeline de envío, CAPTCHA ni Actions. La respuesta
solo contiene value, label, enabled y default. El runtime padre mantiene el
iframe sin scripts propios, aplica respuestas actuales y ofrece reintento ante
fallos; el botón de envío permanece desactivado.


---

<!-- SOURCE: 12_FRONTEND_SPEC.md -->

# 12 — Frontend

> Proyecto: **Nicode Form Studio**  
> Estado del documento: **Especificación inicial normativa**  
> Plataforma objetivo: **Joomla 6.x**  
> Principios obligatorios: **SPEC-DRIVEN** y **DATA-DRIVEN**
>
> Convenciones: **MUST/DEBE** = requisito obligatorio; **SHOULD/DEBERÍA** = recomendado salvo causa documentada; **MAY/PUEDE** = opcional.  
> Todo cambio funcional deberá modificar primero o simultáneamente la especificación correspondiente y sus criterios de aceptación.

## 1. Publicación como página

El componente expondrá un Menu Item Type:

`Nicode Form Studio → Formulario`

Parámetro principal:

- `Formulario`: selector dinámico de formularios utilizables.

El Item de menú almacena el identificador del formulario, no una copia de sus campos.

El selector nativo es compartido por menú y módulo. Muestra formularios publicados
dentro del permiso administrativo `formstudio.forms.manage`; no expone títulos de
formularios fuera de ese ámbito. Conserva un ID previamente elegido aunque deje
de estar disponible y lo indica sin revelar su título. No impone un máximo de
formularios; recorre las páginas del servicio autorizado. La comprobación de
publicación, ventanas temporales, idioma y acceso del visitante sigue siendo
obligatoria en el runtime, independientemente de las opciones del selector.

## 2. Publicación como módulo

`mod_nicode_form_studio`

Parámetro principal:

- Formulario.

Parámetros secundarios, exclusivamente de contexto/presentación:

- mostrar título;
- mostrar descripción;
- class suffix/controlado;
- layout;
- comportamiento si no está disponible.

El módulo NO puede redefinir reglas, destinatarios, validadores o estructura.

`show_form_title` y `show_description` muestran el nombre y descripción traducidos
del snapshot publicado; están desactivados por defecto. El título propio del
módulo conserva el control nativo de Joomla. `form_class` acepta hasta diez nombres
de clase de 64 caracteres, con letras, números, guion o guion bajo y comienzo no
numérico; descarta tokens inválidos. `layout` usa los layouts y overrides nativos
de módulo. `unavailable_mode` elige omitir el contenido (predeterminado) o mostrar
un mensaje genérico, sin revelar título ni descripción del formulario inaccesible.

## 3. Form no disponible

Si un Menu Item o module referencia un Form:

- unpublished;
- fuera de fechas;
- sin permiso;
- inexistente;

el runtime aplica una política definida:

- 404;
- mensaje de indisponibilidad;
- módulo no renderizado.

El POST se rechaza independientemente de lo que hubiera mostrado una caché antigua.

## 4. Múltiples instancias

Cada render obtiene `instance_id`.

El DOM ID se deriva de:

- form identity;
- field identity;
- instance identity.

Dos instancias del mismo Form pueden coexistir sin:

- IDs duplicados;
- rules cruzadas;
- CAPTCHA namespace conflictivo;
- mensajes cruzados.

La inicialización JavaScript se aísla por instancia. Una definición o estructura
DOM inválida bloquea únicamente ese formulario con un mensaje traducido seguro;
no impide inicializar los siguientes. Los eventos de actualización de Joomla no
duplican listeners, incluso cuando el nodo actualizado es el propio formulario.
Un fallo de reglas durante interacción deshabilita envío y navegación hasta que
una reevaluación válida permite recuperarlos. No se muestran excepciones internas.

## 5. Assets

Se cargarán mediante Web Asset Manager.

Solo se activarán cuando exista una instancia que los necesite.

Dependencias declaradas en `joomla.asset.json`.

## 6. Presentación

Por defecto se hereda el template Joomla.

FormStudio aporta estilos mínimos estructurales.

Opciones:

- labels top/side;
- required marker;
- help position;
- spacing;
- columns;
- progress;
- validation summary;
- success/error container.

No se impondrá un framework CSS externo.

## 7. Submit

Soportar:

- traditional POST;
- AJAX.

Ambos contra controller/ruta Joomla y mismo Submission Engine.

## 8. Estados UX

Como mínimo:

- initial;
- validating;
- submitting;
- success;
- validation error;
- anti-spam/captcha error;
- transient processing error;
- unavailable.

## 9. JS

JavaScript se limitará a:

- client validation UX;
- Rule Engine cliente;
- dependent options;
- AJAX;
- progressive enhancement;
- accessibility behavior.

La seguridad y la verdad del estado permanecen en servidor.

## 10. Sin JS

Se definirá qué funcionalidades soportan fallback sin JavaScript.

Los formularios simples deberían poder enviarse; funcionalidades altamente dinámicas podrán requerir JS si la especificación lo declara, pero el sistema nunca confiará en JS para validar.

## Carga de assets tras renderizado

El componente solo activa CSS y JavaScript del runtime cuando obtiene HTML de un
formulario. Un fallo de renderizado con respuesta 503 y correlación no activa esos
assets por sí solo. Los módulos sanos de la misma página pueden necesitarlos y
Joomla Web Asset Manager los incluye una sola vez. Las páginas ajenas sin instancias
FormStudio no cargan sus assets; los assets administrativos no se activan en frontend.


---

<!-- SOURCE: 13_SECURITY_SPEC.md -->

# 13 — Seguridad


> Proyecto: **Nicode Form Studio**  
> Estado del documento: **Especificación inicial normativa**  
> Plataforma objetivo: **Joomla 6.x**  
> Principios obligatorios: **SPEC-DRIVEN** y **DATA-DRIVEN**
>
> Convenciones: **MUST/DEBE** = requisito obligatorio; **SHOULD/DEBERÍA** = recomendado salvo causa documentada; **MAY/PUEDE** = opcional.  
> Todo cambio funcional deberá modificar primero o simultáneamente la especificación correspondiente y sus criterios de aceptación.


## 1. Principios

- deny by default;
- server authoritative;
- least privilege;
- contextual escaping;
- typed validation;
- secrets out of FormSpec portable;
- no arbitrary executable code.

## 2. Amenazas a cubrir

- CSRF;
- XSS almacenado/reflejado;
- SQL injection;
- parameter tampering;
- option tampering;
- rule bypass;
- ACL bypass;
- direct POST a Form despublicado;
- malicious uploads;
- path traversal;
- email header injection;
- SSRF en webhooks/Data Sources;
- replay/double submit;
- brute spam;
- over-posting;
- mass assignment;
- information leakage;
- insecure direct object references;
- export abuse;
- formula injection en CSV;
- log injection;
- secret leakage.

## 3. Input

No se sobrescribirá `$_POST`.

Se extraerán exclusivamente keys esperadas según FormSpec.

Campos desconocidos serán ignorados o rechazados según política.

## 4. SQL

Consultas parametrizadas y APIs DatabaseInterface.

Los identificadores dinámicos deben proceder de allowlists internas; los valores se bindearán.

## 5. Output

Escaping contextual:

- HTML text;
- attribute;
- URL;
- JSON;
- email;
- CSV/export.

Safe HTML será una capacidad explícita y restringida de contenido administrativo, nunca una excusa para imprimir input del visitante sin escapar.

## 6. CSRF

Se utilizarán los mecanismos de token/sesión de Joomla donde el flujo de sesión lo permita.

CSRF y CAPTCHA son controles distintos.

## 7. ACL

Toda acción administrativa comprueba permiso en servidor.

Ocultar botones no sustituye autorización.

## 8. Uploads

- allowlist extension;
- MIME inspection;
- size;
- count;
- generated storage names;
- storage no público/predecible;
- no ejecución;
- download controller con ACL;
- optional malware scanner provider futuro.

La descarga de un archivo cuyo proveedor de almacenamiento ya no esté registrado
o cuyo objeto físico falte devuelve el mismo rechazo genérico de archivo no
disponible (404), sin ruta privada, identificador del proveedor ni cabeceras de
adjunto. No registra una descarga exitosa ni modifica su propiedad persistida.
Restaurar el proveedor u objeto permite recuperar el acceso sujeto a la ACL vigente.

La descarga de un archivo cuyo proveedor de almacenamiento ya no esté registrado
o cuyo objeto físico falte devuelve el mismo rechazo genérico de archivo no
disponible (404), sin ruta privada, identificador del proveedor ni cabeceras de
adjunto. No registra una descarga exitosa ni modifica su propiedad persistida.
Restaurar el proveedor u objeto permite recuperar el acceso sujeto a la ACL vigente.

## 9. Email

- sender configurado;
- Reply-To validado;
- no concatenar headers con input arbitrario;
- recipient sources controladas;
- tokens escapados según contexto.

## 10. Webhooks y HTTP Data Sources

Mitigación SSRF:

- esquemas permitidos;
- bloqueo de loopback/link-local/private cuando corresponda;
- redirects controlados;
- timeouts;
- límites;
- DNS/rebinding considerations en implementación;
- secretos no logados.

## 11. Exports

CSV debe mitigar formula injection para valores que comienzan con caracteres peligrosos según política de exportación.

La política del exportador CSV antepone un apóstrofo a toda celda cuyo primer
carácter sea `=`, `+`, `-`, `@`, un control o espacio ASCII (U+0000–U+0020),
o el BOM U+FEFF. Se aplica también a las etiquetas de encabezado. La escritura
CSV entrecomilla y escapa comas, comillas y saltos de línea, manteniendo cada
valor en su celda. Esta transformación pertenece exclusivamente al archivo CSV:
no modifica la respuesta canónica ni los valores de la exportación JSON.

Las exportaciones respetan ACL y datos sensibles.

## 12. CAPTCHA

FormStudio NO implementará un algoritmo CAPTCHA propio.

Se integrará con el sistema/provider de CAPTCHA Joomla según `25_JOOMLA_CAPTCHA_ANTISPAM_SPEC.md`.

## 13. Rate limiting

Si Joomla no ofrece un mecanismo genérico aplicable al caso, FormStudio podrá implementar un limiter propio como control anti-abuso, desacoplado mediante servicio/provider. No debe confundirse con CAPTCHA.

## 14. Logs

No registrar por defecto:

- password;
- tokens;
- secrets;
- payload completo;
- datos sensibles de campos.

## 15. Security headers

FormStudio no debe romper CSP u otras políticas del sitio mediante inline JS innecesario. Assets y scripts deben diseñarse para integrarse con la política del sitio.

### Ventanas y caducidad del limiter

La política publicada configura un máximo de intentos y una ventana fija. El
scope combina el formulario y un HMAC de la dirección de transporte validada;
no se confía en cabeceras Forwarded sin una política de proxy. Si no hay dirección
válida se usa el vínculo de sesión. No se guarda la dirección en claro. La consulta
de opciones usa un scope separado. El rechazo HTTP es 429 con Retry-After positivo.
La tarea horaria `rate-limit-cleanup` elimina sólo ventanas caducadas en lotes
transaccionales; nunca reinicia contadores activos. Una cola detenida retrasa esa
eliminación y debe detectarse mediante el estado del scheduler/jobs.


---

<!-- SOURCE: 14_PRIVACY_RETENTION_SPEC.md -->

# 14 — Privacidad, retención y datos sensibles

La eliminación y anonimización esperan a las acciones con lease vigente. Un lease
caducado se invalida como resultado desconocido antes de borrar los datos, para
que un proceso interrumpido no bloquee indefinidamente la retención. Un worker
tardío no puede confirmar ese intento ni sobrescribir el estado anonimizado.

> Proyecto: **Nicode Form Studio**  
> Estado del documento: **Especificación inicial normativa**  
> Plataforma objetivo: **Joomla 6.x**  
> Principios obligatorios: **SPEC-DRIVEN** y **DATA-DRIVEN**
>
> Convenciones: **MUST/DEBE** = requisito obligatorio; **SHOULD/DEBERÍA** = recomendado salvo causa documentada; **MAY/PUEDE** = opcional.  
> Todo cambio funcional deberá modificar primero o simultáneamente la especificación correspondiente y sus criterios de aceptación.

## 1. Configuración por Form

- store submissions;
- store authenticated user relation;
- IP storage policy;
- User-Agent policy;
- retention;
- automatic deletion;
- automatic anonymization;
- sensitive fields;
- export visibility;
- email inclusion.

## 2. Minimización

La recogida de IP y User-Agent estará desactivada por defecto salvo decisión explícita de producto/configuración.

No se debe almacenar información técnica "por si acaso".

Las opciones independientes `privacy.store_ip` y `privacy.store_user_agent`
aceptan únicamente booleanos y están desactivadas por omisión. El editor muestra
ambas opciones y su política de consulta. Solo se capturan al persistir una
respuesta en modo `full` o `metadata`; `none` no las recoge ni conserva.
La IP procede de `REMOTE_ADDR`, validada y normalizada como IPv4/IPv6; no se
confía en cabeceras reenviadas ni en valores POST. User-Agent procede de la
cabecera HTTP (declarada por el cliente, no prueba de identidad), exige UTF-8,
elimina controles ASCII y se limita a 512 bytes sin cortar caracteres.

El payload canónico 1.0 admite el objeto opcional `request_metadata` con `ip`
y/o `user_agent`. Su ausencia mantiene la representación anterior. Estos datos
no forman parte de valores de campos, índices, contexto de reglas, plantillas
de Actions ni exportaciones. La consulta ordinaria solo indica su existencia;
la revelación explícita exige `formstudio.submissions.view_sensitive` y registra
el evento `submission.reveal_sensitive`, sin incluir los datos en el log.
Su metadata contiene `fields` (número de campos sensibles revelados, excluyendo
los públicos) y `request_metadata_items` (número de datos técnicos revelados).
Las lecturas ordinarias o denegadas no generan eventos de revelación exitosa.
La anonimización elimina el objeto entero; la eliminación retira la respuesta.
Un reintento conserva los metadatos de la primera solicitud persistida: no
forman parte del fingerprint de respuestas, pues el transporte puede variar.

La regresión automatizada cubre normalización, límites, activación independiente,
recogida diferida, modos de persistencia, replay, ACL, auditoría, exclusión de
exportación y anonimización en MariaDB, MySQL y PostgreSQL. La prueba HTTP nativa
comprueba la precedencia del transporte frente a POST y cabeceras reenviadas.

## 3. Sensitive field

Un Field marcado como sensible:

- no aparece en technical logs;
- puede excluirse de emails;
- puede excluirse de exports;
- puede requerir permiso específico de visualización;
- puede tener retención distinta;
- puede quedar fuera del índice de búsqueda.

## 4. Consent Field

Debe registrar de manera interpretable:

- accepted/not accepted;
- consent text/version reference;
- timestamp de submission;
- FormVersion.

Así puede conocerse qué texto se mostró cuando se obtuvo el consentimiento.

## 5. Retention

Políticas:

- indefinite explícito;
- N días/meses/años;
- delete;
- anonymize.

Los jobs de retención deben ser idempotentes y auditables.

La fecha de caducidad se calcula al recibir la respuesta con la política de su
FormVersion; meses y años se ajustan al último día del mes de destino. La rutina
del programador recorre el índice de caducidad mediante cursor y encola trabajos
por formulario y versión. La operación procede del snapshot original, no del
borrador ni de la versión publicada más reciente. Los trabajos usan como autor
al publicador de esa versión y revalidan su permiso específico de eliminación o
anonimización. Si ese permiso desaparece, fallan de forma visible sin cambiar los
datos. Publicar una política finita exige ese permiso además del de publicación.
Cada lote de retención confirma las mutaciones y el checkpoint conjuntamente;
archivos y exportaciones siguen las mismas reglas de revocación y limpieza que
las operaciones de privacidad manuales.

## 6. Derecho operativo de eliminación/anonimización

La UI debe poder:

- localizar submissions;
- anonimizar;
- eliminar conforme a permisos;
- registrar la acción administrativa.

## 7. Archivos

La retención de Submission y de sus files debe ser coherente.

Eliminar/anonymize debe tener reglas explícitas sobre:

- fichero;
- metadata;
- checksum;
- ActionRun logs.

## 8. Backups

La documentación deberá aclarar que eliminar de la base activa no equivale necesariamente a eliminar copias históricas del backup del sitio; las políticas de backup pertenecen también al operador del sitio.

## 9. FormSpec histórico

Preservar una FormVersion no obliga a preservar datos personales de submissions. Son dominios de retención separados.

## 10. Decisión de borrado operativo y artefactos

La anonimización core elimina valores, consentimiento, etiquetas capturadas,
relación de usuario, notas, índice y ejecuciones de Actions de la respuesta.
Mantiene únicamente encabezados operativos y la referencia aleatoria. La
eliminación también retira la fila principal. Ninguna modifica FormVersions.

La transacción retira la autorización de descarga de archivos y crea un trabajo
durable con sus claves opacas para borrar los objetos físicos. Se conservan
marcas de intento sin fingerprint de datos personales, evitando que un reintento
antiguo vuelva a introducir una respuesta eliminada. Una Action en ejecución
impide temporalmente la operación para evitar procesar datos durante su borrado.

La operación revoca las exportaciones existentes o en curso del formulario,
incluidas sus leases, y programa la retirada de sus artefactos privados. Esta
invalidación conservadora evita mantener descargas con datos recién eliminados.
Los ficheros ya descargados por un operador quedan bajo su política de custodia,
al igual que sus copias de seguridad.

## 11. Subidas interrumpidas

El ADR 0013 exige registrar propiedad antes de crear bytes privados. La reserva
dura una hora; su consumo y la asociación a la respuesta son atómicos. Un job
retira reservas caducadas y mantiene la obligación de limpieza física en el
outbox. El borrado del formulario y la purga incluyen estas reservas. No se borra
un archivo basándose únicamente en que su nombre o ruta parezca pertenecer a
FormStudio. El panel distingue reservas abandonadas de archivos ya asociados.


---

<!-- SOURCE: 15_ACL_SPEC.md -->

# 15 — ACL


> Proyecto: **Nicode Form Studio**  
> Estado del documento: **Especificación inicial normativa**  
> Plataforma objetivo: **Joomla 6.x**  
> Principios obligatorios: **SPEC-DRIVEN** y **DATA-DRIVEN**
>
> Convenciones: **MUST/DEBE** = requisito obligatorio; **SHOULD/DEBERÍA** = recomendado salvo causa documentada; **MAY/PUEDE** = opcional.  
> Todo cambio funcional deberá modificar primero o simultáneamente la especificación correspondiente y sus criterios de aceptación.


## 1. Base

FormStudio utilizará ACL Joomla.

Permisos de componente iniciales:

- `core.admin`;
- `core.options`;
- `core.manage`;
- `core.create`;
- `core.edit`;
- `core.edit.state`;
- `core.delete`.

Permisos específicos propuestos:

- `formstudio.forms.manage`;
- `formstudio.forms.publish`;
- `formstudio.submissions.view`;
- `formstudio.submissions.manage`;
- `formstudio.submissions.export`;
- `formstudio.submissions.delete`;
- `formstudio.submissions.view_sensitive`;
- `formstudio.resources.manage`;
- `formstudio.logs.view`;
- `formstudio.jobs.manage`.

Los nombres anteriores se fijan como identificadores definitivos. Se añaden
`formstudio.submissions.anonymize`, `formstudio.submissions.reindex` y
`formstudio.submissions.retry` para separar operaciones privilegiadas.
`Security/Permissions.php` enumera el contrato y `tools/acl.php` genera `access.xml`.
Un Form sin asset hijo válido no concede permisos administrativos por fallback.

## 2. ACL por Form

Los Forms podrán actuar como assets hijos del componente.

Casos:

- equipo A administra Form A;
- equipo B administra Form B;
- compliance puede ver respuestas pero no editar Forms;
- marketing puede exportar solo ciertos Forms.

## 3. Frontend access

Cada Form tendrá Joomla access level y reglas adicionales cuando proceda.

Comprobar en:

- render;
- Data Source requests;
- submit;
- file download;
- submission view futura de frontend.

## 4. Administrator

Cada Controller comprueba autorización.

Los servicios de administración de Forms exigen `core.manage` en el componente y
`formstudio.forms.manage` sobre el Form, además de la capacidad concreta:
`core.edit` para borradores e histórico; `formstudio.forms.publish` y
`core.edit.state` para publicación/desactivación; `core.delete` adicional para
enviar a papelera. Crear exige las capacidades de gestión y `core.create` en
el componente. La creación del asset y el guardado se confirman en la misma
transacción; un fallo del asset no puede dejar cambios parciales.

La View puede ocultar acciones no permitidas, pero eso es UX, no seguridad.

Modificar reglas ACL por Form exige además `core.admin` en el componente. La
operación modifica permisos de un grupo Joomla existente, conserva las reglas
de los demás grupos, consume la revisión optimista del Form y comprueba una huella
de las reglas del asset. Una modificación concurrente realizada fuera de FormStudio
también debe provocar conflicto, aunque no haya cambiado la revisión del Form.
El estado heredado se calcula mediante ACL Joomla; no se simula en JavaScript.



Consultar respuestas exige core.manage en el componente y formstudio.submissions.view
en cada Form; no exige gestión ni edición de Forms. El scope de búsqueda se
construye exclusivamente con assets y ACL del servidor. Añadir notas o cambiar
estado exige además formstudio.submissions.manage. El historial de auditoría
requiere formstudio.logs.view. Mostrar valores sensibles y descargar archivos
sensibles comprueba view_sensitive sobre el Form; la revelación es explícita,
mediante POST con CSRF y auditoría, y no se activa mediante parámetros GET.

## 5. Datos sensibles

`view_sensitive` puede ser un permiso adicional al simple `submissions.view`.

La UI deberá enmascarar/ocultar campos sensibles a usuarios sin permiso.

## 6. Export

Exportar es una capacidad distinta de visualizar y debe tener permiso independiente.

## 7. Auditoría

Las operaciones privilegiadas deberán quedar registradas:

- export;
- delete;
- anonymize;
- retry Action;
- reveal sensitive;
- cambios de configuración.


---

<!-- SOURCE: 16_DATABASE_SPEC.md -->

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


---

<!-- SOURCE: 17_INSTALL_UPDATE_UNINSTALL_SPEC.md -->

# 17 — Instalación, actualización y desinstalación


> Proyecto: **Nicode Form Studio**  
> Estado del documento: **Especificación inicial normativa**  
> Plataforma objetivo: **Joomla 6.x**  
> Principios obligatorios: **SPEC-DRIVEN** y **DATA-DRIVEN**
>
> Convenciones: **MUST/DEBE** = requisito obligatorio; **SHOULD/DEBERÍA** = recomendado salvo causa documentada; **MAY/PUEDE** = opcional.  
> Todo cambio funcional deberá modificar primero o simultáneamente la especificación correspondiente y sus criterios de aceptación.


## 1. Package

Artefacto distribuible:

`pkg_nicode_form_studio.zip`

Constituyentes iniciales:

- `com_nicode_form_studio`;
- `mod_nicode_form_studio`;
- `lib_nicode_form_studio`.
- `plg_task_nicode_form_studio`, para el ejecutor de jobs nativo.
- `plg_extension_nicode_form_studio`, para auditar guardados de configuración
  nativa; habilitado al instalar y con estado respetado en actualizaciones.

Las dependencias internas deberán quedar declaradas.

El preflight del package se ejecuta antes de modificar piezas hijas. Comprueba
Joomla 6+, PHP 8.3+, extensiones mbstring/intl/bcmath/fileinfo/curl/zip y soporte
de leases SQL con MySQL 8.0.13+, MariaDB 10.6+ o PostgreSQL 14+ (ADR-0006). Estos mínimos
son requisitos de instalación; la matriz de versiones efectivamente probadas
se documenta por separado y no se infiere de esta validación.

## 2. Instalación

Proceso:

1. comprobar versión Joomla;
2. comprobar PHP;
3. comprobar DB soportada por nuestra matriz;
4. instalar library;
5. instalar component;
6. instalar module;
7. crear schema;
8. registrar schema version;
9. instalar configuración por defecto;
10. registrar assets/resources;
11. limpiar/inicializar caches necesarias;
12. health check;
13. mostrar resultado.

## 3. No depender de Compatibility Plugin

La compatibilidad Joomla 6 debe basarse en APIs actuales.

## 4. SQL

Habrá:

- install schema;
- update/migration scripts versionados;
- uninstall strategy.

Cambios de schema no se harán ad-hoc desde un Controller normal.

## 5. Actualizaciones

Una instalación puede saltar varias versiones.

Las migraciones deben aplicarse en orden.

Cada release puede incluir:

- DB migration;
- FormSpec migration;
- config migration;
- index rebuild requirement;
- background post-update job cuando un cambio sea demasiado grande para una petición de actualización.

## 6. Datos masivos durante updates

Nunca se asumirá que millones de submissions pueden reescribirse síncronamente durante la instalación.

Si una versión requiere:

- reindexar;
- recalcular;
- transformar payloads masivos;

se hará mediante proceso versionado, resumible e idempotente fuera del paso crítico de instalación.

## 7. Desinstalación

Configurable:

### Purge data
Eliminar:

- schema;
- cache;
- archivos propiedad inequívoca de FormStudio según política;
- temporales.

### Preserve data
Conservar:

- forms;
- versions;
- submissions;
- files según política;
- schema version.

Una reinstalación debe detectar datos conservados.

El modo predeterminado es conservación. El script nativo guarda los parámetros,
la versión de schema y las reglas ACL antes de que Joomla retire sus assets. Al
reinstalar restaura los assets por nombre y actualiza sus IDs, preservando reglas
de denegación y formularios previamente huérfanos. No depende de la library
durante la desinstalación. Véase ADR-0010.

## 8. Almacenamiento externo

No borrar automáticamente objetos externos que no sean inequívocamente propiedad del package.

## 9. Integridad del package

Cuando sea soportado por el manifest/package, impedir desinstalar una pieza hija necesaria dejando el resto roto.

## 10. Uninstall warnings

Antes de un purge destructivo, Joomla debe mostrar información suficiente sobre:

- formularios;
- submissions;
- files;
- tamaño aproximado;
- irreversibilidad.

La confirmación final se implementará conforme a capacidades Joomla.

## 11. Preparación de purga

El modo purge se activa mediante una revisión y confirmación explícitas en la
administración, no mediante una opción destructiva predeterminada. La preparación
bloquea admisiones y escrituras ordinarias, elimina formularios por jobs acotados
y espera la limpieza física de objetos y exportaciones. Sólo entonces habilita
la desinstalación nativa del package. El preflight comprueba de nuevo el estado
antes de retirar cualquier hijo. Un fallo conserva los registros necesarios
para reanudar; nunca se borra una outbox pendiente. Véase ADR-0012.

La preparación de purga usa el bloqueo exclusivo del checkpoint de schema para
esperar a escritores de uploads activos. Transfiere también reservas interrumpidas
a jobs de limpieza y no permite desinstalar antes de completarlos (ADR 0013).
Una reserva previa no autoriza escribir después de activar la purga. La pantalla
de confirmación incluye la cantidad de subidas pendientes de asociación/limpieza.


---

<!-- SOURCE: 18_EXTENSION_POINTS_SPEC.md -->

# 18 — Extension Points

> Proyecto: **Nicode Form Studio**  
> Estado del documento: **Especificación inicial normativa**  
> Plataforma objetivo: **Joomla 6.x**  
> Principios obligatorios: **SPEC-DRIVEN** y **DATA-DRIVEN**
>
> Convenciones: **MUST/DEBE** = requisito obligatorio; **SHOULD/DEBERÍA** = recomendado salvo causa documentada; **MAY/PUEDE** = opcional.  
> Todo cambio funcional deberá modificar primero o simultáneamente la especificación correspondiente y sus criterios de aceptación.

## 1. Objetivo

Extender FormStudio sin modificar core.

## 2. Registries

### FieldTypeRegistry
Campos.

### ValidatorRegistry
Validaciones.

### RuleOperatorRegistry
Comparadores.

### RuleEffectRegistry
Efectos.

### DataSourceRegistry
Fuentes.

### ActionRegistry
Acciones post-submit.

### StorageProviderRegistry
Files/payload storage si evoluciona.

### SearchProviderRegistry
Búsqueda de submissions.

## 3. Contratos

Cada provider debe declarar:

- ID estable;
- versión;
- config schema;
- capabilities;
- validation;
- lifecycle;
- security constraints;
- serializable metadata.

## 4. Eventos

Eventos conceptuales:

- BeforeFormRender;
- AfterFormRender;
- BeforeValidation;
- AfterValidation;
- BeforeSubmissionPersist;
- AfterSubmissionPersist;
- BeforeAction;
- AfterAction;
- ResolveDataSource;
- BeforeExport;
- AfterExport.

Los nombres y tipos finales deben ajustarse al sistema de eventos Joomla actual.

Los eventos nativos se llaman `onFormStudio` seguido del nombre conceptual y
reciben un `LifecycleEvent` tipado. `phase` y `context` son de solo lectura.
El contexto expone identificadores, versión, canal, idioma y resultado técnico;
no expone respuestas, credenciales, tokens de sesión ni rutas privadas. Los
providers siguen siendo el contrato para transformar/validar datos y renderizar
campos. Los listeners Before y Resolve pueden vetar mediante una excepción.
Un fallo BeforeAction se registra como fallo definitivo antes de ejecutar el
provider. Los listeners After son observacionales: sus fallos se registran sin
convertir una escritura o acción ya completada en un fracaso o repetirla.

Before/AfterValidation rodean la validación definitiva del envío, no la pasada
preliminar de presencia de archivos. Los eventos de persistencia solo se emiten
al intentar crear una respuesta, no al devolver una respuesta de replay ya
completada. AfterAction sigue al resultado persistido del intento. ResolveDataSource
se emite también para lecturas de caché. Before/AfterExport distinguen definición
JSON y fragmentos CSV; el contexto CSV incluye job, progreso y finalización. Los
listeners deben ser idempotentes ante reintentos de jobs; estos eventos no
sustituyen una cola transaccional para efectos externos.

## 5. Plugins Joomla

Cuando una capacidad encaje naturalmente en el ecosistema Joomla, se preferirá un plugin/provider Joomla antes que un mecanismo paralelo.

CAPTCHA es caso obligatorio de esta estrategia.

El grupo de plugins `formstudio` registra providers mediante
`onFormStudioRegisterProviders(ProviderRegistrationEvent $event)`.
El evento expone `kind` y `registry` como propiedades tipadas de solo lectura;
el registro admite altas durante el evento y queda cerrado al terminar.
Los tipos son `fields`, `validators`, `operators`, `effects`, `sources`,
`actions`, `storage`, `renderers`, `jobs` y `search`. Cada registro se inicializa
una vez por contenedor de runtime. No se permite reemplazar identificadores
existentes. Un campo personalizado debe registrar también su renderer;
no se asigna silenciosamente el renderer de campos core a tipos desconocidos.
La metadata de providers debe ser JSON serializable y su versión semántica.

## 6. Compatibility contracts

Un FormSpec publicado debe declarar dependencias de providers no-core.

Si falta un provider requerido:

- el Form no debe ejecutarse silenciosamente de forma incorrecta;
- Administrator debe mostrar error;
- render/submit debe fail closed según criticidad.

## 7. Versionado de provider

Cambios breaking deben versionarse.

El Compiler debe poder detectar configuración antigua incompatible.

El Compiler deriva `provider_dependencies`, un mapa de tipo de registro a
identificador y versión, a partir de todos los providers referenciados. Cada
publicación captura las versiones instaladas; no acepta una dependencia previa
incompatible. Para versiones estables >=1.0 se admite el mismo major sin
downgrade. Versiones 0.x y prerelease exigen coincidencia exacta. El render,
submit y ejecución/reintento de acciones comprueban el contrato antes de
ejecutar providers. La lectura histórica de respuestas sigue disponible aunque
falte un provider. Las instantáneas de desarrollo anteriores sin mapa solo
pueden comprobar existencia; no se les atribuye una versión histórica inventada.

## 8. No `if form_id`

### Contrato de navegador

Los campos, operadores y efectos externos deben declarar su módulo mediante
`BrowserProviderInterface` (ADR 0015). El asset debe existir en Web Asset Manager;
su módulo exporta `providers` con versión y hooks del contrato. No se aceptan
URLs desde FormSpec. La carga y los fallos se aíslan por instancia. La proyección
pública excluye cualquier configuración no declarada por el provider.
Los validators externos sin este contrato permanecen explícitamente server-only.
Debe probarse paridad PHP/JavaScript, falta de módulo, versión incompatible,
dos instancias y ausencia de filtración de configuración privada.

Está prohibido ampliar el producto introduciendo lógica del tipo:

`if ($formId === 12) Ellipsis`

Las variaciones deben expresarse mediante datos o providers.

## Almacenamiento de subidas gestionadas

Para recibir archivos HTTP, un StorageProvider implementa además
`StagedStorageProviderInterface`: `reserveKey()` genera una clave nueva sin crear
bytes y `putReserved()` realiza creación exclusiva sin sobrescribir objetos.
Una colisión debe lanzar `StorageCollision` antes de modificar bytes ajenos.
Las claves no se reutilizan; el runtime registra propiedad antes de escribir,
valida el StoredFile devuelto y consume esa propiedad al guardar la respuesta.
El token de staging es interno y nunca forma parte de la recepción pública.
Ver ADR 0013 para transacciones, recuperación y contrato de limpieza.


---

<!-- SOURCE: 19_I18N_SPEC.md -->

# 19 — Internacionalización


> Proyecto: **Nicode Form Studio**  
> Estado del documento: **Especificación inicial normativa**  
> Plataforma objetivo: **Joomla 6.x**  
> Principios obligatorios: **SPEC-DRIVEN** y **DATA-DRIVEN**
>
> Convenciones: **MUST/DEBE** = requisito obligatorio; **SHOULD/DEBERÍA** = recomendado salvo causa documentada; **MAY/PUEDE** = opcional.  
> Todo cambio funcional deberá modificar primero o simultáneamente la especificación correspondiente y sus criterios de aceptación.


## 1. Interfaz estática

Textos del package mediante language files Joomla.

La paleta y los nombres de tipos del árbol usan etiquetas traducidas. Los
Field Types pueden declarar `label_key` en sus metadatos; Administrator registra
esa clave mediante Joomla Text. Si falta su traducción, se usa `metadata.label`
y después el identificador del provider. Los campos nuevos reciben esa etiqueta
como texto inicial editable; cambiar de idioma administrativo no sustituye las
etiquetas, títulos o textos existentes del formulario.

Inicialmente:

- es-ES;
- en-GB recomendado como base técnica/distribución.

## 2. Contenido dinámico

Traducibles:

- form title;
- description;
- labels;
- placeholders;
- help;
- options;
- validation messages;
- success/error messages;
- email subjects/bodies;
- step titles;
- consent text.

## 3. Idioma base

Cada Form tendrá idioma/base y traducciones o estrategia definida.

## 4. Histórico

La FormVersion debe permitir saber qué contenido/traducción correspondía a la versión publicada.

## 5. Fallback

Orden de fallback documentado:

- idioma exacto;
- base language;
- form default;
- system default según diseño definitivo.

Nunca mostrar una key interna al visitante si existe fallback válido.

## 6. Search

La indexación de textos de respuesta no debe asumir una única lengua.

La normalización debe ser configurable por SearchProvider.

## 7. Date/number

Render y parsing deben respetar semántica de Field Type y locale sin perder representación canónica interna.

## 8. Contrato de contenido versionado

`base_language` identifica el idioma predeterminado del formulario. `translations` es un mapa de etiquetas de idioma a objetos de presentación. Se resuelve cada propiedad en orden de preferencia: idioma exacto del visitante, idioma primario (por ejemplo `es`), idioma predeterminado del formulario y contenido original. Las traducciones pertenecen a la FormVersion; editar el borrador no modifica el histórico.

Los objetos admitidos son `form` (`name`, `description`), `fields[UUID]` (`label`, `help`, `description`, `placeholder`), `elements[UUID]` (`title`, `text`), `options[UUID]` (`label`) y `actions[UUID]` (`subject`, `body_text`, `body_html` de las acciones de correo). La descripción se conserva en `metadata.description`. Los UUID deben existir; una traducción no puede modificar destinatarios, valores, permisos, condiciones ni validadores. Cada variante se somete a las mismas comprobaciones del compilador que el contenido original.

El runtime deriva una proyección de presentación sin sustituir la identidad criptográfica de la versión original en persistencia o ejecución de acciones. Guarda el idioma de la respuesta, el texto de consentimiento mostrado y las etiquetas de opciones seleccionadas; los reintentos de correo utilizan ese mismo idioma. La lectura histórica obtiene etiquetas y presentación de esa versión y ese idioma.

`validation[UUID][code]` traduce los mensajes por código del campo, conservando como fallback `config.validation_messages[code]` y los mensajes estáticos del sistema. La misma configuración se aplica a validación cliente y servidor. `messages[category]` traduce las categorías de confirmación o error; no admite comportamiento, navegación ni destinos. Los mensajes de resultado permiten únicamente los tokens públicos ya admitidos por `post_submit`.

`conditional_messages[UUID].message` traduce un mensaje condicional identificado de forma estable, sin modificar su condición ni su prioridad. El constructor muestra estas entradas dentro de las traducciones de confirmación. La vista previa usa el idioma seleccionado en el editor de traducciones; también al consultar una versión histórica.


---

<!-- SOURCE: 20_ACCESSIBILITY_SPEC.md -->

# 20 — Accesibilidad


> Proyecto: **Nicode Form Studio**  
> Estado del documento: **Especificación inicial normativa**  
> Plataforma objetivo: **Joomla 6.x**  
> Principios obligatorios: **SPEC-DRIVEN** y **DATA-DRIVEN**
>
> Convenciones: **MUST/DEBE** = requisito obligatorio; **SHOULD/DEBERÍA** = recomendado salvo causa documentada; **MAY/PUEDE** = opcional.  
> Todo cambio funcional deberá modificar primero o simultáneamente la especificación correspondiente y sus criterios de aceptación.


## 1. Objetivo

WCAG 2.2 AA como objetivo de diseño y testing.

## 2. Campos

- label programáticamente asociado;
- help mediante `aria-describedby` cuando proceda;
- errores asociados;
- `aria-invalid`;
- required comunicado;
- instructions antes del input cuando corresponda.

## 3. Grupos

Radio/checkbox groups:

- fieldset;
- legend;
- navegación coherente.

## 4. Errores

Tras submit inválido:

- resumen accesible;
- links/foco a campos;
- primer error enfoc-able;
- mensajes no basados solo en color.

La validación AJAX sincroniza el resumen y un mensaje de texto junto a cada
campo. Todos los controles de un grupo comparten la referencia a ese mensaje
en `aria-describedby`, sin perder las referencias de ayuda o del provider.
Al desaparecer el error se limpia el texto, se oculta su contenedor y se retira
su referencia y `aria-invalid`. Los ID de error son exclusivos por instancia.

El resumen renderizado en un envío tradicional enlaza a un contenedor de campo
con ID estable por instancia y `tabindex="-1"`, también en grupos de opciones.
Con JavaScript, el enlace abre el paso afectado y enfoca el primer control
habilitado no oculto; si no existe, enfoca el contenedor. El destino HTML sigue
existiendo sin JavaScript y no depende de índices ni del número de opciones.

## 5. Lógica dinámica

Mostrar/ocultar:

- mantiene orden de foco;
- no deja foco atrapado;
- anuncia cambios relevantes cuando proceda;
- campos ocultos no deben seguir generando errores invisibles.

Cuando una reevaluación desactiva un campo, se retira su error del resumen y
del mensaje asociado sin mover el foco del control que provocó el cambio.
Los errores de otros campos se conservan. Reactivar el campo no recupera un
error obsoleto; su siguiente validación determina el resultado actual.

Si la reevaluación oculta, deshabilita o retira el control enfocado, el runtime
busca el siguiente control utilizable en orden DOM dentro de la misma instancia,
o el último anterior si no queda uno posterior. Si no hay controles disponibles,
enfoca el área de resultado. No desplaza un foco válido colocado por un widget
ni toma el foco cuando la actualización empezó fuera del formulario.

## 6. Multipaso

- step actual identificable;
- progreso comprensible;
- navegación teclado;
- validación comunicada;
- títulos claros.

## 7. CAPTCHA

La accesibilidad dependerá del provider Joomla seleccionado; FormStudio debe renderizarlo correctamente y no degradar su soporte.

## 8. Builder Administrator

El Builder deberá ofrecer alternativa suficiente a drag & drop mediante controles de mover/reordenar accesibles.

## 9. Contraste y CSS

FormStudio no fijará colores que impidan al template cumplir contraste.

## 10. Testing

Pruebas automatizadas donde sea posible + revisión manual de teclado, lector de pantalla y flujos dinámicos en releases relevantes.

Aceptación de entrega 1.0.0 (2026-09-28): el usuario acepta explícitamente la
accesibilidad y dispensa la comprobación manual pendiente con lector de pantalla
para esta entrega. Se conserva la evidencia automática y de teclado existente;
no se declara ejecutada esa comprobación ni una certificación WCAG.


---

<!-- SOURCE: 21_TEST_SPEC.md -->

# 21 — Testing


> Proyecto: **Nicode Form Studio**  
> Estado del documento: **Especificación inicial normativa**  
> Plataforma objetivo: **Joomla 6.x**  
> Principios obligatorios: **SPEC-DRIVEN** y **DATA-DRIVEN**
>
> Convenciones: **MUST/DEBE** = requisito obligatorio; **SHOULD/DEBERÍA** = recomendado salvo causa documentada; **MAY/PUEDE** = opcional.  
> Todo cambio funcional deberá modificar primero o simultáneamente la especificación correspondiente y sus criterios de aceptación.


## 1. Pirámide

### Unit
- compiler;
- validators;
- normalizers;
- Rule Engine;
- option resolver;
- FormSpec migration;
- search query builder;
- action policies.

### Integration
- database;
- Joomla ACL;
- mail;
- CAPTCHA adapter;
- storage;
- cache;
- HTTP providers.

### Functional/System
- Administrator;
- Builder;
- publish;
- module;
- menu item;
- submissions;
- search;
- exports;
- Actions.

### Security
- CSRF;
- XSS;
- SQL injection;
- option tampering;
- rule bypass;
- ACL bypass;
- malicious uploads;
- SSRF;
- header injection;
- CSV injection;
- direct POST.

### Performance
- form render;
- submit;
- search;
- deep browsing;
- exports;
- index rebuild.

## 2. Dataset de escala

Tests de performance deberán incluir datasets sintéticos:

- 100 forms;
- 1M submissions;
- distribución realista de fields indexados;
- varios millones de index rows;
- ActionRun history.

Se ampliará cuando se definan SLOs.

## 3. Criterios esenciales de aceptación

1. Crear Form sin código.
2. Formularios sin límite artificial.
3. Campos sin límite artificial.
4. Reordenar.
5. Agrupar.
6. Multipaso.
7. Validaciones.
8. Dependencias condicionales.
9. Opciones dependientes.
10. Despublicar.
11. POST a despublicado rechazado.
12. Menu Item lista Forms.
13. Menu Item renderiza Form.
14. Module lista Forms.
15. Module renderiza mismo runtime.
16. Dos instancias coexisten.
17. Validación cliente/servidor coherente.
18. Bypass JS no evita validación.
19. Opción manipulada se rechaza.
20. Draft no altera published.
21. Publish crea FormVersion.
22. Submission conserva FormVersion.
23. Store/no-store configurable.
24. Emails configurables.
25. Autorespuesta configurable.
26. Actions condicionales.
27. Action failure persistido.
28. Import/export.
29. Restore crea draft.
30. Install/update/uninstall nativos.
31. New Field Type sin schema por formulario.
32. New Action Type sin editar Forms existentes.
33. Ningún PHP por Form.
34. Views sin lógica de negocio significativa.
35. Input navegador no confiable.
36. Millón de submissions no obliga a cargar/scan completo.
37. Filtros por campos indexados usan índice.
38. Export masivo no requiere una única request web.
39. CAPTCHA usa provider Joomla.
40. Mensajes success/error son configurables.
41. El usuario puede consultar respuesta histórica correctamente.
42. Sensitive fields respetan ACL.
43. Búsqueda global respeta provider/capabilities.
44. Reindexar no altera canonical payload.
45. Un Action fallido puede reintentarse sin repetir exitosas.


---

<!-- SOURCE: 22_RELEASE_SPEC.md -->

# 22 — Release y Definition of Done


> Proyecto: **Nicode Form Studio**  
> Estado del documento: **Especificación inicial normativa**  
> Plataforma objetivo: **Joomla 6.x**  
> Principios obligatorios: **SPEC-DRIVEN** y **DATA-DRIVEN**
>
> Convenciones: **MUST/DEBE** = requisito obligatorio; **SHOULD/DEBERÍA** = recomendado salvo causa documentada; **MAY/PUEDE** = opcional.  
> Todo cambio funcional deberá modificar primero o simultáneamente la especificación correspondiente y sus criterios de aceptación.


## 1. Versionado

Semantic Versioning:

`MAJOR.MINOR.PATCH`.

## 2. Tres versiones relevantes

- package/software version;
- DB schema version;
- FormSpec schema version.

No deben confundirse.

## 3. Definition of Done de feature

Una feature no está terminada solo porque funcione manualmente.

Debe tener:

- requirement ID;
- spec actualizada;
- acceptance criteria;
- tests adecuados;
- implementación;
- ACL revisado;
- security review proporcional;
- i18n;
- accessibility cuando aplique;
- migration si aplica;
- docs;
- changelog.

## 4. Release checklist

- unit/integration/system tests;
- PHP/Joomla compatibility matrix;
- DB matrix declarada;
- package installation clean;
- upgrade from supported previous versions;
- uninstall modes;
- schema checks;
- FormSpec migration;
- assets build;
- language completeness;
- no deprecated APIs bloqueantes;
- security checks;
- performance smoke tests;
- changelog.

## 5. Breaking changes

Requieren:

- ADR;
- migration path;
- documentación;
- version bump acorde;
- compatibility analysis.

## 6. Soporte

`Information System` debe permitir recopilar diagnóstico sin exponer secretos ni payloads personales.

## 7. Release reproducible

El repositorio deberá definir cómo construir el package final a partir de source, assets y manifests.

## Auditoría del artefacto de desarrollo

El build de integración fija orden y fechas de entradas ZIP y verifica después
membresía exacta, contenido SHA-256, licencia, versiones coherentes de extensiones
y bytes de los paquetes anidados. Genera un inventario build-manifest.json con
release=false. La prueba de reproducibilidad exige dos builds idénticos en el
mismo entorno y rechaza archivos adicionales, alterados o ausentes en copias.
La igualdad entre toolchains distintos sigue requiriendo verificación específica;
esta prueba no habilita una release mientras queden gates de producto abiertos.


---

<!-- SOURCE: 23_SUBMISSION_SEARCH_SCALE_SPEC.md -->

# 23 — Submissions, búsqueda y escala


> Proyecto: **Nicode Form Studio**  
> Estado del documento: **Especificación inicial normativa**  
> Plataforma objetivo: **Joomla 6.x**  
> Principios obligatorios: **SPEC-DRIVEN** y **DATA-DRIVEN**
>
> Convenciones: **MUST/DEBE** = requisito obligatorio; **SHOULD/DEBERÍA** = recomendado salvo causa documentada; **MAY/PUEDE** = opcional.  
> Todo cambio funcional deberá modificar primero o simultáneamente la especificación correspondiente y sus criterios de aceptación.


## 1. Requisito principal

FormStudio debe seguir siendo operable cuando existan:

- cientos de Forms;
- millones de Submissions;
- millones o decenas de millones de valores indexados;
- años de histórico.

"Operable" significa poder encontrar información sin descargarla completa ni recorrer manualmente páginas infinitas.

## 2. Separación obligatoria

### Canonical Submission Store
Preserva íntegramente la respuesta.

### Search Projection
Optimizada para consultar.

### Search Provider
Contrato que ejecuta búsqueda.

El índice es regenerable; el canonical payload no se deriva del índice.

Los exports filtrados fijan la versión que interpreta el filtro y un límite
superior de ID al comenzar. Cada lote revalida permisos y reutiliza el SearchProvider
con cursor estable; las respuestas posteriores al límite no entran en el archivo.
Los criterios se evalúan al procesar cada lote, no se promete una transacción
snapshot que bloquee millones de respuestas. Los cambios de privacidad revocan
los exports existentes y en curso según la política de borrado.

La autorización de filtros debe respetar la sensibilidad de cada FormVersion,
no solo la versión actualmente seleccionada. `version_field_policy` proyecta
sensibilidad e indexación por versión/campo dentro de la publicación transaccional.
Un filtro sin permiso sensible excluye versiones sensibles incluso para negación
y valores ausentes. La ausencia de metadata excluye, nunca concede acceso.
Véase ADR-0009 y su criterio de regresión entre versiones pública/sensible.

## 3. Campos indexables

Cada field podrá declarar, según capacidades:

- searchable;
- filterable;
- sortable;
- facetable/aggregatable futuro;
- not indexed.

Defaults conservadores:

- IDs, email, estados y campos cortos pueden ser buenos candidatos;
- textareas largos no deberían crear automáticamente índices costosos sin necesidad;
- fields sensibles no se indexan por defecto.

## 4. Tipos indexados

No almacenar todo como string.

Tipos:

- keyword;
- text;
- integer;
- decimal;
- boolean;
- date;
- datetime;
- multi-value.

Esto permite comparaciones correctas.

## 5. Búsqueda administrativa

### Nivel global

Buscar por:

- Submission UUID/ID;
- Form;
- fechas;
- state;
- user;
- action status;
- texto global cuando provider lo soporte.

### Nivel Form

Cuando se selecciona un Form:

- aparecen sus fields indexados;
- operadores coherentes con Field Type;
- columnas configurables;
- filtros combinables.

Ejemplos:

- email contiene dominio;
- importe > 1000;
- fecha entre A y B;
- provincia = Madrid;
- consentimiento = sí.

## 6. Filtros combinados

Debe permitirse:

- AND;
- múltiples condiciones;
- presets/filtros guardados.

OR avanzado podrá planificarse si complica la primera release, pero el modelo de query no debe impedirlo.

## 7. Saved Views

El Administrator debería permitir guardar una vista:

- Form;
- filtros;
- columnas;
- order;
- nombre.

Puede ser privada por usuario o compartida si ACL lo permite.

Las vistas core son privadas del usuario autenticado, con filtros, columnas y
orden estable por recepción o ID, ascendente o descendente. Guardar o abrir una vista revalida la autorización
actual y los campos disponibles; el preset no conserva permisos ni resultados.
Se muestran hasta 12 columnas de respuesta seleccionadas por UUID del Form, con
lectura de payloads y versiones en lotes. Cada celda sigue la sensibilidad de su
snapshot histórico, aunque el campo actual sea público. Campos sensibles actuales
y contraseñas no se ofrecen como columnas por defecto. El detalle permite la
revelación explícita conforme ACL.

## 8. Paginación a escala

Para tablas masivas se evitará depender únicamente de `OFFSET N` a profundidades enormes.

El SearchProvider debe poder implementar keyset/cursor pagination usando orden estable, por ejemplo:

`received_at DESC, id DESC`.

La UI puede seguir presentando navegación cómoda sin obligar a contar/offsetear millones constantemente.

## 9. Total counts

Un `COUNT(*)` exacto sobre filtros complejos puede ser costoso.

La interfaz debe distinguir:

- exact count cuando sea razonable;
- estimate/unknown cuando el provider lo determine;
- conteos preagregados futuros.

No bloquear una pantalla únicamente para obtener un total exacto decorativo.

## 10. Ordenación

Solo campos declarados sortable.

Nunca construir `ORDER BY` directamente desde input no validado.

El provider SQL declara `received_at_desc`, `received_at_asc`, `id_desc` e `id_asc`.
La recepción desempata por ID en la misma dirección. El ID numérico y la fecha
son las columnas ordenables core; las respuestas arbitrarias no se ordenan sin
un contrato de indexación, privacidad histórica y cardinalidad definido.
Los cursores están ligados al orden elegido además de filtros, scope y provider.
Cambiar orden reinicia la navegación. Las vistas privadas y los jobs de exportación
y operaciones por criterio conservan dicho orden; las inserciones posteriores al
límite superior de un job quedan excluidas también en sentido ascendente.

## 11. Global search

El contrato SearchProvider permite:

### SQL Core Provider
Filtros estructurados e indexación relacional.

### Full-text DB provider opcional
Si se decide implementar por motor.

### External Search Provider futuro
Por ejemplo un motor dedicado.

El dominio no se acoplará a una marca concreta.

## 12. Reindexado

Debe poder:

- reindexar un Form;
- reindexar periodo;
- reindexar Submission;
- reconstruir índice completo.

Reindexar no modifica canonical payload.

Para grandes volúmenes se ejecuta como Job resumible.

El explorador ofrece reconstrucción del formulario completo o selección opcional
por ID de respuesta y límites de recepción UTC. Esos límites pertenecen al job y
no heredan silenciosamente los demás filtros visibles. Sin formulario seleccionado,
el permiso de reindexado a nivel componente permite solicitar la reconstrucción
global. El worker recorre formularios por ID y respuestas en lotes, fija límites
superiores al comenzar y revalida el permiso específico de cada formulario. Una
denegación detiene el job; no se interpreta como autorización global implícita.
Las políticas históricas se reconstruyen antes de los valores y ningún modo
reescribe las respuestas canónicas.

## 13. Cambios de configuración de indexación

Si se marca un field antiguo como searchable:

- nuevas submissions se indexan inmediatamente;
- histórico queda `index pending`;
- un job backfill reconstruye el índice.

Administrator debe mostrar progreso/estado.

La publicación marca el histórico y encola el trabajo en la misma transacción.
El dashboard muestra respuestas pendientes y Jobs el avance o fallo. El worker
revalida el permiso del publicador; un administrador con permiso de reindexado
puede solicitar otro trabajo si ese permiso falta o fue revocado. Los filtros
por valores excluyen respuestas pendientes para evitar resultados negativos
falsos; los filtros por metadatos permiten seguir consultándolas.

La política derivada aplica el indicador actual a campos con el mismo UUID y
tipo, conserva el layout y la privacidad originales y nunca modifica snapshots
ni payloads. Cambiar de publicación entre lotes reinicia el recorrido bajo la
nueva política; los envíos antiguos que terminan tarde también se reconstruyen.
No se truncan valores históricos incompatibles con los límites del índice:
el trabajo falla y conserva el estado pendiente. Véase ADR-0021.

## 14. Exportaciones

Exportar el resultado de un filtro:

- no carga todo en memoria;
- procesa por chunks/cursor;
- genera file temporal/protegido;
- registra Job;
- permite descargar cuando finaliza;
- expira según política.

Formatos iniciales:

- CSV;
- JSON.

JSON utiliza un array de registros, escrito por bloques como un único documento.
Cada registro contiene `reference`, `form_version_id` histórico, `received_at`,
`state` y `values`, con claves UUID de los campos seleccionados. Los campos
repetidos conservan listas ordenadas de `{instance_path, value}`; los valores
no disponibles por la política histórica se representan como null. Se conservan
tipos canónicos y Unicode. No se incluyen metadatos técnicos de la petición ni
campos fuera de la selección. La descarga solo se permite tras finalizar el job.

Futuros:

- XLSX;
- NDJSON;
- otros providers.

## 15. Millones de filas y acciones masivas

Una operación sobre "todo lo que coincide con este filtro" no enviará todos los IDs desde el browser.

Se persistirá:

- query/filtro inmutable;
- snapshot temporal o strategy;
- Job;
- progreso;
- errores.

## 16. Retención e índices

Eliminar/anonimizar canonical data debe actualizar/eliminar su search projection.

No pueden quedar PII buscable después de su eliminación lógica/física según política.

## 17. Vista de Submission

La pantalla de detalle puede reconstruir el layout desde FormVersion, pero no debe ejecutar Data Sources remotas históricas para interpretar un valor.

Labels/opciones necesarias deben estar snapshotizadas/resolubles históricamente.

## 18. Observabilidad

Métricas/health:

- total submissions por Form;
- index lag;
- failed index operations;
- export jobs;
- average search latency opcional;
- storage growth.

## 19. Índices de DB

Todo índice físico se justificará por patrones de query.

No se crearán índices indiscriminados en cada columna.

Se documentarán con query plans en pruebas de escala.

## 20. Degradación

Si SearchProvider externo no está disponible:

- no perder canonical submissions;
- registrar el fallo;
- marcar index pending;
- según arquitectura, permitir búsquedas core limitadas o informar indisponibilidad;
- reindexar al recuperar servicio.

## 21. Semántica de negación multivalor

El operador indexado `not_equals` significa que **ningún valor** del campo coincide
con el valor buscado. Incluye respuestas sin valor indexado para ese campo. Se
implementa como `NOT EXISTS` de una igualdad, nunca como `EXISTS` de desigualdad,
que aceptaría incorrectamente una selección que contiene el valor excluido.
Los operadores ordenados se limitan a índices numéricos o de fecha; texto y
booleanos no se comparan mediante orden implícito del motor SQL.

El job de reconstrucción core fija un ID superior al iniciar el recorrido, usa
cursor por ID, comprueba el permiso del creador en cada lote y renueva su lease
antes de cada escritura. Repetir un lote no modifica el payload canónico.

## 22. Selección del proveedor

La configuración nativa selecciona un ID registrado de `SearchProviderRegistry`;
el valor inicial es `sql`. Explorador, exportaciones filtradas y trabajos masivos
consumen `SearchProviderInterface`. Un proveedor seleccionable declara `keyset`,
`historical_privacy` y `high_water` verdaderos, orden `received_at_desc` con ID
descendente como desempate, y `filter_operators` por tipo de índice. El plugin
configura su conexión; sus credenciales no forman parte del FormSpec ni del cursor.

El adaptador de selección firma cursores ligados al proveedor/versión, consulta,
scope, schema y límite superior. Comprueba tamaño del lote, identidades, scope y
orden de sus filas, y no transmite columnas adicionales de un proveedor al UI.
El cursor interno del proveedor tiene un máximo de 2000 bytes. Los jobs fijan
ID y versión exactos del proveedor al crearse; un cambio impide continuar con
un cursor de otro motor. No se sustituye silenciosamente un proveedor ausente.
Los cursores SQL previos conservan su propia validación de consulta y permisos.


---

<!-- SOURCE: 24_POST_SUBMIT_UX_SPEC.md -->

# 24 — Post-submit, mensajes y experiencia posterior


> Proyecto: **Nicode Form Studio**  
> Estado del documento: **Especificación inicial normativa**  
> Plataforma objetivo: **Joomla 6.x**  
> Principios obligatorios: **SPEC-DRIVEN** y **DATA-DRIVEN**
>
> Convenciones: **MUST/DEBE** = requisito obligatorio; **SHOULD/DEBERÍA** = recomendado salvo causa documentada; **MAY/PUEDE** = opcional.  
> Todo cambio funcional deberá modificar primero o simultáneamente la especificación correspondiente y sus criterios de aceptación.


## 1. Principio

Configurar un Form incluye configurar qué ocurre **después** de pulsar Enviar.

No debe requerir editar templates o PHP.

## 2. Categorías de resultado

- success;
- validation_error;
- captcha_error;
- anti_spam_rejected;
- rate_limited;
- upload_error;
- persistence_error;
- action_partial_failure;
- action_blocking_failure;
- form_unavailable;
- permission_error;
- session/csrf error;
- unexpected_error.

## 3. Mensajes configurables

Cada Form podrá personalizar, con fallback global:

- encabezado de éxito;
- cuerpo de éxito;
- mensaje de validación;
- mensaje CAPTCHA;
- mensaje de rate limit;
- mensaje upload;
- mensaje temporal/retry;
- mensaje indisponible;
- error genérico.

No mostrar detalles técnicos al visitante.

`post_submit.messages.success_heading` configura el encabezado de éxito separado
del cuerpo `success`. Admite los mismos tokens seguros y traducciones por idioma,
con fallback a la cadena Joomla global; una cadena vacía lo oculta. Solo aparece
en resultados success, nunca en rechazos, procesamiento pendiente o fallos de
acciones. Ambos transportes lo presentan como encabezado escapado dentro del
estado de confirmación; no interpreta HTML.

Las claves de mensajes por formulario deben pertenecer al catálogo de categorías
configurables compartido con traducciones y el editor. Una clave desconocida o
mal escrita impide publicar y señala su ruta; no se ignora silenciosamente.
Las traducciones conservan el fallback por categoría. Los rechazos anteriores a
la autorización del formulario conservan el mensaje global seguro, sin cargar
texto privado de una definición a la que el visitante no tiene acceso.

## 4. Comportamiento de éxito

Opciones:

- mantener Form y mostrar mensaje;
- ocultar Form y mostrar mensaje;
- resetear Form;
- conservar determinados valores;
- mostrar un summary de respuestas autorizado;
- redirigir a Menu Item;
- redirigir a URL autorizada;
- ir a página/estado de confirmación;
- entregar identificador/reference code.

El editor permite seleccionar los campos cuyos valores se conservarán después
de un reset. Excluye passwords, archivos y campos sensibles. Una selección
importada que ya no sea válida permanece visible para poder retirarla, pero no
habilita su conservación en el servidor. Las selecciones se guardan por UUID de
campo; dentro de grupos repetibles se conserva el valor de cada fila por separado.

`post_submit.summary_fields` opta explícitamente por mostrar respuestas concretas
en la confirmación de éxito. El editor ofrece campos no sensibles y excluye
contraseñas y archivos; el compilador rechaza referencias ausentes o prohibidas.
El servidor vuelve a aplicar esas exclusiones al construir el resumen, usando
valores normalizados y etiquetas traducidas. No incluye contexto técnico ni
campos inactivos. Las filas repetidas mantienen su orden con etiquetas numeradas,
sin exponer rutas UUID. El resultado usa texto escapado, también tras ocultar el
formulario. Por omisión no se muestra un resumen.

## 5. Redirect

Configurable:

- Menu Item;
- internal route;
- approved URL.

Evitar open redirect: no redirigir a una URL enviada libremente por el visitante.

## 6. Mensaje condicional

El mensaje de success podrá seleccionarse condicionalmente.

Ejemplo:

- tipo soporte → mensaje A;
- tipo comercial → mensaje B.

Debe usar lógica declarativa.

## 7. Emails de notificación

Cero o varios.

Cada email:

- condition;
- to;
- cc;
- bcc;
- reply-to;
- subject;
- template/body;
- attachment rules;
- field inclusion;
- failure policy.

## 8. Autorespuesta

Un email al visitante puede configurarse como Action separada.

Debe:

- obtener destinatario de un Field Type email validado;
- permitir condition;
- tener subject/body;
- admitir tokens seguros;
- registrar ActionRun.

## 9. Respuesta al administrador

No confundir:

- notificación interna;
- autorespuesta al visitante.

Son Actions independientes.

## 10. Attachments

Se podrá decidir:

- adjuntar uploads;
- no adjuntar y proporcionar referencia segura;
- límites.

Por seguridad y tamaño, el default debería evitar adjuntar indiscriminadamente archivos grandes.

## 11. Partial Action Failure

Si Submission se guarda pero una Action non-blocking falla:

- al visitante se puede confirmar recepción;
- se registra el fallo;
- Administrator lo muestra;
- retry disponible.

El mensaje al usuario no debe afirmar que un email externo fue enviado si no lo fue, salvo que ese detalle sea irrelevante para la promesa comunicada.

## 12. Blocking Failure

Se debe especificar si:

- rollback es posible;
- Submission queda en error;
- se conserva para diagnóstico;
- el usuario puede retry sin duplicar.

La transacción exacta dependerá del tipo de Action; no se prometerá atomicidad distribuida imposible con servicios externos.

## 13. Form reset

Configurar:

- reset all;
- preserve selected fields;
- no reset.

En redirect no aplica salvo persistencia cliente explícita.

El reset restaura los valores iniciales de los campos no conservados. Los campos
seleccionados conservan también un valor vacío explícito. En grupos repetibles la
selección se configura por UUID de definición y se aplica a cada dirección de
campo de forma independiente, manteniendo las filas declaradas y su orden. Un
reset confirmado obtiene un intento nuevo. Un error de validación no es un reset:
mantiene los valores enviados, sin reponer un default sobre un campo borrado.

## 14. Reference code

Puede mostrarse un identificador seguro y no predecible de Submission para soporte.

No debe exponer un ID secuencial si eso crea riesgo.

## 15. Eventos post-submit

El motor expondrá eventos/hooks para integraciones, además de Actions declarativas.

## 16. UX AJAX

Durante submit:

- evitar doble click;
- indicar progreso;
- mantener accesibilidad;
- restaurar botón ante error;
- mover foco a resultado;
- tratar timeout sin asumir automáticamente que servidor no recibió el POST.

## 17. Confirmación antes de envío

Opción futura/compatible:

- página de revisión previa;
- checkbox de confirmación;
- summary.

El modelo multipaso deberá permitirlo.

## 18. Mensajes globales y overrides

Jerarquía:

1. Form override;
2. template/resource si aplica;
3. component global default;
4. language default.

## 19. Traducción

Todos los mensajes son traducibles.

## 20. Logging

La configuración de mensajes nunca debe provocar que el log guarde contenido personal innecesario.

## 21. Edición de mensajes condicionales

El constructor permite crear condiciones tipadas, mensajes y un orden explícito de prioridad. El primer mensaje coincidente sustituye la confirmación normal únicamente cuando el procesamiento ya no está pendiente ni bloqueado. Cada mensaje nuevo tiene un UUID estable; reordenarlo conserva su traducción. Los snapshots anteriores sin UUID siguen siendo válidos y mantienen su comportamiento. Duplicar o importar como nuevo formulario remapea los UUID de mensajes y sus traducciones junto con las referencias a campos.


---

<!-- SOURCE: 25_JOOMLA_CAPTCHA_ANTISPAM_SPEC.md -->

# 25 — Integración Joomla CAPTCHA y anti-spam


> Proyecto: **Nicode Form Studio**  
> Estado del documento: **Especificación inicial normativa**  
> Plataforma objetivo: **Joomla 6.x**  
> Principios obligatorios: **SPEC-DRIVEN** y **DATA-DRIVEN**
>
> Convenciones: **MUST/DEBE** = requisito obligatorio; **SHOULD/DEBERÍA** = recomendado salvo causa documentada; **MAY/PUEDE** = opcional.  
> Todo cambio funcional deberá modificar primero o simultáneamente la especificación correspondiente y sus criterios de aceptación.


## 1. Decisión

Nicode Form Studio **NO implementará un CAPTCHA propio**.

Utilizará la infraestructura CAPTCHA de Joomla y los proveedores/plugins CAPTCHA instalados y habilitados.

Esta decisión reduce:

- dependencia de un proveedor concreto;
- duplicación;
- mantenimiento criptográfico/anti-bot;
- configuración paralela.

## 2. Capacidades Joomla a utilizar

La integración debe poder:

- usar el CAPTCHA global por defecto de Joomla;
- seleccionar un plugin/proveedor CAPTCHA instalado cuando Joomla permita esa selección;
- desactivar CAPTCHA por Form si la política global/ACL lo permite;
- renderizar mediante la API/Field de CAPTCHA actual;
- validar mediante el provider Joomla actual.

## 3. Configuración global FormStudio

`Default CAPTCHA policy`:

- Joomla global default;
- specific available provider;
- none;
- required policy según instalación.

## 4. Configuración por Form

- inherit FormStudio default;
- Joomla default;
- specific installed provider;
- none si permitido.

Administrator debe listar providers disponibles, no una lista hardcoded.

## 5. Posición visual

Aunque CAPTCHA es política de seguridad del Form, el Builder debe permitir decidir su posición mediante un elemento de sistema, por ejemplo:

`System → CAPTCHA`

Restricciones:

- máximo definido por política;
- no duplicarlo accidentalmente;
- si no se coloca, Renderer puede usar posición predeterminada configurable.

## 6. Múltiples Forms en una página

Cada instancia deberá utilizar namespace/instance identity apropiado para evitar colisión entre CAPTCHA instances.

## 7. Publicación

Si un Form exige provider X y X no está instalado/habilitado:

- Compiler/Admin muestra ERROR;
- Form no debe publicarse o runtime debe fail closed si se deshabilita posteriormente;
- jamás omitir CAPTCHA silenciosamente.

## 8. Runtime

Orden recomendado:

- disponibilidad/access;
- session/CSRF;
- anti-abuse prechecks;
- CAPTCHA validation;
- payload completo/expensive work según necesidad.

El orden definitivo deberá evitar tanto bypass como trabajo innecesario.

## 9. Joomla y proveedores

FormStudio no debe asumir reCAPTCHA, hCaptcha, Turnstile u otra marca.

El provider lo decide el sitio Joomla.

## 10. Otros mecanismos anti-spam

CAPTCHA no reemplaza:

- CSRF;
- rate limiting;
- duplicate protection;
- input validation.

FormStudio podrá ofrecer controles complementarios como:

- timing;
- honeypot si se decide;
- rate limit;
- throttling;
- idempotency;

pero, cuando Joomla ofrezca una capacidad equivalente reutilizable, se preferirá integración con Joomla.

## 11. Honeypot

Si se incorpora un honeypot FormStudio, debe considerarse una heurística anti-spam, no un CAPTCHA.

También podría suministrarse por un provider CAPTCHA Joomla, por lo que no debe ser requisito obligatorio duplicarlo.

## 12. Configuración segura

No almacenar secretos de providers CAPTCHA dentro de FormSpec portable.

Los secretos pertenecen a la configuración del plugin/provider Joomla.

## 13. Error al visitante

CAPTCHA failure tendrá mensaje configurable/traducible.

No exponer detalles internos del provider.

## 14. Auditoría

No almacenar challenge tokens/secret responses salvo requisito técnico temporal y seguro.

## 15. Actualización futura

Joomla ha evolucionado su API CAPTCHA; FormStudio debe usar la API moderna disponible en Joomla 6 y encapsularla en un `CaptchaAdapter` interno para reducir acoplamiento.


---

<!-- SOURCE: 26_IMPORT_EXPORT_VERSIONING_SPEC.md -->

# 26 — Import, export y versionado

> Proyecto: **Nicode Form Studio**  
> Estado del documento: **Especificación inicial normativa**  
> Plataforma objetivo: **Joomla 6.x**  
> Principios obligatorios: **SPEC-DRIVEN** y **DATA-DRIVEN**
>
> Convenciones: **MUST/DEBE** = requisito obligatorio; **SHOULD/DEBERÍA** = recomendado salvo causa documentada; **MAY/PUEDE** = opcional.  
> Todo cambio funcional deberá modificar primero o simultáneamente la especificación correspondiente y sus criterios de aceptación.

## 1. Export de Form

Debe incluir:

- metadata portable;
- layout;
- fields;
- local options;
- references/resources según modo;
- rules;
- validations;
- actions sin secretos;
- presentation;
- post-submit;
- privacy/storage policy;
- translations;
- FormSpec schema version.

No incluir por defecto:

- submissions;
- secrets;
- API keys;
- provider credentials.

## 2. Modos de export

### Portable
Incluye recursos embebibles.

### Reference-aware
Puede conservar references a recursos si destino compatible.

## 3. Import

Pipeline:

- parse;
- schema validation;
- version compatibility;
- migration;
- security validation;
- dependency analysis;
- UUID collision policy;
- preview;
- import as draft.

Nunca publicar automáticamente un Form importado sin validación/decisión explícita.

El sobre JSON de intercambio usa `nicode.formstudio.definition`, versión `1.0`,
con hash SHA-256 de la definición canónica y un máximo de 2 MiB. Versiones de
esquema desconocidas se rechazan; no se interpreta código ni SQL. Los providers
propios pueden declarar `PortableProviderInterface`; las credenciales se eliminan
también del resultado de ese contrato. Configuraciones opacas sin contrato generan
avisos de revisión y no se exportan. Los avisos identifican rutas y motivos,
nunca incluyen el valor eliminado.

La vista previa distingue errores estructurales, incompatibilidades semánticas
reparables en borrador y conflictos de identidad o recursos. Su confirmación
firmada caduca a los quince minutos y vincula actor, contenido, decisiones,
dependencias y revisión del destino. El guardado repite las comprobaciones dentro
de una transacción. Los avisos sobre credenciales eliminadas y referencias locales
exigen reconocimiento explícito. Actualizar sustituye el borrador; conserva las
versiones publicadas, respuestas, ACL y activación existentes. No cambia el alias
del destino. Duplicar asigna nuevas identidades y crea un formulario en estado draft.

Las referencias a Option Sets deben coincidir en UUID, revisión y hash de la
revisión original del recurso del destino. Un vínculo antiguo sin ese hash se
puede intercambiar en modo portable, o volver a vincular antes de exportarlo en
modo reference-aware. El snapshot de opciones permanece embebido en ambos modos.

## 4. UUID

Import en misma instalación deberá decidir:

- duplicate → new UUID;
- update existing → proceso explícito;
- conflict → error/prompt.

Duplicar conserva el original y crea un borrador sin respuestas, versiones
publicadas ni ejecuciones de acciones. Cambian los UUIDs del formulario y sus
elementos, campos, reglas, acciones y opciones locales. Se remapean las
referencias estructurales, condiciones, dependencias, validadores, destinatarios
de acciones y tokens de campos; los valores literales de negocio no se alteran.
Las identidades de recursos versionados embebidos se conservan. Se copian las
reglas ACL explícitas y la visibilidad/idioma/calendario del original, dentro de
la misma transacción. Se exigen permisos de edición del original y creación y
edición del destino, además de la revisión esperada del origen.

Los providers declaran referencias propias con `reference_paths` y plantillas
con `template_paths` en su metadata. Son punteros JSON relativos a configuración,
con `*` para miembros de una colección. `dependency_parameters` de las fuentes
también identifica referencias. Esto evita sustituir cadenas UUID que son valores
literales y permite duplicar extensiones sin editar el core.

## 5. Versiones

Cada Publish genera FormVersion.

No sobrescribir versiones antiguas.

## 6. Compare

Administrator debería poder comparar dos revisiones:

- fields added/removed;
- property changes;
- rules;
- options;
- Actions;
- messages;
- privacy.

## 7. Restore

Restaurar = crear draft nuevo basado en snapshot antiguo.

## 8. Option Sets

Recursos versionados requieren reglas claras:

- snapshot embedded;
- pinned version;
- dependency import.

## 9. Submission export

Separado de Form definition export.

Puede ser:

- filtered dataset;
- fields selection;
- historical label strategy;
- file references;
- metadata selection.

## 10. Reproducibilidad

Un FormSpec exportado debe poder validarse sin depender de la estructura SQL interna.

El remapeo de duplicación asigna primero todas las identidades locales, incluidas
opciones de campos y de efectos, y resuelve después las referencias. El resultado
no depende del orden de declaración de una opción referida por un provider.


---

<!-- SOURCE: 27_OPERATIONS_OBSERVABILITY_SPEC.md -->

# 27 — Operaciones, observabilidad y jobs

Los handlers de operaciones masivas que solo modifican base de datos implementan
`TransactionalJobHandlerInterface`. El worker confirma las mutaciones del lote y
su checkpoint en la misma transacción; perder el lease revierte ambos. No se
realizan efectos externos bajo esa transacción: el borrado físico de archivos se
encola mediante los jobs de limpieza existentes. Los filtros usan versión fijada,
cursor estable y límite superior de respuestas; no se envían millones de IDs desde
el navegador. Se revalidan permisos del creador en cada lote.

> Proyecto: **Nicode Form Studio**  
> Estado del documento: **Especificación inicial normativa**  
> Plataforma objetivo: **Joomla 6.x**  
> Principios obligatorios: **SPEC-DRIVEN** y **DATA-DRIVEN**
>
> Convenciones: **MUST/DEBE** = requisito obligatorio; **SHOULD/DEBERÍA** = recomendado salvo causa documentada; **MAY/PUEDE** = opcional.  
> Todo cambio funcional deberá modificar primero o simultáneamente la especificación correspondiente y sus criterios de aceptación.

## 1. Technical Log

El registro técnico se almacena separado en `technical_log`, con vocabulario
cerrado de eventos y referencias tipadas. No admite mensajes libres, valores de
campos, encabezados, URLs, rutas ni excepciones serializadas. Una escritura fallida
del registro no cambia el resultado de la operación. El visor requiere permisos
de logs a nivel componente, pagina por ID y filtra por UUID de correlación.
La correlación de un envío rechazado coincide con la devuelta al cliente; las
acciones usan la referencia de respuesta y los jobs su UUID estable.

Un fallo inesperado al renderizar el componente devuelve 503 con mensaje seguro
y referencia de soporte, registrada como `form.render_failed`. Un módulo con ese
fallo muestra su mensaje sin impedir el render de otras instancias de la página.
Los fallos inesperados anteriores al pipeline de envío también se correlacionan.
Nunca se incluye el texto de la excepción ni la configuración del provider.

Los fallos de resolución de Data Sources generan `datasource.failed`, incluso si
el runtime los convierte en un error de opciones. Un almacenamiento local no
disponible genera `storage.unavailable`; si impide recibir un archivo durante un
envío, el evento utiliza la correlación del resultado. No se registran entradas,
opciones, rutas o excepciones del proveedor. El fallo del registro no sustituye
el resultado original ni recupera silenciosamente una fuente fallida.

El nivel DEBUG está desactivado por defecto. La retención técnica configurable
es de 1–3650 días, con 30 días por defecto. El programador encola como máximo
una limpieza técnica por hora; cada lote confirma borrado y cursor de forma
atómica. Esta limpieza no elimina el audit log.

Niveles:

- ERROR;
- WARNING;
- INFO;
- DEBUG configurable.

Eventos:

- compiler failure;
- Data Source failure;
- Action failure;
- email failure;
- webhook failure;
- storage failure;
- indexing failure;
- unexpected exception.

## 2. Audit Log

Separado del technical log.

El visor global requiere `core.manage` y `formstudio.logs.view` sobre el componente.
Muestra eventos, actor, fecha y referencias estables, sin consultar respuestas ni
exponer metadatos de texto libre. Filtra por formulario, actor, tipo de evento,
UUID de respuesta, correlación y días UTC inclusivos. Usa páginas de 100 registros
con cursor de ID descendente; no calcula un total global para navegar.

Eventos:

- create/update/publish/unpublish/delete Form;
- restore version;
- change permissions;
- view/reveal sensitive si se decide auditar;
- export;
- anonymize;
- delete Submission;
- retry Action;
- config security change.

## 3. Correlation

Identificadores correlacionables:

- request/correlation ID;
- form UUID;
- version ID;
- submission UUID;
- action run ID;
- job ID.

No incluir PII innecesaria.

## 4. Jobs

Procesos potencialmente grandes:

- export;
- reindex;
- retention;
- anonymization batch;
- delete batch;
- retry batch;
- cleanup;
- post-upgrade migration.

Job:

- ID;
- type;
- creator;
- state;
- parameters snapshot;
- cursor/progress;
- counts;
- error;
- created/started/finished;
- cancellation semantics.

## 5. Ejecución

La implementación podrá apoyarse en mecanismos Joomla apropiados como CLI/plugins/tareas programadas cuando proceda.

No se dependerá de que el usuario mantenga una pestaña abierta para operaciones masivas.

El package incluye `plg_task_nicode_form_studio`, una rutina del programador nativo
con identificador `nicode.formstudio.jobs`. El administrador del sitio activa el
plugin y configura la tarea; el instalador no crea una planificación oculta.
La tarea permite 1–50 lotes de 1–500 registros por ejecución y deja de iniciar
lotes nuevos tras 20 segundos. Cada lote conserva su lease y checkpoint. La
ejecución puede invocarse mediante `php cli/joomla.php scheduler:run --id=ID` o
la planificación normal de Joomla. Los handlers revalidan los permisos del autor
original; el ejecutor no sustituye esa identidad por la de un superusuario.

El administrador ofrece listado de trabajos propios, listado global con
`formstudio.jobs.manage`, cancelación y descarga privada de CSV completados durante
24 horas. No expone parámetros, tokens de lease ni rutas de almacenamiento.
Las peticiones públicas solo pueden encolar exportación, reindexado y operaciones
masivas permitidas; los trabajos de borrado físico son internos.

El endpoint específico de reintentos genera jobs `action-retry` con intentos
esperados y permisos propios. La rutina del programador encola como máximo una
limpieza de CSV por hora si hay almacenamiento configurado. La creación se
serializa sobre el asset raíz del componente y no duplica una limpieza activa.
El job de limpieza invalida y elimina artefactos caducados, además de restos de
exportaciones fallidas/canceladas una vez transcurrido su plazo de seguridad.

## 6. Estados de Job

- pending;
- running;
- paused/retryable si aplica;
- completed;
- failed;
- cancelled.

## 7. Idempotencia

Jobs resumibles deben evitar reprocesar destructivamente el mismo bloque.

## 8. Diagnóstico

El diagnóstico nativo requiere `core.manage` y `formstudio.logs.view` sobre el
componente. Sus sondas son de solo lectura y muestran códigos seguros, nunca
rutas privadas, credenciales ni mensajes de excepción. Los recuentos de backlog
son muestras limitadas a 100 registros y se identifican como `100+` si hay más.
La indisponibilidad de exportaciones no impide consultar respuestas ni ejecutar
otros handlers; los trabajos CSV existentes quedan reintentables cada 5 minutos
y se rechaza encolar nuevos hasta recuperar el almacenamiento.

La indisponibilidad del volumen de archivos privados tampoco bloquea formularios
sin archivos ni los demás jobs. Los borrados físicos permanecen reintentables;
no se interpreta un volumen ausente como si los objetos ya se hubieran borrado.
La publicación de nuevos campos de archivo se rechaza hasta recuperar el volumen.

El diagnóstico muestra las seis versiones instaladas (package y cinco piezas),
concordancia entre checkpoints de schema, versión DB, proveedor de búsqueda,
política CAPTCHA, configuración básica de correo y directorio de caché. La sonda
de correo no envía mensajes ni afirma que la entrega funcione. La sonda de schema
no sustituye una validación completa de columnas e índices. Las tareas manuales
no cuentan como planificación automática; una última ejecución de mantenimiento
de más de 24 horas se muestra como advertencia.

Una sonda separada comprueba presencia y acceso a todas las tablas y columnas
obligatorias. Comparte `SchemaDefinition` con el generador de DDL y ejecuta una
consulta `WHERE 1 = 0` por tabla, sin leer valores de respuestas. Informa del
número de tablas no disponibles, sin nombres físicos ni mensajes de excepción.
Una tabla ausente, una columna ausente o falta de permisos produce fallo de esa
tabla; restaurar la estructura permite recuperar el estado sin escrituras de la
sonda. Esta comprobación no certifica tipos, nulabilidad, índices o constraints.

La sonda independiente de índices consulta catálogos y compara claves primarias,
unicidad y secuencia completa de columnas con `SchemaDefinition`. Acepta nombres
alternativos equivalentes; no basta que coincida el nombre de un índice. Exige
índices B-tree completos y ascendentes: no acepta prefijos de columna, índices
invisibles/ignorados, parciales, de expresiones o no válidos. Devuelve únicamente
estado y número de tablas afectadas, sin datos ni texto de error. No certifica
tipos, collations, claves foráneas ni todas las restricciones del esquema.

La sonda de tipos compara el catálogo con los tipos SQL que usa el generador:
familia, longitud de caracteres/binarios, precisión y escala decimal, precisión
temporal y nulabilidad. Rechaza enteros unsigned/zerofill que alteran el contrato
con signo. Tolera alias equivalentes del motor y el ancho de presentación de
enteros; no tolera reducir tamaños o cambiar timestamp local por timestamp con
zona. No lee respuestas ni modifica columnas. Su recuento agrega tablas afectadas.
La misma sonda exige `AUTO_INCREMENT` en los IDs MySQL/MariaDB e identidad
`BY DEFAULT` en PostgreSQL, sin columnas calculadas que sustituyan datos.
MySQL/MariaDB exige `utf8mb4` y `utf8mb4_bin` en columnas textuales, y ausencia
de charset/collation en las binarias. PostgreSQL exige la collation heredada
por defecto, conforme al DDL; no certifica las propiedades de la collation
global que el operador eligió al crear la base. Las claves foráneas requieren
una comprobación separada.

La sonda de claves foráneas compara las referencias requeridas por el contrato
compartido: columna de origen, tabla del mismo esquema y su columna `id`, con
reglas restrictivas de actualización/borrado. No acepta una clave compuesta como
sustituto de la relación simple, ni CASCADE/SET NULL. En PostgreSQL exige además
restricciones validadas y no diferibles. Los nombres pueden variar entre
instalaciones; el diagnóstico compara significado. Informa solo del número de
tablas afectadas y no valida datos históricos recorriendo respuestas.

La sonda de caché de fuentes distingue caché compartida desactivada, fallback en
memoria por petición y excepción al leer el backend Joomla configurado. La
lectura usa una clave reservada aleatoria y evita el fallback local para no
ocultar fallos. No guarda ni elimina entradas. Una lectura sin excepción puede
ser un miss o un fallo silencioso del backend, y no certifica escrituras; el
panel lo indica expresamente. La comprobación separada del directorio respeta
`cache_path` sin revelar su valor; no sustituye la sonda de backend.

La sonda de correo comprueba activación, remitente y selección de transporte.
Para SMTP comprueba servidor no vacío y sin controles, puerto entero 1–65535,
seguridad `none`/`ssl`/`tls` y credenciales presentes si se exige autenticación.
Devuelve códigos traducibles de corrección; nunca devuelve servidor, usuario,
contraseña ni configuración original. No realiza DNS, conexiones ni envíos.
El resultado correcto solo acredita estas comprobaciones estáticas, no la
resolución del servidor, autenticación real, disponibilidad de sendmail/PHP mail
o entrega. Las pruebas nativas alteran únicamente la configuración en memoria
de su proceso y la restauran, sin modificar la configuración guardada del sitio.

Information System:

- package versions;
- Joomla;
- PHP;
- DB;
- schema;
- FormSpec versions;
- mail;
- filesystem/storage;
- cache;
- CAPTCHA providers;
- search provider;
- pending jobs;
- index lag;
- cron/task capability;
- last maintenance;
- health warnings.

## 9. Maintenance

Tareas:

- expired export cleanup;
- temp upload cleanup;
- retention;
- orphan checks;
- index reconciliation;
- ActionRun cleanup según retention;
- audit/log retention.

Auditoría e historial anterior de Actions tienen políticas independientes de
0–3650 días; cero significa conservación indefinida y es su valor inicial. Los
jobs internos son transaccionales, reanudables y acotados por candidatos, con
índices fecha/ID. Se conserva el último intento de cada acción como marcador de
idempotencia/reintento, y nunca se elimina uno en ejecución. Cambiar el plazo
invalida la política capturada por un job anterior. Véase ADR 0016.

## 10. Métricas

Sin imponer una solución externa, exponer datos para:

- submissions/day;
- errors/day;
- failed actions;
- avg processing time futuro;
- index backlog;
- job backlog;
- storage consumption.

## 11. Alertas Administrator

Dashboard muestra problemas accionables:

- retries agotados;
- provider missing;
- jobs stalled;
- storage low/unwritable;
- Form invalid;
- index backlog;
- retention failure.

La tarea de mantenimiento encola como máximo una recuperación `upload-cleanup`
por hora. Transfiere reservas caducadas en lotes de hasta 500 al outbox de archivos;
la eliminación física sigue siendo reintentable. Health muestra una muestra de
hasta 100 reservas caducadas y la última ejecución de recuperación, sin claves,
tokens ni rutas. Dashboard enlaza alertas sólo con permiso de lectura de logs.
Los objetos desconocidos de un directorio no se consideran propiedad demostrada.

El mantenimiento horario incluye `rate-limit-cleanup`, que elimina contadores
pseudónimos vencidos por el índice de caducidad sin cargar scopes completos. Los
bloqueos de fila impiden interferir con incrementos concurrentes; los registros
ocupados se recuperan en una ejecución posterior. El checkpoint y las eliminaciones
comparten la transacción del worker. No elimina intentos de idempotencia activos.


El plugin nativo plg_extension_nicode_form_studio audita cada guardado confirmado
de configuración global como `config.security_saved`, incluidos permisos.
No almacena parámetros ni valores. Se habilita en su primera instalación y
Health avisa si está desactivado. Véase ADR 0017.

## Resumen copiable para soporte

La página de diagnósticos ofrece un textarea de solo lectura con etiqueta y ayuda
asociadas. El informe JSON contiene únicamente versiones conocidas, estados de
comprobaciones conocidas, recuentos acotados y las directivas PHP ya permitidas.
No serializa la configuración ni los detalles libres de providers: excluye rutas,
direcciones, credenciales, excepciones, filas SQL y contenido de respuestas.
No se transmite automáticamente ni añade un endpoint público. Conserva los
permisos core.manage y formstudio.logs.view exigidos por SystemHealth. La proyección
se realiza mediante una lista explícita, independiente de futuras ampliaciones
del reporte interno. Valores malformados se representan como no disponibles.

El mantenimiento horario incluye attempt-cleanup, interno y transaccional. Recorre
intentos expirados por fecha e ID con corte fijo y lotes de hasta 500. Elimina los
recibos técnicos solo después de su expiración de 24 horas. Si queda una respuesta
interrumpida cuya versión histórica verificada exige none, la elimina con el
servicio de privacidad (auditoría, revocación de exportaciones y limpieza durable
de archivos). Nunca elimina respuestas full/metadata. Acciones con lease vigente
o sin vencimiento explícito se conservan y se revisan en la siguiente ejecución.
Las acciones caducadas quedan cercadas por la eliminación de su historial; no se
ejecuta ninguna Action desde este mantenimiento. Requiere el scheduler operativo.

La finalización sin almacenamiento y la limpieza comparten orden de bloqueo
formulario -> respuesta -> intento, incluidos los bloqueos de claves foráneas.
La revalidación de caducidad se realiza en SQL para respetar la precisión temporal
de cada motor también en el instante exacto del corte.


---

<!-- SOURCE: 28_DECISIONS_AND_NON_GOALS.md -->

# 28 — Decisiones y no-objetivos


> Proyecto: **Nicode Form Studio**  
> Estado del documento: **Especificación inicial normativa**  
> Plataforma objetivo: **Joomla 6.x**  
> Principios obligatorios: **SPEC-DRIVEN** y **DATA-DRIVEN**
>
> Convenciones: **MUST/DEBE** = requisito obligatorio; **SHOULD/DEBERÍA** = recomendado salvo causa documentada; **MAY/PUEDE** = opcional.  
> Todo cambio funcional deberá modificar primero o simultáneamente la especificación correspondiente y sus criterios de aceptación.


## Decisiones iniciales

### D-001 — Package
Component + Module + Library en un Joomla Package.

### D-002 — FormSpec
Runtime basado en snapshot publicado e inmutable.

### D-003 — Authoring separado
Builder relacional separado del runtime.

### D-004 — Hybrid submissions
Canonical payload + typed search projection.

### D-005 — Registries
Field Types, validators, Rules, Data Sources, Actions, Search y Storage extensibles.

### D-006 — Joomla CAPTCHA
No CAPTCHA propio.

### D-007 — Server authoritative
JS nunca autoridad.

### D-008 — No arbitrary code
Sin PHP/JS/SQL arbitrario.

### D-009 — Historical correctness
Submission siempre vinculada a FormVersion.

### D-010 — Massive operations as jobs
Export/reindex/retention masivos no dependen de una única request.

## No-objetivos iniciales

No se pretende en primera instancia:

- sustituir a un BI completo;
- ser un CRM;
- ser un workflow/BPM universal;
- ofrecer editor de código arbitrario;
- implementar CAPTCHA propio;
- implementar un motor de búsqueda externo propio;
- crear una tabla SQL por Form;
- garantizar atomicidad distribuida entre DB y sistemas externos;
- ser un constructor visual de páginas generalista.

Estas capacidades podrán integrarse mediante providers o Actions cuando tenga sentido.

## Decisiones pendientes para ADR

- DB compatibility matrix exacta de la primera release;
- formato exacto del canonical payload;
- estrategia de secrets;
- Job execution mechanism;
- search provider core;
- editor visual implementation;
- repeatable groups in v1;
- import format signature;
- retention defaults;
- upload storage default.


---

<!-- SOURCE: 29_REQUIREMENTS_TRACEABILITY.md -->

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


---

<!-- SOURCE: 99_REFERENCES.md -->

# 99 — Referencias técnicas


> Proyecto: **Nicode Form Studio**  
> Estado del documento: **Especificación inicial normativa**  
> Plataforma objetivo: **Joomla 6.x**  
> Principios obligatorios: **SPEC-DRIVEN** y **DATA-DRIVEN**
>
> Convenciones: **MUST/DEBE** = requisito obligatorio; **SHOULD/DEBERÍA** = recomendado salvo causa documentada; **MAY/PUEDE** = opcional.  
> Todo cambio funcional deberá modificar primero o simultáneamente la especificación correspondiente y sus criterios de aceptación.


Fecha de revisión inicial de referencias: **2026-09-26**.

Estas referencias documentan capacidades de Joomla utilizadas por la arquitectura. La especificación del producto no debe copiar ciegamente ejemplos antiguos; antes de implementar se comprobará la documentación de la versión objetivo exacta.

## Joomla Programmer Documentation

### Extensions y Packages
- https://manual.joomla.org/docs/next/building-extensions/
- https://manual.joomla.org/docs/5.4/building-extensions/packages/
- https://manual.joomla.org/docs/next/building-extensions/install-update/installation/

### MVC
- https://manual.joomla.org/docs/next/building-extensions/components/mvc/
- https://manual.joomla.org/docs/next/building-extensions/components/mvc/library-mvc/

### Administrator lists, filtros y paginación
- https://manual.joomla.org/docs/next/building-extensions/components/component-development-tutorial/step06-admin-list/
- https://manual.joomla.org/docs/next/building-extensions/components/component-development-tutorial/step12-filter-form/

### CAPTCHA
- https://manual.joomla.org/docs/next/general-concepts/forms-fields/standard-fields/captcha/
- https://manual.joomla.org/docs/next/general-concepts/forms-fields/standard-fields/plugins/
- https://manual.joomla.org/docs/next/building-extensions/plugins/plugin-examples/captcha-plugin/

La documentación actual indica que el Form Field CAPTCHA accede a un plugin CAPTCHA instalado y que los providers se registran mediante la infraestructura CAPTCHA de Joomla.

### ACL
- https://manual.joomla.org/docs/next/general-concepts/acl/acl-permissions/

### Web Asset Manager
- https://manual.joomla.org/docs/next/general-concepts/web-asset-manager

### CLI plugins
- https://manual.joomla.org/docs/5.4/building-extensions/plugins/plugin-examples/basic-console-plugin-helloworld/

### Requisitos técnicos Joomla 6.x
- https://manual.joomla.org/docs/next/get-started/technical-requirements/

En la revisión de 2026-09-26 la documentación de Joomla 6.x enumera PHP 8.3 como versión soportada mínima y MySQL, MariaDB y PostgreSQL entre las bases de datos soportadas. La matriz exacta de Nicode Form Studio se fijará independientemente y se probará.

### Cambios/deprecations CAPTCHA
- https://manual.joomla.org/updates/53-54/changed-deprecations/
- https://manual.joomla.org/updates/44-50/removed-backward-incompatibility/

## Principio de uso de referencias

Antes de implementar una API:

1. verificar documentación de la versión Joomla objetivo;
2. verificar deprecations;
3. preferir API moderna;
4. encapsular integraciones susceptibles de evolución;
5. añadir test de integración.
