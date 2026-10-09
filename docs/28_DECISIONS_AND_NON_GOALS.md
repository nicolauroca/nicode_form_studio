# 28 — Decisiones y no-objetivos


> Proyecto: **Nicode Form Studio**  
> Estado del documento: **Especificación inicial normativa**  
> Plataforma objetivo: **Joomla 6.x**  
> Principios obligatorios: **SPEC-DRIVEN** y **DATA-DRIVEN**
>
> Convenciones: **MUST/DEBE** = requisito obligatorio; **SHOULD/DEBERÍA** = recomendado salvo causa documentada; **MAY/PUEDE** = opcional.  
> Todo cambio funcional deberá modificar primero o simultáneamente la especificación correspondiente y sus criterios de aceptación.


## Decisiones iniciales

### D-001 — Package
Component + Module + Library en un Joomla Package.

### D-002 — FormSpec
Runtime basado en snapshot publicado e inmutable.

### D-003 — Authoring separado
Builder relacional separado del runtime.

### D-004 — Hybrid submissions
Canonical payload + typed search projection.

### D-005 — Registries
Field Types, validators, Rules, Data Sources, Actions, Search y Storage extensibles.

### D-006 — Joomla CAPTCHA
No CAPTCHA propio.

### D-007 — Server authoritative
JS nunca autoridad.

### D-008 — No arbitrary code
Sin PHP/JS/SQL arbitrario.

### D-009 — Historical correctness
Submission siempre vinculada a FormVersion.

### D-010 — Massive operations as jobs
Export/reindex/retention masivos no dependen de una única request.

## No-objetivos iniciales

No se pretende en primera instancia:

- sustituir a un BI completo;
- ser un CRM;
- ser un workflow/BPM universal;
- ofrecer editor de código arbitrario;
- implementar CAPTCHA propio;
- implementar un motor de búsqueda externo propio;
- crear una tabla SQL por Form;
- garantizar atomicidad distribuida entre DB y sistemas externos;
- ser un constructor visual de páginas generalista.

Estas capacidades podrán integrarse mediante providers o Actions cuando tenga sentido.

## Decisiones pendientes para ADR

- DB compatibility matrix exacta de la primera release;
- formato exacto del canonical payload;
- estrategia de secrets;
- Job execution mechanism;
- search provider core;
- editor visual implementation;
- repeatable groups in v1;
- import format signature;
- retention defaults;
- upload storage default.
