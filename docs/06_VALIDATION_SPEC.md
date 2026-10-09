# 06 — Validación y normalización


> Proyecto: **Nicode Form Studio**  
> Estado del documento: **Especificación inicial normativa**  
> Plataforma objetivo: **Joomla 6.x**  
> Principios obligatorios: **SPEC-DRIVEN** y **DATA-DRIVEN**
>
> Convenciones: **MUST/DEBE** = requisito obligatorio; **SHOULD/DEBERÍA** = recomendado salvo causa documentada; **MAY/PUEDE** = opcional.  
> Todo cambio funcional deberá modificar primero o simultáneamente la especificación correspondiente y sus criterios de aceptación.


## 1. Principio

La validación de navegador mejora UX; la validación de servidor decide.

Cada valor seguirá:

raw input  
→ canonical input extraction  
→ normalization  
→ Rule evaluation/context  
→ type validation  
→ field validators  
→ cross-field validators  
→ accepted canonical value.

El envío mejorado agrupa los valores escalares y selecciones en una parte JSON
`nfs_values` del multipart para evitar truncamientos por `max_input_vars`.
Archivos, CSRF, CAPTCHA e identidad mantienen sus partes originales. El servidor
admite también `nfs` tradicional para formularios sin JavaScript, pero rechaza
mezclar ambos mapas. El mapa JSON debe ser un objeto UTF-8 válido de hasta 2 MiB;
este presupuesto protege el parser, no impone un número comercial de campos.
Los valores siguen sin ser confiables y recorren la misma validación autoritativa.
El empaquetado conserva todas las partes de archivo, sus bytes y su orden por
nombre incluso si un control aporta texto con el mismo nombre multipart. Solo
las cadenas se trasladan al mapa JSON; este nunca otorga procedencia de subida.
Una mezcla ambigua de valor escalar y selección para un UUID se rechaza antes de
modificar el multipart original.

## 2. Validadores estándar

- required;
- type;
- min length;
- max length;
- pattern;
- min;
- max;
- step;
- integer;
- decimal precision/scale;
- date min/max;
- time min/max;
- min selections;
- max selections;
- allowed file extension;
- allowed MIME;
- max size;
- max file count.

## 3. Validación entre campos

Debe soportar:

- A = B;
- A != B;
- A > B;
- A < B;
- A >= B;
- A <= B;
- fecha A antes de B;
- fecha A después de B;
- al menos uno en conjunto;
- exactamente N;
- rango coherente;
- confirmación de valor.

El constructor permite añadir, ordenar, editar y eliminar estas validaciones,
seleccionando explícitamente los campos en orden de evaluación. Los parámetros
del editor proceden del schema del provider: referencias de campo, cardinalidad
y número de valores exigidos. Una referencia eliminada permanece visible para
su reparación; nunca se reasigna silenciosamente a otro campo. Guardar conserva
el borrador y previsualizar/publicar ejecuta la validación autoritativa del schema.

Al compilar, los validadores core de dos campos exigen el mismo datatype lógico
y multiplicidad; integer y decimal son compatibles entre sí. La igualdad y
confirmación admiten selecciones múltiples con comparación ordenada de valores.
Los archivos no admiten comparación de valores. Las comparaciones de orden y
rango requieren valores escalares numéricos o temporales compatibles; before y
after requieren el mismo tipo temporal. Los recuentos at_least_one y exactly_n
admiten tipos heterogéneos. Una incompatibilidad produce `validator.compatibility`
en la ubicación original del validador e impide publicar. Esta política pertenece
a los providers core; los validadores personalizados conservan su propio contrato.

En comparaciones de pares, `false` es un valor booleano válido: confirmar true
contra false falla y confirmar false contra false pasa. Solo null, cadena vacía
y lista vacía omiten la comparación opcional. Los recuentos de campos cumplimentados
conservan otra semántica: false no cuenta, mientras que el cero numérico sí cuenta.

En comparaciones de pares, `false` es un valor booleano válido: confirmar true
contra false falla y confirmar false contra false pasa. Solo null, cadena vacía
y lista vacía omiten la comparación opcional. Los recuentos de campos cumplimentados
conservan otra semántica: false no cuenta, mientras que el cero numérico sí cuenta.

Al compilar, los validadores core de dos campos exigen el mismo datatype lógico
y multiplicidad; integer y decimal son compatibles entre sí. La igualdad y
confirmación admiten selecciones múltiples con comparación ordenada de valores.
Los archivos no admiten comparación de valores. Las comparaciones de orden y
rango requieren valores escalares numéricos o temporales compatibles; before y
after requieren el mismo tipo temporal. Los recuentos at_least_one y exactly_n
admiten tipos heterogéneos. Una incompatibilidad produce `validator.compatibility`
en la ubicación original del validador e impide publicar. Esta política pertenece
a los providers core; los validadores personalizados conservan su propio contrato.

