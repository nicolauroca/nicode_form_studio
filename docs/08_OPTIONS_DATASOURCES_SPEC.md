# 08 — Options y Data Sources


> Proyecto: **Nicode Form Studio**  
> Estado del documento: **Especificación inicial normativa**  
> Plataforma objetivo: **Joomla 6.x**  
> Principios obligatorios: **SPEC-DRIVEN** y **DATA-DRIVEN**
>
> Convenciones: **MUST/DEBE** = requisito obligatorio; **SHOULD/DEBERÍA** = recomendado salvo causa documentada; **MAY/PUEDE** = opcional.  
> Todo cambio funcional deberá modificar primero o simultáneamente la especificación correspondiente y sus criterios de aceptación.


## 1. Option

Cada opción tendrá:

- ID/UUID;
- internal value;
- label;
- order;
- enabled;
- default flag;
- metadata opcional.

`label` y `value` nunca deben confundirse.

El valor de opción es una identidad literal: select, radio, button-group y sus
variantes múltiples conservan espacios, tabulaciones y ceros iniciales. No se
aplica trim ni conversión numérica; las selecciones múltiples eliminan únicamente
duplicados exactos. La pertenencia se comprueba contra las opciones habilitadas.

El flag `default` se aplica al render inicial si no existe un valor predeterminado
explícito del campo ni un valor confiable de prefill. Se elige la primera opción
habilitada y coincidente en campos simples, y todas las coincidentes en campos
múltiples, respetando el orden. Las dependencias se resuelven hasta converger.
No se repone automáticamente una selección que el visitante vació ni se corrige
un valor manipulado durante el submit. En campos de solo lectura, el servidor
recalcula ese valor inicial confiable. Los efectos de reglas conservan prioridad.

Los defaults explícitos y prefills constantes de selección se comprueban al
compilar contra los valores habilitados conocidos de opciones locales o snapshots
estáticos. Se incluyen opciones que puedan aportar reglas declarativas activadas.
Un valor imposible en todos esos conjuntos impide publicar. Esta comprobación
no demuestra disponibilidad en cada contexto: el servidor vuelve a validar la
pertenencia al enviar. Fuentes y efectos personalizados que calculan opciones
conservan esa validación de pertenencia en runtime.

Los defaults explícitos y prefills constantes de selección se comprueban al
compilar contra los valores habilitados conocidos de opciones locales o snapshots
estáticos. Se incluyen opciones que puedan aportar reglas declarativas activadas.
Un valor imposible en todos esos conjuntos impide publicar. Esta comprobación
no demuestra disponibilidad en cada contexto: el servidor vuelve a validar la
pertenencia al enviar. Fuentes y efectos personalizados que calculan opciones
conservan esa validación de pertenencia en runtime.

Ejemplo:

- label: `España`;
- value: `ES`.

## 2. Opciones locales

Adecuadas para listas propias de un formulario.

## 3. Option Sets

Recursos reutilizables:

- países;
- provincias;
- departamentos;
- especialidades;
- sí/no;
- clasificaciones internas.

Serán versionables.

Un FormSpec publicado debe apuntar a una versión determinada o incorporar snapshot suficiente para conservar semántica histórica.

Cada guardado de un Option Set crea una revisión inmutable con hash canónico y
control optimista de concurrencia. Sus opciones actuales son una proyección de
esa revisión. Los administradores con `formstudio.resources.manage` pueden
modificar recursos; quienes solo tengan `formstudio.forms.manage` pueden
consultarlos para componer formularios. Ambos requieren `core.manage`.
Las condiciones reutilizables usan nombres de parámetros; al aplicar una revisión
a un campo se enlazan explícitamente con UUIDs del formulario y se incorpora el
snapshot. Una revisión posterior del recurso no modifica ese snapshot.

## 4. Dependencias

Debe soportarse:

- País → Provincia;
- Provincia → Municipio;
- Categoría → Familia → Producto.

Una fuente puede depender de uno o varios campos.

Las fuentes de opciones se asignan a campos con datatype lógico `selection`,
incluidos los providers personalizados que declaren ese tipo. Los campos booleanos
simples, de texto, numéricos, temporales y de archivos no consumen una lista de
opciones: el compilador rechaza una fuente asignada a ellos con
`field.source.unsupported`, sin consultar la fuente. El prefill de valores escalares
conserva su contrato independiente.

Las fuentes de opciones se asignan a campos con datatype lógico `selection`,
incluidos los providers personalizados que declaren ese tipo. Los campos booleanos
simples, de texto, numéricos, temporales y de archivos no consumen una lista de
opciones: el compilador rechaza una fuente asignada a ellos con
`field.source.unsupported`, sin consultar la fuente. El prefill de valores escalares
conserva su contrato independiente.

## 5. Data Source Registry

Cada Data Source Provider declarará:

- identifier;
- config schema;
- input parameters;
- dependency parameters;
- output schema;
- value mapping;
- label mapping;
- cache capability;
- TTL;
- timeout;
- failure mode.

## 6. Fuentes iniciales

- static/local;
- OptionSet;
- entidades Joomla aprobadas;
- provider personalizado;
- HTTP/API provider futuro.

