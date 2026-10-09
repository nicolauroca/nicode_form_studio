# 24 — Post-submit, mensajes y experiencia posterior


> Proyecto: **Nicode Form Studio**  
> Estado del documento: **Especificación inicial normativa**  
> Plataforma objetivo: **Joomla 6.x**  
> Principios obligatorios: **SPEC-DRIVEN** y **DATA-DRIVEN**
>
> Convenciones: **MUST/DEBE** = requisito obligatorio; **SHOULD/DEBERÍA** = recomendado salvo causa documentada; **MAY/PUEDE** = opcional.  
> Todo cambio funcional deberá modificar primero o simultáneamente la especificación correspondiente y sus criterios de aceptación.


## 1. Principio

Configurar un Form incluye configurar qué ocurre **después** de pulsar Enviar.

No debe requerir editar templates o PHP.

## 2. Categorías de resultado

- success;
- validation_error;
- captcha_error;
- anti_spam_rejected;
- rate_limited;
- upload_error;
- persistence_error;
- action_partial_failure;
- action_blocking_failure;
- form_unavailable;
- permission_error;
- session/csrf error;
- unexpected_error.

## 3. Mensajes configurables

Cada Form podrá personalizar, con fallback global:

- encabezado de éxito;
- cuerpo de éxito;
- mensaje de validación;
- mensaje CAPTCHA;
- mensaje de rate limit;
- mensaje upload;
- mensaje temporal/retry;
- mensaje indisponible;
- error genérico.

No mostrar detalles técnicos al visitante.

`post_submit.messages.success_heading` configura el encabezado de éxito separado
del cuerpo `success`. Admite los mismos tokens seguros y traducciones por idioma,
con fallback a la cadena Joomla global; una cadena vacía lo oculta. Solo aparece
en resultados success, nunca en rechazos, procesamiento pendiente o fallos de
acciones. Ambos transportes lo presentan como encabezado escapado dentro del
estado de confirmación; no interpreta HTML.

Las claves de mensajes por formulario deben pertenecer al catálogo de categorías
configurables compartido con traducciones y el editor. Una clave desconocida o
mal escrita impide publicar y señala su ruta; no se ignora silenciosamente.
Las traducciones conservan el fallback por categoría. Los rechazos anteriores a
la autorización del formulario conservan el mensaje global seguro, sin cargar
texto privado de una definición a la que el visitante no tiene acceso.

## 4. Comportamiento de éxito

Opciones:

- mantener Form y mostrar mensaje;
- ocultar Form y mostrar mensaje;
- resetear Form;
- conservar determinados valores;
- mostrar un summary de respuestas autorizado;
- redirigir a Menu Item;
- redirigir a URL autorizada;
- ir a página/estado de confirmación;
- entregar identificador/reference code.

El editor permite seleccionar los campos cuyos valores se conservarán después
de un reset. Excluye passwords, archivos y campos sensibles. Una selección
importada que ya no sea válida permanece visible para poder retirarla, pero no
habilita su conservación en el servidor. Las selecciones se guardan por UUID de
campo; dentro de grupos repetibles se conserva el valor de cada fila por separado.

`post_submit.summary_fields` opta explícitamente por mostrar respuestas concretas
en la confirmación de éxito. El editor ofrece campos no sensibles y excluye
contraseñas y archivos; el compilador rechaza referencias ausentes o prohibidas.
El servidor vuelve a aplicar esas exclusiones al construir el resumen, usando
valores normalizados y etiquetas traducidas. No incluye contexto técnico ni
campos inactivos. Las filas repetidas mantienen su orden con etiquetas numeradas,
sin exponer rutas UUID. El resultado usa texto escapado, también tras ocultar el
formulario. Por omisión no se muestra un resumen.

## 5. Redirect

Configurable:

- Menu Item;
- internal route;
- approved URL.

Evitar open redirect: no redirigir a una URL enviada libremente por el visitante.

