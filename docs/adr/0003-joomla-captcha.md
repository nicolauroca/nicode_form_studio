# ADR-0003 — CAPTCHA mediante Joomla

Status: Accepted

## Context

Implementar un CAPTCHA propio duplica capacidades de ecosistema y crea mantenimiento de seguridad.

## Decision

Nicode Form Studio no implementará algoritmo CAPTCHA. Integrará providers/plugins CAPTCHA Joomla mediante adapter.

## Consequences

- independencia de proveedor;
- configuración compatible con el sitio;
- FormSpec no guarda secretos CAPTCHA;
- si un provider requerido falta, el Form falla de forma segura.
