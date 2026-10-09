# 15 — ACL


> Proyecto: **Nicode Form Studio**  
> Estado del documento: **Especificación inicial normativa**  
> Plataforma objetivo: **Joomla 6.x**  
> Principios obligatorios: **SPEC-DRIVEN** y **DATA-DRIVEN**
>
> Convenciones: **MUST/DEBE** = requisito obligatorio; **SHOULD/DEBERÍA** = recomendado salvo causa documentada; **MAY/PUEDE** = opcional.  
> Todo cambio funcional deberá modificar primero o simultáneamente la especificación correspondiente y sus criterios de aceptación.


## 1. Base

FormStudio utilizará ACL Joomla.

Permisos de componente iniciales:

- `core.admin`;
- `core.options`;
- `core.manage`;
- `core.create`;
- `core.edit`;
- `core.edit.state`;
- `core.delete`.

Permisos específicos propuestos:

- `formstudio.forms.manage`;
- `formstudio.forms.publish`;
- `formstudio.submissions.view`;
- `formstudio.submissions.manage`;
- `formstudio.submissions.export`;
- `formstudio.submissions.delete`;
- `formstudio.submissions.view_sensitive`;
- `formstudio.resources.manage`;
- `formstudio.logs.view`;
- `formstudio.jobs.manage`.

Los nombres anteriores se fijan como identificadores definitivos. Se añaden
`formstudio.submissions.anonymize`, `formstudio.submissions.reindex` y
`formstudio.submissions.retry` para separar operaciones privilegiadas.
`Security/Permissions.php` enumera el contrato y `tools/acl.php` genera `access.xml`.
Un Form sin asset hijo válido no concede permisos administrativos por fallback.

## 2. ACL por Form

Los Forms podrán actuar como assets hijos del componente.

Casos:

- equipo A administra Form A;
- equipo B administra Form B;
- compliance puede ver respuestas pero no editar Forms;
- marketing puede exportar solo ciertos Forms.

## 3. Frontend access

Cada Form tendrá Joomla access level y reglas adicionales cuando proceda.

Comprobar en:

- render;
- Data Source requests;
- submit;
- file download;
- submission view futura de frontend.

## 4. Administrator

Cada Controller comprueba autorización.

Los servicios de administración de Forms exigen `core.manage` en el componente y
`formstudio.forms.manage` sobre el Form, además de la capacidad concreta:
`core.edit` para borradores e histórico; `formstudio.forms.publish` y
`core.edit.state` para publicación/desactivación; `core.delete` adicional para
enviar a papelera. Crear exige las capacidades de gestión y `core.create` en
el componente. La creación del asset y el guardado se confirman en la misma
transacción; un fallo del asset no puede dejar cambios parciales.

La View puede ocultar acciones no permitidas, pero eso es UX, no seguridad.

Modificar reglas ACL por Form exige además `core.admin` en el componente. La
operación modifica permisos de un grupo Joomla existente, conserva las reglas
de los demás grupos, consume la revisión optimista del Form y comprueba una huella
de las reglas del asset. Una modificación concurrente realizada fuera de FormStudio
también debe provocar conflicto, aunque no haya cambiado la revisión del Form.
El estado heredado se calcula mediante ACL Joomla; no se simula en JavaScript.



Consultar respuestas exige core.manage en el componente y formstudio.submissions.view
en cada Form; no exige gestión ni edición de Forms. El scope de búsqueda se
construye exclusivamente con assets y ACL del servidor. Añadir notas o cambiar
estado exige además formstudio.submissions.manage. El historial de auditoría
requiere formstudio.logs.view. Mostrar valores sensibles y descargar archivos
sensibles comprueba view_sensitive sobre el Form; la revelación es explícita,
mediante POST con CSRF y auditoría, y no se activa mediante parámetros GET.

## 5. Datos sensibles

`view_sensitive` puede ser un permiso adicional al simple `submissions.view`.

La UI deberá enmascarar/ocultar campos sensibles a usuarios sin permiso.

## 6. Export

Exportar es una capacidad distinta de visualizar y debe tener permiso independiente.

## 7. Auditoría

Las operaciones privilegiadas deberán quedar registradas:

- export;
- delete;
- anonymize;
- retry Action;
- reveal sensitive;
- cambios de configuración.
