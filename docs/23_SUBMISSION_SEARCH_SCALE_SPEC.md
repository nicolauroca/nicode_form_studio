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
