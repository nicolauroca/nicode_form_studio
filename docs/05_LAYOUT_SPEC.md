# 05 — Layout y estructura


> Proyecto: **Nicode Form Studio**  
> Estado del documento: **Especificación inicial normativa**  
> Plataforma objetivo: **Joomla 6.x**  
> Principios obligatorios: **SPEC-DRIVEN** y **DATA-DRIVEN**
>
> Convenciones: **MUST/DEBE** = requisito obligatorio; **SHOULD/DEBERÍA** = recomendado salvo causa documentada; **MAY/PUEDE** = opcional.  
> Todo cambio funcional deberá modificar primero o simultáneamente la especificación correspondiente y sus criterios de aceptación.


## 1. Tipos estructurales

El formulario podrá contener:

- section;
- group;
- fieldset;
- row;
- columns;
- panel;
- step;
- repeatable group;
- presentation elements;
- fields.

## 2. Árbol

La estructura será un árbol ordenado.

Cada nodo tendrá:

- UUID;
- parent;
- type;
- position/order;
- propiedades;
- reglas de visibilidad si corresponden.

Se permitirá anidación cuando el tipo de container la soporte.

`parent_uuid` ausente o `null` indica el nivel superior. Cualquier otro valor
debe ser un UUID válido antes de recorrer el grafo. Tipos o UUID malformados
producen `element.parent` en la propiedad concreta; nunca se utilizan como
claves de búsqueda ni se convierten implícitamente a otro tipo.

El compilador y el renderer comparten un máximo de 64 contenedores anidados.
Un campo u otro elemento hoja puede estar dentro del contenedor número 64;
un contenedor número 65, aunque esté vacío, se rechaza antes de publicar con
`layout.depth` en su `parent_uuid`. El orden de los nodos en la lista plana no
altera este límite. El renderer mantiene la defensa para snapshots externos.

## 3. Responsive

Los elementos podrán definir anchuras por breakpoint conceptual:

- desktop;
- tablet;
- mobile.

El FormSpec no debe almacenar clases Bootstrap obligatorias. El Renderer traduce el layout a HTML/CSS compatible con el frontend.

El renderer base emplea 12 unidades por contenedor, incluidos fieldset, filas y
columnas. Cada ancho se aplica a su intervalo: mobile por debajo de 40rem,
tablet desde 40rem hasta antes de 64rem, desktop desde 64rem. Un ancho ausente
equivale a 12 en ese intervalo; no hereda el valor de otro breakpoint. Los títulos,
legend y descripciones de pasos ocupan la fila completa. Los fieldsets conservan
su elemento legend y la agrupación semántica de sus controles.

El inspector ofrece un selector traducido por breakpoint con valores de 1 a 12
y «Ancho completo (predeterminado)». Esta última opción elimina la propiedad de
ese breakpoint; no altera los otros tamaños. Los valores explícitos se guardan
como enteros y se validan también en el compilador.

## 4. Builder

El Builder deberá ofrecer:

- drag & drop;
- insertar;
- mover;
- reordenar;
- duplicar;
- copiar/pegar cuando se implemente;
- eliminar;
- contraer;
- vista árbol;
- inspector de propiedades;
- selección múltiple futura.

El arrastre dentro del árbol mueve un elemento con sus descendientes. La zona
superior/inferior de una fila coloca antes/después; el centro de un contenedor
coloca dentro, al final. Un destino de nivel superior permite sacar elementos
de su grupo. Se muestra el destino durante el arrastre y se rechazan ciclos,
padres no contenedores y pasos anidados sin modificar el borrador. Se conservan
UUID, propiedades y referencias. Subir/bajar y el selector de contenedor ofrecen
la alternativa de teclado. El arrastre solo acepta elementos del mismo editor.

Cada rama con hijos puede contraerse con un botón de teclado que expone
`aria-expanded` y `aria-controls`. El estado de expansión es local al editor,
no modifica el borrador ni la publicación y se conserva al redibujar el árbol.
Seleccionar un elemento por diagnóstico, inserción o movimiento expande sus
ancestros para mostrarlo. Contraer no elimina ni reordena descendientes.

