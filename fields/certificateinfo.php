<?php
defined('_JEXEC') or die;

// Los campos personalizados de Joomla pueden cargarse antes que el archivo
// principal del plugin. Cargamos aquí el autoloader para poder usar la misma
// CertificateManagerService que utiliza Veri*Factu.
$vendorAutoload = dirname(__DIR__) . '/vendor/autoload.php';
if (is_readable($vendorAutoload)) {
    require_once $vendorAutoload;
}

class JFormFieldCertificateinfo extends \Joomla\CMS\Form\FormField
{
    protected $type = 'Certificateinfo';

    protected function getInput()
    {
        // En la configuración de un plugin Joomla, los valores están dentro
        // del grupo `params`. Usar un grupo vacío devuelve NULL y provocaba
        // que siempre se mostrara "No disponible".
        $pathValue = $this->form->getValue('cert_path', 'params', null);
        if ($pathValue === null || $pathValue === '') {
            $pathValue = $this->form->getValue('cert_path', null, '');
        }
        $passwordValue = $this->form->getValue('cert_pass', 'params', null);
        if ($passwordValue === null) {
            $passwordValue = $this->form->getValue('cert_pass', null, '');
        }
        $path = trim((string) $pathValue);
        $password = (string) $passwordValue;

        $expiryText = 'No disponible';
        $status = 'info';
        $detail = 'Indique una ruta válida al certificado .pfx/.p12 y su contraseña para consultar automáticamente la fecha de caducidad.';

        // Admitir tanto rutas absolutas como rutas relativas a la raíz de Joomla.
        if ($path !== '' && !preg_match('#^([A-Za-z]:[\\/]|/)#', $path)) {
            $rootPath = defined('JPATH_ROOT') ? JPATH_ROOT . '/' . ltrim($path, '/\\') : '';
            if ($rootPath !== '' && is_file($rootPath)) {
                $path = $rootPath;
            }
        }

        if ($path !== '' && is_file($path)) {
            try {
                $cert = \eseperio\verifactu\services\CertificateManagerService::getCertificate($path, $password);
                $parsed = is_string($cert) ? @openssl_x509_parse($cert) : false;

                if (is_array($parsed) && !empty($parsed['validTo_time_t'])) {
                    $expiry = (new \DateTimeImmutable('@' . (int) $parsed['validTo_time_t']))
                        ->setTimezone(new \DateTimeZone(date_default_timezone_get()));
                    $expiryText = $expiry->format('d/m/Y H:i');

                    $now = new \DateTimeImmutable('now', new \DateTimeZone(date_default_timezone_get()));
                    if ($now >= $expiry) {
                        $status = 'danger';
                        $detail = 'El certificado está caducado.';
                    } else {
                        $days = (int) $now->diff($expiry)->format('%a');
                        if ($days <= 31) {
                            $status = 'danger';
                            $detail = 'El certificado caduca en menos de 1 mes.';
                        } elseif ($days <= 62) {
                            $status = 'warning';
                            $detail = 'El certificado caduca en menos de 2 meses.';
                        } elseif ($days <= 124) {
                            $status = 'warning';
                            $detail = 'El certificado caduca en menos de 4 meses.';
                        } elseif ($days <= 186) {
                            $status = 'warning';
                            $detail = 'El certificado caduca en menos de 6 meses.';
                        } else {
                            $status = 'success';
                            $detail = 'El certificado está vigente.';
                        }
                    }
                } else {
                    $status = 'danger';
                    $detail = 'No se ha podido leer la fecha de caducidad del certificado.';
                }
            } catch (\Throwable $e) {
                $status = 'danger';
                $detail = 'No se ha podido leer el certificado. Compruebe la ruta y la contraseña.';
            }
        }

        $html = '<div class="alert alert-' . htmlspecialchars($status, ENT_QUOTES, 'UTF-8') . ' verifactu-cert-info">';
        $html .= '<div><strong>Caducidad:</strong> ' . htmlspecialchars($expiryText, ENT_QUOTES, 'UTF-8') . '</div>';
        $html .= '<div><strong>Avisos previos:</strong> 6 meses · 4 meses · 2 meses · 1 mes</div>';
        $html .= '<div style="margin-top:6px;">' . htmlspecialchars($detail, ENT_QUOTES, 'UTF-8') . '</div>';
        $html .= '</div>';
        $html .= '<div class="small text-muted">Los avisos se envían automáticamente una vez al alcanzar cada uno de estos plazos, al email configurado a continuación (o al email del sitio Joomla si se deja vacío).</div>';

        return $html;
    }
}
