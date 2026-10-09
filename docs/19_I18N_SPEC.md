# 19 — Internacionalización


> Proyecto: **Nicode Form Studio**  
> Estado del documento: **Especificación inicial normativa**  
> Plataforma objetivo: **Joomla 6.x**  
> Principios obligatorios: **SPEC-DRIVEN** y **DATA-DRIVEN**
>
> Convenciones: **MUST/DEBE** = requisito obligatorio; **SHOULD/DEBERÍA** = recomendado salvo causa documentada; **MAY/PUEDE** = opcional.  
> Todo cambio funcional deberá modificar primero o simultáneamente la especificación correspondiente y sus criterios de aceptación.


## 1. Interfaz estática

Textos del package mediante language files Joomla.

La paleta y los nombres de tipos del árbol usan etiquetas traducidas. Los
Field Types pueden declarar `label_key` en sus metadatos; Administrator registra
esa clave mediante Joomla Text. Si falta su traducción, se usa `metadata.label`
y después el identificador del provider. Los campos nuevos reciben esa etiqueta
como texto inicial editable; cambiar de idioma administrativo no sustituye las
etiquetas, títulos o textos existentes del formulario.

Inicialmente:

- es-ES;
- en-GB recomendado como base técnica/distribución.

## 2. Contenido dinámico

Traducibles:

- form title;
- description;
- labels;
- placeholders;
- help;
- options;
- validation messages;
- success/error messages;
- email subjects/bodies;
- step titles;
- consent text.

## 3. Idioma base

Cada Form tendrá idioma/base y traducciones o estrategia definida.

## 4. Histórico

La FormVersion debe permitir saber qué contenido/traducción correspondía a la versión publicada.

## 5. Fallback

Orden de fallback documentado:

- idioma exacto;
- base language;
- form default;
- system default según diseño definitivo.

Nunca mostrar una key interna al visitante si existe fallback válido.

## 6. Search

La indexación de textos de respuesta no debe asumir una única lengua.

La normalización debe ser configurable por SearchProvider.

## 7. Date/number

Render y parsing deben respetar semántica de Field Type y locale sin perder representación canónica interna.

## 8. Contrato de contenido versionado

`base_language` identifica el idioma predeterminado del formulario. `translations` es un mapa de etiquetas de idioma a objetos de presentación. Se resuelve cada propiedad en orden de preferencia: idioma exacto del visitante, idioma primario (por ejemplo `es`), idioma predeterminado del formulario y contenido original. Las traducciones pertenecen a la FormVersion; editar el borrador no modifica el histórico.

Los objetos admitidos son `form` (`name`, `description`), `fields[UUID]` (`label`, `help`, `description`, `placeholder`), `elements[UUID]` (`title`, `text`), `options[UUID]` (`label`) y `actions[UUID]` (`subject`, `body_text`, `body_html` de las acciones de correo). La descripción se conserva en `metadata.description`. Los UUID deben existir; una traducción no puede modificar destinatarios, valores, permisos, condiciones ni validadores. Cada variante se somete a las mismas comprobaciones del compilador que el contenido original.

El runtime deriva una proyección de presentación sin sustituir la identidad criptográfica de la versión original en persistencia o ejecución de acciones. Guarda el idioma de la respuesta, el texto de consentimiento mostrado y las etiquetas de opciones seleccionadas; los reintentos de correo utilizan ese mismo idioma. La lectura histórica obtiene etiquetas y presentación de esa versión y ese idioma.

`validation[UUID][code]` traduce los mensajes por código del campo, conservando como fallback `config.validation_messages[code]` y los mensajes estáticos del sistema. La misma configuración se aplica a validación cliente y servidor. `messages[category]` traduce las categorías de confirmación o error; no admite comportamiento, navegación ni destinos. Los mensajes de resultado permiten únicamente los tokens públicos ya admitidos por `post_submit`.

`conditional_messages[UUID].message` traduce un mensaje condicional identificado de forma estable, sin modificar su condición ni su prioridad. El constructor muestra estas entradas dentro de las traducciones de confirmación. La vista previa usa el idioma seleccionado en el editor de traducciones; también al consultar una versión histórica.
