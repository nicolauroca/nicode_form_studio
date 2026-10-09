# 12 — Frontend

> Proyecto: **Nicode Form Studio**  
> Estado del documento: **Especificación inicial normativa**  
> Plataforma objetivo: **Joomla 6.x**  
> Principios obligatorios: **SPEC-DRIVEN** y **DATA-DRIVEN**
>
> Convenciones: **MUST/DEBE** = requisito obligatorio; **SHOULD/DEBERÍA** = recomendado salvo causa documentada; **MAY/PUEDE** = opcional.  
> Todo cambio funcional deberá modificar primero o simultáneamente la especificación correspondiente y sus criterios de aceptación.

## 1. Publicación como página

El componente expondrá un Menu Item Type:

`Nicode Form Studio → Formulario`

Parámetro principal:

- `Formulario`: selector dinámico de formularios utilizables.

El Item de menú almacena el identificador del formulario, no una copia de sus campos.

El selector nativo es compartido por menú y módulo. Muestra formularios publicados
dentro del permiso administrativo `formstudio.forms.manage`; no expone títulos de
formularios fuera de ese ámbito. Conserva un ID previamente elegido aunque deje
de estar disponible y lo indica sin revelar su título. No impone un máximo de
formularios; recorre las páginas del servicio autorizado. La comprobación de
publicación, ventanas temporales, idioma y acceso del visitante sigue siendo
obligatoria en el runtime, independientemente de las opciones del selector.

## 2. Publicación como módulo

`mod_nicode_form_studio`

Parámetro principal:

- Formulario.

Parámetros secundarios, exclusivamente de contexto/presentación:

- mostrar título;
- mostrar descripción;
- class suffix/controlado;
- layout;
- comportamiento si no está disponible.

El módulo NO puede redefinir reglas, destinatarios, validadores o estructura.

`show_form_title` y `show_description` muestran el nombre y descripción traducidos
del snapshot publicado; están desactivados por defecto. El título propio del
módulo conserva el control nativo de Joomla. `form_class` acepta hasta diez nombres
de clase de 64 caracteres, con letras, números, guion o guion bajo y comienzo no
numérico; descarta tokens inválidos. `layout` usa los layouts y overrides nativos
de módulo. `unavailable_mode` elige omitir el contenido (predeterminado) o mostrar
un mensaje genérico, sin revelar título ni descripción del formulario inaccesible.

## 3. Form no disponible

Si un Menu Item o module referencia un Form:

- unpublished;
- fuera de fechas;
- sin permiso;
- inexistente;

el runtime aplica una política definida:

- 404;
- mensaje de indisponibilidad;
- módulo no renderizado.

El POST se rechaza independientemente de lo que hubiera mostrado una caché antigua.

## 4. Múltiples instancias

Cada render obtiene `instance_id`.

El DOM ID se deriva de:

- form identity;
- field identity;
- instance identity.

Dos instancias del mismo Form pueden coexistir sin:

- IDs duplicados;
- rules cruzadas;
- CAPTCHA namespace conflictivo;
- mensajes cruzados.

La inicialización JavaScript se aísla por instancia. Una definición o estructura
DOM inválida bloquea únicamente ese formulario con un mensaje traducido seguro;
no impide inicializar los siguientes. Los eventos de actualización de Joomla no
duplican listeners, incluso cuando el nodo actualizado es el propio formulario.
Un fallo de reglas durante interacción deshabilita envío y navegación hasta que
una reevaluación válida permite recuperarlos. No se muestran excepciones internas.

## 5. Assets

Se cargarán mediante Web Asset Manager.

Solo se activarán cuando exista una instancia que los necesite.

Dependencias declaradas en `joomla.asset.json`.

## 6. Presentación

Por defecto se hereda el template Joomla.

FormStudio aporta estilos mínimos estructurales.

Opciones:

- labels top/side;
- required marker;
- help position;
- spacing;
- columns;
- progress;
- validation summary;
- success/error container.

No se impondrá un framework CSS externo.

## 7. Submit

Soportar:

- traditional POST;
- AJAX.

Ambos contra controller/ruta Joomla y mismo Submission Engine.

## 8. Estados UX

Como mínimo:

- initial;
- validating;
- submitting;
- success;
- validation error;
- anti-spam/captcha error;
- transient processing error;
- unavailable.

## 9. JS

JavaScript se limitará a:

- client validation UX;
- Rule Engine cliente;
- dependent options;
- AJAX;
- progressive enhancement;
- accessibility behavior.

La seguridad y la verdad del estado permanecen en servidor.

## 10. Sin JS

Se definirá qué funcionalidades soportan fallback sin JavaScript.

Los formularios simples deberían poder enviarse; funcionalidades altamente dinámicas podrán requerir JS si la especificación lo declara, pero el sistema nunca confiará en JS para validar.

## Carga de assets tras renderizado

El componente solo activa CSS y JavaScript del runtime cuando obtiene HTML de un
formulario. Un fallo de renderizado con respuesta 503 y correlación no activa esos
assets por sí solo. Los módulos sanos de la misma página pueden necesitarlos y
Joomla Web Asset Manager los incluye una sola vez. Las páginas ajenas sin instancias
FormStudio no cargan sus assets; los assets administrativos no se activan en frontend.
