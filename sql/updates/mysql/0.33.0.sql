-- v0.33.0: soporte F3 (sustituye a una factura simplificada previa) y R5
-- (rectificativa de una simplificada). F3 se dispara con un nuevo campo
-- personalizado de HikaShop; R5 no necesita campo nuevo, se deduce igual que
-- F2 (pedido sin NIF), pero ahora aplicado también cuando es rectificativa.
-- La columna de la tabla de pedidos la crea script.php (postflight), válido
-- tanto en MySQL como en MariaDB.

INSERT INTO `#__hikashop_field`
    (`field_table`, `field_realname`, `field_namekey`, `field_type`, `field_value`,
     `field_published`, `field_ordering`, `field_options`, `field_core`,
     `field_required`, `field_backend`, `field_frontcomp`, `field_default`,
     `field_backend_listing`, `field_access`, `field_categories`,
     `field_with_sub_categories`, `field_display`)
SELECT
    'order',
    'Sustituye a factura simplificada (F3)',
    'verifactu_sustituye_simplificada',
    'yesno',
    '0',
    1, 3,
    'a:5:{s:12:"errormessage";s:0:"";s:4:"cols";s:0:"";s:4:"rows";s:0:"";s:4:"size";s:0:"";s:6:"format";s:0:"";}',
    0, 0, 1, 0, '0', 0, 'all', 'all', 0, ';front_order=0;invoice=0;back_shipping_invoice=0;order_edit_fields=1;order_form=1;mail_order_notif=0;mail_status_notif=0;mail_order_creation=0;mail_admin_notif=0;mail_payment_notif=0;'
FROM DUAL
WHERE NOT EXISTS (
    SELECT 1 FROM `#__hikashop_field`
    WHERE `field_table` = 'order'
      AND `field_namekey` = 'verifactu_sustituye_simplificada'
);
