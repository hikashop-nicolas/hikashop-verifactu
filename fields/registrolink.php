<?php
defined('_JEXEC') or die;

/**
 * Enlace, en la propia configuración del plugin, a la pantalla de consulta
 * y exportación del registro de facturación (task=registro en
 * onAjaxVerifactu(), ver verifactu.php). RD 1007/2023 / Orden
 * HAC/1177/2024: el usuario debe poder acceder, consultar y exportar sus
 * registros de facturación sin depender de entrar directamente en la base
 * de datos.
 */
class JFormFieldRegistrolink extends \Joomla\CMS\Form\FormField
{
    protected $type = 'Registrolink';

    protected function getInput()
    {
        $url = 'index.php?option=com_ajax&group=hikashop&plugin=verifactu&format=raw&task=registro';

        $html = '<div style="border:1px solid #b8daff;background:#eaf4ff;border-left:5px solid #2b78e4;padding:14px 16px;border-radius:4px">';
        $html .= '<p style="margin:0 0 8px">Consulta todos los registros de facturación generados, filtra por fecha/estado/tipo, y descarga el período que necesites (CSV con todos los campos, o un .zip con el XML individual enviado a la AEAT de cada registro).</p>';
        $html .= '<p style="margin:0"><a href="' . $url . '" target="_blank" rel="noopener noreferrer" class="btn btn-primary btn-sm">Abrir el registro de facturación</a></p>';
        $html .= '</div>';

        return $html;
    }
}
