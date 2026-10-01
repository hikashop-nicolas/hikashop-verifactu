# AVISO LEGAL, CONDICIONES DE USO Y EXCLUSIÓN DE GARANTÍA Y RESPONSABILIDAD

**HikaShop VeriFactu v0.34.70**
Licencia: **GNU General Public License v3.0 (GPLv3)**
Última actualización: 28 de septiembre de 2026

## 1. Naturaleza del software

HikaShop VeriFactu es un componente informático desarrollado para integrarse con Joomla y HikaShop y proporcionar funcionalidades relacionadas con la generación de información de facturación, generación de registros de facturación y, cuando se configure para ello, comunicación electrónica de dichos registros con los servicios de la Agencia Estatal de Administración Tributaria (AEAT).

El software constituye exclusivamente una **herramienta informática**.

El software, su autor, sus colaboradores y sus distribuidores **no proporcionan asesoramiento fiscal, contable, jurídico ni tributario**.

La utilización del software no sustituye el asesoramiento de un asesor fiscal, contable o jurídico cualificado.

## 2. Responsabilidad del usuario

El usuario del software es el único responsable de:

* La configuración de Joomla, HikaShop y del propio plugin.
* Los datos fiscales introducidos en el sistema.
* Los datos identificativos de la empresa y de sus clientes.
* Los NIF, NIF-IVA, CIF y demás identificadores fiscales utilizados.
* Las fechas de las facturas.
* Los importes, bases imponibles y cuotas de IVA.
* Los tipos de factura utilizados, incluyendo, entre otros, F1, F2, F3 y facturas rectificativas.
* Las causas y tipos de rectificación utilizados, incluidas las rectificaciones por diferencias.
* La procedencia de emitir una factura simplificada (F2 o R5) y el cumplimiento de sus límites de importe y de las demás condiciones que exige la normativa.
* El valor que se configure en el parámetro "Importe máximo de factura simplificada" y la determinación de si un pedido sin NIF corresponde a una venta a consumidor final.
* Las claves de régimen y calificaciones fiscales.
* La determinación de operaciones sujetas, exentas o no sujetas.
* La información incluida en los registros de facturación.
* La información enviada a la AEAT.
* La conservación de las facturas y registros exigidos por la normativa.
* La comprobación de que las facturas y registros generados son correctos antes de utilizarlos.
* El cumplimiento de todas las obligaciones fiscales, contables y de facturación que le sean aplicables.

El usuario debe comprobar que la configuración y el funcionamiento del software son adecuados para su situación fiscal concreta.

## 3. No constituye garantía de corrección fiscal

El hecho de que el software genere una factura, un registro de facturación, un código QR, una comunicación electrónica o cualquier otro dato **no implica que dicha información sea fiscalmente correcta**.

El autor no garantiza que el software determine correctamente, para cada caso particular:

* El tipo de factura aplicable.
* La causa de rectificación.
* La clave de régimen.
* La calificación de IVA.
* La causa de exención.
* La condición de operación sujeta o no sujeta.
* El tratamiento de operaciones nacionales, intracomunitarias o de exportación.
* El tratamiento fiscal de Canarias, Ceuta o Melilla.
* El tratamiento de las rectificaciones por diferencias de facturas exentas (exportaciones y entregas intracomunitarias), incluidos el régimen y la causa de exención que se repiten en la rectificativa.
* La procedencia de emitir una factura simplificada y los límites de importe aplicables en cada caso.
* El tratamiento de operaciones con clientes particulares o empresarios.
* La aplicación de tipos impositivos.
* La aplicación del recargo de equivalencia.
* La aplicación de reglas especiales de IVA.
* La obligación de utilizar una determinada modalidad de factura.
* La obligación de realizar declaraciones fiscales adicionales.

La correcta determinación de estas circunstancias corresponde exclusivamente al usuario.

## 4. Validaciones y bloqueos automáticos del software

Para reducir el riesgo de enviar registros incorrectos, el software puede rechazar determinados envíos antes de comunicarlos a la AEAT. Entre otros, en la fecha de esta versión:

* No envía una factura simplificada (F2) ni una rectificativa simplificada (R5) cuando la operación es exenta de IVA (por ejemplo, una exportación o una entrega intracomunitaria), porque la AEAT ha comunicado que las facturas simplificadas deben llevar IVA. En esos casos exige identificar al cliente para emitir una factura completa.
* No envía una factura simplificada (F2) cuyo importe supere el límite configurado en el parámetro "Importe máximo de factura simplificada" (3.000 € por defecto). Las rectificativas simplificadas no están sujetas a ese límite.
* En una rectificación por diferencias de una factura exenta, envía solo la diferencia de la base imponible, sin tipo ni cuota, y repite el régimen y la causa de exención de la factura original; rechaza la rectificación si se indica un nuevo tipo de IVA o de recargo.

