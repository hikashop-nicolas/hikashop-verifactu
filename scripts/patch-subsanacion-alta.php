<?php
/**
 * Parche local sobre vendor/eseperio/verifactu-php: Subsanacion/RechazoPrevio
 * en RegistroAlta (facturas, no anulaciones).
 *
 * v0.34.23: auditoría de seguridad, punto "Registros rechazados y
 * reintentos" (prioridad 🔴). La librería, tal y como viene de upstream,
 * SOLO implementa RechazoPrevio para InvoiceCancellation (anulaciones) --
 * InvoiceSubmission (altas, el caso normal) no tiene ni la propiedad ni la
 * serialización, aunque el XSD (RegistroFacturacionAltaType, en
 * SuministroInformacion.xsd) sí contempla Subsanacion Y RechazoPrevio para
 * altas, en ese orden, justo entre NombreRazonEmisor y TipoFactura.
 *
 * Sin esto, un reintento de una factura que la AEAT rechazó (ej. NIF de
 * cliente inválido, corregido a mano y reenviado) se envía como si fuera un
 * registro de facturación nuevo y ajeno al anterior, sin indicar en el XML
 * que corrige un rechazo previo -- que es precisamente lo que el modelo de
 * la AEAT espera declarar explícitamente vía Subsanacion=S + RechazoPrevio=S
 * (ver ErrorRegistry 1153/1161/1171: la AEAT valida activamente la relación
 * entre ambos campos, así que no pueden fijarse "a medias").
 *
 * VerifactuLibraryBridge::registrarFactura() decide cuándo fijar estos dos
 * campos (ver ahí el porqué exacto), este parche solo hace que la librería
 * sea CAPAZ de aceptarlos y serializarlos.
 *
 * Es idempotente y falla con código de salida 1 si no encuentra alguno de
 * los puntos de anclaje -- ver scripts/patch-eseperio-lastrequestxml.php
 * para más detalle del mecanismo (vfPatchFile, cargado desde ahí).
 *
 * Uso: ver apply-vendor-patches.php (llama a verifactuPatchSubsanacionAlta($vendorDir)).
 */

/**
 * @return bool true si la librería queda parcheada (ya lo estaba o se ha aplicado ahora)
 */
function verifactuPatchSubsanacionAlta(string $vendorDir): bool
{
    $base = rtrim($vendorDir, '/\\') . '/eseperio/verifactu-php/src';
    $ok = true;

    // ---- src/models/InvoiceSubmission.php ----------------------------------
    $ok = vfPatchFile($base . '/models/InvoiceSubmission.php', [
        [
            'done'   => '/public\s+\$subsanacion;/',
            'anchor' => '/(public\s+\$issuerName;\R)/',
            'insert' => 'after',
            'text'   => <<<'TXT'

    /**
     * v0.34.23 (parche local, no upstream): corrección de un rechazo previo
     * de la AEAT (Subsanacion, opcional). Solo puede ir a YesNoType::YES si
     * previousRejection también es YesNoType::YES (ver ErrorRegistry 1153).
     * @var \eseperio\verifactu\models\enums\YesNoType|null
     */
    public $subsanacion;

    /**
     * v0.34.23 (parche local, no upstream): rechazo previo por la AEAT de
     * este mismo registro (RechazoPrevio, opcional). Mismo campo que ya
     * existía en InvoiceCancellation, portado aquí porque upstream solo lo
     * implementaba para anulaciones pese a que el XSD lo contempla también
     * para altas.
     * @var \eseperio\verifactu\models\enums\YesNoType|null
     */
    public $previousRejection;

TXT,
        ],
        [
            'done'   => "/\\['subsanacion', function/",
            'anchor' => '/(return array_merge\(parent::rules\(\), \[\R)/',
            'insert' => 'after',
            'text'   => <<<'TXT'
            ['subsanacion', function ($value): bool|string {
                if ($value === null) {
                    return true;
                }

                return ($value instanceof YesNoType) ? true : 'Must be an instance of YesNoType.';
            }],
            ['previousRejection', function ($value): bool|string {
                if ($value === null) {
                    return true;
                }

                return ($value instanceof YesNoType) ? true : 'Must be an instance of YesNoType.';
            }],
TXT,
        ],
    ]);

    // ---- src/services/InvoiceSerializer.php --------------------------------
    $ok = vfPatchFile($base . '/services/InvoiceSerializer.php', [
        [
            'done'   => '/sf:Subsanacion/',
            // El hueco en blanco entre NombreRazonEmisor y el comentario "TipoFactura
            // (required)" ya existe en upstream (líneas en blanco dejadas ahí);
            // se ancla al comentario de TipoFactura para insertar justo antes, en el
            // orden exacto que exige RegistroFacturacionAltaType: ... NombreRazonEmisor,
            // Subsanacion, RechazoPrevio, TipoFactura, ...
            'anchor' => '/(\s*\/\/ TipoFactura \(required\)\R)/',
            'insert' => 'before',
            'text'   => <<<'TXT'


        // Subsanacion (optional) -- v0.34.23, parche local, no upstream
        if ($invoice->subsanacion) {
            $root->appendChild($doc->createElementNS(self::SF_NAMESPACE, 'sf:Subsanacion', (string) $invoice->subsanacion->value));
        }

        // RechazoPrevio (optional) -- v0.34.23, parche local, no upstream
        if ($invoice->previousRejection) {
            $root->appendChild($doc->createElementNS(self::SF_NAMESPACE, 'sf:RechazoPrevio', (string) $invoice->previousRejection->value));
        }
TXT,
        ],
    ]) && $ok;

    return $ok;
}

// Ejecución directa por línea de comandos (no cuando se hace require).
if (PHP_SAPI === 'cli' && isset($argv[0]) && realpath($argv[0]) === realpath(__FILE__)) {
    require_once __DIR__ . '/patch-eseperio-lastrequestxml.php';

    $candidatos = isset($argv[1])
        ? [$argv[1]]
        : [__DIR__ . '/../vendor', __DIR__ . '/../plg_hikashop_verifactu/vendor'];

    foreach ($candidatos as $dir) {
        if (is_dir($dir . '/eseperio/verifactu-php')) {
            exit(verifactuPatchSubsanacionAlta($dir) ? 0 : 1);
        }
    }

    fwrite(STDERR, "[patch subsanacion-alta] No encuentro vendor/eseperio/verifactu-php. Pasa la ruta del vendor como argumento.\n");
    exit(1);
}
