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
