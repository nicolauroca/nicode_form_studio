# 25 — Integración Joomla CAPTCHA y anti-spam


> Proyecto: **Nicode Form Studio**  
> Estado del documento: **Especificación inicial normativa**  
> Plataforma objetivo: **Joomla 6.x**  
> Principios obligatorios: **SPEC-DRIVEN** y **DATA-DRIVEN**
>
> Convenciones: **MUST/DEBE** = requisito obligatorio; **SHOULD/DEBERÍA** = recomendado salvo causa documentada; **MAY/PUEDE** = opcional.  
> Todo cambio funcional deberá modificar primero o simultáneamente la especificación correspondiente y sus criterios de aceptación.


## 1. Decisión

Nicode Form Studio **NO implementará un CAPTCHA propio**.

Utilizará la infraestructura CAPTCHA de Joomla y los proveedores/plugins CAPTCHA instalados y habilitados.

Esta decisión reduce:

- dependencia de un proveedor concreto;
- duplicación;
- mantenimiento criptográfico/anti-bot;
- configuración paralela.

## 2. Capacidades Joomla a utilizar

La integración debe poder:

- usar el CAPTCHA global por defecto de Joomla;
- seleccionar un plugin/proveedor CAPTCHA instalado cuando Joomla permita esa selección;
- desactivar CAPTCHA por Form si la política global/ACL lo permite;
- renderizar mediante la API/Field de CAPTCHA actual;
- validar mediante el provider Joomla actual.

## 3. Configuración global FormStudio

`Default CAPTCHA policy`:

- Joomla global default;
- specific available provider;
- none;
- required policy según instalación.

## 4. Configuración por Form

- inherit FormStudio default;
- Joomla default;
- specific installed provider;
- none si permitido.

Administrator debe listar providers disponibles, no una lista hardcoded.

## 5. Posición visual

Aunque CAPTCHA es política de seguridad del Form, el Builder debe permitir decidir su posición mediante un elemento de sistema, por ejemplo:

`System → CAPTCHA`

Restricciones:

- máximo definido por política;
- no duplicarlo accidentalmente;
- si no se coloca, Renderer puede usar posición predeterminada configurable.

## 6. Múltiples Forms en una página

Cada instancia deberá utilizar namespace/instance identity apropiado para evitar colisión entre CAPTCHA instances.

## 7. Publicación

Si un Form exige provider X y X no está instalado/habilitado:

- Compiler/Admin muestra ERROR;
- Form no debe publicarse o runtime debe fail closed si se deshabilita posteriormente;
- jamás omitir CAPTCHA silenciosamente.

## 8. Runtime

Orden recomendado:

- disponibilidad/access;
- session/CSRF;
- anti-abuse prechecks;
- CAPTCHA validation;
- payload completo/expensive work según necesidad.

El orden definitivo deberá evitar tanto bypass como trabajo innecesario.

## 9. Joomla y proveedores

FormStudio no debe asumir reCAPTCHA, hCaptcha, Turnstile u otra marca.

El provider lo decide el sitio Joomla.

## 10. Otros mecanismos anti-spam

CAPTCHA no reemplaza:

- CSRF;
- rate limiting;
- duplicate protection;
- input validation.

FormStudio podrá ofrecer controles complementarios como:

- timing;
- honeypot si se decide;
- rate limit;
- throttling;
- idempotency;

pero, cuando Joomla ofrezca una capacidad equivalente reutilizable, se preferirá integración con Joomla.

## 11. Honeypot

Si se incorpora un honeypot FormStudio, debe considerarse una heurística anti-spam, no un CAPTCHA.

También podría suministrarse por un provider CAPTCHA Joomla, por lo que no debe ser requisito obligatorio duplicarlo.

## 12. Configuración segura

No almacenar secretos de providers CAPTCHA dentro de FormSpec portable.

Los secretos pertenecen a la configuración del plugin/provider Joomla.

## 13. Error al visitante

CAPTCHA failure tendrá mensaje configurable/traducible.

No exponer detalles internos del provider.

## 14. Auditoría

No almacenar challenge tokens/secret responses salvo requisito técnico temporal y seguro.

## 15. Actualización futura

Joomla ha evolucionado su API CAPTCHA; FormStudio debe usar la API moderna disponible en Joomla 6 y encapsularla en un `CaptchaAdapter` interno para reducir acoplamiento.
