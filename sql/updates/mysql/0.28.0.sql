ALTER TABLE `#__hikashop_verifactu_registro`
    ADD COLUMN IF NOT EXISTS `tipo_abono` VARCHAR(80) NULL AFTER `total_factura`,
    ADD COLUMN IF NOT EXISTS `tipo_factura` VARCHAR(2) NULL AFTER `tipo_abono`,
    ADD COLUMN IF NOT EXISTS `tipo_rectificativa` VARCHAR(1) NULL AFTER `tipo_factura`,
    ADD COLUMN IF NOT EXISTS `factura_rectificada` VARCHAR(60) NULL AFTER `tipo_rectificativa`;
