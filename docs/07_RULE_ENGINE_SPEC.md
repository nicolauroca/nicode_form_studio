# 07 — Rule Engine


> Proyecto: **Nicode Form Studio**  
> Estado del documento: **Especificación inicial normativa**  
> Plataforma objetivo: **Joomla 6.x**  
> Principios obligatorios: **SPEC-DRIVEN** y **DATA-DRIVEN**
>
> Convenciones: **MUST/DEBE** = requisito obligatorio; **SHOULD/DEBERÍA** = recomendado salvo causa documentada; **MAY/PUEDE** = opcional.  
> Todo cambio funcional deberá modificar primero o simultáneamente la especificación correspondiente y sus criterios de aceptación.


## 1. Modelo

Una Rule sigue:

`WHEN <condition tree> THEN <effects>`

Las condiciones admiten:

- AND;
- OR;
- grupos anidados;
- negación controlada cuando corresponda.

## 2. Fuentes de condición

- valor de campo;
- estado de campo;
- usuario autenticado;
- idioma;
- fecha/hora;
- canal/contexto;
- otros contextos registrados.

No se permite acceso genérico a variables PHP.

## 3. Operadores

Según Field Type:

- equals;
- not equals;
- contains;
- not contains;
- starts with;
- ends with;
- in;
- not in;
- empty;
- not empty;
- selected;
- not selected;
- greater than;
- less than;
- greater/equal;
- less/equal;
- between;
- before;
- after;
- safe pattern match.

El Field Type Registry determina compatibilidad.

## 4. Effects

- show field;
- hide field;
- show group/container;
- hide group/container;
- show/hide step;
- enable;
- disable;
- required;
- optional;
- set value;
- clear value;
- change/filter options;
- change default;
- activar/desactivar Action cuando sea parte del modelo de Action condition.

## 5. Opciones dinámicas

Una regla podrá provocar que un campo:

- cambie OptionSet;
- aplique filtro;
- envíe parámetros a Data Source;
- se vacíe si su valor deja de ser válido.

## 6. Prioridad

Cada Rule tendrá:

- enabled;
- priority;
- deterministic order.

Se definirá una semántica explícita para efectos múltiples sobre el mismo target.

## 7. Conflictos

El Compiler debe detectar conflictos inequívocos.

Ejemplo bloqueante:

- misma prioridad;
- misma condición;
- mismo target;
- `required`;
- `optional`.

Otros conflictos pueden ser warnings si el orden los hace deterministas.

## 8. Dependencias circulares

Se construirá un grafo de dependencias.

Ciclos que hagan indeterminada la evaluación impedirán publicación.

## 9. Cliente y servidor

Habrá dos evaluadores semánticamente equivalentes:

### Client Rule Engine
UX inmediata.

### Server Rule Engine
autoridad.

Manipular JavaScript no permitirá eludir reglas.

## 10. Estabilización

Las Rules que cambian valores/opciones pueden provocar nuevas Rules.

Las opciones de `change_options` conservan el contrato de identidad literal:
no se permiten valores duplicados dentro de la lista y los flags `enabled` y
`default`, si aparecen, deben ser booleanos. El compilador rechaza los valores
mal formados con una ruta hasta la opción o flag afectado, antes de publicar.

El Engine deberá evaluar hasta estado estable con:

- orden determinista;
- límite de iteraciones;
- detección de ciclo/no convergencia.

Una no convergencia será error de configuración.

## 11. Semántica de evaluación 1.0

Se evalúan las Rules por prioridad ascendente y UUID lexicográfico como desempate.
Cada iteración lee los valores activos de la iteración anterior y reconstruye
efectos sobre el estado inicial; una condición que deja de cumplirse retrae sus
efectos. Mayor prioridad se aplica después. Los ancestros ocultos/inactivos hacen
inactivos a sus descendientes. Valores inactivos se leen como null y no salen en
el resultado aceptado. Los cambios de valor/opciones requieren estabilización;
un estado repetido o el límite de 64 iteraciones generan error de configuración.
Los operadores numéricos usan decimales exactos; igualdad de texto no convierte
`01` en `1`. Los fixtures compartidos PHP/JS forman parte del contrato.

La compilación valida los operandos numéricos antes de publicar: admite enteros
JSON y cadenas decimales exactas, y rechaza flotantes JSON, exponentes, booleanos,
objetos y listas donde corresponde un escalar. `between` exige dos límites
ordenados. La igualdad y pertenencia conservan la posibilidad de comparar con
null; las comparaciones ordenadas requieren números. `empty` y `not_empty` no
requieren un operando numérico.

Los operadores externos pueden declarar `datatypes` para su compatibilidad con
campos existentes. Compiler y Builder suman esa compatibilidad a la declarada
por el Field Type; no modifica las capacidades de operadores core. Operadores
y efectos externos requieren el módulo de navegador versionado de ADR 0015.
Sus hooks reciben copias y se ejecutan dentro del mismo límite de estabilización;
un resultado inválido o no convergente deja indisponible esa instancia.
