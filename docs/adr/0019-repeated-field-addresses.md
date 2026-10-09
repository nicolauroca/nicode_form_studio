# ADR 0019 — Identidad de respuestas dentro de grupos repetibles

Estado: identidad y runtime integrados y probados; publicación normal bloqueada
hasta cerrar su aceptación. Las secciones siguientes conservan el historial de
integración y sus evidencias, sin convertir los hitos parciales en una release.

Las respuestas actuales se identifican por UUID de campo. Reutilizar ese UUID
para varias filas pierde la pertenencia a cada instancia; usar el índice de fila
cambia la identidad al reordenar o eliminar otra fila.

Cada instancia repetida tendrá un UUID independiente del orden de presentación.
Una dirección de campo contiene el UUID de definición del campo y la secuencia
ordenada de pares {group, instance} de sus ancestros repetibles. Admite anidación,
con el mismo máximo estructural de 64 contenedores. Ningún grupo puede aparecer
dos veces en una dirección; el campo tampoco puede ser uno de sus grupos.

La clave portable concatena grupo/instancia por ancestro y termina en el campo,
separando UUIDs canónicos minúsculos con `/`. Sin repetición, la clave es el UUID
actual del campo. No acepta índices posicionales, coerción, escapes de ruta ni
segmentos vacíos. El orden de las filas se conserva por separado de su dirección.

Esta dirección solo establece identidad sintáctica. Antes de aceptar una respuesta,
el runtime deberá comprobar que el campo pertenece a esos grupos en el snapshot,
que cada instancia está declarada en su padre y que cumple límites de repetición.
Una dirección válida no demuestra pertenencia, permiso ni validez del valor.

La futura serialización conservará listas de instancias con identidad y orden;
no confundirá una selección multivalor con varias respuestas del mismo campo.
Validación, errores, archivos, reglas, persistencia, índices, detalle, exportación,
privacidad y Actions deberán transportar esa identidad sin aplanar las filas.
El diseño de almacenamiento y la semántica de consultas por instancia requieren
integración y pruebas adicionales antes de habilitar publicación.

LAYOUT-006 y FIELD-014 continúan pendientes/parciales según su trazabilidad. Los
codecs de dirección no constituyen soporte de grupos repetibles; se mantiene la
protección de publicación existente hasta completar el circuito funcional.

## Declaraciones de instancias y pertenencia

`RepeatedInstances` recibe el árbol de elementos y un mapa de declaraciones. La
clave de cada declaración es la dirección del grupo (pares de ancestros y UUID
del grupo al final); su valor es la lista ordenada de UUIDs de filas. El grupo
declara `repeat: {min: entero, max: entero}` con 0 <= min <= max. Una declaración
ausente representa cero filas. El orden de las claves del mapa no es significativo;
el orden dentro de cada lista de filas sí lo es.

La validación comprueba ancestros exactos del snapshot, UUIDs únicos dentro de
cada grupo e instancia padre, padres declarados y máximos. Una fila de otro padre
no satisface la pertenencia aunque reutilice el mismo UUID. Reordenar filas cambia
la lista, no su dirección. Las direcciones de campos no repetidos siguen válidas
solo fuera de los grupos repetibles.

La expansión está acotada por un presupuesto explícito (10.000 por defecto) para
elementos, declaraciones, filas acumuladas y scopes visitados. El runtime deberá
seleccionar y aplicar el presupuesto admitido antes de aceptar el transporte.
Los mínimos se devuelven como errores por scope, separados del rechazo estructural:
el motor integrado deberá exigirlos solo en scopes activos tras evaluar reglas.
Este componente todavía no recibe peticiones públicas ni habilita publicación.

## Expansión y enlace de respuestas

El registro puede enumerar direcciones de campo en el mismo orden jerárquico de
los elementos y de las filas declaradas. Cada fila se recorre completa antes de
la siguiente; los contenedores no repetibles conservan la ruta actual. Un grupo
sin filas no genera controles, mientras los campos exteriores siguen presentes.
La visita de elementos expandidos tiene un presupuesto independiente para evitar
amplificación por filas con muchos descendientes.

El enlace de entrada verifica todas las claves contra las instancias declaradas
y crea una entrada para cada control esperado, usando null si falta. Conserva los
tipos crudos (incluidos false, cero y listas multivalor) para la normalización
posterior. No transforma selecciones múltiples en filas, no inventa instancias y
no ignora direcciones ajenas. Rechaza padres que sean campos o elementos hoja.
Esta fase no decide activación, normalización, validadores ni políticas de campos
confiables; su integración con esas fases continúa pendiente.

## Referencias y validadores por fila

Una referencia ordinaria por UUID se interpreta en el ámbito léxico de la fila:
puede apuntar a un campo de esa misma fila, de un ancestro repetible o exterior.
Su ruta de grupos debe ser un prefijo de la ruta de origen. No puede seleccionar
implícitamente una fila descendiente o de un grupo hermano, ni siquiera cuando
solo existe una fila. Esos casos requieren un contrato explícito de selección o
agregación; no se convierten en «primera fila».

`ScopedValidator` usa esas referencias para adaptar validadores con `config.fields`:
solo entrega valores activos/normalizados de los campos declarados en el ámbito,
conservando los UUIDs de definición que entiende el provider. Las violaciones se
traducen después a direcciones completas. Un resultado que señale campos no
declarados o inactivos se rechaza. Los providers no reciben respuestas de filas
hermanas. La activación y normalización deben proceder del motor integrado; este
adaptador no las infiere de un POST crudo.

