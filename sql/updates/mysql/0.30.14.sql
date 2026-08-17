-- v0.30.14: use the HikaShop 6.x order display keys so the fields
-- are shown and editable in the backend order form and order details.
-- No other plugin functionality is changed.

UPDATE `#__hikashop_field`
SET `field_display` = ';front_order=0;invoice=0;back_shipping_invoice=0;order_edit_fields=1;order_form=1;mail_order_notif=0;mail_status_notif=0;mail_order_creation=0;mail_admin_notif=0;mail_payment_notif=0;'
WHERE `field_table` = 'order'
  AND `field_namekey` IN ('verifactu_tipo_abono', 'verifactu_comentario_abono');
