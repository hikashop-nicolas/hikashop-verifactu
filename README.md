# Manual de instalación y configuración — PLG_HIKASHOP_VERIFACTU

## 1. Introducción

Este manual explica cómo instalar y configurar el plugin **PLG_HIKASHOP_VERIFACTU** en una instalación Joomla + HikaShop, incluyendo la preparación del certificado digital, la configuración del plugin y la configuración de los campos personalizados utilizados para los abonos y facturas rectificativas.

El plugin permite preparar y enviar las facturas a VeriFactu, registrar la respuesta de la AEAT y generar el código QR correspondiente para incorporarlo a las facturas.

> **Importante:** realiza una copia de seguridad de la web y de la base de datos antes de instalar o modificar el sistema.


## 2. Requisitos del sistema

El plugin ha sido probado y funciona correctamente en el siguiente entorno:

| Componente | Versión / requisito |
|---|---|
| **Joomla** | **4.4.14 — probado** |
| **HikaShop** | **6.5.0 — probado** |
| **PHP** | **8.3.33 — probado** |
| **Certificado digital** | **.pfx** |

## 3. Preparar el certificado digital

### 3.1 Crear la carpeta `cert`

En la **raíz del alojamiento del sitio web**, crea una carpeta llamada:

```text
cert
```

La carpeta debe quedar en la raíz del alojamiento, al mismo nivel que la carpeta pública del sitio (por ejemplo, `httpdocs`), **no dentro de `httpdocs`**.

Sube dentro de esta carpeta el certificado digital:

```text
cert/
└── certificado.pfx
```

Se recomienda utilizar un nombre de archivo sencillo, sin espacios ni caracteres especiales.

### 3.2 Contraseña del certificado

El certificado `.pfx` debe estar protegido con una contraseña fuerte.

Se recomienda una contraseña de **mínimo 16 caracteres**, combinando:

- letras mayúsculas y minúsculas;
- números;
- símbolos especiales.

**No utilices una contraseña sencilla ni reutilices una contraseña empleada en otros servicios.**

La contraseña del certificado se introducirá posteriormente en la configuración del plugin.

### 3.3 Permisos del archivo `.pfx`

El certificado contiene información sensible y debe quedar protegido.

Configura el archivo `.pfx` con permisos:

```text
600
```

Esto significa:

- **Owner/Propietario:** lectura y escritura (`rw`)
- **Group/Grupo:** ningún permiso (`---`)
- **Others/Otros:** ningún permiso (`---`)

Representación:

```text
-rw-------
```

**No utilices permisos 644, 664, 755 ni 777 para el certificado.**


> **Importante:** además de proteger el archivo con permisos 600, evita que el certificado pueda descargarse directamente desde una URL pública. Por este motivo, la carpeta `cert` debe estar fuera de la carpeta pública del sitio (`httpdocs`/`public_html`).


## 4. Instalar el plugin

En el administrador de Joomla:

**Extensiones → Gestionar → Instalar**

1. Selecciona el archivo `.zip` del plugin.
2. Sube el paquete.
3. Espera a que Joomla confirme que la instalación se ha realizado correctamente.

No modifiques los archivos del plugin después de la instalación salvo que este manual indique expresamente que es necesario.


## 5. Configurar el plugin

Una vez instalado, ve a:

**Extensiones → Plugins**

Busca el plugin:

**PLG_HIKASHOP_VERIFACTU**

Ábrelo y rellena los datos solicitados por el plugin.

Entre los datos de configuración se encuentran, según la versión instalada:

- Datos fiscales del emisor.
- NIF.
- Nombre o razón social.
- Entorno de trabajo.
- Ruta del certificado `.pfx`.
- Contraseña del certificado.
- Opciones relacionadas con el envío a VeriFactu.

### Ruta del certificado

La ruta debe apuntar al archivo `.pfx` almacenado en la carpeta `cert` creada anteriormente.

**No introduzcas una URL pública al certificado.** Debe utilizarse una ruta del sistema de archivos del servidor.

Revisa cuidadosamente todos los datos antes de guardar la configuración.


## 6. Habilitar el plugin

Después de guardar la configuración:

1. Vuelve a **Extensiones → Plugins**.
2. Localiza **PLG_HIKASHOP_VERIFACTU**.
3. Activa el plugin.
4. Comprueba que el estado aparece como **Habilitado**.

A partir de este momento el plugin podrá intervenir en el proceso de facturación de HikaShop.


## 7. Comprobar y configurar los campos personalizados de HikaShop

La versión actual del plugin crea automáticamente los campos personalizados necesarios.

Ve a:

**HikaShop → Mostrar → Campos personalizados**

Busca los siguientes dos campos.

### 7.1 Campo «Tipo de abono»

Debe existir un campo con:

| Configuración | Valor |
|---|---|
| Etiqueta | **Tipo de abono** |
| Nombre de columna | `verifactu_tipo_abono` |
| Tabla | `order` / Pedido |
| Tipo de campo | **Single Dropdown / Desplegable sencillo** |

