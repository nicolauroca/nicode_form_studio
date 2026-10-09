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
