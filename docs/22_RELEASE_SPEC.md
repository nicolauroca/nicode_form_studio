# 22 — Release y Definition of Done


> Proyecto: **Nicode Form Studio**  
> Estado del documento: **Especificación inicial normativa**  
> Plataforma objetivo: **Joomla 6.x**  
> Principios obligatorios: **SPEC-DRIVEN** y **DATA-DRIVEN**
>
> Convenciones: **MUST/DEBE** = requisito obligatorio; **SHOULD/DEBERÍA** = recomendado salvo causa documentada; **MAY/PUEDE** = opcional.  
> Todo cambio funcional deberá modificar primero o simultáneamente la especificación correspondiente y sus criterios de aceptación.


## 1. Versionado

Semantic Versioning:

`MAJOR.MINOR.PATCH`.

## 2. Tres versiones relevantes

- package/software version;
- DB schema version;
- FormSpec schema version.

No deben confundirse.

## 3. Definition of Done de feature

Una feature no está terminada solo porque funcione manualmente.

Debe tener:

- requirement ID;
- spec actualizada;
- acceptance criteria;
- tests adecuados;
- implementación;
- ACL revisado;
- security review proporcional;
- i18n;
- accessibility cuando aplique;
- migration si aplica;
- docs;
- changelog.

## 4. Release checklist

- unit/integration/system tests;
- PHP/Joomla compatibility matrix;
- DB matrix declarada;
- package installation clean;
- upgrade from supported previous versions;
- uninstall modes;
- schema checks;
- FormSpec migration;
- assets build;
- language completeness;
- no deprecated APIs bloqueantes;
- security checks;
- performance smoke tests;
- changelog.

## 5. Breaking changes

Requieren:

- ADR;
- migration path;
- documentación;
- version bump acorde;
- compatibility analysis.

## 6. Soporte

`Information System` debe permitir recopilar diagnóstico sin exponer secretos ni payloads personales.

## 7. Release reproducible

El repositorio deberá definir cómo construir el package final a partir de source, assets y manifests.

## Auditoría del artefacto de desarrollo

El build de integración fija orden y fechas de entradas ZIP y verifica después
membresía exacta, contenido SHA-256, licencia, versiones coherentes de extensiones
y bytes de los paquetes anidados. Genera un inventario build-manifest.json con
release=false. La prueba de reproducibilidad exige dos builds idénticos en el
mismo entorno y rechaza archivos adicionales, alterados o ausentes en copias.
La igualdad entre toolchains distintos sigue requiriendo verificación específica;
esta prueba no habilita una release mientras queden gates de producto abiertos.