Estas validaciones son una ayuda técnica y **no constituyen una garantía de corrección fiscal**: no cubren todos los supuestos, pueden no reflejar cambios normativos posteriores y no sustituyen la revisión del usuario ni de su asesor. Los límites de importe indicados en la configuración del plugin son solo orientativos ("En 2026, los límites de la factura simplificada son 3.000 € (B2C) y 400 € (B2B), aunque pueden variar en el futuro"); el usuario debe comprobar la normativa vigente y ajustar el parámetro a su caso.

Que el software no bloquee un envío no significa que el envío sea correcto.

## 5. Registros aceptados por la AEAT

La aceptación de un registro por parte de la AEAT **no constituye una garantía de que la operación subyacente sea fiscalmente correcta**.

Del mismo modo, el hecho de que un registro sea técnicamente válido, aceptado o procesado por los servicios de la AEAT no significa necesariamente que:

* La factura sea correcta desde el punto de vista fiscal.
* El tratamiento del IVA sea correcto.
* La operación haya sido correctamente calificada.
* Se hayan cumplido todas las obligaciones fiscales del contribuyente.
* Se hayan cumplido todas las obligaciones de declaración, información o conservación.

El usuario debe realizar las comprobaciones fiscales que correspondan a su situación.

## 6. Cambios normativos y técnicos

La normativa tributaria, las especificaciones técnicas, los servicios electrónicos de la AEAT y los requisitos aplicables a los Sistemas Informáticos de Facturación pueden cambiar.

El autor no garantiza que una determinada versión del software continúe siendo adecuada después de:

* Cambios legislativos.
* Cambios reglamentarios.
* Cambios en las especificaciones técnicas de la AEAT.
* Cambios en los servicios web de la AEAT.
* Cambios en los esquemas XML.
* Cambios en los sistemas de validación.
* Cambios en los certificados electrónicos.
* Cambios en los requisitos de seguridad.
* Cambios en Joomla, HikaShop, PHP u otros componentes de terceros.

El usuario es responsable de mantener el software y sus dependencias en versiones compatibles y adecuadas para su entorno.

## 7. Dependencias y software de terceros

HikaShop VeriFactu puede utilizar bibliotecas, componentes, servicios, APIs, extensiones y otros programas desarrollados por terceros.

El autor no garantiza la disponibilidad, funcionamiento, compatibilidad, exactitud o continuidad de servicios proporcionados por terceros.

Las condiciones de licencia de las bibliotecas de terceros serán aplicables según corresponda.

El productor no será responsable de modificaciones, adaptaciones, extensiones, alteraciones o integraciones realizadas por el usuario o por terceros sobre el software original, ni de las consecuencias derivadas de dichas modificaciones.

## 8. Errores, interrupciones y pérdida de información

El software se proporciona sin garantía de funcionamiento ininterrumpido o libre de errores.

El autor no garantiza que:

* El software funcione sin errores.
* Las comunicaciones con la AEAT estén siempre disponibles.
* Las comunicaciones electrónicas sean siempre satisfactorias.
* Los registros sean siempre aceptados.
* Los códigos QR sean siempre generados correctamente.
* Las facturas PDF sean siempre generadas correctamente.
* Las conexiones con servicios externos funcionen permanentemente.
* No se produzcan errores de configuración.
* No se produzcan errores derivados de Joomla, HikaShop, PHP, servidores, certificados, bases de datos o extensiones de terceros.

El usuario debe disponer de mecanismos adecuados de copia de seguridad y recuperación de sus datos.

## 9. Exclusión de responsabilidad

EN LA MÁXIMA MEDIDA PERMITIDA POR LA LEGISLACIÓN APLICABLE, EL AUTOR, DESARROLLADOR, COLABORADORES Y DISTRIBUIDORES DEL SOFTWARE NO SERÁN RESPONSABLES DE DAÑOS, PÉRDIDAS, COSTES, SANCIONES, RECARGOS, INTERESES, PERJUICIOS, RECLAMACIONES O CONSECUENCIAS DE CUALQUIER NATURALEZA DERIVADAS DEL USO O IMPOSIBILIDAD DE USO DEL SOFTWARE.

Esta exclusión incluye, entre otros:

