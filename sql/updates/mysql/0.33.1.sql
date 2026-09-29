-- v0.33.1: el tipo de campo 'yesno' de HikaShop no está disponible en todas
-- las instalaciones (depende de un plugin de Joomla que puede no estar
-- instalado/publicado). Se cambia a 'checkbox', que es un tipo de campo
-- núcleo de HikaShop y no depende de ningún plugin adicional.
UPDATE `#__hikashop_field`
SET `field_type` = 'checkbox'
WHERE `field_table` = 'order'
  AND `field_namekey` = 'verifactu_sustituye_simplificada'
  AND `field_type` = 'yesno';
