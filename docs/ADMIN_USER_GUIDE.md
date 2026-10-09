# Guía de administración

## Organización del editor

Las pestañas superiores separan Campos, Lógica, Validación, Acciones, Privacidad,
Seguridad, Después del envío, Traducciones, Publicación, Permisos, Vista previa y
Versiones. Las secciones de Publicación y Permisos dependen de los permisos del
administrador. Solo se muestra una sección cada vez y los cambios del borrador
se conservan al pasar de una a otra.

**Vista previa** y **Versiones** abren su pestaña y cargan el resultado automáticamente.
Los errores de validación y los enlaces para localizar diagnósticos revelan la
pestaña del control afectado. Las flechas, Inicio y Fin permiten recorrer las
pestañas con el teclado; Intro o Espacio activa la seleccionada.

## Instalación y primera configuración

Instale el ZIP del paquete desde el instalador de extensiones de Joomla 6. El
paquete contiene componente, biblioteca, módulo y plugins de tareas y extensiones.
La entrega 1.0.4 está en `dist/pkg_nicode_easy_forms-1.0.4.zip`; consulte
[el estado y la aceptación](IMPLEMENTATION_STATUS.md).

El resumen del instalador muestra la versión, el contenido del paquete, los próximos pasos y enlaces a documentación y soporte.

Abra **Componentes → Nicode Form Studio** y sus opciones. El submenú de Joomla permite acceder directamente a Formularios, Respuestas, Trabajos, recursos y diagnóstico. Todas las pantallas ofrecen la barra de herramientas nativa: **Volver**, **Ir a**, **Ayuda** y, según sus permisos, **Opciones**. En el editor, **Guardar borrador**, **Compilar y publicar**, **Vista previa** y **Más acciones** están en esa barra. Volver desde un detalle conduce a su listado; los cambios pendientes mantienen el aviso de salida. Configure directorios
privados de archivos y exportaciones fuera del directorio público de Joomla,
permisos de acceso, persistencia predeterminada y límites de recursos. Configure
el correo y, si lo necesita, un proveedor CAPTCHA en Joomla. **Diagnóstico**
muestra los problemas de configuración; comprobar la configuración de correo
no equivale a probar una entrega.

Asigne los permisos de Joomla según el trabajo de cada administrador. Consultar
respuestas, revelar datos sensibles, exportar, reindexar y eliminar tienen
permisos independientes. Los permisos efectivos también dependen del formulario.

## Crear, revisar y publicar

1. En **Formularios**, cree un formulario con nombre y alias.
2. Añada campos desde la paleta y configure etiquetas, ayuda, opciones y
   validaciones en el inspector. Los nombres de máquina deben ser únicos;
   cambiar una etiqueta no cambia la identidad del campo.
3. Organice secciones, grupos, columnas, pasos o grupos repetibles. Puede usar
   arrastrar y soltar o los controles de contenedor y mover arriba/abajo. Defina
   mínimos y máximos para grupos repetibles.
4. Configure reglas, fuentes de opciones, traducciones y acciones según el flujo.
   Las reglas pueden mostrar, ocultar, habilitar o modificar campos; la validación
   del servidor sigue siendo obligatoria.
5. Guarde y compruebe la vista previa. Publicar valida la definición y crea una
   versión inmutable. Corregir el borrador no cambia el formulario ya publicado.

Si otro administrador ha guardado desde que abrió el editor, recargue y revise
sus cambios antes de guardar de nuevo. El historial permite consultar versiones
y restaurarlas como borrador; la restauración necesita una nueva publicación.
Duplicar e importar crean identidades propias. Exportar una definición no exporta
respuestas ni valores de secretos.

Para mostrar el formulario, cree un elemento de menú del componente o publique
un módulo Nicode Form Studio y seleccione el formulario. Ajuste en Joomla acceso,
idioma, posición y asignación de páginas. El módulo permite mostrar título y
descripción y elegir qué hacer si el formulario no está disponible.

## Datos, acciones y confirmación

Elija persistencia completa, solo metadatos o ninguna antes de publicar. Marque
campos sensibles y seleccione explícitamente su uso en correo, exportación o
índices. Las contraseñas no se conservan. La información no guardada no puede
recuperarse más tarde mediante un reintento.

Las acciones se ejecutan en el orden configurado y pueden tener condiciones y
fallos bloqueantes. Configure destinatarios, plantillas y adjuntos de correo de
forma explícita. Los webhooks usan HTTPS y referencias a secretos configurados
en el servidor, nunca el valor del secreto escrito en la definición. No se
reintenta automáticamente una entrega cuyo resultado sea incierto.

En la configuración posterior al envío, defina encabezado, mensajes, resumen
autorizado y comportamiento: conservar, ocultar, reiniciar o navegar al destino
permitido. Puede añadir mensajes condicionales y traducciones. El resumen solo
incluye los campos seleccionados que permiten su presentación pública.

