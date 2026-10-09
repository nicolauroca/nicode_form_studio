# Nicode Form Studio — documentación de producto y arquitectura


> Proyecto: **Nicode Form Studio**  
> Estado del documento: **Especificación inicial normativa**  
> Plataforma objetivo: **Joomla 6.x**  
> Principios obligatorios: **SPEC-DRIVEN** y **DATA-DRIVEN**
>
> Convenciones: **MUST/DEBE** = requisito obligatorio; **SHOULD/DEBERÍA** = recomendado salvo causa documentada; **MAY/PUEDE** = opcional.  
> Todo cambio funcional deberá modificar primero o simultáneamente la especificación correspondiente y sus criterios de aceptación.


Esta carpeta es la fuente documental de Nicode Form Studio. El objetivo es que el repositorio pueda construirse a partir de especificaciones explícitas, trazables y versionadas, sin depender de conocimiento implícito ni de formularios codificados individualmente.

## Idea central

Nicode Form Studio es un **motor genérico de formularios para Joomla**. El código implementa capacidades; los datos describen cada formulario. Crear, editar, publicar, despublicar, duplicar, versionar, importar, exportar o eliminar un formulario no debe requerir modificar PHP, JavaScript, SQL ni desplegar una nueva versión del paquete.

Un formulario debe poder publicarse al menos de dos formas:

1. como página del componente mediante un tipo de elemento de menú de Joomla;
2. mediante un único módulo Joomla capaz de seleccionar cualquiera de los formularios publicados.

Ambos canales deben utilizar el mismo motor de renderizado, reglas, validación, seguridad y procesamiento.

## Índice documental

La [guía de administración](ADMIN_USER_GUIDE.md) describe instalación,
publicación, respuestas, trabajos y mantenimiento para usuarios de Joomla.

| Archivo | Propósito |
|---|---|
| `00_PRODUCT_VISION.md` | Visión, alcance, principios, objetivos y no-objetivos |
| `01_ARCHITECTURE.md` | Arquitectura general del package y separación de responsabilidades |
| `02_DOMAIN_MODEL.md` | Entidades, relaciones, identidad y ciclo de vida |
| `03_FORM_SPEC.md` | Contrato declarativo FormSpec y compilación |
| `04_FIELD_TYPE_REGISTRY.md` | Tipos de campos, capacidades y extensibilidad |
| `05_LAYOUT_SPEC.md` | Estructura, grupos, secciones, columnas y multipaso |
| `06_VALIDATION_SPEC.md` | Validación cliente/servidor, normalización y errores |
| `07_RULE_ENGINE_SPEC.md` | Condiciones, efectos, dependencias y reglas dinámicas |
| `08_OPTIONS_DATASOURCES_SPEC.md` | Opciones, Option Sets, cascadas y Data Sources |
| `09_SUBMISSION_SPEC.md` | Pipeline completo de envío y persistencia |
| `10_ACTION_ENGINE_SPEC.md` | Acciones posteriores al envío |
| `11_ADMIN_UX_SPEC.md` | Navegación y UX completa del Administrator |
| `12_FRONTEND_SPEC.md` | Página, módulo, renderizado y múltiples instancias |
| `13_SECURITY_SPEC.md` | Modelo de amenazas y controles |
| `14_PRIVACY_RETENTION_SPEC.md` | Privacidad, retención, anonimización y datos sensibles |
| `15_ACL_SPEC.md` | Permisos Joomla y autorización por formulario |
| `16_DATABASE_SPEC.md` | Modelo físico de datos e índices |
| `17_INSTALL_UPDATE_UNINSTALL_SPEC.md` | Package, instalación, migraciones y desinstalación |
| `18_EXTENSION_POINTS_SPEC.md` | Registries, eventos, providers y extensiones futuras |
| `19_I18N_SPEC.md` | Internacionalización |
| `20_ACCESSIBILITY_SPEC.md` | Accesibilidad |
| `21_TEST_SPEC.md` | Estrategia de pruebas y criterios de aceptación |
| `22_RELEASE_SPEC.md` | Versionado, releases y Definition of Done |
| `23_SUBMISSION_SEARCH_SCALE_SPEC.md` | Millones de respuestas, búsqueda, filtros, índices y exportaciones |
| `24_POST_SUBMIT_UX_SPEC.md` | Mensajes, confirmaciones, emails, redirecciones y errores |
| `25_JOOMLA_CAPTCHA_ANTISPAM_SPEC.md` | Integración con CAPTCHA y mecanismos Joomla |
| `26_IMPORT_EXPORT_VERSIONING_SPEC.md` | Portabilidad, snapshots y restauraciones |
| `27_OPERATIONS_OBSERVABILITY_SPEC.md` | Logs, auditoría, diagnóstico, jobs y mantenimiento |
| `28_DECISIONS_AND_NON_GOALS.md` | Decisiones arquitectónicas y límites del producto |
| `29_REQUIREMENTS_TRACEABILITY.md` | Requisitos identificados y trazabilidad |
| `99_REFERENCES.md` | Referencias técnicas oficiales |
| `MASTER_SPEC.md` | Compilación integral de todos los documentos |
| `adr/` | Architecture Decision Records |

## Regla de autoridad documental

En caso de conflicto:

1. un ADR aceptado prevalece para la decisión concreta que documenta;
2. la especificación temática prevalece sobre el resumen de `MASTER_SPEC.md`;
3. los requisitos identificados en `29_REQUIREMENTS_TRACEABILITY.md` no pueden eliminarse silenciosamente;
4. cualquier modificación incompatible debe quedar documentada en un ADR y en el changelog de documentación.

## Reglas de diseño no negociables

- Ningún formulario tendrá un PHP propio.
- Ninguna tabla se creará por formulario.
- Ninguna columna se añadirá por cada campo creado.
- No se almacenará PHP, JavaScript o SQL arbitrario como lógica ejecutable del formulario.
- Módulo y página utilizarán el mismo runtime.
- La validación del navegador nunca será autoridad.
- La versión publicada será inmutable.
- Toda submission quedará asociada a la versión exacta contra la que se envió.
- El sistema debe poder gestionar cientos de formularios y desde cientos hasta millones de submissions.
- La administración de respuestas debe permitir búsqueda, filtrado, visualización, exportación, acciones masivas y explotación operativa sin obligar a descargar todo el dataset.
- CAPTCHA debe integrarse preferentemente mediante la infraestructura de proveedores/plugins CAPTCHA de Joomla; FormStudio no debe inventar un algoritmo CAPTCHA propio.