## Comprobación final de valores por instancia

La fase `AddressedFieldValidator` consume estados autoritativos de reglas con
claves de dirección completas. Comprueba tipos, obligatoriedad activa, opciones
habilitadas y límites de indexación con `FieldValueValidation`, compartido con el
pipeline ordinario. Serializa únicamente valores válidos y conserva los errores
bajo la dirección de su instancia. Rechaza estados esperados ausentes; no convierte
su ausencia en desactivación. Las referencias y los validadores entre campos se
resuelven mediante el adaptador de ámbito descrito anteriormente.

Esta fase no debe recibir directamente el POST: todavía falta producir sus
estados a través de la expansión de reglas, resolver valores confiables y unirla
con mínimos activos, validadores globales, archivos y persistencia. El compilador
sigue impidiendo publicar grupos repetibles hasta completar esa integración.

## Evaluación de reglas por instancia

`RuleEngine::evaluateInstances` construye un grafo efímero de ejecución a partir
de las declaraciones validadas. Expande también los contenedores: el grupo
repetible conserva su estado por ámbito padre y cada contenedor interior tiene
su dirección por fila. La herencia de activación sigue esos padres completos.
No se crea ni publica otro FormSpec para representar este grafo.

Cada efecto se evalúa en el ámbito de su destino. Las condiciones y las copias
de campos resuelven referencias de la misma fila o de sus ancestros. Se conserva
el orden original de prioridad, UUID de regla y efectos. Una condición que
requiera seleccionar una fila descendiente sin contrato explícito se rechaza.
La expansión de nodos y efectos tiene límites independientes.

Las fuentes conservan su configuración y sus UUIDs de dependencia originales:
el motor les entrega únicamente los valores de esas dependencias resueltos para
la instancia actual. Esto preserva el contrato del provider y las claves de
caché por entradas. Las iteraciones, retractación de efectos, normalización de
copias, defaults de opciones y detección de falta de convergencia usan el mismo
motor que los formularios ordinarios.

Esta entrada requiere valores previamente normalizados y una política confiable
de defaults; no es un endpoint para aceptar POST directamente. Su integración
con inicialización, mínimos activos, validadores, archivos, representación y
persistencia sigue pendiente. La prohibición de publicación permanece vigente.

## Validación de envío por instancia

`ValidationEngine::validateInstances` enlaza la política de autoridad de valores,
normalización, reglas y comprobación final. `SubmittedFieldValue` comparte esa
política con el envío ordinario: valores de archivos exclusivamente confiables,
campos de solo lectura/sistema/calculados derivados en servidor, y ausencia de
un campo editable como valor vacío. Los defaults explícitos se identifican por
dirección completa; incluso un null confiable impide reemplazarlo por un default
o copia. No se admiten claves de respuesta o integración fuera de las instancias
declaradas.

Los mínimos se comprueban después de las reglas sobre el estado activo del grupo
en su ámbito padre. Un grupo vacío conserva su estado y puede producir un error
`min_instances`; un grupo oculto o deshabilitado por un ancestro no lo produce.

Los validadores asociados a campos se ejecutan por cada propietario activo. Los
validadores de formulario con referencias declaradas se ejecutan una vez por el
ámbito léxico más profundo de sus referencias, conservando acceso a ancestros.
Referencias a grupos hermanos requieren agregación explícita y se rechazan aunque
los grupos no contengan filas. Las violaciones mantienen las direcciones completas
y se deduplican sus códigos. Hay un presupuesto separado de llamadas expandidas.

Esta entrada sigue siendo interna. Faltan inicialización de presentación, contrato
de agregación explícita entre grupos, integración de archivos y del pipeline
público, interfaz, almacenamiento y búsqueda de respuestas repetidas. Las pruebas
de esta fase no habilitan publicación ni certifican esos recorridos pendientes.

## Estado inicial y reintentos de presentación

`PresentationState` comparte la política de valores iniciales entre la vista
previa administrativa, el componente/módulo público y las instancias repetidas.
En una primera presentación aplica valores confiables, defaults y prellenados,
normaliza y evalúa las reglas. Una copia entre campos se resuelve por fila. Un
prefill inválido queda vacío; los errores de defaults ordinarios no se ocultan.

En un reintento, un campo editable omitido permanece vacío y no recupera su
default. Los campos autoritativos vuelven a derivarse; un null confiable explícito
sigue suprimiendo esa derivación. Contraseñas y archivos no se repueblan desde
valores enviados o confiables. Las declaraciones de filas todavía proceden del
llamador: quedan pendientes su creación inicial y los controles de añadir/quitar,
la representación repetida y la conexión del transporte y almacenamiento públicos.

## Declaraciones iniciales

`RepeatedInstances::initial` crea las filas mínimas de una presentación nueva con
UUIDs aleatorios. Recorre los mínimos anidados por cada padre existente; un grupo
con mínimo cero conserva una declaración vacía y no crea descendientes. Los
presupuestos limitan tanto el total de filas (también filas sin controles) como
el árbol expandido. Una configuración que exceda esos límites falla sin devolver
declaraciones parciales.