## 4. Mensajes

Cada validador podrá tener:

- mensaje por defecto traducible;
- override a nivel de formulario;
- override a nivel de campo/regla.

No se expondrá información técnica sensible.

## 5. Campos inactivos

Después de ejecutar el Rule Engine servidor:

- un campo no activo no debe considerarse requerido;
- por defecto, valores enviados para campos inactivos se ignorarán y no persistirán;
- una política futura podría permitir conservarlos explícitamente, pero deberá ser consciente y documentada.

## 6. Valores de selección

El servidor DEBE comprobar que los valores recibidos pertenecen a:

- opciones estáticas válidas;
- OptionSet exacto;
- resultado válido de Data Source para el contexto;
- conjunto permitido por reglas.

Un `<select>` manipulado no puede introducir valores arbitrarios.

## 7. Normalización

Ejemplos:

- strings: política de trim definida;
- email: forma canónica conservando el valor válido;
- integer/decimal: conversión tipada;
- date/datetime: representación canónica;
- boolean: representación inequívoca;
- multi-value: array normalizado;
- files: metadata controlada.

No se aplicará un "sanitizado genérico" como sustituto de validación por tipo.

Los límites de longitud cuentan puntos de código Unicode después de normalizar,
sin componer ni descomponer texto: un emoji astral simple cuenta como uno y una
letra con marca combinante cuenta como dos. PHP usa UTF-8 explícito y JavaScript
itera puntos de código. El renderer no usa minlength/maxlength nativos, que
cuentan unidades UTF-16 y pueden truncar una entrada válida; ambos validadores
aplican los límites y muestran el error sin truncar el valor del visitante.

## 8. Errores

La respuesta de validación estructurada deberá contener:

- error code;
- field UUID/machine name cuando proceda;
- mensaje de usuario;
- severidad;
- metadata no sensible.

El frontend podrá mostrar:

- resumen;
- mensaje junto al campo;
- foco en primer error.

## Comparación temporal exacta

Los tipos date, time, datetime-local, month y week validan calendario real y
límites min/max al compilar. Usan años de cuatro dígitos entre 0001 y 9999;
las semanas siguen ISO 8601. Hora y datetime local admiten minutos o segundos,
sin zona horaria ni fracciones. Para límites y operadores, los segundos omitidos
son cero: 12:00 y 12:00:00 representan el mismo valor. Un límite mal formado o
invertido impide publicar. PHP y JavaScript usan los mismos casos de aceptación,
incluidos bisiestos, semana 53 y límites inclusivos; no delegan esta semántica
exclusivamente en la validación HTML del navegador.

La proyección SQL de fechas y datetime se limita al intervalo portable 1000–9999.
El servidor y el navegador rechazan fuera de ese rango con `index_date` antes de
persistir; los campos no indexados conservan soporte desde el año 0001. El
proyector aplica la misma defensa al reconstruir índices históricos.

## URL y color

El campo URL conserva la cadena aceptada y admite URLs absolutas ASCII HTTP o
HTTPS. Dominios internacionales requieren punycode y caracteres no ASCII del
path requieren percent-encoding. La validación de navegador comprueba protocolo
y ASCII además de la sintaxis nativa; PHP conserva la decisión autoritativa.
El campo color admite `#RRGGBB` con seis dígitos hexadecimales, sin abreviaturas
ni canal alfa. Los casos compartidos cubren estas políticas en ambos lenguajes.

Los controles HTML y `FILTER_VALIDATE_EMAIL` no comparten exactamente la misma
gramática. La validación de correo autoritativa conserva la política de PHP
(sin dominios sin punto, plegado ni comentarios); la comprobación HTML mejora
la UX y no acredita por sí sola equivalencia de todos los casos especiales.
La comprobación JavaScript anticipa también los rechazos por dominio sin punto,
etiquetas DNS inválidas o mayores de 63 caracteres, TLD numérico, caracteres
no ASCII y CR/LF. En partes locales sin comillas comprueba puntos consecutivos
o en los extremos y los límites de 64 caracteres locales y 254 totales.
Los casos compartidos verifican esas fronteras. Las partes locales con comillas
y los literales de dirección siguen sujetos a la gramática autoritativa PHP;
estas comprobaciones no afirman equivalencia completa con el parser de correo.
Para partes locales con comillas o dominios entre corchetes, el frontend permite
que el servidor decida el error nativo HTML `typeMismatch`. No omite required,
pattern, errores personalizados ni otras restricciones, ni altera proveedores
de correo personalizados. Esto evita impedir el envío de direcciones que PHP
acepta; una dirección especial mal formada continúa rechazándose en el servidor.
Referencias: [filtros PHP](https://www.php.net/manual/en/filter.constants.php) y
[controles HTML](https://html.spec.whatwg.org/dev/input.html).
