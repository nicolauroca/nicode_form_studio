# 17 — Instalación, actualización y desinstalación


> Proyecto: **Nicode Form Studio**  
> Estado del documento: **Especificación inicial normativa**  
> Plataforma objetivo: **Joomla 6.x**  
> Principios obligatorios: **SPEC-DRIVEN** y **DATA-DRIVEN**
>
> Convenciones: **MUST/DEBE** = requisito obligatorio; **SHOULD/DEBERÍA** = recomendado salvo causa documentada; **MAY/PUEDE** = opcional.  
> Todo cambio funcional deberá modificar primero o simultáneamente la especificación correspondiente y sus criterios de aceptación.


## 1. Package

Artefacto distribuible:

`pkg_nicode_form_studio.zip`

Constituyentes iniciales:

- `com_nicode_form_studio`;
- `mod_nicode_form_studio`;
- `lib_nicode_form_studio`.
- `plg_task_nicode_form_studio`, para el ejecutor de jobs nativo.
- `plg_extension_nicode_form_studio`, para auditar guardados de configuración
  nativa; habilitado al instalar y con estado respetado en actualizaciones.

Las dependencias internas deberán quedar declaradas.

El preflight del package se ejecuta antes de modificar piezas hijas. Comprueba
Joomla 6+, PHP 8.3+, extensiones mbstring/intl/bcmath/fileinfo/curl/zip y soporte
de leases SQL con MySQL 8.0.13+, MariaDB 10.6+ o PostgreSQL 14+ (ADR-0006). Estos mínimos
son requisitos de instalación; la matriz de versiones efectivamente probadas
se documenta por separado y no se infiere de esta validación.

## 2. Instalación

Proceso:

1. comprobar versión Joomla;
2. comprobar PHP;
3. comprobar DB soportada por nuestra matriz;
4. instalar library;
5. instalar component;
6. instalar module;
7. crear schema;
8. registrar schema version;
9. instalar configuración por defecto;
10. registrar assets/resources;
11. limpiar/inicializar caches necesarias;
12. health check;
13. mostrar resultado.

## 3. No depender de Compatibility Plugin

La compatibilidad Joomla 6 debe basarse en APIs actuales.

## 4. SQL

Habrá:

- install schema;
- update/migration scripts versionados;
- uninstall strategy.

Cambios de schema no se harán ad-hoc desde un Controller normal.

## 5. Actualizaciones

Una instalación puede saltar varias versiones.

Las migraciones deben aplicarse en orden.

Cada release puede incluir:

- DB migration;
- FormSpec migration;
- config migration;
- index rebuild requirement;
- background post-update job cuando un cambio sea demasiado grande para una petición de actualización.

## 6. Datos masivos durante updates

Nunca se asumirá que millones de submissions pueden reescribirse síncronamente durante la instalación.

Si una versión requiere:

- reindexar;
- recalcular;
- transformar payloads masivos;

se hará mediante proceso versionado, resumible e idempotente fuera del paso crítico de instalación.

## 7. Desinstalación

Configurable:

### Purge data
Eliminar:

- schema;
- cache;
- archivos propiedad inequívoca de FormStudio según política;
- temporales.

### Preserve data
Conservar:

- forms;
- versions;
- submissions;
- files según política;
- schema version.

Una reinstalación debe detectar datos conservados.

El modo predeterminado es conservación. El script nativo guarda los parámetros,
la versión de schema y las reglas ACL antes de que Joomla retire sus assets. Al
reinstalar restaura los assets por nombre y actualiza sus IDs, preservando reglas
de denegación y formularios previamente huérfanos. No depende de la library
durante la desinstalación. Véase ADR-0010.

## 8. Almacenamiento externo

No borrar automáticamente objetos externos que no sean inequívocamente propiedad del package.

## 9. Integridad del package

Cuando sea soportado por el manifest/package, impedir desinstalar una pieza hija necesaria dejando el resto roto.

## 10. Uninstall warnings

Antes de un purge destructivo, Joomla debe mostrar información suficiente sobre:

- formularios;
- submissions;
- files;
- tamaño aproximado;
- irreversibilidad.

La confirmación final se implementará conforme a capacidades Joomla.

## 11. Preparación de purga

El modo purge se activa mediante una revisión y confirmación explícitas en la
administración, no mediante una opción destructiva predeterminada. La preparación
bloquea admisiones y escrituras ordinarias, elimina formularios por jobs acotados
y espera la limpieza física de objetos y exportaciones. Sólo entonces habilita
la desinstalación nativa del package. El preflight comprueba de nuevo el estado
antes de retirar cualquier hijo. Un fallo conserva los registros necesarios
para reanudar; nunca se borra una outbox pendiente. Véase ADR-0012.

La preparación de purga usa el bloqueo exclusivo del checkpoint de schema para
esperar a escritores de uploads activos. Transfiere también reservas interrumpidas
a jobs de limpieza y no permite desinstalar antes de completarlos (ADR 0013).
Una reserva previa no autoriza escribir después de activar la purga. La pantalla
de confirmación incluye la cantidad de subidas pendientes de asociación/limpieza.
