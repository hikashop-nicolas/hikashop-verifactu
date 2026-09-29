<?php
/**
 * Aplica los parches locales a las librerías de vendor/.
 *
 * composer.json lo ejecuta solo tras "composer install" y "composer update"
 * (y a mano con "composer patch"), porque regenerar vendor/ borra los cambios
 * hechos directamente en la librería.
 *
 * Para añadir otro parche: crea scripts/patch-<nombre>.php con una función
 * que devuelva true/false y llámala aquí abajo.
 */

$vendorDir = dirname(__DIR__) . '/vendor';

if (!is_dir($vendorDir)) {
    fwrite(STDERR, "[apply-vendor-patches] No existe $vendorDir. Ejecuta antes composer install.\n");
    exit(1);
}

require_once __DIR__ . '/patch-eseperio-lastrequestxml.php';
require_once __DIR__ . '/patch-subsanacion-alta.php';

$ok = true;

// eseperio/verifactu-php: getLastRequestXml() (guarda en xml_enviado el XML realmente enviado a la AEAT)
$ok = verifactuPatchLastRequestXml($vendorDir) && $ok;

// eseperio/verifactu-php: Subsanacion/RechazoPrevio en RegistroAlta (v0.34.23,
// ver scripts/patch-subsanacion-alta.php)
$ok = verifactuPatchSubsanacionAlta($vendorDir) && $ok;

exit($ok ? 0 : 1);
