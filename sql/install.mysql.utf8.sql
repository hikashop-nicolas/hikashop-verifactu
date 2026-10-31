CREATE TABLE IF NOT EXISTS `#__hikashop_verifactu_registro` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `order_id` INT UNSIGNED NOT NULL,
    `invoice_number` VARCHAR(60) NOT NULL,
    `invoice_date` DATETIME NOT NULL,
    `nif_emisor` VARCHAR(20) NOT NULL,
    `total_factura` DECIMAL(12,2) NOT NULL,
    `tipo_abono` VARCHAR(80) NULL,
    `tipo_factura` VARCHAR(2) NULL,
    `tipo_rectificativa` VARCHAR(1) NULL,
    `factura_rectificada` VARCHAR(60) NULL,
    `registro_anterior_id` INT UNSIGNED NULL,
    `hash_anterior` CHAR(64) NULL,
    `hash_actual` CHAR(64) NOT NULL,
    `qr_url` TEXT NULL,
    `xml_enviado` TEXT NULL,
    `estado_envio` VARCHAR(60) NOT NULL DEFAULT 'pendiente',
    `respuesta_aeat` TEXT NULL,
    `intentos` INT UNSIGNED NOT NULL DEFAULT 0,
    `created` DATETIME NOT NULL,
    `modified` DATETIME NULL,
    PRIMARY KEY (`id`),
    KEY `idx_order` (`order_id`),
    KEY `idx_estado` (`estado_envio`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;


CREATE TABLE IF NOT EXISTS `#__hikashop_verifactu_declaracion` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `version_sistema` VARCHAR(50) NOT NULL,
    `nombre` VARCHAR(255) NULL,
    `nif` VARCHAR(50) NULL,
    `direccion` TEXT NULL,
    `codigo_postal` VARCHAR(30) NULL,
    `localidad` VARCHAR(150) NULL,
    `provincia` VARCHAR(150) NULL,
    `pais` VARCHAR(100) NULL,
    `lugar` VARCHAR(150) NULL,
    `fecha_firma` DATE NULL,
    `declaracion_html` LONGTEXT NOT NULL,
    `condiciones_html` LONGTEXT NOT NULL,
    `created` DATETIME NOT NULL,
    PRIMARY KEY (`id`),
    KEY `idx_version` (`version_sistema`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- v0.31.10: numeración propia para facturas F2 (simplificadas) y R
-- (rectificativas), independiente de la serie continua nativa de HikaShop
-- que usan las F1. `familia` es 'F2' o 'R' (R1-R4 comparten una sola serie).
CREATE TABLE IF NOT EXISTS `#__hikashop_verifactu_contador` (
    `familia` VARCHAR(2) NOT NULL,
    `anio` VARCHAR(4) NOT NULL,
    `ultimo_numero` INT UNSIGNED NOT NULL DEFAULT 0,
    PRIMARY KEY (`familia`, `anio`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- v0.34.92: estado por año del aviso de fin de año (email enviado / acción hecha).
CREATE TABLE IF NOT EXISTS `#__hikashop_verifactu_aviso_fin_anio` (
    `anio` VARCHAR(4) NOT NULL,
    `email_enviado` DATETIME NULL,
    `gestionado` DATETIME NULL,
    `accion` VARCHAR(20) NULL,
    PRIMARY KEY (`anio`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Guarda qué número propio se le asignó a cada pedido, para que un reintento
-- (ej. tras corregir el NIF) reutilice el mismo número en vez de consumir
-- otro de la serie.
CREATE TABLE IF NOT EXISTS `#__hikashop_verifactu_numeracion_asignada` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `order_id` INT UNSIGNED NOT NULL,
    `familia` VARCHAR(10) NOT NULL,
    `numero` VARCHAR(60) NOT NULL,
    `numero_original_hikashop` VARCHAR(60) NULL,
    `created` DATETIME NOT NULL,
    PRIMARY KEY (`id`),
    UNIQUE KEY `idx_order_familia` (`order_id`, `familia`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- v0.30.12: crea automáticamente los campos personalizados de HikaShop
-- para Tipo de abono y Comentario del abono. Las columnas correspondientes de
-- la tabla de pedidos las crea script.php, porque la comprobación de existencia
-- de una columna no se puede escribir en SQL de forma válida para MySQL y para
-- MariaDB a la vez.
--
-- v0.34.54: se corrigen los valores por defecto de las 3 pantallas
-- "Formulario del back-end" / "Listado del Back-end" / "Información
-- adicional del pedido" para los 9 campos personalizados de VeriFactu, tras
-- revisar el código real de HikaShop (administrator/components/com_hikashop/
-- views/field/view.html.php y views/field/tmpl/form.php):
--   - "Formulario del back-end"          -> columna `field_backend` (ya
--     nacía en 1/Sí; no hacía falta ningún cambio aquí).
--   - "Listado del Back-end"             -> columna `field_backend_listing`
--     (nacía en 0/No; se cambia a 1).
--   - "Información adicional del pedido" -> bandera dentro de
--     `field_display`, cuyo nombre interno real es `order_edit` (no
--     `order_edit_fields`, que es el nombre que se usó por error en
--     versiones anteriores basándose en la clave de idioma
--     FIELD_ORDER_EDIT_FIELDS -- HikaShop comprueba el nombre corto
--     `order_edit`, así que esa bandera nunca tenía efecto).
-- La migración equivalente para instalaciones que actualicen el plugin (en
-- vez de instalarlo de cero) está en script.php, método
-- migrarPantallasCamposAbono().

INSERT INTO `#__hikashop_field`
    (`field_table`, `field_realname`, `field_namekey`, `field_type`, `field_value`,
     `field_published`, `field_ordering`, `field_options`, `field_core`,
     `field_required`, `field_backend`, `field_frontcomp`, `field_default`,
     `field_backend_listing`, `field_access`, `field_categories`,
     `field_with_sub_categories`, `field_display`)
SELECT
    'order',
    'Tipo abono',
    'verifactu_tipo_abono',
    'singledropdown',
    'Factura::Factura::0
Devolución completa::Devolución completa::0
Devolución parcial::Devolución parcial::0
Mercancía obsoleta::Mercancía obsoleta::0
Descuento posterior::Descuento posterior::0
Error en IVA::Error en IVA::0
Concurso de acreedores::Concurso de acreedores::0
Crédito incobrable::Crédito incobrable::0
Otro::Otro::0',
    1, 1,
    'a:5:{s:12:"errormessage";s:0:"";s:4:"cols";s:0:"";s:4:"rows";s:0:"";s:4:"size";s:0:"";s:6:"format";s:0:"";}',
    0, 0, 1, 0, '', 1, 'all', 'all', 0, ';front_order=0;invoice=0;back_shipping_invoice=0;order_edit=1;order_form=1;mail_order_notif=0;mail_status_notif=0;mail_order_creation=0;mail_admin_notif=0;mail_payment_notif=0;'
FROM DUAL
WHERE NOT EXISTS (
    SELECT 1 FROM `#__hikashop_field`
    WHERE `field_table` = 'order'
      AND `field_namekey` = 'verifactu_tipo_abono'
);

INSERT INTO `#__hikashop_field`
    (`field_table`, `field_realname`, `field_namekey`, `field_type`, `field_value`,
     `field_published`, `field_ordering`, `field_options`, `field_core`,
     `field_required`, `field_backend`, `field_frontcomp`, `field_default`,
     `field_backend_listing`, `field_access`, `field_categories`,
     `field_with_sub_categories`, `field_display`)
SELECT
    'order',
    'Comentario del abono',
    'verifactu_comentario_abono',
    'textarea',
    '',
    1, 2,
    'a:6:{s:12:"errormessage";s:0:"";s:4:"cols";s:0:"";s:4:"rows";s:0:"";s:4:"size";s:0:"";s:6:"format";s:0:"";s:11:"placeholder";s:35:"Indicar número de factura original";}',
    0, 0, 1, 0, '', 0, 'all', 'all', 0, ';front_order=0;invoice=0;back_shipping_invoice=0;order_edit=1;order_form=1;mail_order_notif=0;mail_status_notif=0;mail_order_creation=0;mail_admin_notif=0;mail_payment_notif=0;'
FROM DUAL
WHERE NOT EXISTS (
    SELECT 1 FROM `#__hikashop_field`
    WHERE `field_table` = 'order'
      AND `field_namekey` = 'verifactu_comentario_abono'
);

-- v0.33.0: campo que dispara la emisión de una F3 (sustituye a una factura
-- simplificada previa) para este pedido.
INSERT INTO `#__hikashop_field`
    (`field_table`, `field_realname`, `field_namekey`, `field_type`, `field_value`,
     `field_published`, `field_ordering`, `field_options`, `field_core`,
     `field_required`, `field_backend`, `field_frontcomp`, `field_default`,
     `field_backend_listing`, `field_access`, `field_categories`,
     `field_with_sub_categories`, `field_display`)
SELECT
    'order',
    'Sustituye F2 (F3)',
    'verifactu_sustituye_simplificada',
    'singledropdown',
    'No::No::0
Sí::Sí::0',
    1, 3,
    'a:5:{s:12:"errormessage";s:0:"";s:4:"cols";s:0:"";s:4:"rows";s:0:"";s:4:"size";s:0:"";s:6:"format";s:0:"";}',
    0, 0, 1, 0, 'No', 1, 'all', 'all', 0, ';front_order=0;invoice=0;back_shipping_invoice=0;order_edit=1;order_form=1;mail_order_notif=0;mail_status_notif=0;mail_order_creation=0;mail_admin_notif=0;mail_payment_notif=0;'
FROM DUAL
WHERE NOT EXISTS (
    SELECT 1 FROM `#__hikashop_field`
    WHERE `field_table` = 'order'
      AND `field_namekey` = 'verifactu_sustituye_simplificada'
);

-- v0.34.0: campos que disparan la anulación de una factura ya emitida por
-- error (RegistroAnulacion ante la AEAT, no una rectificativa). El
-- field_display copia el MISMO valor que ya tenga, en esta instalación,
-- verifactu_sustituye_simplificada, para que los tres se mantengan siempre
-- configurados igual (ver comentario de v0.34.54 más arriba: desde esa
-- versión el valor de origen ya activa correctamente las 3 pantallas).
INSERT INTO `#__hikashop_field`
    (`field_table`, `field_realname`, `field_namekey`, `field_type`, `field_value`,
     `field_published`, `field_ordering`, `field_options`, `field_core`,
     `field_required`, `field_backend`, `field_frontcomp`, `field_default`,
     `field_backend_listing`, `field_access`, `field_categories`,
     `field_with_sub_categories`, `field_display`)
SELECT
    'order',
    'Anular factura',
    'verifactu_anular_factura',
    'singledropdown',
    'No::No::0
Sí::Sí::0',
    1, 4,
    'a:5:{s:12:"errormessage";s:0:"";s:4:"cols";s:0:"";s:4:"rows";s:0:"";s:4:"size";s:0:"";s:6:"format";s:0:"";}',
    0, 0, 1, 0, 'No', 1, 'all', 'all', 0,
    COALESCE(
        (SELECT field_display FROM `#__hikashop_field` WHERE field_table = 'order' AND field_namekey = 'verifactu_sustituye_simplificada' LIMIT 1),
        ';front_order=0;invoice=0;back_shipping_invoice=0;order_edit=1;order_form=1;mail_order_notif=0;mail_status_notif=0;mail_order_creation=0;mail_admin_notif=0;mail_payment_notif=0;'
    )
FROM DUAL
WHERE NOT EXISTS (
    SELECT 1 FROM `#__hikashop_field`
    WHERE `field_table` = 'order'
      AND `field_namekey` = 'verifactu_anular_factura'
);

INSERT INTO `#__hikashop_field`
    (`field_table`, `field_realname`, `field_namekey`, `field_type`, `field_value`,
     `field_published`, `field_ordering`, `field_options`, `field_core`,
     `field_required`, `field_backend`, `field_frontcomp`, `field_default`,
     `field_backend_listing`, `field_access`, `field_categories`,
     `field_with_sub_categories`, `field_display`)
SELECT
    'order',
    'Motivo anulación',
    'verifactu_motivo_anulacion',
    'textarea',
    '',
    1, 5,
    'a:5:{s:12:"errormessage";s:0:"";s:4:"cols";s:0:"";s:4:"rows";s:0:"";s:4:"size";s:0:"";s:6:"format";s:0:"";}',
    0, 0, 1, 0, '', 0, 'all', 'all', 0,
    COALESCE(
        (SELECT field_display FROM `#__hikashop_field` WHERE field_table = 'order' AND field_namekey = 'verifactu_sustituye_simplificada' LIMIT 1),
        ';front_order=0;invoice=0;back_shipping_invoice=0;order_edit=1;order_form=1;mail_order_notif=0;mail_status_notif=0;mail_order_creation=0;mail_admin_notif=0;mail_payment_notif=0;'
    )
FROM DUAL
WHERE NOT EXISTS (
    SELECT 1 FROM `#__hikashop_field`
    WHERE `field_table` = 'order'
      AND `field_namekey` = 'verifactu_motivo_anulacion'
);

-- v0.34.30: rectificación por diferencias (R1 positiva o negativa) sobre una
-- factura YA aceptada del mismo pedido, sin necesidad de un pedido de abono
-- con importe negativo. Ver VerifactuLibraryBridge::rectificarPorDiferencias().
-- field_display copia el mismo valor que ya tenga, EN ESTA INSTALACIÓN,
-- verifactu_sustituye_simplificada, igual que ya hacen anular_factura/
-- motivo_anulacion más arriba.
INSERT INTO `#__hikashop_field`
    (`field_table`, `field_realname`, `field_namekey`, `field_type`, `field_value`,
     `field_published`, `field_ordering`, `field_options`, `field_core`,
     `field_required`, `field_backend`, `field_frontcomp`, `field_default`,
     `field_backend_listing`, `field_access`, `field_categories`,
     `field_with_sub_categories`, `field_display`)
SELECT
    'order',
    'Rectificar R1',
    'verifactu_rectificar_diferencias',
    'singledropdown',
    'No::No::0
Sí::Sí::0',
    1, 6,
    'a:5:{s:12:"errormessage";s:0:"";s:4:"cols";s:0:"";s:4:"rows";s:0:"";s:4:"size";s:0:"";s:6:"format";s:0:"";}',
    0, 0, 1, 0, 'No', 1, 'all', 'all', 0,
    COALESCE(
        (SELECT field_display FROM `#__hikashop_field` WHERE field_table = 'order' AND field_namekey = 'verifactu_sustituye_simplificada' LIMIT 1),
        ';front_order=0;invoice=0;back_shipping_invoice=0;order_edit=1;order_form=1;mail_order_notif=0;mail_status_notif=0;mail_order_creation=0;mail_admin_notif=0;mail_payment_notif=0;'
    )
FROM DUAL
WHERE NOT EXISTS (
    SELECT 1 FROM `#__hikashop_field`
    WHERE `field_table` = 'order'
      AND `field_namekey` = 'verifactu_rectificar_diferencias'
);

INSERT INTO `#__hikashop_field`
    (`field_table`, `field_realname`, `field_namekey`, `field_type`, `field_value`,
     `field_published`, `field_ordering`, `field_options`, `field_core`,
     `field_required`, `field_backend`, `field_frontcomp`, `field_default`,
     `field_backend_listing`, `field_access`, `field_categories`,
     `field_with_sub_categories`, `field_display`)
SELECT
    'order',
    'Nueva base',
    'verifactu_nueva_base_imponible',
    'text',
    '',
    1, 7,
    'a:5:{s:12:"errormessage";s:0:"";s:4:"cols";s:0:"";s:4:"rows";s:0:"";s:4:"size";s:0:"";s:6:"format";s:0:"";}',
    0, 0, 1, 0, '', 0, 'all', 'all', 0,
    COALESCE(
        (SELECT field_display FROM `#__hikashop_field` WHERE field_table = 'order' AND field_namekey = 'verifactu_sustituye_simplificada' LIMIT 1),
        ';front_order=0;invoice=0;back_shipping_invoice=0;order_edit=1;order_form=1;mail_order_notif=0;mail_status_notif=0;mail_order_creation=0;mail_admin_notif=0;mail_payment_notif=0;'
    )
FROM DUAL
WHERE NOT EXISTS (
    SELECT 1 FROM `#__hikashop_field`
    WHERE `field_table` = 'order'
      AND `field_namekey` = 'verifactu_nueva_base_imponible'
);

INSERT INTO `#__hikashop_field`
    (`field_table`, `field_realname`, `field_namekey`, `field_type`, `field_value`,
     `field_published`, `field_ordering`, `field_options`, `field_core`,
     `field_required`, `field_backend`, `field_frontcomp`, `field_default`,
     `field_backend_listing`, `field_access`, `field_categories`,
     `field_with_sub_categories`, `field_display`)
SELECT
    'order',
    'Nuevo IVA %',
    'verifactu_nuevo_tipo_iva',
    'text',
    '',
    1, 8,
    'a:5:{s:12:"errormessage";s:0:"";s:4:"cols";s:0:"";s:4:"rows";s:0:"";s:4:"size";s:0:"";s:6:"format";s:0:"";}',
    0, 0, 1, 0, '', 0, 'all', 'all', 0,
    COALESCE(
        (SELECT field_display FROM `#__hikashop_field` WHERE field_table = 'order' AND field_namekey = 'verifactu_sustituye_simplificada' LIMIT 1),
        ';front_order=0;invoice=0;back_shipping_invoice=0;order_edit=1;order_form=1;mail_order_notif=0;mail_status_notif=0;mail_order_creation=0;mail_admin_notif=0;mail_payment_notif=0;'
    )
FROM DUAL
WHERE NOT EXISTS (
    SELECT 1 FROM `#__hikashop_field`
    WHERE `field_table` = 'order'
      AND `field_namekey` = 'verifactu_nuevo_tipo_iva'
);

INSERT INTO `#__hikashop_field`
    (`field_table`, `field_realname`, `field_namekey`, `field_type`, `field_value`,
     `field_published`, `field_ordering`, `field_options`, `field_core`,
     `field_required`, `field_backend`, `field_frontcomp`, `field_default`,
     `field_backend_listing`, `field_access`, `field_categories`,
     `field_with_sub_categories`, `field_display`)
SELECT
    'order',
    'Nuevo recargo %',
    'verifactu_nuevo_tipo_recargo',
    'text',
    '',
    1, 9,
    'a:5:{s:12:"errormessage";s:0:"";s:4:"cols";s:0:"";s:4:"rows";s:0:"";s:4:"size";s:0:"";s:6:"format";s:0:"";}',
    0, 0, 1, 0, '', 0, 'all', 'all', 0,
    COALESCE(
        (SELECT field_display FROM `#__hikashop_field` WHERE field_table = 'order' AND field_namekey = 'verifactu_sustituye_simplificada' LIMIT 1),
        ';front_order=0;invoice=0;back_shipping_invoice=0;order_edit=1;order_form=1;mail_order_notif=0;mail_status_notif=0;mail_order_creation=0;mail_admin_notif=0;mail_payment_notif=0;'
    )
FROM DUAL
WHERE NOT EXISTS (
    SELECT 1 FROM `#__hikashop_field`
    WHERE `field_table` = 'order'
      AND `field_namekey` = 'verifactu_nuevo_tipo_recargo'
);
