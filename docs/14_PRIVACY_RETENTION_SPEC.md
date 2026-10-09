# 14 — Privacidad, retención y datos sensibles

La eliminación y anonimización esperan a las acciones con lease vigente. Un lease
caducado se invalida como resultado desconocido antes de borrar los datos, para
que un proceso interrumpido no bloquee indefinidamente la retención. Un worker
tardío no puede confirmar ese intento ni sobrescribir el estado anonimizado.

> Proyecto: **Nicode Form Studio**  
> Estado del documento: **Especificación inicial normativa**  
> Plataforma objetivo: **Joomla 6.x**  
> Principios obligatorios: **SPEC-DRIVEN** y **DATA-DRIVEN**
>
> Convenciones: **MUST/DEBE** = requisito obligatorio; **SHOULD/DEBERÍA** = recomendado salvo causa documentada; **MAY/PUEDE** = opcional.  
> Todo cambio funcional deberá modificar primero o simultáneamente la especificación correspondiente y sus criterios de aceptación.

## 1. Configuración por Form

- store submissions;
- store authenticated user relation;
- IP storage policy;
- User-Agent policy;
- retention;
- automatic deletion;
- automatic anonymization;
- sensitive fields;
- export visibility;
- email inclusion.

## 2. Minimización

La recogida de IP y User-Agent estará desactivada por defecto salvo decisión explícita de producto/configuración.

No se debe almacenar información técnica "por si acaso".

Las opciones independientes `privacy.store_ip` y `privacy.store_user_agent`
aceptan únicamente booleanos y están desactivadas por omisión. El editor muestra
ambas opciones y su política de consulta. Solo se capturan al persistir una
respuesta en modo `full` o `metadata`; `none` no las recoge ni conserva.
La IP procede de `REMOTE_ADDR`, validada y normalizada como IPv4/IPv6; no se
confía en cabeceras reenviadas ni en valores POST. User-Agent procede de la
cabecera HTTP (declarada por el cliente, no prueba de identidad), exige UTF-8,
elimina controles ASCII y se limita a 512 bytes sin cortar caracteres.

El payload canónico 1.0 admite el objeto opcional `request_metadata` con `ip`
y/o `user_agent`. Su ausencia mantiene la representación anterior. Estos datos
no forman parte de valores de campos, índices, contexto de reglas, plantillas
de Actions ni exportaciones. La consulta ordinaria solo indica su existencia;
la revelación explícita exige `formstudio.submissions.view_sensitive` y registra
el evento `submission.reveal_sensitive`, sin incluir los datos en el log.
Su metadata contiene `fields` (número de campos sensibles revelados, excluyendo
los públicos) y `request_metadata_items` (número de datos técnicos revelados).
Las lecturas ordinarias o denegadas no generan eventos de revelación exitosa.
La anonimización elimina el objeto entero; la eliminación retira la respuesta.
Un reintento conserva los metadatos de la primera solicitud persistida: no
forman parte del fingerprint de respuestas, pues el transporte puede variar.

La regresión automatizada cubre normalización, límites, activación independiente,
recogida diferida, modos de persistencia, replay, ACL, auditoría, exclusión de
exportación y anonimización en MariaDB, MySQL y PostgreSQL. La prueba HTTP nativa
comprueba la precedencia del transporte frente a POST y cabeceras reenviadas.

## 3. Sensitive field

Un Field marcado como sensible:

- no aparece en technical logs;
- puede excluirse de emails;
- puede excluirse de exports;
- puede requerir permiso específico de visualización;
- puede tener retención distinta;
- puede quedar fuera del índice de búsqueda.

## 4. Consent Field

Debe registrar de manera interpretable:

- accepted/not accepted;
- consent text/version reference;
- timestamp de submission;
- FormVersion.

Así puede conocerse qué texto se mostró cuando se obtuvo el consentimiento.

## 5. Retention

Políticas:

- indefinite explícito;
- N días/meses/años;
- delete;
- anonymize.

Los jobs de retención deben ser idempotentes y auditables.

La fecha de caducidad se calcula al recibir la respuesta con la política de su
FormVersion; meses y años se ajustan al último día del mes de destino. La rutina
del programador recorre el índice de caducidad mediante cursor y encola trabajos
por formulario y versión. La operación procede del snapshot original, no del
borrador ni de la versión publicada más reciente. Los trabajos usan como autor
al publicador de esa versión y revalidan su permiso específico de eliminación o
anonimización. Si ese permiso desaparece, fallan de forma visible sin cambiar los
datos. Publicar una política finita exige ese permiso además del de publicación.
Cada lote de retención confirma las mutaciones y el checkpoint conjuntamente;
archivos y exportaciones siguen las mismas reglas de revocación y limpieza que
las operaciones de privacidad manuales.

## 6. Derecho operativo de eliminación/anonimización

La UI debe poder:

- localizar submissions;
- anonimizar;
- eliminar conforme a permisos;
- registrar la acción administrativa.

## 7. Archivos

La retención de Submission y de sus files debe ser coherente.

Eliminar/anonymize debe tener reglas explícitas sobre:

- fichero;
- metadata;
- checksum;
- ActionRun logs.

## 8. Backups

La documentación deberá aclarar que eliminar de la base activa no equivale necesariamente a eliminar copias históricas del backup del sitio; las políticas de backup pertenecen también al operador del sitio.

## 9. FormSpec histórico

Preservar una FormVersion no obliga a preservar datos personales de submissions. Son dominios de retención separados.

## 10. Decisión de borrado operativo y artefactos

La anonimización core elimina valores, consentimiento, etiquetas capturadas,
relación de usuario, notas, índice y ejecuciones de Actions de la respuesta.
Mantiene únicamente encabezados operativos y la referencia aleatoria. La
eliminación también retira la fila principal. Ninguna modifica FormVersions.

La transacción retira la autorización de descarga de archivos y crea un trabajo
durable con sus claves opacas para borrar los objetos físicos. Se conservan
marcas de intento sin fingerprint de datos personales, evitando que un reintento
antiguo vuelva a introducir una respuesta eliminada. Una Action en ejecución
impide temporalmente la operación para evitar procesar datos durante su borrado.

La operación revoca las exportaciones existentes o en curso del formulario,
incluidas sus leases, y programa la retirada de sus artefactos privados. Esta
invalidación conservadora evita mantener descargas con datos recién eliminados.
Los ficheros ya descargados por un operador quedan bajo su política de custodia,
al igual que sus copias de seguridad.

## 11. Subidas interrumpidas

El ADR 0013 exige registrar propiedad antes de crear bytes privados. La reserva
dura una hora; su consumo y la asociación a la respuesta son atómicos. Un job
retira reservas caducadas y mantiene la obligación de limpieza física en el
outbox. El borrado del formulario y la purga incluyen estas reservas. No se borra
un archivo basándose únicamente en que su nombre o ruta parezca pertenecer a
FormStudio. El panel distingue reservas abandonadas de archivos ya asociados.