## Consultar y gestionar respuestas

En **Respuestas**, seleccione el formulario y los filtros de metadatos o valores.
Las fechas se introducen en UTC. Los filtros por valores necesitan campos
indexados; todas las condiciones deben coincidir. Las columnas y vistas guardadas
facilitan consultas habituales. Cada respuesta conserva su versión original.

Abra una respuesta para revisar valores, archivos y resultados de acciones.
Revelar datos sensibles requiere permiso y queda auditado. Descargue archivos
mediante los enlaces protegidos. Un fallo definitivo elegible puede reintentarse;
las acciones completadas o con entrega incierta no se repiten.

Las operaciones masivas se aplican al resultado autorizado del filtro y se
ejecutan por lotes. Las exportaciones CSV/JSON aparecen en **Trabajos**; descárguelas
cuando finalicen, antes de su caducidad de 24 horas. La anonimización elimina los
valores personales y la eliminación retira la respuesta; compruebe la selección
antes de confirmar operaciones irreversibles.

## Reindexar y supervisar trabajos

En **Exportación y acciones masivas**, reindexe el formulario entero dejando
vacíos los selectores, o indique ID de respuesta y/o intervalo UTC. Estos
selectores son independientes de los filtros del explorador. Sin formulario
seleccionado, un administrador con permiso global puede reconstruir todos los
índices. El trabajador revalida además el permiso sobre cada formulario.

Publicar un cambio de indexación marca las respuestas antiguas como pendientes
y crea un trabajo de reconstrucción. El dashboard muestra cuántas quedan; los
filtros por valores las excluyen mientras están pendientes. El trabajo conserva
los datos originales y su privacidad histórica. Si el publicador no tiene permiso
de reindexar, un administrador autorizado debe solicitar la reconstrucción.

**Trabajos** muestra estado, procesados y fallos. Puede procesar un lote desde
esa pantalla o configurar la tarea programada Nicode Form Studio en el planificador
de Joomla. Un trabajo interrumpido puede continuar desde su cursor. Cancelarlo
detiene lotes posteriores; no deshace los lotes completados.

Consulte **Registro técnico**, **Auditoría** y **Diagnóstico** ante problemas.
Use el identificador de correlación para relacionar incidencias, sin copiar
respuestas privadas o secretos en solicitudes de soporte. Los índices pendientes
por un valor histórico fuera de los límites admitidos no se truncan automáticamente.

## Retención, actualización y desinstalación

Configure el plazo y la operación de retención antes de publicar. Se aplica la
política de la versión con la que se recibió cada respuesta. Las tareas de Joomla
ejecutan la retención y las limpiezas pendientes; compruebe que están habilitadas.

Realice una copia de seguridad antes de actualizar. Instale el nuevo paquete
completo. Compruebe Diagnóstico y un envío de prueba tras la actualización.
Despublicar, archivar o enviar a papelera no borra el historial. La eliminación
permanente del formulario exige revisión y confirmación y limpia sus archivos
mediante trabajos. La desinstalación conserva datos salvo preparación explícita
de purga; siga el flujo de revisión y espere la limpieza antes de desinstalar.

Para construir paquetes y ejecutar verificaciones, consulte
[Build and release](BUILD_AND_RELEASE.md) y [Testing guide](TESTING_GUIDE.md).


## Composing notification and autoresponse emails

In **Actions**, open an email notification or autoresponse. In **Email content**, choose **Send as: Plain text** or **HTML with a plain text alternative**. Existing configurations retain their previous delivery behavior until you change this selection. Switching to plain text preserves the stored HTML for later editing but does not send it.

Write the subject and message. Place the cursor where an answer belongs, choose a **Form field**, and click **Insert selected field**. **Include field label** inserts the field name with its answer; clear it for just the answer. **Insert all answers** inserts a dynamic summary of all submitted fields allowed in email, including eligible fields added to the form later. Passwords and fields excluded from email remain excluded. Individual dropdown/choice insertions use their option labels. Repeated values retain the existing ordered representation. File attachments remain an explicit, separate selection.

**Insert into** chooses the subject, plain text body or HTML body. The subject supports individual values; the multi-line all-answer summary is available only in message bodies. Inserted variables are replaced at delivery, so you never need to copy field UUIDs by hand.

HTML mode provides paragraph, heading, bold and italic controls around selected content, an editable HTML body and an isolated layout preview. The preview displays placeholders, not real responses, and blocks scripts and external resources. Plain text content is escaped when first converted to HTML. Open **Plain text alternative** to supply a separate message for clients that cannot display HTML; leave it empty to generate one from the HTML at delivery. Submitted values are HTML-escaped during substitution. Save the draft and publish to activate the changes.
