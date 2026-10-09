# 21 — Testing


> Proyecto: **Nicode Form Studio**  
> Estado del documento: **Especificación inicial normativa**  
> Plataforma objetivo: **Joomla 6.x**  
> Principios obligatorios: **SPEC-DRIVEN** y **DATA-DRIVEN**
>
> Convenciones: **MUST/DEBE** = requisito obligatorio; **SHOULD/DEBERÍA** = recomendado salvo causa documentada; **MAY/PUEDE** = opcional.  
> Todo cambio funcional deberá modificar primero o simultáneamente la especificación correspondiente y sus criterios de aceptación.


## 1. Pirámide

### Unit
- compiler;
- validators;
- normalizers;
- Rule Engine;
- option resolver;
- FormSpec migration;
- search query builder;
- action policies.

### Integration
- database;
- Joomla ACL;
- mail;
- CAPTCHA adapter;
- storage;
- cache;
- HTTP providers.

### Functional/System
- Administrator;
- Builder;
- publish;
- module;
- menu item;
- submissions;
- search;
- exports;
- Actions.

### Security
- CSRF;
- XSS;
- SQL injection;
- option tampering;
- rule bypass;
- ACL bypass;
- malicious uploads;
- SSRF;
- header injection;
- CSV injection;
- direct POST.

### Performance
- form render;
- submit;
- search;
- deep browsing;
- exports;
- index rebuild.

## 2. Dataset de escala

Tests de performance deberán incluir datasets sintéticos:

- 100 forms;
- 1M submissions;
- distribución realista de fields indexados;
- varios millones de index rows;
- ActionRun history.

Se ampliará cuando se definan SLOs.

## 3. Criterios esenciales de aceptación

1. Crear Form sin código.
2. Formularios sin límite artificial.
3. Campos sin límite artificial.
4. Reordenar.
5. Agrupar.
6. Multipaso.
7. Validaciones.
8. Dependencias condicionales.
9. Opciones dependientes.
10. Despublicar.
11. POST a despublicado rechazado.
12. Menu Item lista Forms.
13. Menu Item renderiza Form.
14. Module lista Forms.
15. Module renderiza mismo runtime.
16. Dos instancias coexisten.
17. Validación cliente/servidor coherente.
18. Bypass JS no evita validación.
19. Opción manipulada se rechaza.
20. Draft no altera published.
21. Publish crea FormVersion.
22. Submission conserva FormVersion.
23. Store/no-store configurable.
24. Emails configurables.
25. Autorespuesta configurable.
26. Actions condicionales.
27. Action failure persistido.
28. Import/export.
29. Restore crea draft.
30. Install/update/uninstall nativos.
31. New Field Type sin schema por formulario.
32. New Action Type sin editar Forms existentes.
33. Ningún PHP por Form.
34. Views sin lógica de negocio significativa.
35. Input navegador no confiable.
36. Millón de submissions no obliga a cargar/scan completo.
37. Filtros por campos indexados usan índice.
38. Export masivo no requiere una única request web.
39. CAPTCHA usa provider Joomla.
40. Mensajes success/error son configurables.
41. El usuario puede consultar respuesta histórica correctamente.
42. Sensitive fields respetan ACL.
43. Búsqueda global respeta provider/capabilities.
44. Reindexar no altera canonical payload.
45. Un Action fallido puede reintentarse sin repetir exitosas.
