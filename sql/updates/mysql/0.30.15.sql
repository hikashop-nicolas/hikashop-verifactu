-- v0.30.15: force the native order custom fields to be available in the
-- backend order editor. The plugin also renders the fields through the
-- HikaShop Display API and persists their values on order update.
UPDATE `#__hikashop_field`
SET `field_backend` = 1,
    `field_published` = 1,
    `field_display` = ';front_order=0;invoice=0;back_shipping_invoice=0;order_edit=1;order_edit_fields=1;order_form=1;mail_order_notif=0;mail_status_notif=0;mail_order_creation=0;mail_admin_notif=0;mail_payment_notif=0;'
WHERE `field_table` = 'order'
  AND `field_namekey` IN ('verifactu_tipo_abono', 'verifactu_comentario_abono');