El resultado se conserva y se reutiliza al presentar, validar y reintentar. Esta
factoría no se debe invocar para corregir un envío incompleto: el constructor
ordinario mantiene las omisiones y la validación aplica los mínimos activos.
Los controles de añadir/quitar y el renderer comparten este registro de instancias
tanto en la preview como en los formularios publicados.

## Cambios de filas

`withAddedRow` y `withRemovedRow` devuelven un nuevo conjunto de declaraciones sin
modificar el anterior. Añadir valida el ámbito y el máximo y genera solamente la
nueva rama con sus mínimos anidados. Quitar valida pertenencia y mínimo y elimina
las declaraciones descendientes del prefijo completo grupo/fila. Las identidades
y el orden de las demás filas se conservan, incluso si otra rama reutiliza el
mismo UUID de fila. Se validan los presupuestos del resultado completo antes de
devolverlo; un fallo no deja cambios parciales.

Estas operaciones de edición no sustituyen la validación de un envío: las filas
omitidas siguen produciendo mínimos según activación. Tampoco borran respuestas
ni archivos por sí mismas. El controlador deberá retirar el estado de la fila
eliminada; el binding rechaza cualquier respuesta residual de esa dirección.
La conexión a los controles de interfaz y al transporte sigue pendiente.

## Proyección pública por instancia

`PublicSpec::projectInstances` expande referencias de reglas, copias, validadores
y dependencias bajo las direcciones completas antes de aplicar la misma lista
de propiedades públicas que utiliza el formulario ordinario. Las condiciones
`when` de opciones estáticas también se traducen al ámbito de cada fila. Las
fuentes remotas mantienen únicamente dependencias y opciones públicas activas;
su configuración privada no se entrega al navegador. Los defaults autoritativos
explícitos conservan la supresión de copias por instancia.

La proyección incluye las declaraciones validadas y rechaza estados de campos
ausentes. Es el contrato de datos previo a conectar la representación y el runtime
del navegador; no constituye todavía una interfaz utilizable de grupos repetidos.

## Representación de filas declaradas

`FormRenderer::renderInstances` utiliza el mismo recorrido y los mismos providers
de controles que la representación ordinaria. Cada grupo y fila se representa
con fieldsets y leyendas; cada control usa la dirección completa para su nombre,
ID, etiqueta y error. Los IDs mantienen también el prefijo de instancia de
componente/módulo. Los errores de mínimos apuntan al grupo vacío correspondiente.
Las declaraciones se incluyen en la proyección pública y en el campo oculto
`nfs_instances` para el futuro transporte tradicional.

Esta entrada exige estados de todos los nodos expandidos y respeta el presupuesto
de expansión. Todavía no está conectada a los endpoints públicos ni a controles
de añadir/quitar del navegador. La presencia del campo oculto no autoriza por sí
misma un envío repetido; quedan pendientes su parsing y validación en el pipeline.

## Mínimos activos en navegador

La proyección de instancias incluye los límites y títulos de los grupos. El
runtime comprueba mínimos tras evaluar las reglas y solo para grupos activos del
paso validado. El resumen y los mensajes inline usan la dirección completa del
grupo y permiten enfocar el fieldset vacío. Desactivar el grupo retira sus errores.
La búsqueda de mensajes inline selecciona la dirección exacta para no modificar
mensajes de campos descendientes. Este feedback no sustituye la validación de
declaraciones y mínimos en servidor.

## Lectura del transporte

El empaquetador del navegador reconoce direcciones canónicas completas en los
nombres `nfs[dirección]` y `nfs[dirección][]`. Los valores se reúnen en el objeto
JSON `nfs_values`; los archivos mantienen sus partes multipart y bytes. La
declaración `nfs_instances` permanece separada de los campos multivalor.

`RequestAdapter::requestInstances` decodifica una declaración JSON de objeto con
límite de dos MiB, valida pertenencia y presupuestos y comparte la lectura del
envelope ordinario. Las respuestas y claves de archivos deben pertenecer a filas
declaradas. Los metadatos de archivo conservan nombre, ruta temporal y estado;
MIME, tamaño y autenticidad de subida todavía competen al gateway de archivos.
Una omisión de filas conserva sus errores de mínimo para la fase de activación.

El resultado es `RepeatedSubmitRequest`, aceptado por `submitInstances` en el
pipeline compartido. Los endpoints aún no invocan esta entrada. El parsing y
la conexión interna no habilitan por sí solos la publicación.

## Selección de valores para almacenamiento

`StoredValues` comparte la política de selección con el repositorio ordinario.
Su entrada por instancias exige validación satisfactoria, estados activos
completos y pertenencia de todas las direcciones. Rechaza valores inactivos y
mínimos activos incumplidos. En modo `full` conserva valores permitidos,
consentimientos y etiquetas por dirección y declaraciones de grupos activos en
su orden original. Contraseñas y campos con `persist=false` quedan excluidos.
Los modos `metadata` y `none` no conservan valores, consentimientos, etiquetas ni
declaraciones de filas.

Esta selección produce el fragmento canónico utilizado por la entrada interna
`SubmissionRepository::persistInstances`. La entrada ordinaria reutiliza la
misma selección de políticas y transacción.

## Identidad de reintentos

`RequestFingerprint` conserva exactamente la entrada HMAC ordinaria y añade una
entrada por instancias con los valores validados (incluidos los consumibles por
acciones aunque no se persistan) y las declaraciones activas en su orden. Una
variación de fila, orden, valor, versión, canal o idioma modifica esa identidad.

