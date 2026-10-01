-- v0.34.0: soporte para anular una factura emitida por error mediante un
-- Registro de Anulación (RegistroAnulacion) ante la AEAT, distinto de una
-- rectificativa. Ver VerifactuLibraryBridge::anularFactura().
--
-- field_display copia el mismo valor que ya tenga, EN ESTA INSTALACIÓN,
-- verifactu_sustituye_simplificada -- si ya lo corregiste a mano para que
-- aparezca en "Información adicional del pedido" (hizo falta en v0.33.x),
-- estos dos campos nacen ya con esa misma configuración. Si no lo habías
-- corregido, habrá que repetir ese mismo paso para estos dos.
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
    0, 0, 1, 0, 'No', 0, 'all', 'all', 0,
    COALESCE(
        (SELECT field_display FROM `#__hikashop_field` WHERE field_table = 'order' AND field_namekey = 'verifactu_sustituye_simplificada' LIMIT 1),
        ';front_order=0;invoice=0;back_shipping_invoice=0;order_edit_fields=1;order_form=1;mail_order_notif=0;mail_status_notif=0;mail_order_creation=0;mail_admin_notif=0;mail_payment_notif=0;'
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
        ';front_order=0;invoice=0;back_shipping_invoice=0;order_edit_fields=1;order_form=1;mail_order_notif=0;mail_status_notif=0;mail_order_creation=0;mail_admin_notif=0;mail_payment_notif=0;'
    )
FROM DUAL
WHERE NOT EXISTS (
    SELECT 1 FROM `#__hikashop_field`
    WHERE `field_table` = 'order'
      AND `field_namekey` = 'verifactu_motivo_anulacion'
);