* Errores en facturas.
* Errores en registros de facturación.
* Errores en tipos de factura.
* Errores en tipos de rectificación.
* Errores en claves de régimen.
* Errores en la calificación de operaciones.
* Errores o rechazos derivados de facturas simplificadas emitidas fuera de sus límites o para operaciones exentas.
* Errores en rectificaciones por diferencias.
* Errores en bases imponibles.
* Errores en cuotas de IVA.
* Errores en recargos de equivalencia.
* Errores en NIF o NIF-IVA.
* Errores en registros enviados a la AEAT.
* Registros rechazados o aceptados con errores.
* Registros duplicados.
* Registros no enviados.
* Registros enviados incorrectamente.
* Facturas anuladas o rectificativas incorrectamente configuradas.
* Errores en códigos QR.
* Errores de comunicación con la AEAT.
* Indisponibilidad de los servicios de la AEAT.
* Errores derivados de modificaciones normativas.
* Errores derivados de configuraciones realizadas por el usuario.
* Errores derivados de Joomla, HikaShop, PHP, el servidor o software de terceros.
* Pérdida de datos.
* Pérdida de facturación.
* Pérdida de negocio.
* Reclamaciones de clientes.
* Sanciones administrativas.
* Recargos.
* Intereses.
* Costes de regularización.
* Gastos profesionales, fiscales, contables o jurídicos derivados de cualquier incidencia.

Las sanciones, multas, recargos, intereses, costes de regularización, gastos profesionales, fiscales, contables o jurídicos que pudieran derivarse de las obligaciones tributarias del usuario, de la configuración de su instalación, de los datos introducidos, de las facturas emitidas, de los registros generados o remitidos, de las decisiones fiscales adoptadas por el usuario o de la utilización del software serán responsabilidad del obligado tributario correspondiente, en la máxima medida permitida por la legislación aplicable.

## 10. Responsabilidad por las obligaciones fiscales

El usuario reconoce que la obligación legal de emitir facturas correctamente, mantener los registros de facturación y cumplir las obligaciones tributarias corresponde al obligado tributario y no al software utilizado para realizar dichas tareas.

El usuario debe verificar la legislación vigente y, cuando sea necesario, consultar a un profesional fiscal o tributario.

## 11. Verificación antes de producción

Antes de utilizar el software en un entorno de producción, el usuario debe realizar las pruebas necesarias para verificar su funcionamiento en su propio entorno.

Se recomienda comprobar especialmente:

* Numeración de facturas.
* Fechas.
* NIF de clientes.
* Tipos F1, F2, F3 y demás tipos aplicables.
* Facturas rectificativas, incluidas las rectificaciones por diferencias.
* Facturas simplificadas y sus límites de importe.
* Pedidos sin NIF a Canarias, Ceuta, Melilla o fuera de la Unión Europea.
* Anulaciones.
* Bases imponibles.
* IVA.
* Recargo de equivalencia.
* Operaciones intracomunitarias.
* Exportaciones.
* Operaciones no sujetas.
* Operaciones exentas.
* Registros enviados a la AEAT.
* Respuestas recibidas de la AEAT.
* Códigos QR.
* Certificados electrónicos.
* Copias de seguridad.

## 12. No existe garantía de cumplimiento normativo

El software no constituye una certificación, homologación, autorización o garantía de cumplimiento de la normativa fiscal española.

La utilización del software no implica que el autor certifique que una determinada instalación, configuración o uso del software cumple todas las obligaciones legales aplicables al usuario.

Corresponde al usuario determinar si el software es adecuado para sus necesidades y obligaciones.

## 13. Uso bajo responsabilidad del usuario

Al instalar, activar, configurar o utilizar HikaShop VeriFactu, el usuario reconoce que lo hace bajo su propia responsabilidad.

El usuario debe realizar las comprobaciones necesarias antes de utilizar cualquier información generada por el software para emitir facturas o remitir información a la AEAT.

## 14. Licencia del software

HikaShop VeriFactu se distribuye bajo los términos de la **GNU General Public License, versión 3 (GPLv3)**.

El software se proporciona "TAL CUAL" y sin garantías, en la medida permitida por la legislación aplicable.

Las disposiciones de la GPLv3 relativas a la exclusión de garantía y limitación de responsabilidad forman parte de las condiciones de distribución del software.

## 15. Legislación aplicable

Nada de lo establecido en este aviso pretende excluir o limitar derechos o responsabilidades que no puedan ser excluidos o limitados legalmente conforme a la legislación aplicable.

Cuando una disposición de este aviso no pueda aplicarse legalmente en una determinada jurisdicción, dicha disposición se aplicará en la máxima medida permitida por la legislación aplicable, sin afectar al resto de las disposiciones.

## 16. Aceptación

El usuario reconoce haber leído y comprendido este aviso antes de utilizar el software en un entorno de producción.

La instalación o utilización del software implica la aceptación de estas condiciones, en la medida permitida por la legislación aplicable.

---

**HikaShop VeriFactu v0.34.70**

**Copyright © 2026 Custom**

Licencia de software: **GNU General Public License v3.0 (GPLv3)**

Este software no está afiliado, patrocinado, respaldado ni certificado por la Agencia Estatal de Administración Tributaria (AEAT).
