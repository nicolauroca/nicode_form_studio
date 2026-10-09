# 99 — Referencias técnicas


> Proyecto: **Nicode Form Studio**  
> Estado del documento: **Especificación inicial normativa**  
> Plataforma objetivo: **Joomla 6.x**  
> Principios obligatorios: **SPEC-DRIVEN** y **DATA-DRIVEN**
>
> Convenciones: **MUST/DEBE** = requisito obligatorio; **SHOULD/DEBERÍA** = recomendado salvo causa documentada; **MAY/PUEDE** = opcional.  
> Todo cambio funcional deberá modificar primero o simultáneamente la especificación correspondiente y sus criterios de aceptación.


Fecha de revisión inicial de referencias: **2026-09-26**.

Estas referencias documentan capacidades de Joomla utilizadas por la arquitectura. La especificación del producto no debe copiar ciegamente ejemplos antiguos; antes de implementar se comprobará la documentación de la versión objetivo exacta.

## Joomla Programmer Documentation

### Extensions y Packages
- https://manual.joomla.org/docs/next/building-extensions/
- https://manual.joomla.org/docs/5.4/building-extensions/packages/
- https://manual.joomla.org/docs/next/building-extensions/install-update/installation/

### MVC
- https://manual.joomla.org/docs/next/building-extensions/components/mvc/
- https://manual.joomla.org/docs/next/building-extensions/components/mvc/library-mvc/

### Administrator lists, filtros y paginación
- https://manual.joomla.org/docs/next/building-extensions/components/component-development-tutorial/step06-admin-list/
- https://manual.joomla.org/docs/next/building-extensions/components/component-development-tutorial/step12-filter-form/

### CAPTCHA
- https://manual.joomla.org/docs/next/general-concepts/forms-fields/standard-fields/captcha/
- https://manual.joomla.org/docs/next/general-concepts/forms-fields/standard-fields/plugins/
- https://manual.joomla.org/docs/next/building-extensions/plugins/plugin-examples/captcha-plugin/

La documentación actual indica que el Form Field CAPTCHA accede a un plugin CAPTCHA instalado y que los providers se registran mediante la infraestructura CAPTCHA de Joomla.

### ACL
- https://manual.joomla.org/docs/next/general-concepts/acl/acl-permissions/

### Web Asset Manager
- https://manual.joomla.org/docs/next/general-concepts/web-asset-manager

### CLI plugins
- https://manual.joomla.org/docs/5.4/building-extensions/plugins/plugin-examples/basic-console-plugin-helloworld/

### Requisitos técnicos Joomla 6.x
- https://manual.joomla.org/docs/next/get-started/technical-requirements/

En la revisión de 2026-09-26 la documentación de Joomla 6.x enumera PHP 8.3 como versión soportada mínima y MySQL, MariaDB y PostgreSQL entre las bases de datos soportadas. La matriz exacta de Nicode Form Studio se fijará independientemente y se probará.

### Cambios/deprecations CAPTCHA
- https://manual.joomla.org/updates/53-54/changed-deprecations/
- https://manual.joomla.org/updates/44-50/removed-backward-incompatibility/

## Principio de uso de referencias

Antes de implementar una API:

1. verificar documentación de la versión Joomla objetivo;
2. verificar deprecations;
3. preferir API moderna;
4. encapsular integraciones susceptibles de evolución;
5. añadir test de integración.
