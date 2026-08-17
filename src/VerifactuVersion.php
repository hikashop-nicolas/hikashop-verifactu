<?php
defined('_JEXEC') or die;

/**
 * The version of the plugin, read from its manifest.
 *
 * There is one number to bump, in verifactu.xml, and everything which has to
 * state a version reads it here: the responsible declaration, and the
 * SistemaInformatico block sent to the AEAT with every invoice. A declaration
 * naming a version other than the one which produced the record is exactly what
 * the declaration is supposed to rule out.
 */
abstract class VerifactuVersion
{
    private static ?string $version = null;

    public static function get(): string
    {
        if (self::$version !== null) {
            return self::$version;
        }

        self::$version = '0.0.0';

        $manifest = dirname(__DIR__) . '/verifactu.xml';
        if (is_readable($manifest)) {
            $xml = @simplexml_load_file($manifest);
            if ($xml !== false && isset($xml->version)) {
                $read = trim((string) $xml->version);
                if ($read !== '') {
                    self::$version = $read;
                }
            }
        }

        return self::$version;
    }
}
