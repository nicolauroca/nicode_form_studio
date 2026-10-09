# 04 — Field Type Registry


> Proyecto: **Nicode Form Studio**  
> Estado del documento: **Especificación inicial normativa**  
> Plataforma objetivo: **Joomla 6.x**  
> Principios obligatorios: **SPEC-DRIVEN** y **DATA-DRIVEN**
>
> Convenciones: **MUST/DEBE** = requisito obligatorio; **SHOULD/DEBERÍA** = recomendado salvo causa documentada; **MAY/PUEDE** = opcional.  
> Todo cambio funcional deberá modificar primero o simultáneamente la especificación correspondiente y sus criterios de aceptación.


## 1. Objetivo

La capacidad de admitir "todos los inputs actuales y futuros" no se resolverá con una lista rígida.

Cada Field Type será una capacidad registrada.

Un Field Type deberá declarar:

- identifier;
- categoría;
- schema de propiedades;
- tipo lógico de valor;
- renderer;
- normalizador;
- serializer;
- validator;
- operadores de Rule admitidos;
- capacidades de indexación;
- soporte de default/prefill;
- soporte de readonly/disabled;
- soporte de múltiples valores;
- configuración administrativa;
- requisitos de assets;
- compatibilidad de exportación.

## 2. Field Types estándar

### Texto

- text;
- textarea;
- email;
- telephone;
- url;
- search;
- password;
- hidden.

### Numéricos

- integer;
- decimal;
- number;
- currency;
- range.

### Fecha y tiempo

- date;
- time;
- datetime-local;
- month;
- week.

### Selección

- select;
- multiselect;
- radio;
- checkbox;
- checkbox group;
- toggle;
- yes/no;
- button group.

### Archivos

- file;
- multiple files.

### Otros HTML

- color.

### Funcionales

- consent;
- captcha placement/system captcha element;
- anti-spam marker cuando proceda;
- calculated/read-only value;
- hidden/system value.

### Presentación

No son campos de entrada, pero se gestionarán en el mismo Builder:

- heading;
- subheading;
- paragraph/text;
- safe HTML;
- separator;
- spacer;
- notice/help block.

## 3. Campos compuestos

Conceptos como:

- nombre completo;
- dirección;
- contacto;
- rango de fechas;

DEBERÍAN modelarse como presets que crean varios campos primitivos cuando eso facilite búsqueda, reglas y exportación.

## 4. Propiedades comunes

- UUID;
- machine name;
- label;
- admin label opcional;
- description;
- help;
- placeholder;
- default;
- required;
- readonly;
- disabled;
- autocomplete;
- inputmode;
- width/layout;
- CSS class controlada;
- initial visibility;
- persist value;
- include in notification;
- include in exports;
- searchable/indexed;
- sensitive;
- translatable properties.

## 5. Restricciones específicas

Cuando corresponda:

- min/max length;
- regex/pattern seguro;
- min/max numeric;
- step;
- decimal scale/precision;
- min/max date;
- min/max time;
- allowed MIME;
- allowed extension;
- max file size;
- max files;
- min/max selections.

## 6. Prefill

Fuentes permitidas de valor inicial:

- constante;
- usuario Joomla;
- contexto autorizado;
- query parameter explícitamente permitido;
- otro campo;
- Data Source.

Todo prefill se normaliza y valida.

`field.prefill` declara `type` (`constant`, `user`, `context`, `query`, `field`
o `source`) y su valor, propiedad, clave o UUID según corresponda. Las propiedades
de usuario permitidas son `id`, `name`, `username` y `email`; usuarios anónimos
no reciben datos de perfil. El contexto lo construye el adaptador del servidor,
nunca se acepta del POST. Un parámetro URL debe estar nombrado explícitamente
en el campo y solo inicia campos editables; los parámetros inválidos se descartan.
Los valores editables no se reponen después de que el visitante los vacíe.

Los campos de solo lectura se recalculan al enviar. Las referencias a otros
campos participan en el grafo de dependencias, exigen tipos compatibles y no
pueden convertir datos sensibles en no sensibles. Si el origen está inactivo,
no se copia su valor. `source` usa las opciones marcadas como predeterminadas en
la fuente configurada, con revalidación de opciones al enviar. Las reglas tienen
prioridad sobre el valor inicial. Las constantes sensibles se eliminan de las
exportaciones portables con una nota de revisión.