Duplicar un elemento copia su rama al mismo contenedor con UUID nuevos y nombres
de campo únicos. Copia propiedades, opciones locales, validadores de campo,
traducciones y los efectos de reglas que apuntan a la rama. Las referencias
internas apuntan a las nuevas identidades; las externas se conservan. No duplica
acciones, validadores globales ni política post-submit. Los providers usan sus
referencias declaradas para remapear; los valores literales no se sustituyen.
La operación guarda el borrador con revisión optimista y nunca publica la copia.

## 5. Agrupación semántica

`fieldset`/`legend` deberán utilizarse cuando exista agrupación semántica de controles, especialmente radio/checkbox groups.

Un grupo visual no debe confundirse necesariamente con un fieldset semántico.

## 6. Formularios multipaso

Un formulario podrá tener pasos.

Los pasos forman una secuencia de páginas: un Step no puede ser descendiente
de otro Step, tampoco a través de grupos intermedios. Sí puede contener grupos
y puede pertenecer a un contenedor general. El compilador rechaza la anidación
de pasos con `layout.step.nested`. El inspector excluye padres incompatibles,
también al mover un grupo que contiene pasos. Insertar un paso desde otro paso
o sus descendientes lo añade al contenedor exterior de ese paso.

Cada Step:

- title;
- description;
- rules;
- validation boundary;
- previous/next;
- progress metadata.

El título y la descripción del Step son texto plano traducible. El inspector
permite editar ambos; preview y frontend muestran la descripción escapada y la
asocian al contenedor mediante `aria-describedby`, con ID exclusivo por instancia.
Una descripción vacía se omite. El compilador rechaza descripciones no textuales.

Antes de avanzar se validarán los campos activos del paso según política.

Al pulsar Siguiente se validan los campos del paso actual. Una validación entre
campos con referencias declaradas a pasos activos posteriores se aplaza hasta
alcanzarlos, para no impedir acceder al campo que permite corregirla. Las
referencias a pasos anteriores o a campos fuera de pasos sí pueden evaluarse;
solo se muestran errores del paso actual durante esa navegación. El envío final
valida todos los campos activos y todas las relaciones, también las aplazadas.
Los validadores personalizados pueden declarar sus dependencias mediante
`config.fields` para participar en esta política de navegación.

Una Rule podrá ocultar un Step completo.

Una reevaluación conserva la identidad del paso actual si continúa activo,
aunque cambie su índice al mostrar u ocultar pasos anteriores. Si desaparece,
se selecciona el siguiente paso activo en el orden del formulario, o el último
anterior si no existe uno posterior. Un reset tras envío vuelve al primer paso
activo de la definición reevaluada.

## 7. Repeatable groups

La arquitectura contemplará grupos repetibles:

- mínimo de repeticiones;
- máximo;
- botón añadir/quitar;
- validación por instancia;
- identidad de cada instancia;
- serialización inequívoca.

Si no entran en la primera release, el FormSpec no debe bloquear su incorporación.

El tipo `repeatable-group` se ofrece en la paleta con mínimo 1 y máximo 5,
editables en el inspector. Se puede anidar, mover, duplicar y previsualizar como
borrador. La publicación conserva su semántica de filas repetibles y exige
límites válidos, referencias compatibles y un árbol dentro del presupuesto.
Un borrador importado conserva los nodos inválidos para corregirlos antes de
publicar; nunca se convierten silenciosamente en grupos estáticos.

## 8. Preview

La Preview administrativa DEBE utilizar el mismo Renderer.

Modos de viewport:

- desktop;
- tablet;
- mobile.

No deberá existir un renderer paralelo "solo de preview".