Los archivos se identifican por nombre, MIME, tamaño y checksum, sin UUIDs de
recibo ni rutas de almacenamiento transitorias. El contexto de archivos repetidos
debe indicar `field_address`, pertenecer a un campo de archivo activo y coincidir
con cada recibo canónico. Se rechazan recibos duplicados, ausentes o ajenos; se
usa el orden canónico de los archivos. `persistInstances` y
`findReplayInstances` comprueban esta identidad antes de reutilizar un intento.

## Proyección de índices por ámbito

`IndexProjector::projectInstances` reutiliza la política y conversión de tipos
ordinaria sobre valores canónicos ya aceptados. Cada entrada conserva el UUID de
definición del campo, su dirección completa y el ordinal dentro del valor múltiple.
Incluye además la ruta grupo/fila de sus ancestros y su SHA-256, compartido entre
campos del mismo ámbito. La posición visual de una fila no modifica esa identidad.
La ruta exacta debe conservarse junto al hash; el hash no sustituye la pertenencia
ni debe ser la única comprobación de igualdad en consultas.

Los límites de expansión y de cantidad de entradas se aplican separadamente para
acotar la multiplicación de campos multivalor. Se mantienen las restricciones de
indexación sensible, persistencia, longitud y precisión. La estructura de salida
se consume en la escritura y el reindexado del repositorio. El escritor verifica
la relación entre dirección, campo, ruta y hash y omite la dirección derivable
antes del INSERT. El proveedor SQL permite correlación explícita entre campos.

## Esquema SQL por instancia

Los índices y archivos incorporan `instance_path` (hasta 4735 caracteres, ruta de
64 pares grupo/fila) e `instance_hash` (SHA-256). La dirección completa se deriva
de esa ruta y `field_uuid`; no se duplica como columna. El ámbito ordinario usa
la ruta vacía y su hash, también por defecto para escritores ordinarios.

La unicidad de índice pasa a respuesta/campo/hash de instancia/ordinal de valor.
La ruta exacta sigue siendo necesaria para comprobar igualdad y pertenencia;
una colisión de hash debe fallar, nunca mezclar ámbitos. Los índices de búsqueda
por tipo se mantienen. Los recibos y claves de almacenamiento de archivos
conservan su unicidad previa.

`InstanceSchema` completa las instalaciones de desarrollo de la misma versión:
añade columnas con valores ordinarios por defecto y sustituye la clave antigua
en un único ALTER. Detecta la clave por sus columnas, incluso con nombres de
instalaciones anteriores. Puede repetirse sin reescribir rutas existentes.
El instalador ejecuta esta migración y los diagnósticos comparten el contrato
del DDL generado para ambos dialectos.

`tests/database-instances-schema.php` verifica conservación de datos anteriores,
idempotencia, ámbitos distintos, rechazo de ordinal duplicado y rutas máximas en
MariaDB, MySQL y PostgreSQL. Esta migración no habilita la publicación de grupos
repetibles.

## Transacción de respuestas repetidas

`persistInstances` exige un `ValidationResult` satisfactorio y las declaraciones
de filas. Comparte con la entrada ordinaria el bloqueo del formulario, propiedad
y hash del snapshot, intento único y transacción de respuesta/índices/archivos.
Los archivos conservan el UUID de campo de definición y su ruta de instancia;
el diario de uploads se consume dentro de la misma transacción cuando está
configurado. Los reintentos equivalentes no adjuntan nuevos recibos u objetos.

El reindexado detecta el mapa `instances` del payload, proyecta sus valores por
dirección y conserva el payload original. Los modos `metadata` y `none` dejan
vacíos valores, declaraciones, índices y archivos; `none` conserva únicamente
el resultado técnico de replay tras completar y descartar la respuesta.

Las pruebas SQL usan snapshots internos explícitos, sin sortear el bloqueo del
compilador de producción. Comprueban persistencia por ámbito, política de campos,
replay con nuevos recibos, cambios de orden/valores efímeros, reindexado inmutable,
rollback por conflicto de archivo y modos sin respuestas. Faltan todavía la
integración del pipeline público, interfaz de consultas correlacionadas,
acciones y controles de edición dinámica de filas.

## Lectura histórica y archivos por instancia

`SubmissionReader` expande las definiciones sobre las declaraciones almacenadas,
valida pertenencia y aplica las mismas políticas de contraseña, exportación y
revelado sensible a cada dirección. Valores, etiquetas, consentimientos y
etiquetas de opciones conservan claves completas; el audit cuenta los valores
sensibles revelados sin registrar su contenido.

`SubmissionPresentation` conserva el orden de grupos y filas, incluso con filas
anidadas que reutilicen un UUID bajo padres diferentes. Omite ramas sin valores
visibles o enmascarados y no expone configuración de autoría. Los controles de
historial existentes consumen esta estructura de grupos y campos.

Los listados y descargas de archivos enlazan el UUID del recibo con el valor
canónico de su dirección exacta. La descarga verifica además hash de ruta,
pertenencia de la instancia y permisos sobre la definición histórica. Una ruta
que apunte a otra fila declarada tampoco permite reutilizar su recibo.

La lectura con propósito export aplica la política por dirección antes de formar
las columnas del archivo.

## Columnas CSV de valores repetidos

