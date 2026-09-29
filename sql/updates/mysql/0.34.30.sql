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
    'Rectificar por diferencias (R1)',
    'verifactu_rectificar_diferencias',
    'singledropdown',
    'No::No::0
Sí::Sí::0',
    1, 6,
    'a:5:{s:12:"errormessage";s:0:"";s:4:"cols";s:0:"";s:4:"rows";s:0:"";s:4:"size";s:0:"";s:6:"format";s:0:"";}',
    0, 0, 1, 0, 'No', 0, 'all', 'all', 0,
    COALESCE(
        (SELECT field_display FROM `#__hikashop_field` WHERE field_table = 'order' AND field_namekey = 'verifactu_sustituye_simplificada' LIMIT 1),
        ';front_order=0;invoice=0;back_shipping_invoice=0;order_edit_fields=1;order_form=1;mail_order_notif=0;mail_status_notif=0;mail_order_creation=0;mail_admin_notif=0;mail_payment_notif=0;'
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
    'Nueva base imponible',
    'verifactu_nueva_base_imponible',
    'text',
    '',
    1, 7,
    'a:5:{s:12:"errormessage";s:0:"";s:4:"cols";s:0:"";s:4:"rows";s:0:"";s:4:"size";s:0:"";s:6:"format";s:0:"";}',
    0, 0, 1, 0, '', 0, 'all', 'all', 0,
    COALESCE(
        (SELECT field_display FROM `#__hikashop_field` WHERE field_table = 'order' AND field_namekey = 'verifactu_sustituye_simplificada' LIMIT 1),
        ';front_order=0;invoice=0;back_shipping_invoice=0;order_edit_fields=1;order_form=1;mail_order_notif=0;mail_status_notif=0;mail_order_creation=0;mail_admin_notif=0;mail_payment_notif=0;'
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
    'Nuevo tipo de IVA (%)',
    'verifactu_nuevo_tipo_iva',
    'text',
    '',
    1, 8,
    'a:5:{s:12:"errormessage";s:0:"";s:4:"cols";s:0:"";s:4:"rows";s:0:"";s:4:"size";s:0:"";s:6:"format";s:0:"";}',
    0, 0, 1, 0, '', 0, 'all', 'all', 0,
    COALESCE(
        (SELECT field_display FROM `#__hikashop_field` WHERE field_table = 'order' AND field_namekey = 'verifactu_sustituye_simplificada' LIMIT 1),
        ';front_order=0;invoice=0;back_shipping_invoice=0;order_edit_fields=1;order_form=1;mail_order_notif=0;mail_status_notif=0;mail_order_creation=0;mail_admin_notif=0;mail_payment_notif=0;'
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
    'Nuevo tipo de recargo de equivalencia (%)',
    'verifactu_nuevo_tipo_recargo',
    'text',
    '',
    1, 9,
    'a:5:{s:12:"errormessage";s:0:"";s:4:"cols";s:0:"";s:4:"rows";s:0:"";s:4:"size";s:0:"";s:6:"format";s:0:"";}',
    0, 0, 1, 0, '', 0, 'all', 'all', 0,
    COALESCE(
        (SELECT field_display FROM `#__hikashop_field` WHERE field_table = 'order' AND field_namekey = 'verifactu_sustituye_simplificada' LIMIT 1),
        ';front_order=0;invoice=0;back_shipping_invoice=0;order_edit_fields=1;order_form=1;mail_order_notif=0;mail_status_notif=0;mail_order_creation=0;mail_admin_notif=0;mail_payment_notif=0;'
    )
FROM DUAL
WHERE NOT EXISTS (
    SELECT 1 FROM `#__hikashop_field`
    WHERE `field_table` = 'order'
      AND `field_namekey` = 'verifactu_nuevo_tipo_recargo'
);
