<?php
/**
 * HikaShop PDF Invoice override for VeriFactu QR.
 * Installed by PLG_HIKASHOP_VERIFACTU.
 * This file wraps the original HikaShop invoice layout and adds the QR
 * when the PDF Invoice plugin generates the customer/attachment PDF.
 */
defined('_JEXEC') or die('Restricted access');

$original = JPATH_PLUGINS . '/hikashop/attachinvoice/attachinvoice/invoice.php';

if (is_file($original)) {
    ob_start();
    require $original;
    $invoiceHtml = ob_get_clean();
} else {
    $invoiceHtml = '';
}

$hasQr = (strpos($invoiceHtml, 'class="verifactu-qr"') !== false);

if (!$hasQr && !empty($order->order_id)) {
    try {
        $db = JFactory::getDbo();
        $query = $db->getQuery(true)
            ->select('qr_url, invoice_number')
            ->from('#__hikashop_verifactu_registro')
            ->where('order_id = ' . (int) $order->order_id)
            ->where('estado_envio IN (' . $db->quote('aceptado') . ',' . $db->quote('aceptado_con_errores') . ')')
            ->order('id DESC')
            ->setLimit(1);
        $db->setQuery($query);
        $registro = $db->loadObject();

        if (!empty($registro) && !empty($registro->qr_url)) {
            $qr = trim($registro->qr_url);
            if (strpos($qr, 'data:image/') !== 0) {
                if (preg_match('#^[A-Za-z0-9+/=\r\n]+$#', $qr)) {
                    $qr = 'data:image/png;base64,' . preg_replace('/\s+/', '', $qr);
                }
            }
            $invoiceHtml .= '<div class="verifactu-qr" style="margin-top:15px;text-align:center;width:35mm;">'
                . '<img src="' . htmlspecialchars($qr, ENT_QUOTES, 'UTF-8') . '" style="width:35mm;height:35mm;" alt="QR VeriFactu" />'
                . '<p style="font-size:9px;margin-top:4px;max-width:35mm;">Factura verificable en la sede electrónica de la AEAT — VERIFACTU</p>'
                . '</div>';
        }
    } catch (Throwable $e) {
        // Never prevent HikaShop from generating the invoice if the QR lookup fails.
    }
}

echo $invoiceHtml;