Un input `hidden` editable sigue siendo entrada no confiable del visitante:
se normaliza y valida igual que el resto. Ocultarlo no le concede autoridad.
Para valores determinados por el servidor se usa `readonly`, `system` o
`calculated`; el POST no sustituye esos valores, incluso con tipos manipulados.

Los controles nativos que no admiten `readonly` (selecciones, booleanos, rango,
color y archivos) se presentan deshabilitados también después de reevaluar reglas.
Esto no cambia su estado semántico activo: el servidor obtiene su valor autorizado
sin depender de que el navegador lo envíe. Un valor explícito aportado por una
integración autorizada tiene prioridad sobre el prefill y no se sustituye en el
navegador por una copia derivada de otro campo.

## 7. Indexabilidad

Cada Field Type declarará cómo puede indexarse:

- keyword;
- text;
- integer;
- decimal;
- boolean;
- date;
- datetime;
- multi-value;
- non-indexable.

El Builder podrá permitir elegir si un campo debe participar en búsquedas/filtrado, dentro de las capacidades del tipo.

## 8. Extensibilidad

Añadir un nuevo Field Type NO DEBE exigir:

- alterar `forms`;
- crear columnas en `submissions`;
- modificar cada formulario;
- añadir `if field_type == ...` dispersos por todo el core.

Debe registrarse una implementación que cumpla el contrato.

El renderer y los hooks de navegador de un campo externo se registran junto a
su provider. `BrowserProviderInterface` declara el módulo y los estilos de WAM,
la versión y las claves públicas (ADR 0015). El navegador invoca read, normalize,
validate y update por instancia; no aplica silenciosamente el comportamiento de
un input de texto a un widget desconocido. El servidor conserva la validación
autoritativa y la extracción por UUID/nombres HTML nativos.

Las propiedades comunes se exponen en el inspector mediante metadata compartida:
etiqueta pública y administrativa, descripción, ayuda, visibilidad inicial,
readonly/disabled y clases CSS. La etiqueta administrativa solo identifica el
campo dentro del constructor; nunca sustituye la pública ni se envía al runtime.
Descripción y ayuda se muestran juntas, escapadas y asociadas al control.
Los campos textuales admiten autocomplete/inputmode y trim explícito (excepto
password, que conserva sus espacios). Las clases personalizadas son hasta ocho
tokens `nfs-custom-...`, con sufijo ASCII de 1–48 caracteres; no aceptan CSS libre
ni nombres que sustituyan clases reservadas del layout o del framework.

El campo `range` aplica mínimo 0, máximo 100 y paso 1 cuando se omiten o son
nulos. Estos valores se muestran en el inspector y se aplican por igual en
HTML, validación de navegador, publicación y envío servidor. Las sustituciones
explícitas admiten decimales exactos; el paso usa el mínimo efectivo como base.
Una configuración cuyo mínimo efectivo supera al máximo impide publicar.
En campos `integer`, mínimo, máximo y paso deben ser números enteros; se rechazan
fracciones para impedir una base de paso HTML incompatible con valores enteros.

El inspector refleja los valores efectivos de privacidad: correo y exportación
están desactivados por defecto para campos sensibles, conservando una elección
explícita del autor. Al activar indexación de un campo sensible muestra la
autorización adicional `allow_sensitive_index`; sin ella no se puede publicar.
En password, almacenamiento, correo y exportación se muestran desactivados y
no editables, conforme a las exclusiones obligatorias del servidor.

Si un provider rechaza la normalización de una copia derivada, el motor conserva
un error de tipo asociado al destino y presenta un valor vacío. El envío rechaza
ese campo incluso si es opcional, sin propagar el mensaje de excepción del
provider. Los errores se recalculan en cada iteración; una asignación o vaciado
explícitos por regla sustituyen el intento fallido y pasan por la validación final.
Un destino inactivo no aporta errores ni respuestas aceptadas.

La copia derivada en JavaScript usa el normalizador del campo destino, incluidos
providers de navegador. El normalizador compartido reside en field-normalization.js
para evitar dependencias circulares entre reglas y validación. Los rechazos
producen errores de tipo asociados al destino, conservados hasta validar aunque
el campo sea opcional; una asignación/vaciado explícitos por regla pueden recuperar
el valor, con validación posterior. La entrada original no se modifica.
