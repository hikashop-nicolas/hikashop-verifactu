# Changelog

## 0.34.83

Importes con coma decimal y punto de miles (1.234,56): en la pantalla "Registro de
facturación VeriFactu" (columna Total) y en la exportación CSV (total, base
imponible, tipo de IVA, cuota, tipo de recargo y recargo). El XML enviado a la
AEAT y el ZIP de XML no cambian: el esquema oficial exige el punto decimal.

## 0.34.82

Columna "Rectificaciones R1" del listado de pedidos: queda vacía cuando el pedido
no tiene rectificaciones ni anulación (solo la factura F1/F2/F3 original); sigue
mostrando el número si las hay y "Anulado" si la factura está anulada.

Campo "Anular factura": cuando la anulación se acepta, el campo vuelve a No tras
guardar el pedido (antes se quedaba en Sí). Si la AEAT no la acepta se deja en Sí
para poder corregir y reintentar.

## 0.34.81

Columna "Rectificaciones R1" del listado de pedidos: la cabecera se muestra en
dos líneas (Rectifi- / caciones R1) para caber en el ancho de la columna, y la
celda muestra solo el número de rectificaciones vigentes del pedido (0 si no
tiene) o "Anulado" si la factura del pedido está anulada. Una R1/R5 anulada no
cuenta ni marca el pedido como anulado.

## 0.34.80

