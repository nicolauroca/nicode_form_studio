# ADR-0020 — Estados administrativos completos de respuesta

Status: Accepted

## Contexto

El modelo de dominio exige new, viewed, processed, spam, archived y error. La
especificación de Administrator y la implementación habían reducido esa lista a
new, reviewed, archived y spam. Mantener la lista reducida omite estados normativos.

## Decisión

Se admiten los seis estados del dominio y se conserva reviewed como estado
adicional compatible. No se renombra ni transforma ninguna respuesta existente.
Son estados administrativos explícitos, independientes de action_status. Consultar
una respuesta no cambia su estado; los cambios requieren gestión, POST, CSRF y
comparación del estado anterior bajo bloqueo. Se admiten transiciones entre todos
los estados y el cambio al mismo estado no genera auditoría adicional.

La auditoría conserva y muestra únicamente from/to pertenecientes a esta lista.
Los selectores individuales y masivos comparten la constante del servicio. Las
columnas existentes son textuales y no requieren migración de esquema. La futura
extensión de workflows debe preservar las respuestas y controles ya existentes.

## Verificación

Matriz de 49 transiciones con conservación de payload, metadatos y action_status;
rechazos independientes de permisos, conflicto, formulario ajeno y estados
inválidos. Recorrido HTTP nativo con filtros de estado y conservación del contenido.
