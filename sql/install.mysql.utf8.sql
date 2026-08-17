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
    `hash_anterior` CHAR(64) NULL,
    `hash_actual` CHAR(64) NOT NULL,
    `qr_url` TEXT NULL,
    `xml_enviado` TEXT NULL,
    `estado_envio` ENUM('pendiente','enviado','aceptado','aceptado_con_errores','rechazado','error') NOT NULL DEFAULT 'pendiente',
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

-- v0.30.12: crea automáticamente los campos personalizados de HikaShop
-- para Tipo de abono y Comentario del abono.
ALTER TABLE `#__hikashop_order`
    ADD COLUMN IF NOT EXISTS `verifactu_tipo_abono` VARCHAR(255) NULL,
    ADD COLUMN IF NOT EXISTS `verifactu_comentario_abono` TEXT NULL;

INSERT INTO `#__hikashop_field`
    (`field_table`, `field_realname`, `field_namekey`, `field_type`, `field_value`,
     `field_published`, `field_ordering`, `field_options`, `field_core`,
     `field_required`, `field_backend`, `field_frontcomp`, `field_default`,
     `field_backend_listing`, `field_access`, `field_categories`,
     `field_with_sub_categories`, `field_display`)
SELECT
    'order',
    'Tipo de abono',
    'verifactu_tipo_abono',
    'singledropdown',
    'Factura::Factura::0
Devolución completa::Devolución completa::0
Devolución parcial::Devolución parcial::0
Mercancía obsoleta::Mercancía obsoleta::0
Descuento posterior::Descuento posterior::0
Error en IVA::Error en IVA::0
Cancelación::Cancelación::0
Concurso de acreedores::Concurso de acreedores::0
Crédito incobrable::Crédito incobrable::0
Otro::Otro::0',
    1, 1,
    'a:5:{s:12:"errormessage";s:0:"";s:4:"cols";s:0:"";s:4:"rows";s:0:"";s:4:"size";s:0:"";s:6:"format";s:0:"";}',
    0, 0, 1, 0, '', 0, 'all', 'all', 0, ';front_order=0;invoice=0;back_shipping_invoice=0;order_edit_fields=1;order_form=1;mail_order_notif=0;mail_status_notif=0;mail_order_creation=0;mail_admin_notif=0;mail_payment_notif=0;'
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
    'a:5:{s:12:"errormessage";s:0:"";s:4:"cols";s:0:"";s:4:"rows";s:0:"";s:4:"size";s:0:"";s:6:"format";s:0:"";}',
    0, 0, 1, 0, '', 0, 'all', 'all', 0, ';front_order=0;invoice=0;back_shipping_invoice=0;order_edit_fields=1;order_form=1;mail_order_notif=0;mail_status_notif=0;mail_order_creation=0;mail_admin_notif=0;mail_payment_notif=0;'
FROM DUAL
WHERE NOT EXISTS (
    SELECT 1 FROM `#__hikashop_field`
    WHERE `field_table` = 'order'
      AND `field_namekey` = 'verifactu_comentario_abono'
);