Listado de pedidos de HikaShop: la columna del campo "Rectificar R1" pasa a
llamarse "Rectificaciones R1" y, en lugar de un simple "Sí", indica cuántas
rectificaciones por diferencias (R1/R5) vigentes tiene cada pedido ("1
rectificación", "2 rectificaciones"). Cuenta las aceptadas por la AEAT y no
anuladas, igual que los enlaces de la página del pedido. Si el pedido no tiene
ninguna aceptada, la celda se queda como estaba (Sí pendiente, No o vacía). El
campo dentro del pedido sigue llamándose "Rectificar R1".

## 0.34.79

Factura rectificativa: los títulos (Fecha, Factura, Dirección de facturación,
Lista de productos, Imprimir) solo se toman de la traducción de HikaShop si el
back-end está en español; con otro idioma se usa el texto español fijo, para que
el documento no quede mezclado. Si la dirección de la tienda de HikaShop no
incluye el nombre del emisor configurado en el plugin, se antepone ese nombre
(igual que ya se hacía con el NIF).

## 0.34.78

El "Sí" de "Rectificar R1" ya no se queda pegado cuando se anula la
rectificativa: al aceptarse una anulación, si el pedido no conserva ninguna
rectificación por diferencias válida (todas anuladas, o su factura anulada), el
campo vuelve a No. Además, una R1/R5 anulada deja de contar como rectificación
aceptada al decidir si un guardado con "Sí" es una petición nueva.

## 0.34.77

Pantalla de registros: nuevo filtro "Tipo de factura" (F1, F2, R1, R5), que
también se aplica a las exportaciones CSV y XML del período. La exportación CSV
incluye ahora base imponible, tipo de IVA, cuota de IVA, tipo de recargo y
recargo de equivalencia de cada registro, además del total. Filtrando por fechas
del trimestre (la fecha de cada registro es la de expedición de esa factura, por
lo que una R1 de octubre cae en el cuarto trimestre aunque rectifique una
factura de septiembre) y por tipo, el CSV da directamente los importes a
declarar. Las anulaciones no llevan importes propios y salen en blanco.

## 0.34.76

La factura rectificativa por diferencias (R1/R5) tiene ahora el mismo aspecto
que la factura del pedido: título centrado, logo y dirección de la tienda de
HikaShop, fecha y número a la derecha, recuadros "Dirección de facturación" y
"Lista de productos" con el desglose (subtotal, IVA, recargo y total de la
diferencia) y recuadro "Información adicional" con los mismos campos del pedido
que muestra la factura, por lo que se mantiene el comentario. Solo cambia la
presentación: no se modifica ningún dato del pedido, de la factura original ni
del registro Verifactu.

## 0.34.75

La columna "Rectificar R1" del listado de pedidos muestra "Sí" en los pedidos
rectificados por diferencias. Antes el campo volvía a "No" al aceptarse la
R1/R5; ahora se queda en "Sí" y solo se vacían los importes. Un "Sí" sin
"Nueva base" en un pedido ya rectificado es solo la marca: guardar el pedido
no repite la rectificación ni da error. Para una nueva corrección basta con
indicar la nueva base final. Al actualizar, una sola vez, se marcan con "Sí"
los pedidos que ya tenían una R1/R5 por diferencias aceptada. El texto bajo el
QR pasa a "VERI*FACTU" (factura del pedido y rectificativa).

## 0.34.74

Documento de la factura rectificativa por diferencias (R1/R5). Hasta ahora
la rectificación solo existía en el registro y en la AEAT, y el cliente solo
podía recibir la factura del pedido, con las líneas e importes de la
original. En la página del pedido, bajo el número de factura, aparece la
línea "Factura rectificativa" con un enlace por cada rectificación por
diferencias aceptada; cada enlace abre un documento imprimible con su número
y fecha, la factura que rectifica, solo la diferencia (base, IVA, recargo y
total), la base resultante y el QR de la propia rectificativa. La factura
del pedido no cambia. Los pedidos de abono no lo necesitan: su propia
factura es la rectificativa.

## 0.34.73

Nombres más cortos para 3 campos personalizados de pedido: "Tipo abono",
"Sustituye F2 (F3)" y "Anular factura". Al actualizar, solo se renombran los
campos que aún tienen el nombre original (si se cambiaron a mano, se
respeta). Mensajes de error, tabla de ayuda, textos de configuración y
manual actualizados con los nombres nuevos.

## 0.34.72

"Comentario del abono" tampoco se muestra por defecto en el "Listado del
Back-end" (instalaciones nuevas y, una sola vez, al actualizar). Manual
actualizado: nombres cortos de los campos, campos que no se muestran por
defecto en el listado y estrechamiento de las cabeceras.

## 0.34.71

Nombres más cortos para 5 campos personalizados de pedido: "Motivo anulación",
"Rectificar R1", "Nueva base", "Nuevo IVA %" y "Nuevo recargo %". Al
actualizar, solo se renombran los campos que aún tienen el nombre original
(si se cambiaron a mano, se respeta). Los 4 campos "Motivo anulación",
"Nueva base", "Nuevo IVA %" y "Nuevo recargo %" dejan de mostrarse por
defecto en el listado de pedidos del back-end ("Listado del Back-end" = No,
tanto en instalaciones nuevas como, una sola vez, al actualizar).

En el listado de pedidos, las cabeceras de los campos de VeriFactu se
estrechan a 90 px para que el título se parta en varias líneas. Mensajes,
tabla de ayuda y manual actualizados con los nombres nuevos.

## 0.34.70

Columna "VeriFactu" nueva en el listado de pedidos de HikaShop (Pedidos >
Compras), con el estado del último envío a la AEAT de cada pedido (misma
insignia de color que el registro de facturación). Se muestra siempre, sin
selector de columnas de por medio: HikaShop no ofrece un hook fiable para
añadir una columna real al listado (mismo problema que ya tuvo el botón
"Acciones Veri*Factu" de la v0.34.46-0.34.49), así que se añade por
JavaScript sobre el HTML ya renderizado, localizando las filas por su
casilla de selección (cid[]), el patrón estándar de los listados de
administración de Joomla.

Nueva tarea AJAX 'estados_pedidos' (un solo envío, con todos los order_id
visibles en la página) y el método estadosPorPedido(), que devuelven el
estado del último intento -- incluida una incidencia (excepción de la
v0.34.69) si es más reciente que el último registro guardado, mostrada como
"excepcion".

No se ha podido verificar contra un HikaShop real: si la columna no aparece
o aparece mal encajada en el listado, es la clase de ajuste que ya hizo
falta con el botón "Acciones Veri*Factu" en su momento -- lo más rápido es
una captura de pantalla del listado para ajustar los selectores.

## 0.34.68

El aviso de envío fallido que ya se mostraba al guardar un pedido
(procesarPedidoVerifactu()) depende de que esa misma petición termine
renderizando una página completa de Joomla. Varios de los caminos que
disparan el envío no lo hacen: el desplegable rápido de estado del listado
"Compras" y el popup "Información adicional" del pedido guardan por AJAX, y
un pedido puede cambiar de estado automáticamente desde el checkout, sin
ninguna petición de administración de por medio. En los tres casos el aviso
solo quedaba en el log, tal y como se ha confirmado en producción.

Se añade avisarEnviosFallidosRecientes(), en la misma línea que el aviso de
aceptación pendiente de la v0.34.67: en vez de depender de la petición que
falló, consulta la tabla de registros (la misma fuente que ya usa el
listado de facturas para el color rojo) y muestra un aviso en la SIGUIENTE
página de administración que se cargue, listando los pedidos cuyo último
intento de envío quedó en error o rechazado. Desaparece solo cuando ese
pedido se reenvía con éxito, sin marcar nada a mano.

## 0.34.67

Un comentario de revisión sobre `migrarAceptacionAvisoLegal()` (el
comentario de v0.34.7 sobre las 8 facturas 26-0237 a 26-0244) señalaba un
problema real: al actualizar, el plugin daba por aceptado el aviso legal en
instalaciones que nunca habían tenido la casilla delante (las que
instalaron el plugin antes de que existiera la v0.34.7), dejando registrada
una aceptación que nadie dio.

Se cambia de estrategia. Ya NO se auto-acepta el aviso legal en una
actualización: el parámetro se deja como esté. Mientras no valga "1":

- El envío a la AEAT sigue bloqueado (sin cambios; ya existía desde v0.34.7).
- Además del aviso que ya se mostraba al guardar un pedido, ahora se
  muestra un aviso persistente en TODA la administración (cada página, no
  solo pedidos), para que no pase desapercibido como antes de la v0.34.7.

Con esto la aceptación sigue siendo real y no se repite el caso de las 8
facturas sin dar por aceptado algo que no se aceptó. Se actualiza también el
manual (apartado 15.1) explicando el aviso persistente y que una
actualización ya no auto-acepta.

## 0.34.66

Correcciones propuestas por HikaShop sobre la 0.34.65.

Rectificación por diferencias:
- Ya no se repite en cada guardado del pedido. La diferencia se calcula
  frente a la factura original más las rectificaciones por diferencias ya
  aceptadas, y una vez aceptada el pedido vuelve a "Rectificar por
  diferencias" = No con los tres importes vacíos.
- Cada rectificación tiene su propio número y su propia fecha y no cambia el
  número de la factura del pedido: siguiente número de la serie R con la
  numeración propia, o el número de la factura original seguido de -R1,
  -R2... sin ella. Antes tomaba el mismo número que la factura original, y
  su QR sustituía al de esa factura.
- R5 si la factura rectificada es simplificada (F2), R1 en los demás casos,
  en vez de decidirlo según el NIF que tenga el pedido en ese momento.
- La factura del pedido muestra el QR de la propia factura, no el de una
  rectificación posterior.
- "Nueva base imponible" en blanco se queda vacía al guardar el popup
  "Información adicional" del pedido (antes se guardaba 0,00 y se enviaba
  una R1 por el total de la base en negativo).

Otras correcciones:
- La fecha de expedición congelada ya se guarda: insertObject() recibía un
  objeto literal, lo que en Joomla 4 y posteriores da un error (capturado y
  anotado en el log), así que cada reintento se enviaba con la fecha del
  reintento.
- Pedidos de abono: la factura rectificada se toma del "Comentario del
  abono" si contiene un número de factura del registro; la factura anterior
  (número - 1) solo se usa si es del mismo cliente.
- Subsanacion/RechazoPrevio solo se marcan al reenviar la misma factura que
  la AEAT rechazó, no cualquier factura posterior del pedido.
- El límite de la factura simplificada se compara en euros aunque el pedido
  esté en otra moneda.
- Los errores y rechazos se muestran al administrador que guarda el pedido,
  no solo en el log.
- Si el bloqueo falla después de asignar el número, queda una fila en el
  registro como en los demás errores.
- Guardar los datos de la Declaración Responsable exige el token del
  formulario.
- La instalación se detiene con un mensaje en PHP anterior a 8.1.
- La comprobación de caducidad del certificado se hace en la administración,
  una vez al día, y envía solo el aviso más cercano.
- Las pantallas de los campos personalizados solo se activan al actualizar
  desde una versión anterior a la 0.34.54, para respetar las que haya
  elegido el comerciante.
- El filtro de estados del Registro incluye los cuatro estados que faltaban.

## 0.34.65

Manual (`Manual_de_instalacion_y_configuracion.txt`):
- Sección 8: explica los nueve campos personalizados de pedido (antes solo
  describía dos), con el mismo formato que 8.1 y 8.2: etiqueta, nombre de
  columna, tabla, tipo, opciones y para qué sirve cada uno (8.3 a 8.9). La
  sección 9 pasa a decir "los nueve campos".
- Nuevo apartado 12.1: cómo se manejan las rectificaciones de facturas
  exentas (Canarias, Ceuta, Melilla, fuera de la UE) con NIF (R1 por
  diferencias exenta) y sin NIF (no se envía: `error_simplificada_exenta`;
  hay que indicar el NIF y emitir F1/R1), y qué hacer con las F2 exentas ya
  enviadas.
El número de versión se actualiza en el manual, `DISCLAIMER.md` y
`verifactu.xml`.

## 0.34.64

El texto de "Aviso legal, condiciones de uso y exclusión de garantía y
responsabilidad" que muestra el plugin (`verifactu.php`, `buildConditionsHtml()`,
el mismo que se guarda en la Declaración Responsable) se sincroniza con
`DISCLAIMER.md`: nuevas responsabilidades del usuario (facturas simplificadas
y sus límites, rectificaciones por diferencias), nueva sección 4
"Validaciones y bloqueos automáticos del software" y ampliación de las listas
de exclusión de responsabilidad y de comprobaciones previas a producción. Las
secciones siguientes pasan a numerarse de la 5 a la 16. El número de versión
se actualiza en el manual (.txt), `DISCLAIMER.md` y `verifactu.xml`.