Las opciones deben ser exactamente:

- Factura
- Devolución completa
- Devolución parcial
- Mercancía obsoleta
- Descuento posterior
- Error en IVA
- Cancelación
- Concurso de acreedores
- Crédito incobrable
- Otro

### 7.2 Campo «Comentario del abono»

Debe existir un segundo campo con:

| Configuración | Valor |
|---|---|
| Etiqueta | **Comentario del abono** |
| Nombre de columna | `verifactu_comentario_abono` |
| Tabla | `order` / Pedido |
| Tipo de campo | **Text area / Área de texto** |


## 8. Configuración de «Pantalla» de los dos campos

Este paso es **obligatorio** para que los campos puedan utilizarse correctamente desde los pedidos de HikaShop.

Abre cada uno de los dos campos personalizados:

1. **Tipo de abono**
2. **Comentario del abono**

En el apartado **Pantalla**, comprueba que estén marcadas como **Sí** las siguientes opciones:

- **Formulario del back-end:** Sí
- **Listado del Back-end:** Sí
- **Información adicional del pedido:** Sí

La configuración debe quedar conceptualmente así:

| Pantalla | Valor |
|---|---|
| Formulario del back-end | **Sí** |
| Listado del Back-end | **Sí** |
| Información adicional del pedido | **Sí** |

Guarda cada campo después de comprobar la configuración.

> **Importante:** si estos tres parámetros no están activados, los campos pueden existir en HikaShop pero no aparecer en los lugares necesarios del administrador o en la información adicional del pedido.


## 9. Correspondencia del «Tipo de abono» con VeriFactu

El usuario no tiene que introducir directamente los códigos R1, R2, R3, R4, I o S. Selecciona una opción comprensible en «Tipo de abono» y el plugin realiza la correspondencia. El plugin no dispone de la opción de sustituir (S - Sustitución) facturas. Para las facturas rectificativas, únicamente está disponible la modalidad I — Diferencias.

| Tipo de abono | Tipo de factura | Rectificación |
|---|---|---|
| Factura | F1 | — |
| Devolución completa | R1 | I |
| Devolución parcial | R1 | I |
| Mercancía obsoleta | R1 | I |
| Descuento posterior | R1 | I |
| Error en IVA | R4 | I |
| Cancelación | R1 | I |
| Concurso de acreedores | R2 | I |
| Crédito incobrable | R3 | I |
| Otro | R4 | I |

En esta implementación, las opciones de abono se procesan como rectificaciones por diferencias (**I**).


## 10. Crear un abono mediante una factura negativa

Para generar un abono en HikaShop:

1. Crea un pedido normal.
2. Modifica el pedido y convierte los importes positivos en negativos.
3. En **Tipo de abono**, selecciona el motivo correspondiente.
4. En **Comentario del abono**, indica la factura original que se está abonando.
5. Procesa la factura negativa/rectificativa.

Ejemplo:

```text
Producto:     -100,00 €
IVA 21 %:      -21,00 €
Total:        -121,00 €
```

En «Tipo de abono»:

```text
Devolución completa
```

En «Comentario del abono»:

```text
Abono de factura F-125
```

El plugin utilizará estos datos para determinar el tratamiento VeriFactu y mantener la relación con la factura original.



## 11. Configurar el estado que dispara el envío a VeriFactu

Esta configuración se realiza **directamente dentro del plugin PLG_HIKASHOP_VERIFACTU**.

En la configuración del plugin encontrarás el campo:

**Estado que dispara el envío a VeriFactu**

En este desplegable debes seleccionar el **estado de pedido de HikaShop** que utilizará el plugin como momento para iniciar el envío de la factura a la AEAT.

### Importante: el número de factura debe existir antes

El estado seleccionado debe producirse **después de que HikaShop haya generado y asignado el número de factura**.

El plugin utilizará el cambio al estado seleccionado como disparador del envío, pero la factura debe tener ya su número de factura antes de llegar a ese estado.

El orden correcto debe ser:

```text
Pedido
   ↓
HikaShop genera el número de factura
   ↓
El pedido pasa al estado seleccionado en el plugin
   ↓
El plugin prepara el registro VeriFactu
   ↓
Envío a la AEAT
   ↓
Respuesta de la AEAT
   ↓
Registro del resultado y QR VeriFactu
```

Como ejemplo, se puede utilizar un estado estándar de HikaShop como:

```text
Factura enviada (invoice_sent)
```

Este estado es adecuado como referencia porque corresponde al momento posterior a la generación de la factura.

**Lo importante es que el estado seleccionado se produzca después de que HikaShop haya generado y asignado el número de factura.**

Por tanto, antes de seleccionar un estado, comprueba el flujo de estados de tu tienda y asegúrate de que:

- La factura ya ha sido generada.
- HikaShop ya ha asignado el número de factura.
- Después de esto, el pedido alcanza el estado seleccionado en el plugin.
- En ese momento el plugin puede iniciar el envío a VeriFactu.

