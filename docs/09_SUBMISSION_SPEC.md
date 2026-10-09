# 09 — Submission Engine


> Proyecto: **Nicode Form Studio**  
> Estado del documento: **Especificación inicial normativa**  
> Plataforma objetivo: **Joomla 6.x**  
> Principios obligatorios: **SPEC-DRIVEN** y **DATA-DRIVEN**
>
> Convenciones: **MUST/DEBE** = requisito obligatorio; **SHOULD/DEBERÍA** = recomendado salvo causa documentada; **MAY/PUEDE** = opcional.  
> Todo cambio funcional deberá modificar primero o simultáneamente la especificación correspondiente y sus criterios de aceptación.


## 1. Responsabilidad

El Submission Engine recibe una petición y decide si genera una Submission válida, consistente, persistida según política y procesada por Actions.

## 2. Pipeline normativo

1. resolver Form y FormVersion;
2. comprobar disponibilidad;
3. comprobar access level/usuario;
4. comprobar origen/contexto;
5. comprobar CSRF cuando proceda;
6. ejecutar política CAPTCHA/anti-spam;
7. comprobar límites/rate policies;
8. extraer payload permitido;
9. normalizar;
10. ejecutar Rule Engine servidor;
11. resolver opciones activas;
12. validar campos;
13. validar relaciones entre campos;
14. validar y almacenar temporalmente uploads;
15. generar idempotency/attempt semantics;
16. persistir Submission si procede;
17. persistir índice de búsqueda si procede;
18. finalizar archivos;
19. ejecutar Action Engine;
20. calcular respuesta post-submit;
21. registrar observabilidad/auditoría necesaria.

## 3. Modos de persistencia

Cada formulario podrá definir:

- almacenar submission completa;
- no almacenar respuestas tras procesamiento;
- almacenar solo metadata mínima;
- política especial aprobada.

El valor predeterminado del producto se definirá en configuración global y podrá sobrescribirse por Form.

La opción global `default_persistence` admite full (predeterminado), metadata y
none. Se copia al borrador al crear un formulario. Cambiarla no modifica borradores
existentes ni versiones publicadas; cada formulario conserva su elección explícita
y el editor permite cambiarla antes de publicar una nueva versión. Duplicaciones
e importaciones conservan la política de la definición copiada. Un valor global
inválido se rechaza, sin convertirlo silenciosamente en almacenamiento completo.

## 4. Submission canónica

Cuando se almacene, debe preservar:

- Submission UUID;
- Form;
- FormVersion;
- timestamp;
- valores normalizados;
- labels/metadata necesarios o resolubles desde snapshot;
- archivos;
- contexto permitido;
- resultado de procesamiento;
- Action status.

## 5. Idempotencia

El sistema debe mitigar dobles envíos accidentales.

Cada render/attempt podrá incorporar un token/identifier.

La idempotencia debe distinguir:

- retry técnico de la misma petición;
- una segunda submission legítima.

## 6. AJAX y no AJAX

Ambos modos usarán el mismo pipeline.

AJAX solo cambia el transporte/presentación de la respuesta.

Un rechazo conocido del servidor debe mostrar su mensaje localizado también en
AJAX (por ejemplo, sesión caducada o límite de frecuencia). No debe convertirse
en un error genérico de red. El cliente conserva los valores y el attempt para
permitir la recuperación; solo un envío aceptado aplica reset, hide, redirect o
next_attempt. Una respuesta ilegible o un fallo de transporte mantiene el mensaje
de resultado no confirmado.

Un rechazo conocido del servidor debe mostrar su mensaje localizado también en
AJAX (por ejemplo, sesión caducada o límite de frecuencia). No debe convertirse
en un error genérico de red. El cliente conserva los valores y el attempt para
permitir la recuperación; solo un envío aceptado aplica reset, hide, redirect o
next_attempt. Una respuesta ilegible o un fallo de transporte mantiene el mensaje
de resultado no confirmado.

## 7. Estado y Action status

El éxito de persistencia y el éxito de las Actions son dimensiones diferentes.

Ejemplo:

- Submission guardada: sí.
- Email notificación: falló.
- Webhook: correcto.

La UI debe representar esa diferencia.

## 8. Formularios despublicados

El POST siempre vuelve a comprobar estado y permisos.

Haber renderizado anteriormente un formulario no concede derecho perpetuo a enviarlo.

Una cuenta bloqueada no recibe niveles de acceso para formularios, aunque su
sesión Joomla siga abierta. El contexto vuelve a consultar la identidad vigente
y el POST rechaza el formulario antes de persistir. La misma restricción se
aplica al renderizado y a los servicios públicos que reutilizan ese contexto.

## 9. Límites

Políticas futuras/initiales configurables:

- unlimited;
- one per user;
- one per session;
- global maximum;
- date window;
- optional custom limiter provider.

## 10. Contexto de canal

Se registrará de forma controlada si la submission vino de:

- component page;
- module;
- future content plugin;
- API futura.

Nunca se confiará en un channel enviado libremente por el navegador sin validación.

## Límites de tamaño del transporte

Si Content-Length supera el post_max_size efectivo de PHP, el controlador rechaza
el envío antes del pipeline con HTTP 413 y request_too_large. El mensaje indica
que la respuesta no se guardó y propone reducir archivos/texto o contactar con la
administración. No expone límites internos ni datos enviados. El envío mejorado
recibe JSON según su cabecera Accept aunque PHP haya descartado el campo format;
el envío ordinario recibe HTML. La comprobación de tamaño no autoriza la petición
ni sustituye CSRF. Los recortes por variables o partes sin evidencia suficiente
para clasificarlos conservan el rechazo de sesión y no persisten datos.