## 0.34.63

Se incluye en el plugin el "Manual de instalación y configuración" en formato
texto (`Manual_de_instalacion_y_configuracion.txt`, convertido del .docx) y se
añade a la lista de archivos de `verifactu.xml`. El número de versión se
actualiza en el manual, en `DISCLAIMER.md` y en `verifactu.xml`.

## 0.34.62

`DISCLAIMER.md` actualizado: versión (figuraba v0.34.25) y fecha; nuevas
responsabilidades del usuario (facturas simplificadas y sus límites,
rectificaciones por diferencias); nueva sección 4 "Validaciones y bloqueos
automáticos del software" (F2/R5 exentas, límite de importe, R1 por
diferencias de facturas exentas) con la aclaración de que no son garantía de
corrección fiscal; y ampliación de las listas de exclusión de
responsabilidad y de comprobaciones previas a producción. Las secciones
posteriores se renumeran (5-16).

## 0.34.61

Texto de la nota bajo el campo "Importe máximo de factura simplificada (€,
IVA incluido)": "En 2026, los límites de la factura simplificada son 3.000 €
(B2C) y 400 € (B2B), aunque pueden variar en el futuro."

## 0.34.60

- Configuración del plugin: bajo el campo "Importe máximo de factura
  simplificada (€, IVA incluido)" se muestra ahora la nota "Límites actuales
  de factura simplificada: 3.000 € (B2C) y 400 € (B2B)".
