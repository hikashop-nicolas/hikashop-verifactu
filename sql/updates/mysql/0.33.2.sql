-- v0.33.2: 'checkbox' tampoco se ha renderizado correctamente en todas las
-- instalaciones. Se cambia a 'singledropdown' (No/Sí), el mismo tipo de
-- campo que ya usa "Tipo de abono" y que sabemos que funciona.
UPDATE `#__hikashop_field`
SET `field_type` = 'singledropdown',
    `field_value` = 'No::No::0
Sí::Sí::0',
    `field_default` = 'No'
WHERE `field_table` = 'order'
  AND `field_namekey` = 'verifactu_sustituye_simplificada'
  AND `field_type` IN ('checkbox', 'yesno');