No habrá un textarea de SQL libre como funcionalidad estándar.

Las fuentes `joomla.categories` y `joomla.articles` consultan exclusivamente
categorías de `com_content` y artículos. Su alcance es una lista explícita de
categorías aprobadas: padres directos para categorías, categorías contenedoras
para artículos. Un parámetro opcional `category_field` elige una de ellas y debe
declararse como dependencia. Solo se devuelven entidades publicadas y accesibles
para los niveles de acceso e idioma del visitante, comprobando también sus
categorías antecesoras y el calendario de los artículos. El valor es el ID estable
de la entidad y la etiqueta su título. Nunca se exponen usuarios ni tablas libres.
El límite configurable de opciones es operativo: si se supera, la fuente falla
sin truncar silenciosamente. Estas fuentes se consultan de nuevo en cada petición
y no usan caché compartida, para revalidar cambios de acceso y publicación.

## 7. Seguridad

Un valor devuelto al navegador no se convierte por ello en confiable.

Al submit, el servidor deberá revalidar la opción contra la fuente correspondiente o contra snapshot/política válida.

Las fuentes no embebidas se resuelven mediante `form.options`, exclusivamente
por POST y con CSRF nativo, token de instancia ligado a sesión/canal y la versión
publicada vigente. Se comprueban acceso, idioma, calendario y dependencias de
providers antes de consultar. La respuesta no se cachea públicamente y contiene
solo valores, etiquetas y habilitación de campos activos; nunca configuración
privada del provider. El límite operativo de consultas es 120 por minuto y
formulario/contexto de visitante, independiente del límite de envíos.

## 8. Caché

Cada provider declara si admite cache y qué elementos del contexto forman parte de la cache key.

No se cachearán resultados dependientes de información sensible de distintos usuarios bajo una clave compartida incorrecta.

La clave incluye provider, versión, configuración, entradas declaradas, contexto
declarado y TTL. El adaptador nativo usa el backend configurado en Joomla y respeta
su activación; conserva una caché de petición si el backend no está disponible.
Cada entrada contiene su caducidad en segundos y se rechaza exactamente al vencer,
aunque la limpieza física del backend sea posterior. Solo se almacenan valores JSON.

La aceptación del backend nativo de archivos incluye procesos PHP independientes:
lectura/escritura en ambos sentidos, borrado visible para una instancia nueva y
rechazo en el segundo exacto de caducidad. La prueba usa claves sintéticas aisladas;
no equivale a aceptación de todos los backends admitidos por Joomla.

## 9. Errores

Políticas posibles:

- fail closed y mostrar error;
- lista vacía;
- fallback definido;
- retry limitado para llamadas remotas.

La semántica deberá configurarse explícitamente.

## 10. Recursos Data Source

Una fuente configurada en un borrador guardado puede capturarse como recurso
reutilizable. La captura aplica el contrato de portabilidad y elimina credenciales
antes de guardarla. Sus dependencias se presentan como parámetros nombrados y
se vinculan explícitamente a campos compatibles del formulario destino. Solo se
remapean referencias declaradas por el provider; los valores literales no cambian.

Aplicar una revisión copia la configuración validada y su procedencia al borrador.
No consulta el recurso mutable al renderizar ni modifica formularios ya publicados.
Actualizar o desactivar el recurso requiere permisos de recursos y control de
revisión. Desactivar impide nuevas aplicaciones, conservando la semántica de las
copias existentes. Las configuraciones se editan con el editor de su provider en
un formulario y se recapturan como nueva revisión del recurso.

Los defaults de opciones se eligen sobre el conjunto final tras aplicar filtros o
sustituciones por reglas. Un efecto que asigna o vacía el valor conserva su
prioridad, incluso si coincide con el valor anterior; no se repara mediante un
default una asignación explícita inválida. Esta evaluación también se aplica al
recalcular campos de solo lectura en el servidor. La verificación del navegador
para cambios interactivos posteriores se registra separadamente.

El runtime público recibe `option_defaults` únicamente para selecciones derivadas
de solo lectura sin override confiable. Las marcas `default` viajan en snapshots
y respuestas de opciones. El motor del navegador recalcula esos valores con las
opciones finales, respetando filtros, dependencias y efectos explícitos de valor.
Los campos editables no reciben esta instrucción y conservan su vaciado manual.

Las respuestas de opciones remotas se aplican de forma atómica para todos los
campos: una lista inválida conserva el estado anterior y exige reintento. El
cliente exige marcas `default` booleanas si están presentes y rechaza valores
de opción duplicados por identidad literal; distingue mayúsculas y minúsculas.
Las respuestas de peticiones anteriores no sustituyen el estado más reciente.

El disparo de consultas remotas observa las dependencias declaradas por fuentes,
los campos referenciados en condiciones de reglas y prefills de campo, y la
activación de los destinos remotos. Cambiar una respuesta ajena a ese conjunto
no inicia una nueva consulta. Se observan conservadoramente todas las condiciones
para conservar la semántica de reglas; el reintento explícito no exige cambios.
Esta selección afecta al disparo de consultas, no redefine el payload normalizado
ni las comprobaciones autorizadas que realiza el servidor.
