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
