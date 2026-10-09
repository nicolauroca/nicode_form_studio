# 20 — Accesibilidad


> Proyecto: **Nicode Form Studio**  
> Estado del documento: **Especificación inicial normativa**  
> Plataforma objetivo: **Joomla 6.x**  
> Principios obligatorios: **SPEC-DRIVEN** y **DATA-DRIVEN**
>
> Convenciones: **MUST/DEBE** = requisito obligatorio; **SHOULD/DEBERÍA** = recomendado salvo causa documentada; **MAY/PUEDE** = opcional.  
> Todo cambio funcional deberá modificar primero o simultáneamente la especificación correspondiente y sus criterios de aceptación.


## 1. Objetivo

WCAG 2.2 AA como objetivo de diseño y testing.

## 2. Campos

- label programáticamente asociado;
- help mediante `aria-describedby` cuando proceda;
- errores asociados;
- `aria-invalid`;
- required comunicado;
- instructions antes del input cuando corresponda.

## 3. Grupos

Radio/checkbox groups:

- fieldset;
- legend;
- navegación coherente.

## 4. Errores

Tras submit inválido:

- resumen accesible;
- links/foco a campos;
- primer error enfoc-able;
- mensajes no basados solo en color.

La validación AJAX sincroniza el resumen y un mensaje de texto junto a cada
campo. Todos los controles de un grupo comparten la referencia a ese mensaje
en `aria-describedby`, sin perder las referencias de ayuda o del provider.
Al desaparecer el error se limpia el texto, se oculta su contenedor y se retira
su referencia y `aria-invalid`. Los ID de error son exclusivos por instancia.

El resumen renderizado en un envío tradicional enlaza a un contenedor de campo
con ID estable por instancia y `tabindex="-1"`, también en grupos de opciones.
Con JavaScript, el enlace abre el paso afectado y enfoca el primer control
habilitado no oculto; si no existe, enfoca el contenedor. El destino HTML sigue
existiendo sin JavaScript y no depende de índices ni del número de opciones.

## 5. Lógica dinámica

Mostrar/ocultar:

- mantiene orden de foco;
- no deja foco atrapado;
- anuncia cambios relevantes cuando proceda;
- campos ocultos no deben seguir generando errores invisibles.

Cuando una reevaluación desactiva un campo, se retira su error del resumen y
del mensaje asociado sin mover el foco del control que provocó el cambio.
Los errores de otros campos se conservan. Reactivar el campo no recupera un
error obsoleto; su siguiente validación determina el resultado actual.

Si la reevaluación oculta, deshabilita o retira el control enfocado, el runtime
busca el siguiente control utilizable en orden DOM dentro de la misma instancia,
o el último anterior si no queda uno posterior. Si no hay controles disponibles,
enfoca el área de resultado. No desplaza un foco válido colocado por un widget
ni toma el foco cuando la actualización empezó fuera del formulario.

## 6. Multipaso

- step actual identificable;
- progreso comprensible;
- navegación teclado;
- validación comunicada;
- títulos claros.

## 7. CAPTCHA

La accesibilidad dependerá del provider Joomla seleccionado; FormStudio debe renderizarlo correctamente y no degradar su soporte.

## 8. Builder Administrator

El Builder deberá ofrecer alternativa suficiente a drag & drop mediante controles de mover/reordenar accesibles.

## 9. Contraste y CSS

FormStudio no fijará colores que impidan al template cumplir contraste.

## 10. Testing

Pruebas automatizadas donde sea posible + revisión manual de teclado, lector de pantalla y flujos dinámicos en releases relevantes.

Aceptación de entrega 1.0.0 (2026-09-28): el usuario acepta explícitamente la
accesibilidad y dispensa la comprobación manual pendiente con lector de pantalla
para esta entrega. Se conserva la evidencia automática y de teclado existente;
no se declara ejecutada esa comprobación ni una certificación WCAG.
