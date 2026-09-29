<?php
/**
 * Parche local sobre vendor/eseperio/verifactu-php: getLastRequestXml().
 *
 * Añade a la librería (no está en upstream) la captura del XML firmado que
 * REALMENTE se envía a la AEAT, para que VerifactuLibraryBridge pueda
 * guardarlo en `xml_enviado` incluso si la llamada SOAP falla:
 *
 *   src/services/VerifactuService.php
 *     - propiedad estática $lastRequestXml + getter getLastRequestXml()
 *     - registerInvoice(): reset al inicio y captura justo antes de la llamada SOAP
 *     - cancelInvoice():   reset al inicio y captura justo antes de la llamada SOAP
 *   src/Verifactu.php
 *     - fachada getLastRequestXml()
 *
 * Es idempotente (si ya está aplicado, no toca nada) y falla con código de
 * salida 1 si no encuentra alguno de los puntos de anclaje -- es decir, si
 * upstream ha cambiado el código y el parche hay que revisarlo, en vez de
 * dejar la librería a medio parchear sin avisar.
 *
 * Uso:  php scripts/patch-eseperio-lastrequestxml.php [ruta/al/vendor]
 * (por defecto, ../vendor respecto a este script o ./plg_hikashop_verifactu/vendor;
 *  también se puede hacer require desde scripts/apply-vendor-patches.php,
 *  llamando a verifactuPatchLastRequestXml($vendorDir)).
 */

/**
 * @return bool true si la librería queda parcheada (ya lo estaba o se ha aplicado ahora)
 */
function verifactuPatchLastRequestXml(string $vendorDir): bool
{
    $base = rtrim($vendorDir, '/\\') . '/eseperio/verifactu-php/src';
    $ok = true;

    // ---- src/services/VerifactuService.php --------------------------------
    $ok = vfPatchFile($base . '/services/VerifactuService.php', [
        [
            'done'   => '/protected\s+static\s+\?string\s+\$lastRequestXml/',
            'anchor' => '/(protected\s+static\s+\$client;\R)/',
            'insert' => 'after',
            'text'   => <<<'TXT'

    /**
     * v0.34.9 (parche local, no upstream): último XML firmado y envuelto
     * REALMENTE enviado a la AEAT (RegFactuSistemaFacturacion), capturado
     * justo antes de la llamada SOAP -- así queda disponible aunque la
     * propia llamada falle (SoapFault) o la respuesta no se pueda parsear.
     * El RD 1007/2023 exige poder conservar y exportar los registros de
     * facturación; sin esto, el bridge (VerifactuLibraryBridge) no tenía
     * ninguna forma de recuperar el XML exacto que salió hacia la AEAT
     * para guardarlo en `xml_enviado`.
     */
    protected static ?string $lastRequestXml = null;

    /**
     * Ver comentario de $lastRequestXml. Devuelve el XML de la ÚLTIMA
     * llamada a registerInvoice()/cancelInvoice() en este mismo proceso PHP
     * (null si esa llamada falló antes de llegar a construir el XML, o si
     * todavía no se ha llamado a ninguna de las dos).
     */
    public static function getLastRequestXml(): ?string
    {
        return self::$lastRequestXml;
    }

TXT,
        ],
        [
            'done'   => '/function\s+registerInvoice\s*\([^)]*\)\s*\{[^}]*?\$lastRequestXml\s*=\s*null;/',
            'anchor' => '/(public\s+static\s+function\s+registerInvoice\s*\(\s*InvoiceSubmission\s+\$invoice\s*\)\s*\{\R)/',
            'insert' => 'after',
            'text'   => <<<'TXT'
        // Se resetea al principio de cada llamada -- si esta llamada falla
        // ANTES de llegar a construir el XML (validación, certificado,
        // etc.), getLastRequestXml() debe devolver null y no el XML de una
        // factura anterior ya enviada en este mismo proceso.
        self::$lastRequestXml = null;


TXT,
        ],
        [
            'done'   => '/self::\$lastRequestXml\s*=\s*\$xml;/',
            'anchor' => '/^([ \t]*\/\/\s*8\.\s*Get SOAP client\R)/m',
            'insert' => 'before',
            'text'   => <<<'TXT'
        // Capturado ANTES de la llamada SOAP a propósito: si __soapCall()
        // lanza un SoapFault, el XML que se intentó enviar sigue siendo
        // recuperable vía getLastRequestXml().
        self::$lastRequestXml = $xml;


TXT,
        ],
        [
            'done'   => '/function\s+cancelInvoice\s*\([^)]*\)\s*\{\s*self::\$lastRequestXml\s*=\s*null;/',
            'anchor' => '/(public\s+static\s+function\s+cancelInvoice\s*\(\s*InvoiceCancellation\s+\$cancellation\s*\)\s*\{\R)/',
            'insert' => 'after',
            'text'   => <<<'TXT'
        self::$lastRequestXml = null;


TXT,
        ],
        [
            'done'   => '/self::\$lastRequestXml\s*=\s*\$signedXml;/',
            'anchor' => '/(\$signedXml\s*=\s*preg_replace\(\'\/<\\\\\?xml\.\*\?\\\\\?>\/\',\s*\'\',\s*\$signedXml\);\R)/',
            'insert' => 'after',
            'text'   => <<<'TXT'

        // Ver comentario en registerInvoice(): capturado antes de la
        // llamada SOAP para que sobreviva a un SoapFault.
        self::$lastRequestXml = $signedXml;
TXT . "\n",
        ],
    ]);

    // ---- src/Verifactu.php (fachada) --------------------------------------
    $ok = vfPatchFile($base . '/Verifactu.php', [
        [
            'done'   => '/function\s+getLastRequestXml\s*\(/',
            'anchor' => '/^([ \t]*\/\*\*\R[ \t]*\*\s*Queries submitted invoices from AEAT)/m',
            'insert' => 'before',
            'text'   => <<<'TXT'
    /**
     * v0.34.9 (parche local, no upstream): XML real de la última llamada a
     * registerInvoice()/cancelInvoice() en este proceso -- ver el comentario
     * de VerifactuService::$lastRequestXml.
     */
    public static function getLastRequestXml(): ?string
    {
        return VerifactuService::getLastRequestXml();
    }


TXT,
        ],
    ]) && $ok;

    return $ok;
}

