<?php
defined('_JEXEC') or die;

require_once __DIR__ . '/../src/VerifactuVersion.php';

/**
 * Aviso legal breve mostrado en la configuración del plugin, justo antes del
 * selector de Entorno AEAT (Pruebas/Producción). No sustituye al aviso legal
 * completo (ver DISCLAIMER.md en la carpeta del plugin, o el botón "Leer el
 * aviso legal completo" de aquí abajo, que reutiliza el mismo endpoint AJAX
 * que ya usa "Ver condiciones de uso" en la Declaración Responsable) -- es
 * solo el resumen que el usuario debe leer antes de activar el envío real.
 */
class JFormFieldAvisolegal extends \Joomla\CMS\Form\FormField
{
    protected $type = 'Avisolegal';

    protected function getInput()
    {
        $version = htmlspecialchars(VerifactuVersion::get(), ENT_QUOTES, 'UTF-8');
        // format=raw (a diferencia de format=json, que usan los botones de la
        // Declaración Responsable vía fetch+popup) hace que com_ajax devuelva
        // el HTML de buildConditionsHtml() tal cual, así que aquí basta un
        // enlace normal en vez de JavaScript.
        $docUrl = 'index.php?option=com_ajax&group=hikashop&plugin=verifactu&format=raw&task=condiciones';

        $html = '<div class="verifactu-aviso-legal alert alert-warning" style="border:1px solid #e0a800;border-left:5px solid #e0a800;padding:16px 18px;margin:0 0 4px;border-radius:4px">';
        $html .= '<p style="margin:0 0 8px"><strong>Antes de activar el envío a la AEAT (Entorno = Producción), lee esto:</strong></p>';
        $html .= '<p style="margin:0 0 8px">Este plugin es una herramienta informática. No proporciona asesoramiento fiscal, contable ni jurídico, y no garantiza que las facturas, claves de régimen, calificaciones de IVA, causas de exención o registros generados sean fiscalmente correctos para tu caso concreto -- ni siquiera cuando la AEAT los acepta. La responsabilidad de que la configuración, los datos fiscales y cada factura emitida sean correctos es exclusivamente tuya. El software se distribuye "tal cual", sin garantía, bajo licencia GNU GPLv3.</p>';
        $html .= '<p style="margin:0"><a href="' . $docUrl . '" target="_blank" rel="noopener noreferrer" class="btn btn-outline-secondary btn-sm">Leer el aviso legal completo</a> <span style="opacity:.75;margin-left:8px">(versión del plugin: ' . $version . ')</span></p>';
        $html .= '</div>';

        return $html;
    }
}