## 6. Mensaje condicional

El mensaje de success podrá seleccionarse condicionalmente.

Ejemplo:

- tipo soporte → mensaje A;
- tipo comercial → mensaje B.

Debe usar lógica declarativa.

## 7. Emails de notificación

Cero o varios.

Cada email:

- condition;
- to;
- cc;
- bcc;
- reply-to;
- subject;
- template/body;
- attachment rules;
- field inclusion;
- failure policy.

## 8. Autorespuesta

Un email al visitante puede configurarse como Action separada.

Debe:

- obtener destinatario de un Field Type email validado;
- permitir condition;
- tener subject/body;
- admitir tokens seguros;
- registrar ActionRun.

## 9. Respuesta al administrador

No confundir:

- notificación interna;
- autorespuesta al visitante.

Son Actions independientes.

## 10. Attachments

Se podrá decidir:

- adjuntar uploads;
- no adjuntar y proporcionar referencia segura;
- límites.

Por seguridad y tamaño, el default debería evitar adjuntar indiscriminadamente archivos grandes.

## 11. Partial Action Failure

Si Submission se guarda pero una Action non-blocking falla:

- al visitante se puede confirmar recepción;
- se registra el fallo;
- Administrator lo muestra;
- retry disponible.

El mensaje al usuario no debe afirmar que un email externo fue enviado si no lo fue, salvo que ese detalle sea irrelevante para la promesa comunicada.

## 12. Blocking Failure

Se debe especificar si:

- rollback es posible;
- Submission queda en error;
- se conserva para diagnóstico;
- el usuario puede retry sin duplicar.

La transacción exacta dependerá del tipo de Action; no se prometerá atomicidad distribuida imposible con servicios externos.

## 13. Form reset

Configurar:

- reset all;
- preserve selected fields;
- no reset.

En redirect no aplica salvo persistencia cliente explícita.

El reset restaura los valores iniciales de los campos no conservados. Los campos
seleccionados conservan también un valor vacío explícito. En grupos repetibles la
selección se configura por UUID de definición y se aplica a cada dirección de
campo de forma independiente, manteniendo las filas declaradas y su orden. Un
reset confirmado obtiene un intento nuevo. Un error de validación no es un reset:
mantiene los valores enviados, sin reponer un default sobre un campo borrado.

## 14. Reference code

Puede mostrarse un identificador seguro y no predecible de Submission para soporte.

No debe exponer un ID secuencial si eso crea riesgo.

## 15. Eventos post-submit

El motor expondrá eventos/hooks para integraciones, además de Actions declarativas.

## 16. UX AJAX

Durante submit:

- evitar doble click;
- indicar progreso;
- mantener accesibilidad;
- restaurar botón ante error;
- mover foco a resultado;
- tratar timeout sin asumir automáticamente que servidor no recibió el POST.

## 17. Confirmación antes de envío

Opción futura/compatible:

- página de revisión previa;
- checkbox de confirmación;
- summary.

El modelo multipaso deberá permitirlo.

## 18. Mensajes globales y overrides

Jerarquía:

1. Form override;
2. template/resource si aplica;
3. component global default;
4. language default.

## 19. Traducción

Todos los mensajes son traducibles.

## 20. Logging

La configuración de mensajes nunca debe provocar que el log guarde contenido personal innecesario.

## 21. Edición de mensajes condicionales

El constructor permite crear condiciones tipadas, mensajes y un orden explícito de prioridad. El primer mensaje coincidente sustituye la confirmación normal únicamente cuando el procesamiento ya no está pendiente ni bloqueado. Cada mensaje nuevo tiene un UUID estable; reordenarlo conserva su traducción. Los snapshots anteriores sin UUID siguen siendo válidos y mantienen su comportamiento. Duplicar o importar como nuevo formulario remapea los UUID de mensajes y sus traducciones junto con las referencias a campos.