El job CSV conserva una columna por UUID de definición seleccionado y una fila
por respuesta. `FieldColumns` agrupa exclusivamente los valores autorizados por
`SubmissionReader`. Un valor ordinario conserva su representación escalar o
multivalor anterior; un campo repetido produce una lista JSON de objetos
`instance_path`/`value`, en el orden histórico de sus filas. La ruta incluye todos
los ancestros para distinguir filas anidadas. No se aplana el valor multiselección
ni se confunde su ordinal con el orden de las instancias.

Una respuesta sin valores autorizados para ese campo exporta una celda vacía.
La selección de columnas sigue fijada al snapshot del job y cada respuesta aplica
además sus propias políticas históricas de exclusión y datos sensibles. La lista
JSON pasa por el escritor CSV existente, con escapado, Unicode y protección de
fórmulas. Una mezcla de ámbito ordinario y repetido para el mismo campo dentro de
una respuesta se rechaza como inconsistencia.

Las pruebas SQL verifican columnas estables, orden y contenido por instancia,
omisión en modo metadata, permisos históricos y recuperación tras escribir un
chunk sin checkpoint. El soporte de exportación JSON como formato separado y
la activación pública de grupos repetibles siguen pendientes.

## Correlación SQL entre campos repetidos

Los filtros de campo pueden declarar `same_instance` con un nombre de grupo
ASCII (letra minúscula inicial, hasta 32 letras, cifras o guiones bajos). Los
miembros del grupo deben compartir exactamente la misma cadena de ancestros
repetibles en el snapshot seleccionado. Sin esa propiedad conservan su semántica
independiente sobre toda la respuesta.

Cada grupo requiere al menos una condición positiva indexada para seleccionar
una instancia. Las restantes condiciones se evalúan en esa misma ruta y hash;
`not_equals` significa ausencia del valor comparado en esa instancia, incluyendo
un campo vacío dentro de una fila seleccionada. Los grupos solo negativos se
rechazan: el índice de valores no enumera filas completamente vacías. No se
interpreta una negación como existencia de filas que el índice no puede probar.

La consulta usa parámetros ligados, verifica la forma de la ruta según los
ancestros del snapshot y compara la ruta exacta además del hash. Las políticas
sensibles históricas se aplican a cada campo, incluso a los negativos. La
correlación forma parte de la identidad del cursor. `SelectedSearch` exige que
el proveedor declare la capacidad `same_instance`, evitando que otro motor la
ignore silenciosamente.

Las pruebas SQL cubren diferencias entre AND independiente y AND por fila,
negación anclada, ausencia de valor, hashes coincidentes con rutas diferentes,
cursores, ámbito incompatible y privacidad histórica. La interfaz visual para
construir estos grupos y la activación pública de repetibles siguen pendientes.

## Contexto y condiciones de acciones repetidas

`ActionContext` acepta declaraciones de instancias activas y valida la pertenencia
de sus valores. `forAction` conserva ese contexto. Los tokens de campo repetido
usan listas JSON ordenadas de ruta/valor; las etiquetas de opciones siguen las
mismas rutas. La política `include_email`, la exclusión sensible por defecto y
la exclusión incondicional de contraseñas se aplican antes de emitir tokens.
El resumen incluye valores permitidos consumibles por acciones aunque no se
persistan. Los transportes mantienen su escapado y configuración de destinos.

Una condición de acción se evalúa completa dentro de cada ámbito léxico más
profundo de sus referencias. La acción se ejecuta una vez si alguna fila satisface
la expresión, nunca una vez por coincidencia. Las referencias raíz/ancestro se
resuelven dentro de ese ámbito. Referencias a ramas hermanas incompatibles se
rechazan; cero filas produce falso para condiciones que dependen de esas filas.
La evaluación limita profundidad, nodos y producto filas/nodos.

`ActionRetryHandler` reconstruye las declaraciones almacenadas. Los reintentos
conservan el fencing de intentos y no duplican acciones terminadas. Las pruebas
usan exclusivamente proveedores en memoria y datos sintéticos: incluyen un job
real de retry SQL, sin correo ni HTTP externos.

Esto todavía no conecta el pipeline público repetido ni define un destinatario
único a partir de un campo email repetido: los campos de destinatario/reply-to
siguen exigiendo un valor email escalar. La publicación repetible permanece
bloqueada hasta completar esas decisiones de configuración y la integración.

## Entrada repetida del pipeline compartido

`SubmissionPipeline::submitInstances` recibe el sobre validado por el adaptador y
reconstruye su registro contra el snapshot autorizado. Comparte el proceso de
acceso, versión activa, CSRF, token de intento, honeypot, rate limit, CAPTCHA,
eventos y limpieza con `submit`. La entrada ordinaria rechaza snapshots repetidos
sin su sobre, evitando degradarlos silenciosamente a valores sin ámbito.

Las políticas y nombres de archivos se expanden por dirección; validación
preliminar/final, etiquetas de opciones, errores personalizados, replay y escritura
usan esa identidad. Los contextos de acciones reciben las declaraciones activas
incluso en modos metadata/none. Los mensajes condicionales posteriores al envío
reutilizan la evaluación por fila del contexto de acciones.

Las suites SQL ejercitan full/metadata/none, replay sin repetir CAPTCHA, cambio de
valores con el mismo intento, CSRF, CAPTCHA rechazado, despublicación y mensajes
por dirección. El multipart nativo y su limpieza se verifican en la sección siguiente;
falta la UX dinámica/reset antes de activar publicación.