/**
 * @param array<int, array{done:string, anchor:string, insert:string, text:string}> $steps
 */
function vfPatchFile(string $file, array $steps): bool
{
    if (!is_file($file) || !is_readable($file)) {
        fwrite(STDERR, "[patch lastRequestXml] No existe o no se puede leer: $file\n");

        return false;
    }

    $original = file_get_contents($file);
    $code = $original;
    // Se trabaja con \n y se restituye el fin de línea original al escribir.
    $crlf = strpos($code, "\r\n") !== false;
    if ($crlf) {
        $code = str_replace("\r\n", "\n", $code);
    }

    $aplicados = 0;

    foreach ($steps as $i => $step) {
        if (preg_match($step['done'], $code) === 1) {
            continue; // ya aplicado
        }

        $n = preg_match_all($step['anchor'], $code, $m);
        if ($n !== 1) {
            fwrite(STDERR, sprintf(
                "[patch lastRequestXml] %s: paso %d, se esperaba 1 punto de anclaje y hay %d. "
                . "¿Ha cambiado upstream? Revisa el parche.\n",
                basename($file),
                $i + 1,
                (int) $n
            ));

            return false;
        }

        $code = preg_replace_callback(
            $step['anchor'],
            static fn(array $mm): string => $step['insert'] === 'after'
                ? $mm[1] . $step['text']
                : $step['text'] . $mm[1],
            $code,
            1
        );
        $aplicados++;
    }

    if ($aplicados === 0) {
        fwrite(STDOUT, "[patch lastRequestXml] " . basename($file) . ": ya estaba aplicado.\n");

        return true;
    }

    if ($crlf) {
        $code = str_replace("\n", "\r\n", $code);
    }

    if (file_put_contents($file, $code) === false) {
        fwrite(STDERR, "[patch lastRequestXml] No se pudo escribir: $file\n");

        return false;
    }

    fwrite(STDOUT, "[patch lastRequestXml] " . basename($file) . ": $aplicados cambio(s) aplicado(s).\n");

    return true;
}

// Ejecución directa por línea de comandos (no cuando se hace require).
if (PHP_SAPI === 'cli' && isset($argv[0]) && realpath($argv[0]) === realpath(__FILE__)) {
    $candidatos = isset($argv[1])
        ? [$argv[1]]
        : [__DIR__ . '/../vendor', __DIR__ . '/../plg_hikashop_verifactu/vendor'];

    foreach ($candidatos as $dir) {
        if (is_dir($dir . '/eseperio/verifactu-php')) {
            exit(verifactuPatchLastRequestXml($dir) ? 0 : 1);
        }
    }

    fwrite(STDERR, "[patch lastRequestXml] No encuentro vendor/eseperio/verifactu-php. Pasa la ruta del vendor como argumento.\n");
    exit(1);
}
