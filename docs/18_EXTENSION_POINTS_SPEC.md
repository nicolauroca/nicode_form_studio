# 18 — Extension Points

> Proyecto: **Nicode Form Studio**  
> Estado del documento: **Especificación inicial normativa**  
> Plataforma objetivo: **Joomla 6.x**  
> Principios obligatorios: **SPEC-DRIVEN** y **DATA-DRIVEN**
>
> Convenciones: **MUST/DEBE** = requisito obligatorio; **SHOULD/DEBERÍA** = recomendado salvo causa documentada; **MAY/PUEDE** = opcional.  
> Todo cambio funcional deberá modificar primero o simultáneamente la especificación correspondiente y sus criterios de aceptación.

## 1. Objetivo

Extender FormStudio sin modificar core.

## 2. Registries

### FieldTypeRegistry
Campos.

### ValidatorRegistry
Validaciones.

### RuleOperatorRegistry
Comparadores.

### RuleEffectRegistry
Efectos.

### DataSourceRegistry
Fuentes.

### ActionRegistry
Acciones post-submit.

### StorageProviderRegistry
Files/payload storage si evoluciona.

### SearchProviderRegistry
Búsqueda de submissions.

## 3. Contratos

Cada provider debe declarar:

- ID estable;
- versión;
- config schema;
- capabilities;
- validation;
- lifecycle;
- security constraints;
- serializable metadata.

## 4. Eventos

Eventos conceptuales:

- BeforeFormRender;
- AfterFormRender;
- BeforeValidation;
- AfterValidation;
- BeforeSubmissionPersist;
- AfterSubmissionPersist;
- BeforeAction;
- AfterAction;
- ResolveDataSource;
- BeforeExport;
- AfterExport.

Los nombres y tipos finales deben ajustarse al sistema de eventos Joomla actual.

Los eventos nativos se llaman `onFormStudio` seguido del nombre conceptual y
reciben un `LifecycleEvent` tipado. `phase` y `context` son de solo lectura.
El contexto expone identificadores, versión, canal, idioma y resultado técnico;
no expone respuestas, credenciales, tokens de sesión ni rutas privadas. Los
providers siguen siendo el contrato para transformar/validar datos y renderizar
campos. Los listeners Before y Resolve pueden vetar mediante una excepción.
Un fallo BeforeAction se registra como fallo definitivo antes de ejecutar el
provider. Los listeners After son observacionales: sus fallos se registran sin
convertir una escritura o acción ya completada en un fracaso o repetirla.

Before/AfterValidation rodean la validación definitiva del envío, no la pasada
preliminar de presencia de archivos. Los eventos de persistencia solo se emiten
al intentar crear una respuesta, no al devolver una respuesta de replay ya
completada. AfterAction sigue al resultado persistido del intento. ResolveDataSource
se emite también para lecturas de caché. Before/AfterExport distinguen definición
JSON y fragmentos CSV; el contexto CSV incluye job, progreso y finalización. Los
listeners deben ser idempotentes ante reintentos de jobs; estos eventos no
sustituyen una cola transaccional para efectos externos.

## 5. Plugins Joomla

Cuando una capacidad encaje naturalmente en el ecosistema Joomla, se preferirá un plugin/provider Joomla antes que un mecanismo paralelo.

CAPTCHA es caso obligatorio de esta estrategia.

El grupo de plugins `formstudio` registra providers mediante
`onFormStudioRegisterProviders(ProviderRegistrationEvent $event)`.
El evento expone `kind` y `registry` como propiedades tipadas de solo lectura;
el registro admite altas durante el evento y queda cerrado al terminar.
Los tipos son `fields`, `validators`, `operators`, `effects`, `sources`,
`actions`, `storage`, `renderers`, `jobs` y `search`. Cada registro se inicializa
una vez por contenedor de runtime. No se permite reemplazar identificadores
existentes. Un campo personalizado debe registrar también su renderer;
no se asigna silenciosamente el renderer de campos core a tipos desconocidos.
La metadata de providers debe ser JSON serializable y su versión semántica.

## 6. Compatibility contracts

Un FormSpec publicado debe declarar dependencias de providers no-core.

Si falta un provider requerido:

- el Form no debe ejecutarse silenciosamente de forma incorrecta;
- Administrator debe mostrar error;
- render/submit debe fail closed según criticidad.

## 7. Versionado de provider

Cambios breaking deben versionarse.

El Compiler debe poder detectar configuración antigua incompatible.

El Compiler deriva `provider_dependencies`, un mapa de tipo de registro a
identificador y versión, a partir de todos los providers referenciados. Cada
publicación captura las versiones instaladas; no acepta una dependencia previa
incompatible. Para versiones estables >=1.0 se admite el mismo major sin
downgrade. Versiones 0.x y prerelease exigen coincidencia exacta. El render,
submit y ejecución/reintento de acciones comprueban el contrato antes de
ejecutar providers. La lectura histórica de respuestas sigue disponible aunque
falte un provider. Las instantáneas de desarrollo anteriores sin mapa solo
pueden comprobar existencia; no se les atribuye una versión histórica inventada.

## 8. No `if form_id`

### Contrato de navegador

Los campos, operadores y efectos externos deben declarar su módulo mediante
`BrowserProviderInterface` (ADR 0015). El asset debe existir en Web Asset Manager;
su módulo exporta `providers` con versión y hooks del contrato. No se aceptan
URLs desde FormSpec. La carga y los fallos se aíslan por instancia. La proyección
pública excluye cualquier configuración no declarada por el provider.
Los validators externos sin este contrato permanecen explícitamente server-only.
Debe probarse paridad PHP/JavaScript, falta de módulo, versión incompatible,
dos instancias y ausencia de filtración de configuración privada.

Está prohibido ampliar el producto introduciendo lógica del tipo:

`if ($formId === 12) Ellipsis`

Las variaciones deben expresarse mediante datos o providers.

## Almacenamiento de subidas gestionadas

Para recibir archivos HTTP, un StorageProvider implementa además
`StagedStorageProviderInterface`: `reserveKey()` genera una clave nueva sin crear
bytes y `putReserved()` realiza creación exclusiva sin sobrescribir objetos.
Una colisión debe lanzar `StorageCollision` antes de modificar bytes ajenos.
Las claves no se reutilizan; el runtime registra propiedad antes de escribir,
valida el StoredFile devuelto y consume esa propiedad al guardar la respuesta.
El token de staging es interno y nunca forma parte de la recepción pública.
Ver ADR 0013 para transacciones, recuperación y contrato de limpieza.