## Verificación multipart nativa

`tests/http-repeated-uploads.php` recorre el adaptador Joomla, pipeline compartido,
gateway HTTP, diario durable y repositorio con subidas reales reconocidas por
`is_uploaded_file`. El endpoint de pruebas está fuera del paquete, limitado a
loopback y nonce; usa exclusivamente la base aislada `formstudio_test`.

Se verifican archivos únicos y múltiples, MIME obtenido de bytes, pertenencia a
la fila, orden canónico de varios recibos y límites por instancia. Un reintento
con nuevos temporales conserva los objetos originales y descarta los nuevos.
Errores de CAPTCHA, validación, extensión parcial y cambio de payload no dejan
reservas ni bytes sin dueño. Ocultar el campo archivo en una fila no afecta a la
hermana activa; una fila no declarada se rechaza antes de almacenar.

Esta evidencia cubre el flujo de archivos interno completo. Los controles dinámicos
y la UX completa de reset de filas siguen pendientes antes de retirar el bloqueo
de publicación.

## Enrutamiento público por instancia

FormDisplay crea las filas mínimas para una nueva instancia de componente/módulo.
Un reintento exige sus declaraciones originales: no genera identidades nuevas si
faltan. Presentación y renderizado usan las direcciones completas para valores,
opciones, errores y controles. El intento conserva su vinculación a sesión/canal.

El controlador elige requestInstances/submitInstances según el snapshot publicado
y devuelve las mismas declaraciones al recuperar HTML tras errores. FormOptions
reconstruye la pertenencia contra el snapshot autorizado, valida por instancia y
devuelve opciones públicas por dirección. Omitir declaraciones falla explícitamente;
la validación incompleta de otros campos no impide consultar opciones.

Las suites SQL verifican mínimos, independencia de renders, identidad de reintentos,
errores y dependencias remotas por fila. La prueba HTTP usa un snapshot interno en
el Joomla aislado y lo despublica al finalizar. El compilador continúa bloqueando
la publicación normal de repetibles hasta completar controles dinámicos y su
verificación de extremo a extremo.

## Reset con conservación por definición

La selección de campos que se conservan usa UUID de definición. El navegador
resuelve ese UUID desde cada dirección y guarda/restaura el valor de cada fila
por separado, incluido un valor vacío. Las filas y su orden se mantienen.

En HTML sin JavaScript, la presentación recibe explícitamente que se trata de
un reset: los campos no conservados recuperan defaults/prefills y los conservados
mantienen su valor. Un valor ausente durante un error de validación sigue vacío;
no se confunde con reset. Campos derivados se recalculan y archivos/passwords
no se recuperan del envío. El intento nuevo permite una respuesta posterior.

Esta política ya cubre filas existentes; añadir/quitar filas y la interacción de
esos controles con reset requieren todavía implementación y pruebas de navegador.

## Edición de filas en el servidor

FormRows aplica add/remove a declaraciones reconstruidas contra el snapshot
publicado. Exige acceso público, versión activa, CSRF e intento válido para la
sesión/canal; usa un límite independiente de 120 operaciones por minuto. El
registro de dominio comprueba grupo, pertenencia de fila, mínimos, máximos y
presupuesto expandido. Los límites de un objeto de entrada no sustituyen al
snapshot del repositorio.

Añadir inicializa solo la rama nueva, incluidos sus mínimos anidados. Quitar
elimina sus declaraciones descendientes y filtra sus respuestas por dirección.
Las respuestas omitidas de filas preexistentes se marcan como null antes de
editar: la presentación puede inicializar campos nuevos sin reponer defaults
sobre campos que el visitante borró. No se crea Submission ni se almacena upload.

El controlador público form.rows acepta POST y devuelve JSON con el formulario
actualizado mediante el renderer compartido, manteniendo el intento verificado.
Los fallos devuelven categorías seguras y no-store. Esta ruta no retira el bloqueo
de publicación.

El renderer añade botones nativos por scope, con etiquetas accesibles y límites
min/max reflejados en disabled. El valor del botón contiene una única operación
JSON acotada; el controlador rechaza sobres ambiguos o propiedades extra. Los
botones usan formnovalidate porque editar filas no equivale a enviar la respuesta.
El submit público deriva estas operaciones al servicio de filas antes del pipeline.

Sin pedir JSON, se devuelve la página HTML del formulario actualizado y un mensaje
traducido. Si la operación se rechaza por formato/límite o rate limit, se intenta
recuperar el formulario original con su intento verificado. No se convierten errores
de acceso o sesión en un formulario utilizable. La vista previa desactiva los botones.

Sin JavaScript, el recorrido navega a una página nueva y los archivos necesitan
seleccionarse otra vez.

## Actualización dinámica del DOM

El manejador JavaScript intercepta los botones y envía solo texto/valores packed;
no transmite archivos al endpoint de edición. El servidor conserva el identificador
de render validado para que las referencias DOM no cambien. Antes de aplicar la
respuesta, el cliente comprueba identidad de form/version/attempt/channel/instance,
declaraciones, direcciones de campos y tipos de controles conservados, y carga los
proveedores de navegador necesarios para campos que aparecen por primera vez.