El selector administrativo ofrece 1280, 800 y 390 píxeles CSS, respectivamente.
El iframe conserva su anchura real dentro de un área con desplazamiento
horizontal si no cabe en el editor. Cambiar el tamaño no recarga la vista ni
descarta los valores introducidos durante la prueba. El selector tiene etiqueta
localizada y admite teclado; estas dimensiones no simulan hardware ni agentes
de usuario de dispositivos reales.

El selector administrativo ofrece 1280, 800 y 390 píxeles CSS, respectivamente.
El iframe conserva su anchura real dentro de un área con desplazamiento
horizontal si no cabe en el editor. Cambiar el tamaño no recarga la vista ni
descarta los valores introducidos durante la prueba. El selector tiene etiqueta
localizada y admite teclado; estas dimensiones no simulan hardware ni agentes
de usuario de dispositivos reales.

La identidad base de una respuesta repetida se define en ADR 0019: UUID del campo
más pares ordenados {group, instance} para sus ancestros repetibles. Cada fila usa
un UUID estable, independiente de su posición; anidar grupos añade pares a la
ruta. Sin repetición se conserva la clave UUID existente. Los codecs validan
sintaxis y profundidad, no sustituyen la comprobación de pertenencia al snapshot.
Este contrato está integrado en renderer, validación por fila, persistencia,
archivos, lectura histórica, búsqueda y contexto de acciones. Los grupos repetibles
válidos se publican por el mismo servicio de compilación y publicación que los
demás formularios, sin insertar snapshots por vías especiales.

El registro de instancias valida las declaraciones de filas contra la ascendencia
repetible del árbol. Sus listas conservan orden; campos ajenos, filas huérfanas y
máximos excedidos se rechazan. Los mínimos se reportan por scope para aplicarlos
según activación de reglas. Esta validación de pertenencia está conectada al
transporte y al pipeline compartido, incluidos los controladores públicos.

El compilador valida límites enteros `0 <= min <= max <= 10000` y calcula el coste
máximo del árbol, incluidas filas y elementos expandidos. La suma debe caber en el
presupuesto de 10000 del runtime, aunque los mínimos sean cero. Los efectos de
reglas se cuentan por instancia de su destino. Los árboles de condiciones
copiados por los efectos están acotados: la suma de nodos
de todas las condiciones de reglas expandidas no puede superar 10000. Se cuentan
tanto grupos lógicos como hojas, por cada efecto y cada instancia de su destino.
El runtime aplica el mismo límite mientras construye el grafo, antes de evaluarlo
o enviarlo al navegador. Las condiciones globales y los
validadores usan su contexto repetido más profundo para comprobar el presupuesto.
Las llamadas de todos los validadores de campo y de formulario se suman en un
único presupuesto de 10000. CAPTCHA no puede ubicarse en una rama cuya expansión
máxima produzca más de una instancia, ya que su ámbito es el formulario.
Estos diagnósticos siguen siendo obligatorios al publicar y no sustituyen la
defensa del runtime. Un error conserva el borrador y la versión activa.

El inspector permite corregir los límites de un grupo repetible ya presente en
un borrador. Ambos valores son obligatorios, enteros y acotados; cambiar uno
actualiza la restricción del otro sin sobrescribir valores inválidos ni crear
filas. La publicación exige que ambos valores y el árbol expandido sean válidos.

La previsualización usa la misma compilación semántica. Crea las filas mínimas
con RepeatedInstances y ejecuta PresentationState y FormRenderer compartidos.
Las consultas de opciones llevan sus declaraciones de filas y se validan contra
el borrador/revisión o la versión histórica autorizada. No hay token de envío ni
activación del formulario. Las filas de preview se añaden y quitan mediante un
POST administrativo con CSRF, revisión y pertenencia verificadas. Se conserva la
identidad del formulario y de las filas restantes; solo los campos nuevos reciben
sus valores iniciales. La operación no guarda borradores ni respuestas y el envío
permanece deshabilitado incluso si falla el cambio de filas.
El iframe conserva el bloqueo de envíos de su sandbox. Por ello, los botones de
filas de preview usan type=button con eventos administrados; los públicos siguen
usando submit para el fallback HTML.
