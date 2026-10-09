# ADR-0004 — Distribuir Component + Module + Library en Package

Status: Accepted

## Context

Página y módulo necesitan compartir runtime sin duplicar código.

## Decision

Distribuir `com_nicode_form_studio`, `mod_nicode_form_studio` y `lib_nicode_form_studio` mediante `pkg_nicode_form_studio`.

## Consequences

- instalación única;
- runtime común;
- dependencias explícitas;
- futuros plugins pueden añadirse al package.