La actualización conserva el formulario raíz, navegación, resumen y CAPTCHA externo.
Mueve los nodos originales de campos que siguen presentes al nuevo árbol de layout,
en lugar de clonarlos: mantiene FileList, listeners y escritura durante la petición.
Reconstruye mapas de controles/elementos, conserva los defaults iniciales existentes,
inicializa los nuevos y actualiza el valor y default del sobre de declaraciones.
Las consultas anteriores de opciones se cancelan e invalidan antes de sustituir
su resolver, evitando callbacks sobre el grafo anterior.

La edición y el envío comparten exclusión mutua. El foco pasa a un campo de la fila
añadida o al grupo tras quitar; los errores de transporte conservan el formulario.
La prueba de navegador real usa el renderer PHP y un transporte sintético retrasado:
verifica identidad de nodos, FileList, texto cambiado en vuelo, foco, doble clic,
error, reset, aislamiento de dos formularios y desbloqueo tras un envío rechazado.
La regresión HTTP verifica por separado el endpoint instalado en Joomla. Todavía
se requieren pruebas de los controles y proveedores que exceden los escenarios
concretos documentados a continuación antes de considerar completa la UX repetible.

## Navegador con grupos anidados y proveedor instalado

`tests/joomla-repeated-browser.php` crea un snapshot interno en el Joomla aislado,
con familias y contactos anidados. El grupo hijo comienza con cero filas: el
proveedor fixture.upper no tiene controles iniciales y su módulo/estilo se cargan
al añadir el primer contacto mediante form.rows. El recorrido real de navegador
comprueba normalización a mayúsculas, copia readonly por fila, dos contactos en una
familia y un contacto en otra, mínimo/máximo de botones, eliminación de un hijo y
eliminación de una familia con descendientes. La URL e instancia de formulario se
mantienen mientras el foco se recupera en la fila/grupo correspondiente.

Tras enviar la estructura final por el controlador público, --verify comprueba
una única Submission, los UUID de padre/hijo observados durante el recorrido,
dos valores BETA (campo de proveedor y copia) y ausencia de todas las ramas
eliminadas. --cleanup despublica exclusivamente el fixture cuya identidad y alias
se verifican dentro de la base/host de pruebas. Evidencia visual:
`tests/artifacts/nested-joomla-browser.png`.

Esta evidencia cubre un proveedor de campo instalado y su carga diferida real;
no certifica todos los widgets de terceros. El editor de repetibles y las garantías
de compilación/publicación aún requieren trabajo antes de retirar la protección.

## Diagnósticos estáticos de repetición

RepeatedLayoutValidator comprueba límites y ascendencia antes de recorrer referencias.
No genera UUID de filas: calcula multiplicidades saturadas a partir de los máximos
de los ancestros, por lo que un mínimo cero no oculta una rama inválida. Elementos,
filas y efectos expandidos tienen presupuestos independientes de 10000, coherentes
con los límites de ejecución existentes. Condiciones de acciones/post-submit
también acotan contextos por número de nodos evaluados.

Las llamadas a validadores comparten un único presupuesto acumulado: cada
validador de campo aporta la multiplicidad máxima de su campo y cada validador
de formulario aporta la de su contexto más profundo. No basta con comprobar cada
validador por separado. El diagnóstico señala el primer validador que excede la
suma permitida, en coherencia con ValidationEngine::validateInstances.

CAPTCHA pertenece al formulario. Una ubicación cuya multiplicidad máxima supera
uno se rechaza con layout.repeatable.captcha, antes de que el renderer encuentre
una segunda instancia. Una ubicación raíz o limitada a una única instancia no
incurre en esa duplicación.

Prefills, dependencias de fuentes y validadores de campo se resuelven desde el campo
propietario. Una regla resuelve su condición desde cada destino. Las referencias
pueden usar la misma cadena de grupos repetibles o un prefijo ancestro, sin saltar a
hermanos ni escoger implícitamente una fila descendiente. Validadores de formulario
y condiciones globales admiten cadenas comparables y rechazan grupos independientes.
Los errores conservan índices JSON Pointer del borrador.

El compilador mantiene el rechazo general de publicación. Además, señala con un
diagnóstico específico email_field/reply_to_field dentro de un grupo repetible:
se exige una política explícita de selección de destinatarios. Este
diagnóstico evita una selección accidental y no excluye esa capacidad del alcance
final. Las comprobaciones del editor y la auditoría restante siguen pendientes.

La selección de email se configura por referencia con first_nonempty,
last_nonempty o unique. EmailAction recorre direcciones validadas en orden de
layout/filas; no usa el orden del mapa de valores recibido. unique deduplica por
igualdad exacta y rechaza varias direcciones distintas. Ausencia de candidatos o
valores malformados producen ActionFailure antes de invocar el transporte. La
misma configuración del snapshot gobierna el reintento, sin multiplicar mensajes
ni introducir resultados parciales de entrega entre filas.

La prueba SQL de email anidado usa EmailAction y ActionRetryHandler reales con un
transporte en memoria. Después del fallo confirmado, cambia borrador y versión
activa; el job mantiene destinatario y Reply-To de la versión histórica y el orden
canónico de las filas. Las tres políticas, recuperación y exclusión de reintentos
obsoletos pasan en MariaDB, MySQL 8 y PostgreSQL. No se verifica con ello un envío
externo ni se retira la protección de publicación.

## Edición de límites en borradores

