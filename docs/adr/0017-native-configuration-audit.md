# ADR 0017 — Auditoría de configuración nativa

Estado: aceptado.

La configuración global se guarda mediante `com_config`, que no necesita arrancar
el componente configurado. Por ello, sus cambios no pueden auditarse fiablemente
desde un controlador de FormStudio ni desde el plugin de tareas.

El package incorpora un plugin del grupo nativo `extension`, habilitado al
instalarlo. Escucha `onExtensionAfterSave` y registra `config.security_saved`
exclusivamente para `com_nicode_form_studio` en el contexto `com_config.component`.
Cada guardado confirmado de esta configuración, incluidos sus permisos, deja
actor, fecha y correlación. No guarda valores anteriores/nuevos, rutas, hosts,
secretos ni parámetros. El evento significa guardado, sin afirmar que hubo una
diferencia material. El plugin no observa cambios directos mediante SQL.

La desactivación administrativa del plugin se conserva en actualizaciones y se
muestra en diagnóstico. El package pasa a contener cinco extensiones hijas.