> **Regla fundamental:** no selecciones un estado que pueda producirse antes de que HikaShop haya generado el número de factura.

## 12. Factura PDF adjunta al correo

Si la tienda utiliza el plugin **HikaShop - generate PDF invoice / Attach Invoice**, la factura PDF adjunta puede utilizar una plantilla independiente.

Si el QR no aparece en el PDF adjunto, comprueba:

```text
httpdocs/plugins/hikashop/attachinvoice/attachinvoice/invoice.php
```

La plantilla puede requerir la integración específica del bloque QR VeriFactu.

**Este apartado solo debe modificarse si la instalación utiliza ese plugin y la factura PDF adjunta no muestra el QR.**


## 13. Comprobaciones finales

Antes de considerar terminada la instalación, realiza una prueba completa:

### Certificado

- [ ] La carpeta `cert` está fuera de la carpeta pública del sitio.
- [ ] El certificado `.pfx` está dentro de `cert`.
- [ ] El archivo `.pfx` tiene permisos **600**.
- [ ] El certificado está protegido con una contraseña fuerte de al menos 16 caracteres.
- [ ] La ruta del certificado configurada en el plugin es correcta.

### Plugin

- [ ] El plugin está instalado.
- [ ] Los datos fiscales están configurados.
- [ ] El certificado y su contraseña están configurados.
- [ ] El plugin está **Habilitado**.

### Campos personalizados

- [ ] Existe «Tipo de abono».
- [ ] Existe «Comentario del abono».
- [ ] Ambos pertenecen a la tabla/pedido `order`.
- [ ] En **Pantalla → Formulario del back-end** está marcado **Sí**.
- [ ] En **Pantalla → Listado del Back-end** está marcado **Sí**.
- [ ] En **Pantalla → Información adicional del pedido** está marcado **Sí**.
- [ ] Las opciones de «Tipo de abono» son las indicadas en este manual.

### Prueba de factura

- [ ] Crear una factura de prueba.
- [ ] Comprobar que se procesa correctamente.
- [ ] Comprobar el estado de envío a VeriFactu.
- [ ] Comprobar la respuesta de la AEAT.
- [ ] Comprobar que el QR aparece en la factura cuando corresponda.
- [ ] Si se utiliza Attach Invoice, comprobar también el PDF adjunto al correo.


## 14. Seguridad

El certificado digital es un elemento de alta sensibilidad.

**Nunca:**

- publiques el archivo `.pfx` dentro de una carpeta accesible directamente desde Internet;
- utilices permisos `777`;
- envíes la contraseña del certificado por correo junto con el archivo;
- reutilices una contraseña débil;
- dejes el certificado en una copia pública de la web.

Mantén una copia de seguridad segura del certificado y de su contraseña, separadas entre sí.

---

## 15. Referencia de los archivos y rutas principales

```text
[Raíz del alojamiento]
├── cert/
│   └── certificado.pfx
│
└── httpdocs/
    └── plugins/
        └── hikashop/
            └── verifactu/
                ├── verifactu.php
                ├── composer.json
                ├── composer.lock
                ├── apply_vendor_patches.sh
                └── vendor/
```

El certificado debe permanecer en `cert/`, fuera de `httpdocs/`.

---

## 16. Estado esperado del sistema

Una vez finalizada la instalación y realizadas las pruebas:

- El plugin VeriFactu está habilitado.
- El certificado está protegido y accesible para el servidor mediante su ruta local.
- HikaShop dispone de los campos «Tipo de abono» y «Comentario del abono».
- Los dos campos son visibles en el back-end y en la información adicional del pedido según la configuración de Pantalla.
- Las facturas pueden procesarse mediante el flujo VeriFactu.
- Las facturas aceptadas disponen de la información necesaria para generar el QR.
- Las facturas rectificativas pueden clasificarse mediante «Tipo de abono».

17. Modalidad de funcionamiento: VERI*FACTU con remisión a la AEAT

El plugin implementa la modalidad VERI*FACTU, con remisión de los registros de facturación a la AEAT mediante su servicio web.

Esta versión del plugin NO implementa la modalidad alternativa de sistemas informáticos de facturación sin remisión a la AEAT, basada en firma XAdES y conservación local de los registros.

Por tanto, el plugin no dispone de un selector para elegir entre:

- VERI*FACTU con remisión a la AEAT.
- Sistema sin remisión a la AEAT con firma XAdES y conservación local.

La modalidad implementada en esta versión es exclusivamente:

VERI*FACTU → generación del registro → remisión a la AEAT → recepción de la respuesta → registro del resultado y generación del QR cuando corresponda.

El certificado digital se utiliza para el proceso de autenticación/firma necesario para la remisión de los registros a la AEAT.

Importante: la firma del registro que se remite a la AEAT no significa que el plugin implemente la modalidad alternativa sin remisión. Esta versión está diseñada para la remisión de los registros mediante VERI*FACTU.