- Campo personalizado "Comentario del abono": lleva ahora "Indicar número de
  factura original" como Marcador de posición (placeholder). Las
  instalaciones nuevas lo crean así; en una actualización, `script.php` lo
  añade solo si el campo no tenía ya un marcador de posición (no pisa uno
  puesto por el usuario).

## 0.34.59

Límite de importe de la factura simplificada. Según el manual práctico del
IVA de la AEAT, una simplificada solo vale hasta 400 € IVA incluido, o hasta
3.000 € IVA incluido en ventas al por menor a consumidor final (las
rectificativas pueden ser simplificadas sin límite). El plugin emitía F2 a
cualquier pedido sin NIF, fuera cual fuera el importe.

Ahora, si un pedido sin NIF que saldría como F2 supera el límite, no se
envía nada a la AEAT y se devuelve `error_simplificada_supera_limite`: hace
falta el NIF/identificación fiscal del cliente para emitir F1. El rechazo
ocurre antes de asignar número, así que no consume uno de la serie F2.

El límite es un parámetro nuevo, "Importe máximo de factura simplificada",
con 3.000 € por defecto (pon 400 si la tienda no vende solo a consumidores
finales). La R5 (rectificativa simplificada) no tiene límite.

## 0.34.58

La AEAT ha comunicado que todas las facturas simplificadas deben llevar IVA
(el manual práctico del IVA exige en la simplificada el "tipo impositivo
aplicado"). Hasta ahora, un pedido SIN NIF a Canarias, Ceuta, Melilla, un
país fuera de la UE o la UE salía como F2 exenta (E2/E5) sin tipo ni cuota, y
la rectificación por diferencias de un pedido así (v0.34.57) como R5 exenta.

Ahora, si el desglose es exento y la factura fuera a ser simplificada
(F2 o R5), no se envía nada a la AEAT y se devuelve
`error_simplificada_exenta`: hace falta el NIF/identificación fiscal del
cliente para emitir factura completa (F1/R1). En la rectificación por
diferencias el rechazo ocurre antes de asignar número; en la emisión
inicial, como el resto de errores posteriores a la numeración, queda
guardada la fila local con el número ya asignado.

Las facturas con IVA y las exentas con NIF no cambian.

## 0.34.57

"Rectificar por diferencias (R1)" fallaba con clientes de Canarias, Ceuta,
Melilla y fuera de la UE (y con entregas intracomunitarias): la factura
original es una operación exenta (E2/E5), guardada sin tipo ni cuota de IVA,
y la rectificación la trataba como "sin desglose" (`error_sin_desglose_original`).
Además, aunque no se hubiera bloqueado, habría declarado IVA general.

Ahora, si la factura original es exenta, la R1 por diferencias:
- envía solo la diferencia de BASE (positiva o negativa), sin tipo ni cuota;
- repite el mismo régimen y la misma causa de exención de la original
  (02 + E2 exportación; 01 + E5 entrega intracomunitaria);
- rechaza "Nuevo tipo de IVA" / "Nuevo tipo de recargo" informados
  (`error_rectificacion_exenta_con_tipo`), porque no aplican.

Se guardan dos columnas nuevas en el registro (`clave_regimen`,
`operacion_exenta`; las crean `script.php` y `ensureSchema()`, sin SQL de
actualización). Las facturas exentas anteriores a esta versión no las tienen:
en ese caso se deducen del destino del pedido con el mismo criterio que
`registrarFactura()`. Las facturas con IVA no cambian.

## 0.34.56

El botón "Acciones Veri*Factu" dejó de aparecer en la página del pedido
con la v0.34.55: `OrderController::edit()` de HikaShop, cuando no hay
`subtask`, cambia internamente `task` a `show` antes de renderizar, así que
al ejecutarse `onAfterRender()` la página ya no tenía `task=edit`. El
filtro acepta ahora `task=edit` o `task=show` (sin `subtask` y sin
`tmpl=component`) para esa pantalla. Listado e "Información adicional" no
cambian.

## 0.34.55

El botón "Acciones Veri*Factu" aparecía en todas las pantallas y popups de
la sección Pedido (Correo electrónico, Factura, Albarán, etc.). Ahora solo
aparece en las 3 pantallas pedidas:
1. Listado de pedidos (`ctrl=order`, sin task o `task=listing`).
2. Página del pedido (`task=edit`, sin subtask).
3. Popup "Información adicional" (`task=edit&subtask=additional`).

Se retira además la inyección duplicada desde `onHikashopAfterDisplayView`
(la que lo colocaba en cualquier vista de pedido); queda solo
`onAfterRender()`, con el filtro por task/subtask.

## 0.34.54

Se activan por defecto las 3 pantallas pedidas para los 9 campos
personalizados de VeriFactu (Tipo de abono, Comentario del abono,
Sustituye a simplificada, Anular factura, Motivo de la anulación,
Rectificar por diferencias, Nueva base imponible, Nuevo tipo de IVA,
Nuevo tipo de recargo): "Formulario del back-end", "Listado del Back-end"
e "Información adicional del pedido".

Revisando el código real de HikaShop (`views/field/view.html.php` y
`views/field/tmpl/form.php` de `com_hikashop`) se localizó la causa
exacta:
- "Formulario del back-end" ya nacía activado (columna `field_backend`);
  no hacía falta ningún cambio ahí.
- "Listado del Back-end" nacía desactivado (columna
  `field_backend_listing` en 0); se corrige a 1.
- "Información adicional del pedido" nunca se activaba porque el
  instalador usaba, por error, el nombre de bandera `order_edit_fields`
  dentro de `field_display` -- HikaShop nunca comprueba ese nombre; el
  nombre corto real es `order_edit`.

`sql/install.mysql.utf8.sql` ya crea los 9 campos bien configurados en
instalaciones nuevas. Para instalaciones que actualicen el plugin y ya
tuvieran estos 9 campos creados con los valores antiguos, `script.php`
añade la migración `migrarPantallasCamposAbono()`, idempotente, que
corrige los que hiciera falta y avisa con un mensaje.

## 0.34.50 a 0.34.53 (sin registrar previamente en este changelog)

Mejoras sobre el botón "Acciones Veri*Factu" añadido en v0.34.46-49:
- Se retira `onHikashopBeforeDisplayView` (el intento con
  `$view->toolbar`, anterior al cambio a `onAfterRender()`): con
  `onAfterRender()` ya funcionando de forma fiable, dejarlo activo solo
  duplicaba el botón.
- La búsqueda del botón "Ayuda" propio de HikaShop (para colocar el botón
  junto a él) se acota ahora a contenedores que ya parecen barra de
  herramientas (`#toolbar`, `.subhead-collapse`, `.btn-toolbar`,
  `.header-actions`), en vez de a toda la página: antes podía engancharse
  primero al enlace "Ayuda"/"Help" del menú lateral de administración de
  Joomla (oculto con `visibility:hidden` hasta desplegarse), heredando esa
  ocultación y quedando invisible aunque existía en el DOM.
- Además, ahora clona el icono del botón "Ayuda" encontrado para dar el
  mismo estilo visual, y si tras insertarlo el botón queda con tamaño 0
  (señal de haber heredado alguna ocultación inesperada), se retira de ahí
  y se reinserta como flotante en su lugar.

## 0.34.49

El botón "Acciones Veri*Factu" seguía sin aparecer incluso tras la
v0.34.48. Cambio de estrategia: en vez de depender del trigger
`onHikashopAfterDisplayView` (que exige acertar exactamente en qué
vista/layout de HikaShop se está, y que ese trigger llegue a ejecutarse
antes de que la página termine de generarse), se añade `onAfterRender()`,
que se dispara al final sobre el HTML completo ya generado de cualquier
página de administración, y se detecta si es una pantalla de HikaShop →
Pedidos por los parámetros de la URL (`option=com_hikashop` y
`ctrl=order` o `view=order`, según la pantalla). Si lo es, se inserta el
script del botón justo antes de `</body>`, sin importar la vista, el
layout o el task concretos de esa pantalla. El código anterior (v0.34.46
a v0.34.48) se mantiene sin quitar, por si ayuda en alguna pantalla
adicional, pero ya no es la vía principal.

## 0.34.48

El botón "Acciones Veri*Factu" seguía sin aparecer en la pantalla
principal de un pedido concreto (la que se ve con el título "Pedido" y
paneles "Información principal"/"Información adicional"): esa pantalla no
es el layout "edit" al que se limitaba la v0.34.47, así que el script ni
siquiera llegaba a ejecutarse ahí.

Corregido: el botón ahora se inyecta para cualquier vista de pedido en
la que HikaShop tenga cargado el pedido (`$view->order`), sin mirar el
layout concreto -- igual que ya hacía el script de la coma decimal desde
la v0.34.36, por el mismo motivo. Además, ahora primero intenta colocarse
junto al botón "Ayuda" propio de HikaShop (localizándolo por su texto,
en vez de por clases CSS que pueden variar según la plantilla de
administración), y solo si no lo encuentra recurre a los selectores
genéricos o al botón flotante.

## 0.34.47

El botón "Acciones Veri*Factu" añadido en v0.34.46 para la pantalla de
edición de un pedido concreto no llegaba a aparecer: esa pantalla no
construye su barra de herramientas a partir de `$view->toolbar` (a
diferencia del listado "Compras", donde sí funciona), así que empujar el
botón ahí no tenía ningún efecto visible.

Corregido inyectando el botón directamente por JavaScript (mismo
mecanismo, ya probado, que ya usa el plugin para los campos del abono en
esta misma pantalla): busca la barra de herramientas nativa de
administración y añade el botón al final; si no la encuentra, lo muestra
igualmente como botón flotante fijo para no dejar la ayuda inaccesible.
Al pulsarlo abre la misma página de ayuda, en una ventana emergente del
navegador. El botón del listado "Compras" no se ha tocado.

## 0.34.46

El botón "Acciones Veri*Factu" ahora aparece también en la página de
edición de un pedido concreto (layout=edit), además del listado de
pedidos ("Compras"). No se toca ninguna otra pantalla (popups, impresión
de factura, etc.).

## 0.34.45

En la tabla de ayuda "Acciones Veri*Factu": las celdas de la columna
"Crear / modificar" que no aplican (antes con un guion largo "—") se
dejan ahora vacías.

## 0.34.44

Ajustado el texto del punto 3 ("Sustituye a simplificada (F3)") de la
tabla de ayuda "Acciones Veri*Factu": ahora indica primero modificar los
datos del cliente y guardar, y después seleccionar "Sí".

## 0.34.43

Nuevo botón "Acciones Veri*Factu" en la barra de herramientas del listado
de pedidos de administración (HikaShop → Pedidos → Compras), añadido de
forma no invasiva mediante el trigger `onHikashopBeforeDisplayView` (sin
modificar ni sobrescribir ningún archivo original de HikaShop):

- El botón aparece solo si el plugin está habilitado y desaparece por sí
  solo si se deshabilita o desinstala (no hay ningún archivo que limpiar).
- No depende de seleccionar ningún pedido: es un botón de ayuda general.
- Al pulsarlo se abre una ventana emergente con la tabla de referencia de
  qué acción realizar (tipo de abono, anulación, rectificación por
  diferencias...) y qué resultado VeriFactu genera cada una
  (F1/F2/F3/R1.../Registro de Anulación), servida como página propia vía
  `com_ajax` (`task=ayuda_acciones`), igual que el registro de facturación.

## 0.34.36

**Localizado el motivo real por el que las versiones anteriores no arreglaban nada: los 3 campos NO están en la pantalla principal de edición del pedido.**

Captura de pantalla real aportada por el usuario: los campos "Nueva base
imponible", "Nuevo tipo de IVA (%)" y "Nuevo tipo de recargo de
equivalencia (%)" se muestran en el popup **"Información adicional"** de
HikaShop (el mismo donde se editan Cupón/Envío/Pago/Impuestos), no en la
pantalla principal "Pedido". Ese popup es una vista o task distinta --
posiblemente cargada en su propio contexto (p.ej. un iframe) -- por lo que
el `onHikashopAfterDisplayView` con la comprobación estricta
`$view->getLayout() === 'edit'` de las versiones 0.34.34/0.34.35 nunca
llegaba a inyectar el script ahí: el script existía, pero no en la página
donde el usuario realmente escribe estos valores.

Cambios:

1. El script de la coma decimal ahora se inyecta en **cualquier** vista
   "order" del backend en la que `$view->order` esté disponible, sin
   exigir `layout === 'edit'`. Si ese popup se renderiza mediante el mismo
   sistema de vistas de HikaShop (lo más probable), este cambio es
   suficiente para que el script llegue también ahí. El resto del método
   (el bloque de campos del abono, específico de la pantalla principal)
   sigue exigiendo `layout === 'edit'` exactamente igual que antes -- no
   se ha tocado ese comportamiento.
2. Se añade un `MutationObserver` (con un pequeño debounce de 150 ms) que
   vuelve a ejecutar la preparación de los 3 campos cada vez que cambia el
   DOM de la página. Esto cubre el caso de que el popup se inserte
   dinámicamente (vía AJAX/JS) DESPUÉS de que el script ya se ejecutó una
   vez al cargar la página -- el escaneo original, de una sola vez al
   cargar, no podía encontrar unos inputs que todavía no existían.

Si tras instalar esta versión el problema persiste, lo más probable es que
ese popup se cargue por una vía que no dispara el evento
`onHikashopAfterDisplayView` en absoluto (por ejemplo, un endpoint AJAX
que no pasa por el sistema de vistas de Joomla/HikaShop). En ese caso hace
falta identificar exactamente cómo se carga ese popup para inyectar el
script en el sitio correcto.

Sin cambios de esquema de base de datos en esta versión.

## 0.34.35

**La v0.34.34 no era suficiente: seguía guardándose "45.00" en vez de "45.95".**

Diagnóstico: HikaShop enlaza su propio listener (probablemente de
`blur`/`change`) directamente sobre estos 3 inputs, que redondea el valor a
2 decimales con algo equivalente a `parseFloat(valor).toFixed(2)`. En
JavaScript, `parseFloat("45,95")` se detiene en la coma y devuelve `45` --
de ahí el "45.00" (45 redondeado a 2 decimales, no "45" a secas). Este
listener se ejecutaba antes de que nuestra conversión del submit (v0.34.34)
llegara a corregir nada.

Ahora se añade, además, un listener de `blur`/`change`/`focusout` en el
`document` con **captura** (`addEventListener(tipo, fn, true)`). La fase de
captura de un evento atraviesa siempre el árbol del DOM desde el `document`
hacia el elemento antes de llegar a los listeners enlazados directamente
sobre ese elemento (fase "target"), sea cual sea el orden en que se
registraron. Por eso, en cuanto el usuario sale de uno de estos 3 campos:

1. Se convierte ya la coma a punto (para que cualquier listener de
   HikaShop que se ejecute después encuentre un número válido y no lo
   trunque).
2. Se llama a `stopImmediatePropagation()` para que ese listener de
   HikaShop, si existe directamente sobre el input, no llegue a
   ejecutarse sobre estos 3 campos concretos (no afecta a ningún otro
   campo del formulario).

La conversión en el `submit` (ya existente desde la v0.34.34) se mantiene
como red de seguridad adicional.

También se retiran, además de `pattern`, los atributos `step`/`min`/`max`
del input, por si HikaShop los añadía junto con `type="number"`.

Sin cambios de esquema de base de datos en esta versión.

## 0.34.34

**Corrige la coma decimal en los campos de la rectificación por diferencias (backend del pedido).**

Los tres custom fields de HikaShop sobre la tabla `order` (tipo `text`,
columnas `DECIMAL` en base de datos):

- `verifactu_nueva_base_imponible`
- `verifactu_nuevo_tipo_iva`
- `verifactu_nuevo_tipo_recargo`

no admitían escribir la coma como separador decimal en la pantalla de
edición de pedido del backend de HikaShop: al escribir, por ejemplo,
`45,95`, solo llegaba `45` al guardar. El guardado en sí (PHP,
`Verifactu::parseNumeroEuropeo()` en `onBeforeOrderUpdate`) ya interpretaba
bien la coma decimal española (y el punto de miles); el problema estaba en
el propio widget del formulario, que descartaba la coma mientras se
escribía.

Se añade un script embebido (`Verifactu::scriptDecimalesEuropeos()`,
inyectado desde `onHikashopAfterDisplayView` en la vista `order`/`edit`
del backend de `com_hikashop`) que:

1. Localiza los tres inputs por coincidencia en su `name`/`id` (sin asumir
   un prefijo de array concreto, ya que HikaShop lo genera internamente).
2. Libera cada input de lo que le impedía escribir la coma (típicamente
   `type="number"`, que el navegador filtra carácter a carácter),
   pasándolo a `type="text"` sin tocar su valor. Así se puede escribir con
   normalidad tanto `45,95` como `1.234,56`.
3. Solo en el `submit` del formulario de edición del pedido, convierte el
   valor escrito al formato con punto decimal simple que espera la columna
   `DECIMAL`, con el mismo criterio miles/decimal que ya usaba el PHP
   (punto+coma → punto de miles y coma decimal; solo coma → decimal; solo
   punto seguido de exactamente 3 dígitos, sin empezar por 0 → miles;
   cualquier otro caso con punto → decimal).

No modifica ningún otro campo del formulario, no intercepta el envío
(`preventDefault`) ni valida nada: solo reescribe el valor de estos tres
inputs justo antes de que HikaShop procese el guardado normal del pedido.

Sin cambios de esquema de base de datos en esta versión.
