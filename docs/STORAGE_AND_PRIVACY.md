# Almacenamiento de respuestas

En Configuración de Nicode Form Studio, «Almacenamiento predeterminado para
formularios nuevos» establece la política inicial. Elija una de estas opciones:

| Modo | Respuestas conservadas | Archivos después del procesamiento |
| --- | --- | --- |
| Completo (`full`) | Valores permitidos por la política de cada campo, etiquetas y consentimientos correspondientes. | Se conservan únicamente los archivos de campos que permiten persistencia. |
| Solo metadatos (`metadata`) | Registro del envío y su procesamiento, sin valores de respuestas, etiquetas de opciones ni consentimientos respondidos. | Los archivos temporales se eliminan. |
| Ninguno (`none`) | Se elimina el registro temporal del envío y sus acciones al finalizar el procesamiento. | Los archivos temporales se eliminan. |

El valor global se copia al crear el formulario. Cambiarlo no modifica formularios
existentes ni sus versiones publicadas. Para cambiar un formulario, edite su
política de almacenamiento y publique una nueva versión. Las respuestas anteriores
conservan la política de su versión original. Duplicar o importar una definición
conserva su política.

En modo completo, desactivar la persistencia de un campo impide guardar su valor.
Las contraseñas no se guardan aunque se active esa opción. Los índices de búsqueda
se construyen exclusivamente a partir de valores conservados y de las opciones
de indexación permitidas. Un campo sensible tiene controles independientes para
consulta, exportación, indexación e inclusión en correo.

Las acciones pueden utilizar valores y archivos durante la petición aunque no se
conserven como respuestas. Por ejemplo, un correo autorizado puede adjuntar un
archivo temporal. Elegir «Ninguno» no impide que un destinatario de correo o un
servicio externo reciba los datos que la acción tenga configurados para enviar.
Revise las acciones junto con la política de almacenamiento.

La relación con el usuario autenticado, la IP y User-Agent son opciones separadas.
IP y User-Agent están desactivados por omisión y solo se conservan con autorización
explícita en los modos completo o metadatos. En «Ninguno» no se recoge esa metadata
ni se conserva la relación con el usuario. La consulta de detalles técnicos exige
el permiso de datos sensibles; no se incluyen en índices ni exportaciones.

## Reintentos y procesamiento interrumpido

Todos los modos mantienen un recibo técnico para evitar repetir acciones al
reenviar la misma petición. Contiene identificadores, una huella HMAC de la
petición y un resultado de procesamiento; no contiene las respuestas en claro.
Caduca a las 24 horas. La limpieza programada elimina los recibos caducados.

En modo «Ninguno» puede existir durante el procesamiento una fila temporal sin
respuestas. Si el proceso se interrumpe, repetir la petición permite recuperar su
resultado sin volver a ejecutar una acción de resultado incierto. Si el visitante
no regresa, la limpieza de intentos caducados elimina esa fila conforme a su
versión histórica. Conserva acciones cuyo permiso temporal de ejecución sigue
vigente; vuelve a examinarlas en una ejecución posterior.

La tarea Nicode Form Studio del programador de Joomla debe ejecutarse regularmente
para que funcione la limpieza diferida. La limpieza de subidas abandonadas y los
trabajos de eliminación de archivos también utilizan ese sistema. La caducidad
no garantiza una eliminación a una hora exacta si la tarea está desactivada,
hay trabajos pendientes o el almacenamiento no está disponible.

## Cambios y eliminación

Cambiar de modo no borra respuestas antiguas. Utilice las operaciones autorizadas
de eliminación o anonimización y las políticas de retención para esos datos.
Estas operaciones revocan exportaciones afectadas y dejan trabajos durables para
eliminar los archivos asociados. Las políticas de retención y sus permisos tienen
su propia aceptación; no equivalen al modo de almacenamiento.

## Evidencia de aceptación

La matriz técnica está documentada en [REQUIREMENT_ACCEPTANCE.md](REQUIREMENT_ACCEPTANCE.md).
Incluye configuración nativa, los tres motores SQL, efectos fallidos o inciertos,
reintentos, limpieza programada y 24 casos HTTP de archivos. Esta guía describe
el almacenamiento implementado; no declara completa la extensión ni sus demás
requisitos de publicación.
