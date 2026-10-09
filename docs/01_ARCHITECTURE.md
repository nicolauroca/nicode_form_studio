# 01 — Arquitectura


> Proyecto: **Nicode Form Studio**  
> Estado del documento: **Especificación inicial normativa**  
> Plataforma objetivo: **Joomla 6.x**  
> Principios obligatorios: **SPEC-DRIVEN** y **DATA-DRIVEN**
>
> Convenciones: **MUST/DEBE** = requisito obligatorio; **SHOULD/DEBERÍA** = recomendado salvo causa documentada; **MAY/PUEDE** = opcional.  
> Todo cambio funcional deberá modificar primero o simultáneamente la especificación correspondiente y sus criterios de aceptación.


## 1. Unidad de distribución

Nicode Form Studio se distribuirá como un **Joomla Package**:

`pkg_nicode_form_studio`

Constituyentes iniciales:

### `com_nicode_form_studio`

Componente principal.

Responsabilidades:

- Administrator;
- Builder;
- formularios y versiones;
- submissions;
- recursos;
- configuración;
- Menu Item de frontend;
- controllers de envío;
- endpoints administrativos;
- reporting operativo;
- import/export.

### `mod_nicode_form_studio`

Módulo de Site.

Responsabilidad principal:

- seleccionar un formulario;
- aportar parámetros estrictamente de presentación del módulo;
- pedir al runtime compartido que lo renderice.

El módulo NO DEBE duplicar reglas, validación o procesamiento.

### `lib_nicode_form_studio`

Librería compartida.

Contendrá contratos y servicios reutilizables:

- FormSpec;
- Form Compiler;
- Field Type Registry;
- Layout Registry;
- Validator Registry;
- Rule Engine;
- Data Source Registry;
- Renderer;
- Submission Engine;
- Action Engine;
- Storage abstractions;
- Search abstractions;
- servicios comunes.

## 2. Extensiones futuras

La arquitectura DEBE permitir añadir sin rediseñar el núcleo:

- plugin de contenido para insertar formularios;
- Field Types adicionales;
- Validators adicionales;
- Data Sources adicionales;
- Actions adicionales;
- Storage Providers;
- Search Providers;
- integraciones REST;
- integraciones CRM/ERP;
- comandos CLI;
- tareas programadas.

## 3. Capas

### Domain

Entidades, value objects, políticas y contratos puros.

### Application

Casos de uso:

- crear formulario;
- guardar borrador;
- compilar;
- publicar;
- recibir submission;
- ejecutar Action;
- buscar submissions;
- exportar;
- eliminar/anonimizar.

### Infrastructure

Adaptadores:

- Joomla;
- base de datos;
- mail;
- filesystem;
- CAPTCHA;
- ACL;
- cache;
- HTTP;
- logs.

### Presentation

- Administrator;
- Site component;
- module;
- JSON endpoints.

No se exigirá una interpretación dogmática de Clean Architecture, pero una plantilla PHP NO DEBE contener consultas de negocio ni lógica de dominio.

## 4. Authoring Model y Runtime Model

La arquitectura separará:

### Authoring Model

Modelo editable y relacional:

- forms;
- elements;
- fields;
- rules;
- options;
- actions;
- translations.

### Runtime Model

Snapshot publicado, validado e inmutable:

`FormSpec`

El frontend deberá cargar normalmente una versión compilada, no reconstruir el formulario mediante docenas de queries.

## 5. Flujo de publicación

Borrador editable  
→ validación estructural  
→ resolución de referencias  
→ detección de ciclos  
→ validación de Actions  
→ compilación  
→ snapshot FormSpec  
→ versión publicada inmutable  
→ invalidación de cache correspondiente.

## 6. Flujo de ejecución

Request  
→ Joomla Controller  
→ autorización/contexto  
→ CSRF cuando corresponda  
→ anti-spam/CAPTCHA  
→ carga FormSpec exacto  
→ normalización  
→ Rule Engine servidor  
→ resolución de opciones  
→ validación  
→ procesamiento de archivos  
→ persistencia  
→ Action Engine  
→ Post-submit Response.

## 7. Joomla moderno

La implementación DEBE basarse en:

- MVC de Joomla;
- Dependency Injection;
- DatabaseInterface;
- ACL Joomla;
- Web Asset Manager;
- APIs actuales de formularios cuando sean adecuadas;
- sistema de plugins/eventos;
- servicios de mail;
- cache y logging de Joomla cuando sean adecuados.

No deberá depender de `JFactory` ni de APIs legacy como requisito de funcionamiento.

## 8. Independencia entre canales

Component page, module y futuros canales DEBEN utilizar:

- el mismo FormSpec;
- el mismo Renderer;
- el mismo Rule Engine;
- la misma validación;
- el mismo Submission Engine;
- el mismo Action Engine.

Solo podrá variar el contexto de renderizado.

## 9. Registro de capacidades

Los conceptos extensibles se resolverán mediante registries:

- FieldTypeRegistry;
- ValidatorRegistry;
- RuleOperatorRegistry;
- RuleEffectRegistry;
- DataSourceRegistry;
- ActionRegistry;
- StorageProviderRegistry;
- SearchProviderRegistry.

El core deberá conocer interfaces, no todas las implementaciones futuras.
