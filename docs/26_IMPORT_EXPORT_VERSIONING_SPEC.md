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