El inspector de un grupo repetible existente ofrece mínimo y máximo obligatorios.
Reutiliza el control entero del constructor y coordina los límites nativos entre
ambos inputs: un mínimo mayor que el máximo queda inválido, sin sustituir el valor
introducido. Los datos malformados importados permanecen visibles y corregibles;
abrir el inspector no genera UUID de filas ni rellena límites omitidos con valores
arbitrarios. La paleta crea grupos con límites editables; los borradores válidos
pueden publicarse mediante el servicio administrativo ordinario.

## Preview sin publicación

FormCompiler::compilePreview comparte el recorrido semántico de compile.
Límites, referencias, presupuestos y los demás errores impiden ambos recorridos.
FormRepository publica exclusivamente con compile; invocar preview no activa
ningún snapshot. La prohibición temporal de publicar grupos repetibles se retira
al verificar el servicio nativo de publicación y envío.
FormPreview crea las filas mínimas y usa evaluateInstances/renderInstances.

previewOptions recibe las declaraciones junto con los valores por dirección.
Comprueba autorización y revisión antes de compilar, valida pertenencia contra el
árbol autorizado y usa validateInstances. El navegador incluye el registro de
filas de la propia preview. Archivos y passwords no pasan a la consulta; las
opciones públicas conservan el scope de cada fila. La preview mantiene el envío
deshabilitado, sin token público ni persistencia de respuestas.

previewRows añade/quita filas mediante POST administrativo protegido por CSRF y
revisión. Recompila el borrador o snapshot autorizado, valida las declaraciones y
reutiliza withAddedRow/withRemovedRow. El render posterior vuelve a comprobar la
revisión del borrador para evitar mezclar cambios concurrentes. Las filas restantes
conservan UUID y valores explícitos vacíos; solo las nuevas reciben defaults.

El editor del navegador usa el mismo reemplazo de layout y conservación de nodos
que el runtime público, con una función de transporte administrativo explícita.
Mantiene también el transporte de opciones después de reemplazar filas. La preview
sin ese transporte no permite editar filas y nunca cae al endpoint público. El
botón de envío sigue deshabilitado tras éxito o error. El recorrido completo de
navegador administrativo sigue pendiente de verificación.

La primera prueba real detectó que el sandbox `allow-same-origin` de la preview
bloquea eventos de envío nativo, incluidos los botones de filas de tipo submit.
Se conserva ese sandbox: los botones de filas son type=button en preview y su
click se delega al transporte administrativo. En formularios públicos siguen
siendo submit para mantener el fallback HTML. No se añade allow-forms al iframe.

El recorrido real en Joomla confirma añadir hasta el máximo, quitar la fila
intermedia, mantener valores y resolver opciones por fila después de reemplazar
el layout. También comprueba el foco en la fila nueva, los mínimos y el botón de
envío deshabilitado. Evidencia: tests/artifacts/joomla-repeated-preview-rows.png.
Esta comprobación cubre el proveedor remoto fixture.department; los grupos
anidados y widgets personalizados dentro de preview requieren su propio recorrido.

## Creación desde la paleta y preview anidada

La paleta permite crear grupos repetibles como borradores con límites iniciales
min=1 y max=5, independientes para cada nodo y editables en el inspector. La
publicación conserva su protección. Los controles de árbol existentes permiten
anidar, mover y eliminar ramas sin crear instancias de respuesta. La duplicación
remapea referencias internas y conserva los límites; una copia no referencia
implícitamente las filas de la rama original.

Un recorrido real sobre el borrador de pruebas 3599 crea Contacts dentro del grupo
existente, cambia su máximo a dos, añade un campo fixture.upper y guarda. La preview
muestra un contacto por cada padre; añadir un segundo contacto al primer padre
respeta su máximo sin afectar al segundo. Tras quitar el primer hijo quedan GAMMA
y BETA normalizados e independientes; el envío sigue deshabilitado. Evidencia:
tests/artifacts/joomla-nested-preview-authoring.png y
build/nested-preview-browser-results.json. Esto cubre un proveedor instalado, no
todos los widgets externos ni la aceptación completa de publicación.

## Editor de correlación de búsquedas

El catálogo de campos indexados comunica si cada campo admite `same_instance`,
según su ascendencia repetible y la capacidad del proveedor seleccionado. El
editor ofrece un nombre opcional de grupo de filtros con ayuda en inglés/español.
El mismo nombre exige coincidencia dentro de una fila; vacío mantiene predicados
independientes. El servidor sigue validando ámbitos idénticos y ancla positiva.

La serialización del formulario y de las vistas guardadas conserva ese nombre.
Si una selección deja de admitir correlación, el valor existente permanece visible
y bloquea la validación nativa hasta que el administrador lo corrija o vacíe;
no se elimina silenciosamente una restricción de una consulta guardada.

## Presupuesto agregado de condiciones de reglas

Contar solo los efectos expandidos dejaba sin acotar la suma de árboles de
condiciones copiados por efecto y fila. El compilador suma todos sus nodos,
incluidos los grupos lógicos, multiplicados por los máximos del destino. Informa
`rule.repeatable.condition_budget` en la condición que supera 10000 nodos.
`RepeatedRuleExpansion` incrementa un contador compartido durante la copia y
rechaza el exceso antes de construir el resto del grafo. Esta defensa se comparte
entre reglas de servidor y proyección pública. Las filas inicialmente vacías no
ocultan al compilador el coste potencial; el runtime cuenta las filas efectivas.
