# 00 — Product Vision


> Proyecto: **Nicode Form Studio**  
> Estado del documento: **Especificación inicial normativa**  
> Plataforma objetivo: **Joomla 6.x**  
> Principios obligatorios: **SPEC-DRIVEN** y **DATA-DRIVEN**
>
> Convenciones: **MUST/DEBE** = requisito obligatorio; **SHOULD/DEBERÍA** = recomendado salvo causa documentada; **MAY/PUEDE** = opcional.  
> Todo cambio funcional deberá modificar primero o simultáneamente la especificación correspondiente y sus criterios de aceptación.


## 1. Producto

**Nombre:** Nicode Form Studio.

Nicode Form Studio será un sistema integral de creación, publicación, procesamiento, consulta y administración de formularios para Joomla 6.

No será una colección de formularios programados. Será un **motor declarativo de formularios**.

> El código implementa capacidades; los datos definen cada formulario.

## 2. Objetivos

El producto DEBE permitir:

- crear una cantidad no limitada artificialmente de formularios;
- editar, duplicar, desactivar, publicar, archivar, enviar a papelera y eliminar formularios;
- construir cada formulario con una cantidad no limitada artificialmente de elementos;
- utilizar campos primitivos, elementos de presentación, contenedores, grupos, secciones y pasos;
- definir validaciones simples y entre campos;
- definir lógica condicional de visibilidad, obligatoriedad, habilitación, valores y opciones;
- definir dependencias entre listas y fuentes de datos;
- publicar cualquier formulario como una página seleccionable desde un elemento de menú Joomla;
- publicar cualquier formulario mediante un único módulo configurable;
- mostrar varias instancias del mismo o de distintos formularios en una misma página;
- almacenar de forma íntegra las submissions cuando la política del formulario así lo indique;
- consultar cómodamente cientos o millones de submissions desde Administrator;
- buscar, filtrar, ordenar, segmentar, inspeccionar y exportar respuestas;
- configurar qué ocurre después de un envío: persistencia, mensajes, emails, autorespuestas, redirecciones, webhooks y futuras Actions;
- mantener histórico de versiones del formulario;
- preservar el significado histórico de las respuestas;
- importar y exportar definiciones;
- funcionar con ACL, idiomas, assets, base de datos, mail y demás servicios Joomla mediante APIs modernas;
- integrar CAPTCHA mediante los mecanismos de Joomla y proveedores instalados.

## 3. Principio SPEC-DRIVEN

Toda capacidad significativa DEBE definirse antes de considerarse implementable:

1. requisito;
2. contrato de datos;
3. estados y transiciones;
4. permisos;
5. validaciones;
6. errores;
7. criterios de aceptación;
8. pruebas.

Los requisitos tendrán identificadores estables como `FORM-001`, `FIELD-001`, `SUB-001`, `RULE-001`, `ACTION-001`, `SEC-001`.

## 4. Principio DATA-DRIVEN

No existirán implementaciones como:

- `soporte.php`;
- `contacto.php`;
- `inscripcion.php`;
- `registro_evento.php`.

Existirá un único runtime capaz de interpretar la definición publicada de cualquier formulario.

También serán datos:

- campos;
- estructura;
- opciones;
- reglas;
- validaciones;
- acciones;
- emails;
- mensajes;
- comportamiento post-submit;
- política de persistencia;
- política de privacidad;
- comportamiento de publicación.

## 5. Declarativo, no ejecutable

DATA-DRIVEN no autoriza a guardar código arbitrario en base de datos.

El producto NO DEBE aceptar como funcionalidad estándar:

- PHP arbitrario;
- `eval`;
- JavaScript arbitrario;
- SQL arbitrario escrito en el Builder;
- rutas de archivo arbitrarias;
- plantillas capaces de ejecutar código.

Las expresiones admitidas deberán pertenecer a un lenguaje declarativo limitado y validable.

## 6. Escala

El diseño DEBE contemplar desde el primer día:

- cientos de formularios;
- formularios con cientos de campos si el caso lo requiere;
- millones de submissions;
- decenas o cientos de millones de valores indexables en escenarios extremos;
- exportaciones que no caben razonablemente en una única petición HTTP;
- búsquedas que no dependan de recorrer el payload completo de cada respuesta.

La escala no implica que la primera release deba incluir un clúster externo de búsqueda. Sí implica que el dominio y las interfaces no pueden impedir añadirlo.

## 7. Experiencia objetivo

Para un administrador funcional, crear un formulario debe parecer una operación de configuración.

Para un desarrollador, ampliar el sistema debe hacerse mediante contratos y registries, sin introducir excepciones por `form_id`.

Para soporte, una submission debe ser trazable desde su recepción hasta cada Action ejecutada.

Para auditoría, debe poder saberse qué versión de formulario y qué textos estaban vigentes en el momento del envío.

## 8. Calidad

La extensión DEBE perseguir:

- seguridad por diseño;
- accesibilidad WCAG 2.2 AA como objetivo;
- compatibilidad con Joomla 6.x declarada y probada;
- independencia razonable del motor de base de datos soportado por Joomla;
- ausencia de dependencia de APIs legacy;
- rendimiento predecible;
- trazabilidad;
- observabilidad;
- capacidad de migración futura.
