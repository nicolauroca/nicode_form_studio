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
