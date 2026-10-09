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
