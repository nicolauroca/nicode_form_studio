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
