<?php
defined('_JEXEC') or die;

require_once __DIR__ . '/src/VerifactuLibraryBridge.php';

class plgHikashopVerifactu extends \Joomla\CMS\Plugin\CMSPlugin
{
    /**
     * Escribe en el registro de Joomla, categoría 'verifactu'.
     * El archivo queda en la carpeta de logs de Joomla (administrator/logs/
     * salvo que se haya cambiado en la configuración global).
     */
    private static function log(string $mensaje, int $nivel = \Joomla\CMS\Log\Log::INFO): void
    {
        VerifactuLog::add($mensaje, $nivel);
    }


    /**
     * Herramientas de la Declaración Responsable integradas en este mismo plugin.
     * Los datos se cargan inicialmente desde la configuración fiscal del plugin
     * y desde la dirección de tienda de HikaShop; después quedan editables en
     * la configuración del plugin.
     */
    private function getHikaShopBillingData(): array
    {
        $out = ['direccion' => ''];

        // La Declaración Responsable solo utiliza la dirección de la tienda.
        // Se carga únicamente cuando HikaShop tiene configurado store_address.
        try {
            if (!function_exists('hikashop_config')) {
                $helper = JPATH_ADMINISTRATOR . '/components/com_hikashop/helpers/helper.php';
                if (is_readable($helper)) {
                    require_once $helper;
                }
            }
            if (function_exists('hikashop_config')) {
                $config = hikashop_config();
                $value = trim((string) $config->get('store_address', ''));
                if ($value !== '') {
                    $out['direccion'] = $value;
                }
            }
        } catch (\Throwable $e) {
            self::log('No se pudo leer store_address de HikaShop: ' . $e->getMessage(), \Joomla\CMS\Log\Log::WARNING);
        }

        // Fallback directo a la tabla de configuración de HikaShop.
        if ($out['direccion'] === '') {
            try {
                $db = $this->db();
                $query = $db->getQuery(true)
                    ->select($db->quoteName('config_value'))
                    ->from($db->quoteName('#__hikashop_config'))
                    ->where($db->quoteName('config_name') . ' = ' . $db->quote('store_address'));
                $db->setQuery($query);
                $value = trim((string) $db->loadResult());
                if ($value !== '') {
                    $out['direccion'] = $value;
                }
            } catch (\Throwable $e) {
                // Algunas instalaciones/versiones pueden no exponer esta tabla.
            }
        }

        return $out;
    }

    private function getDeclarationData(?array $paramsOverride = null): array
    {
        $billing = $this->getHikaShopBillingData();

        // Estos valores siempre proceden directamente de la configuración
        // fiscal del plugin y no pueden sustituirse por valores guardados
        // anteriormente en la Declaración Responsable.
        $params = $paramsOverride !== null ? $paramsOverride : $this->params;
        $getParam = static function ($key, $default = '') use ($params) {
            if (is_array($params)) {
                return $params[$key] ?? $default;
            }
            return $params->get($key, $default);
        };

        $name = trim((string) $getParam('nombre_emisor', ''));
        $nif = trim((string) $getParam('nif_emisor', ''));

        // La dirección sí es editable: se conserva la introducida por el
        // usuario y solo se usa store_address como valor inicial cuando está vacía.
        $address = trim((string) $getParam('declaracion_direccion', ''));
        if ($address === '' && $billing['direccion'] !== '') {
            $address = $billing['direccion'];
        }

        $lugar = trim((string) $getParam('declaracion_lugar', ''));
        $fecha = \Joomla\CMS\Factory::getDate()->format('d/m/Y');
        $version = VerifactuVersion::get();

        return [
            'name' => $name,
            'nif' => $nif,
            'address' => $address,
            'cp' => '',
            'localidad' => '',
            'provincia' => '',
            'pais' => '',
            'lugar' => $lugar,
            'fecha' => $fecha,
            'version' => $version,
            'nombre' => $name,
            'direccion' => $address,
        ];
    }

    private function declarationEscape(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
    }

    private function buildDeclarationHtml(array $d): string
    {
        $e = fn($v) => $this->declarationEscape((string) $v);
        $componentes = 'Joomla, HikaShop y el plugin propio HikaShop VeriFactu. '
            . 'Joomla y HikaShop son componentes de terceros; el componente propio '
            . 'desarrollado específicamente para la integración Veri*Factu* es '
            . 'HikaShop VeriFactu.';

        return '<!doctype html><html lang="es"><head><meta charset="utf-8">'
            . '<title>Declaración Responsable - HikaShop VeriFactu</title>'
            . '<style>body{font-family:Arial,sans-serif;max-width:900px;margin:40px auto;padding:0 24px;line-height:1.55;color:#222}'
            . 'h1{font-size:22px;text-align:center;margin-bottom:35px}h2{font-size:17px;margin-top:28px}'
            . '.box{border:1px solid #ccc;padding:18px;margin:18px 0}dt{font-weight:bold;margin-top:10px}dd{margin-left:0}'
            . '.print{position:fixed;right:20px;top:20px;padding:9px 14px;cursor:pointer}</style></head><body>'
            . '<button class="print" onclick="window.print()">Imprimir</button>'
            . '<h1>DECLARACIÓN RESPONSABLE DEL SISTEMA INFORMÁTICO DE FACTURACIÓN</h1>'
            . '<div class="box"><h2>1. Identificación del sistema informático</h2>'
            . '<dl><dt>Nombre del sistema informático</dt><dd>HikaShop VeriFactu</dd>'
            . '<dt>Código identificador del sistema informático</dt><dd>' . $e(VerifactuLibraryBridge::ID_SISTEMA_INFORMATICO) . '</dd>'
            . '<dt>Versión concreta</dt><dd>' . $e($d['version']) . '</dd>'
            . '<dt>Componentes</dt><dd>' . $e($componentes) . '</dd>'
            . '<dt>Modalidad de funcionamiento</dt><dd>VERI*FACTU</dd>'
            . '<dt>Uso por varios obligados tributarios</dt><dd>' . ((string)$this->params->get('declaracion_uso_propio', '1') === '1'
                ? 'No. El sistema se utiliza exclusivamente por el obligado tributario identificado en esta declaración.'
                : 'La configuración permite su utilización por más de un obligado tributario.') . '</dd></dl></div>'
            . '<div class="box"><h2>2. Productor del sistema informático</h2>'
            . '<dl><dt>Nombre / razón social</dt><dd>' . $e($d['nombre']) . '</dd>'
            . '<dt>NIF</dt><dd>' . $e($d['nif']) . '</dd>'
            . '<dt>Dirección</dt><dd>' . nl2br($e($d['direccion'])) . '</dd></dl></div>'
            . '<div class="box"><h2>3. Declaración de cumplimiento</h2>'
            . '<p>La persona o entidad productora del sistema informático declara bajo su responsabilidad que el sistema informático, en la versión indicada en la presente declaración, cumple con lo dispuesto en:</p>'
            . '<ul>'
            . '<li>el artículo 29.2.j) de la Ley 58/2003, de 17 de diciembre, General Tributaria;</li>'
            . '<li>el Reglamento que establece los requisitos que deben adoptar los sistemas y programas informáticos o electrónicos que soporten los procesos de facturación de empresarios y profesionales, y la estandarización de formatos de los registros de facturación, aprobado por el Real Decreto 1007/2023, de 5 de diciembre;</li>'
            . '<li>la Orden HAC/1177/2024, de 17 de octubre; y</li>'
            . '<li>la sede electrónica de la Agencia Estatal de Administración Tributaria para todo aquello que complete las especificaciones de dicha orden.</li>'
            . '</ul>'
            . '<p>La presente declaración corresponde exclusivamente a la versión concreta del sistema informático indicada en este documento.</p></div>'
            . '<div class="box"><h2>4. Lugar y fecha</h2>'
            . '<p><strong>Lugar:</strong> ' . $e($d['lugar']) . '</p>'
            . '<p><strong>Fecha:</strong> ' . $e($d['fecha']) . '</p>'
            . '<p><strong>Productor:</strong> ' . $e($d['nombre']) . '</p>'
            . '<p><strong>NIF:</strong> ' . $e($d['nif']) . '</p>'
            . '<p style="margin-top:45px">Firma: ______________________________</p></div>'
            . '</body></html>';
    }

    private function buildConditionsHtml(array $d): string
    {
        $e = fn($v) => $this->declarationEscape((string) $v);
        $li = fn(array $items) => '<ul>' . implode('', array_map(fn($i) => '<li>' . $i . '</li>', $items)) . '</ul>';

        return '<!doctype html><html lang="es"><head><meta charset="utf-8">'
            . '<title>Aviso legal, condiciones de uso y exclusión de garantía y responsabilidad - HikaShop VeriFactu</title>'
            . '<style>body{font-family:Arial,sans-serif;max-width:900px;margin:40px auto;padding:0 24px;line-height:1.55;color:#222}'
            . 'h1{font-size:20px;text-align:center;margin-bottom:6px}h2{font-size:16px;margin-top:30px}'
            . '.subt{text-align:center;color:#555;margin:0 0 30px}'
            . '.print{position:fixed;right:20px;top:20px;padding:9px 14px;cursor:pointer}'
            . 'ul{margin:8px 0;padding-left:22px}li{margin:2px 0}'
            . '.foot{margin-top:40px;padding-top:18px;border-top:1px solid #ccc;text-align:center;color:#444}</style></head><body>'
            . '<button class="print" onclick="window.print()">Imprimir</button>'
            . '<h1>AVISO LEGAL, CONDICIONES DE USO Y EXCLUSIÓN DE GARANTÍA Y RESPONSABILIDAD</h1>'
            . '<p class="subt"><strong>HikaShop VeriFactu v' . $e($d['version']) . '</strong> · Licencia: GNU General Public License v3.0 (GPLv3)</p>'

            . '<h2>1. Naturaleza del software</h2>'
            . '<p>HikaShop VeriFactu es un componente informático desarrollado para integrarse con Joomla y HikaShop y proporcionar funcionalidades relacionadas con la generación de información de facturación, generación de registros de facturación y, cuando se configure para ello, comunicación electrónica de dichos registros con los servicios de la Agencia Estatal de Administración Tributaria (AEAT).</p>'
            . '<p>El software constituye exclusivamente una <strong>herramienta informática</strong>.</p>'
            . '<p>El software, su autor, sus colaboradores y sus distribuidores <strong>no proporcionan asesoramiento fiscal, contable, jurídico ni tributario</strong>.</p>'
            . '<p>La utilización del software no sustituye el asesoramiento de un asesor fiscal, contable o jurídico cualificado.</p>'

            . '<h2>2. Responsabilidad del usuario</h2>'
            . '<p>El usuario del software es el único responsable de:</p>'
            . $li([
                'La configuración de Joomla, HikaShop y del propio plugin.',
                'Los datos fiscales introducidos en el sistema.',
                'Los datos identificativos de la empresa y de sus clientes.',
                'Los NIF, NIF-IVA, CIF y demás identificadores fiscales utilizados.',
                'Las fechas de las facturas.',
                'Los importes, bases imponibles y cuotas de IVA.',
                'Los tipos de factura utilizados, incluyendo, entre otros, F1, F2, F3 y facturas rectificativas.',
                'Las causas y tipos de rectificación utilizados, incluidas las rectificaciones por diferencias.',
                'La procedencia de emitir una factura simplificada (F2 o R5) y el cumplimiento de sus límites de importe y de las demás condiciones que exige la normativa.',
                'El valor que se configure en el parámetro "Importe máximo de factura simplificada" y la determinación de si un pedido sin NIF corresponde a una venta a consumidor final.',
                'Las claves de régimen y calificaciones fiscales.',
                'La determinación de operaciones sujetas, exentas o no sujetas.',
                'La información incluida en los registros de facturación.',
                'La información enviada a la AEAT.',
                'La conservación de las facturas y registros exigidos por la normativa.',
                'La comprobación de que las facturas y registros generados son correctos antes de utilizarlos.',
                'El cumplimiento de todas las obligaciones fiscales, contables y de facturación que le sean aplicables.',
            ])
            . '<p>El usuario debe comprobar que la configuración y el funcionamiento del software son adecuados para su situación fiscal concreta.</p>'

            . '<h2>3. No constituye garantía de corrección fiscal</h2>'
            . '<p>El hecho de que el software genere una factura, un registro de facturación, un código QR, una comunicación electrónica o cualquier otro dato <strong>no implica que dicha información sea fiscalmente correcta</strong>.</p>'
            . '<p>El autor no garantiza que el software determine correctamente, para cada caso particular:</p>'
            . $li([
                'El tipo de factura aplicable.',
                'La causa de rectificación.',
                'La clave de régimen.',
                'La calificación de IVA.',
                'La causa de exención.',
                'La condición de operación sujeta o no sujeta.',
                'El tratamiento de operaciones nacionales, intracomunitarias o de exportación.',
                'El tratamiento fiscal de Canarias, Ceuta o Melilla.',
                'El tratamiento de las rectificaciones por diferencias de facturas exentas (exportaciones y entregas intracomunitarias), incluidos el régimen y la causa de exención que se repiten en la rectificativa.',
                'La procedencia de emitir una factura simplificada y los límites de importe aplicables en cada caso.',
                'El tratamiento de operaciones con clientes particulares o empresarios.',
                'La aplicación de tipos impositivos.',
                'La aplicación del recargo de equivalencia.',
                'La aplicación de reglas especiales de IVA.',
                'La obligación de utilizar una determinada modalidad de factura.',
                'La obligación de realizar declaraciones fiscales adicionales.',
            ])
            . '<p>La correcta determinación de estas circunstancias corresponde exclusivamente al usuario.</p>'

            . '<h2>4. Validaciones y bloqueos automáticos del software</h2>'
            . '<p>Para reducir el riesgo de enviar registros incorrectos, el software puede rechazar determinados envíos antes de comunicarlos a la AEAT. Entre otros, en la fecha de esta versión:</p>'
            . $li([
                'No envía una factura simplificada (F2) ni una rectificativa simplificada (R5) cuando la operación es exenta de IVA (por ejemplo, una exportación o una entrega intracomunitaria), porque la AEAT ha comunicado que las facturas simplificadas deben llevar IVA. En esos casos exige identificar al cliente para emitir una factura completa.',
                'No envía una factura simplificada (F2) cuyo importe supere el límite configurado en el parámetro "Importe máximo de factura simplificada" (3.000 € por defecto). Las rectificativas simplificadas no están sujetas a ese límite.',
                'En una rectificación por diferencias de una factura exenta, envía solo la diferencia de la base imponible, sin tipo ni cuota, y repite el régimen y la causa de exención de la factura original; rechaza la rectificación si se indica un nuevo tipo de IVA o de recargo.',
            ])
            . '<p>Estas validaciones son una ayuda técnica y <strong>no constituyen una garantía de corrección fiscal</strong>: no cubren todos los supuestos, pueden no reflejar cambios normativos posteriores y no sustituyen la revisión del usuario ni de su asesor. Los límites de importe indicados en la configuración del plugin son solo orientativos ("En 2026, los límites de la factura simplificada son 3.000 € (B2C) y 400 € (B2B), aunque pueden variar en el futuro"); el usuario debe comprobar la normativa vigente y ajustar el parámetro a su caso.</p>'
            . '<p>Que el software no bloquee un envío no significa que el envío sea correcto.</p>'

            . '<h2>5. Registros aceptados por la AEAT</h2>'
            . '<p>La aceptación de un registro por parte de la AEAT <strong>no constituye una garantía de que la operación subyacente sea fiscalmente correcta</strong>.</p>'
            . '<p>Del mismo modo, el hecho de que un registro sea técnicamente válido, aceptado o procesado por los servicios de la AEAT no significa necesariamente que:</p>'
            . $li([
                'La factura sea correcta desde el punto de vista fiscal.',
                'El tratamiento del IVA sea correcto.',
                'La operación haya sido correctamente calificada.',
                'Se hayan cumplido todas las obligaciones fiscales del contribuyente.',
                'Se hayan cumplido todas las obligaciones de declaración, información o conservación.',
            ])
            . '<p>El usuario debe realizar las comprobaciones fiscales que correspondan a su situación.</p>'

            . '<h2>6. Cambios normativos y técnicos</h2>'
            . '<p>La normativa tributaria, las especificaciones técnicas, los servicios electrónicos de la AEAT y los requisitos aplicables a los Sistemas Informáticos de Facturación pueden cambiar.</p>'
            . '<p>El autor no garantiza que una determinada versión del software continúe siendo adecuada después de:</p>'
            . $li([
                'Cambios legislativos.',
                'Cambios reglamentarios.',
                'Cambios en las especificaciones técnicas de la AEAT.',
                'Cambios en los servicios web de la AEAT.',
                'Cambios en los esquemas XML.',
                'Cambios en los sistemas de validación.',
                'Cambios en los certificados electrónicos.',
                'Cambios en los requisitos de seguridad.',
                'Cambios en Joomla, HikaShop, PHP u otros componentes de terceros.',
            ])
            . '<p>El usuario es responsable de mantener el software y sus dependencias en versiones compatibles y adecuadas para su entorno.</p>'

            . '<h2>7. Dependencias y software de terceros</h2>'
            . '<p>HikaShop VeriFactu puede utilizar bibliotecas, componentes, servicios, APIs, extensiones y otros programas desarrollados por terceros.</p>'
            . '<p>El autor no garantiza la disponibilidad, funcionamiento, compatibilidad, exactitud o continuidad de servicios proporcionados por terceros.</p>'
            . '<p>Las condiciones de licencia de las bibliotecas de terceros serán aplicables según corresponda.</p>'
            . '<p>El productor no será responsable de modificaciones, adaptaciones, extensiones, alteraciones o integraciones realizadas por el usuario o por terceros sobre el software original, ni de las consecuencias derivadas de dichas modificaciones.</p>'

            . '<h2>8. Errores, interrupciones y pérdida de información</h2>'
            . '<p>El software se proporciona sin garantía de funcionamiento ininterrumpido o libre de errores.</p>'
            . '<p>El autor no garantiza que:</p>'
            . $li([
                'El software funcione sin errores.',
                'Las comunicaciones con la AEAT estén siempre disponibles.',
                'Las comunicaciones electrónicas sean siempre satisfactorias.',
                'Los registros sean siempre aceptados.',
                'Los códigos QR sean siempre generados correctamente.',
                'Las facturas PDF sean siempre generadas correctamente.',
                'Las conexiones con servicios externos funcionen permanentemente.',
                'No se produzcan errores de configuración.',
                'No se produzcan errores derivados de Joomla, HikaShop, PHP, servidores, certificados, bases de datos o extensiones de terceros.',
            ])
            . '<p>El usuario debe disponer de mecanismos adecuados de copia de seguridad y recuperación de sus datos.</p>'

            . '<h2>9. Exclusión de responsabilidad</h2>'
            . '<p>EN LA MÁXIMA MEDIDA PERMITIDA POR LA LEGISLACIÓN APLICABLE, EL AUTOR, DESARROLLADOR, COLABORADORES Y DISTRIBUIDORES DEL SOFTWARE NO SERÁN RESPONSABLES DE DAÑOS, PÉRDIDAS, COSTES, SANCIONES, RECARGOS, INTERESES, PERJUICIOS, RECLAMACIONES O CONSECUENCIAS DE CUALQUIER NATURALEZA DERIVADAS DEL USO O IMPOSIBILIDAD DE USO DEL SOFTWARE.</p>'
            . '<p>Esta exclusión incluye, entre otros:</p>'
            . $li([
                'Errores en facturas.', 'Errores en registros de facturación.', 'Errores en tipos de factura.',
                'Errores en tipos de rectificación.', 'Errores en claves de régimen.', 'Errores en la calificación de operaciones.',
                'Errores o rechazos derivados de facturas simplificadas emitidas fuera de sus límites o para operaciones exentas.', 'Errores en rectificaciones por diferencias.',
                'Errores en bases imponibles.', 'Errores en cuotas de IVA.', 'Errores en recargos de equivalencia.',
                'Errores en NIF o NIF-IVA.', 'Errores en registros enviados a la AEAT.', 'Registros rechazados o aceptados con errores.',
                'Registros duplicados.', 'Registros no enviados.', 'Registros enviados incorrectamente.',
                'Facturas anuladas o rectificativas incorrectamente configuradas.', 'Errores en códigos QR.',
                'Errores de comunicación con la AEAT.', 'Indisponibilidad de los servicios de la AEAT.',
                'Errores derivados de modificaciones normativas.', 'Errores derivados de configuraciones realizadas por el usuario.',
                'Errores derivados de Joomla, HikaShop, PHP, el servidor o software de terceros.', 'Pérdida de datos.',
                'Pérdida de facturación.', 'Pérdida de negocio.', 'Reclamaciones de clientes.', 'Sanciones administrativas.',
                'Recargos.', 'Intereses.', 'Costes de regularización.',
                'Gastos profesionales, fiscales, contables o jurídicos derivados de cualquier incidencia.',
            ])
            . '<p>Las sanciones, multas, recargos, intereses, costes de regularización, gastos profesionales, fiscales, contables o jurídicos que pudieran derivarse de las obligaciones tributarias del usuario, de la configuración de su instalación, de los datos introducidos, de las facturas emitidas, de los registros generados o remitidos, de las decisiones fiscales adoptadas por el usuario o de la utilización del software serán responsabilidad del obligado tributario correspondiente, en la máxima medida permitida por la legislación aplicable.</p>'

            . '<h2>10. Responsabilidad por las obligaciones fiscales</h2>'
            . '<p>El usuario reconoce que la obligación legal de emitir facturas correctamente, mantener los registros de facturación y cumplir las obligaciones tributarias corresponde al obligado tributario y no al software utilizado para realizar dichas tareas.</p>'
            . '<p>El usuario debe verificar la legislación vigente y, cuando sea necesario, consultar a un profesional fiscal o tributario.</p>'

            . '<h2>11. Verificación antes de producción</h2>'
            . '<p>Antes de utilizar el software en un entorno de producción, el usuario debe realizar las pruebas necesarias para verificar su funcionamiento en su propio entorno.</p>'
            . '<p>Se recomienda comprobar especialmente:</p>'
            . $li([
                'Numeración de facturas.', 'Fechas.', 'NIF de clientes.', 'Tipos F1, F2, F3 y demás tipos aplicables.',
                'Facturas rectificativas, incluidas las rectificaciones por diferencias.', 'Facturas simplificadas y sus límites de importe.',
                'Pedidos sin NIF a Canarias, Ceuta, Melilla o fuera de la Unión Europea.', 'Anulaciones.', 'Bases imponibles.', 'IVA.', 'Recargo de equivalencia.',
                'Operaciones intracomunitarias.', 'Exportaciones.', 'Operaciones no sujetas.', 'Operaciones exentas.',
                'Registros enviados a la AEAT.', 'Respuestas recibidas de la AEAT.', 'Códigos QR.',
                'Certificados electrónicos.', 'Copias de seguridad.',
            ])

            . '<h2>12. No existe garantía de cumplimiento normativo</h2>'
            . '<p>El software no constituye una certificación, homologación, autorización o garantía de cumplimiento de la normativa fiscal española.</p>'
            . '<p>La utilización del software no implica que el autor certifique que una determinada instalación, configuración o uso del software cumple todas las obligaciones legales aplicables al usuario.</p>'
            . '<p>Corresponde al usuario determinar si el software es adecuado para sus necesidades y obligaciones.</p>'

            . '<h2>13. Uso bajo responsabilidad del usuario</h2>'
            . '<p>Al instalar, activar, configurar o utilizar HikaShop VeriFactu, el usuario reconoce que lo hace bajo su propia responsabilidad.</p>'
            . '<p>El usuario debe realizar las comprobaciones necesarias antes de utilizar cualquier información generada por el software para emitir facturas o remitir información a la AEAT.</p>'

            . '<h2>14. Licencia del software</h2>'
            . '<p>HikaShop VeriFactu se distribuye bajo los términos de la <strong>GNU General Public License, versión 3 (GPLv3)</strong>.</p>'
            . '<p>El software se proporciona "TAL CUAL" y sin garantías, en la medida permitida por la legislación aplicable.</p>'
            . '<p>Las disposiciones de la GPLv3 relativas a la exclusión de garantía y limitación de responsabilidad forman parte de las condiciones de distribución del software.</p>'

            . '<h2>15. Legislación aplicable</h2>'
            . '<p>Nada de lo establecido en este aviso pretende excluir o limitar derechos o responsabilidades que no puedan ser excluidos o limitados legalmente conforme a la legislación aplicable.</p>'
            . '<p>Cuando una disposición de este aviso no pueda aplicarse legalmente en una determinada jurisdicción, dicha disposición se aplicará en la máxima medida permitida por la legislación aplicable, sin afectar al resto de las disposiciones.</p>'

            . '<h2>16. Aceptación</h2>'
            . '<p>El usuario reconoce haber leído y comprendido este aviso antes de utilizar el software en un entorno de producción.</p>'
            . '<p>La instalación o utilización del software implica la aceptación de estas condiciones, en la medida permitida por la legislación aplicable.</p>'

            . '<div class="foot"><p><strong>HikaShop VeriFactu v' . $e($d['version']) . '</strong></p>'
            . '<p><strong>Copyright © 2026 Custom</strong></p>'
            . '<p>Licencia de software: <strong>GNU General Public License v3.0 (GPLv3)</strong></p>'
            . '<p>Este software no está afiliado, patrocinado, respaldado ni certificado por la Agencia Estatal de Administración Tributaria (AEAT).</p>'
            . '<p><strong>Fecha de generación de este documento:</strong> ' . $e($d['fecha']) . '</p></div>'
            . '</body></html>';
    }

    private function ensureDeclarationSchema(): void
    {
        $table = '#__hikashop_verifactu_declaracion';
        $sql = "CREATE TABLE IF NOT EXISTS `" . $this->dbTableName($table) . "` (
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
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";
        try {
            $this->db()->setQuery($sql)->execute();
        } catch (\Throwable $e) {
            self::log('No se pudo crear/verificar la tabla de Declaración Responsable: ' . $e->getMessage(), \Joomla\CMS\Log\Log::ERROR);
        }
    }

    private function dbTableName(string $table): string
    {
        return str_replace('#__', \Joomla\CMS\Factory::getDbo()->getPrefix(), $table);
    }

    /**
     * Sin tipo de retorno estricto a propósito: distintas versiones de Joomla
     * devuelven clases diferentes para el objeto de base de datos (ver la
     * misma nota en VerifactuLibraryBridge.php).
     */
    private function db()
    {
        return \Joomla\CMS\Factory::getDbo();
    }

    private function saveDeclarationSnapshot(array $d, string $declaration, string $conditions): void
    {
        $this->ensureDeclarationSchema();
        $db = $this->db();
        $dateSql = null;
        if (preg_match('/^(\d{2})\/(\d{2})\/(\d{4})$/', $d['fecha'], $m)) {
            $dateSql = $m[3] . '-' . $m[2] . '-' . $m[1];
        }
        $existing = $db->getQuery(true)
            ->select('id')
            ->from('#__hikashop_verifactu_declaracion')
            ->where('version_sistema = ' . $db->quote($d['version']))
            ->order('id DESC')->setLimit(1);
        $db->setQuery($existing);
        $id = (int) $db->loadResult();

        $data = [
            'version_sistema' => $d['version'],
            'nombre' => $d['nombre'],
            'nif' => $d['nif'],
            'direccion' => $d['direccion'],
            'codigo_postal' => null,
            'localidad' => null,
            'provincia' => null,
            'pais' => null,
            'lugar' => $d['lugar'],
            'fecha_firma' => $dateSql,
            'declaracion_html' => $declaration,
            'condiciones_html' => $conditions,
            'created' => date('Y-m-d H:i:s'),
        ];

        if ($id) {
            $q = $db->getQuery(true)->update('#__hikashop_verifactu_declaracion')
                ->where('id=' . $id);
            foreach ($data as $k => $val) {
                $q->set($k . ' = ' . ($val === null ? 'NULL' : $db->quote($val)));
            }
        } else {
            $q = $db->getQuery(true)->insert('#__hikashop_verifactu_declaracion')
                ->columns(array_keys($data))
                ->values(implode(',', array_map(fn($v) => $v === null ? 'NULL' : $db->quote($v), array_values($data))));
        }
        try {
            $db->setQuery($q)->execute();
        } catch (\Throwable $e) {
            self::log('Error al guardar la instantánea de la Declaración Responsable: ' . $e->getMessage(), \Joomla\CMS\Log\Log::ERROR);
            throw $e;
        }
    }

    /**
     * Guarda automáticamente una instantánea de la Declaración Responsable
     * cada vez que se guarda la configuración de este plugin.
     *
     * Esto no depende de pulsar el botón "Ver declaración responsable".
     * Los campos automáticos se calculan en el servidor a partir de los datos
     * recién guardados, por lo que también funciona en la primera configuración.
     */
    public function onExtensionAfterSave($context, $table, $isNew): void
    {
        if ((string) $context !== 'com_plugins.plugin' || !is_object($table)) {
            return;
        }

        if ((string) ($table->element ?? '') !== 'verifactu' || (string) ($table->folder ?? '') !== 'hikashop') {
            return;
        }

        try {
            $params = [];
            if (is_string($table->params ?? null)) {
                $decoded = json_decode((string) $table->params, true);
                if (is_array($decoded)) {
                    $params = $decoded;
                }
            } elseif (is_array($table->params ?? null)) {
                $params = $table->params;
            }

            $d = $this->getDeclarationData($params);
            $declaration = $this->buildDeclarationHtml($d);
            $conditions = $this->buildConditionsHtml($d);
            $this->saveDeclarationSnapshot($d, $declaration, $conditions);
            self::log('Declaración Responsable guardada automáticamente al guardar la configuración del plugin.', \Joomla\CMS\Log\Log::INFO);
        } catch (\Throwable $e) {
            self::log('Error al guardar automáticamente la Declaración Responsable: ' . $e->getMessage(), \Joomla\CMS\Log\Log::ERROR);
        }
    }

    /**
     * Endpoint usado por los botones de la sección Declaración Responsable
     * dentro de la configuración del propio plugin.
     */
    public function onAjaxVerifactu()
    {
        $user = \Joomla\CMS\Factory::getUser();
        if ($user->guest || (!$user->authorise('core.manage') && !$user->authorise('core.admin'))) {
            throw new \RuntimeException('Acceso no autorizado', 403);
        }

        $task = \Joomla\CMS\Factory::getApplication()->input->getCmd('task', 'declaracion');

        // Tabla de ayuda "Acciones Veri*Factu", abierta desde el botón del
        // listado de pedidos (HikaShop → Pedidos → Compras). No depende de
        // ningún pedido concreto: es contenido de referencia estático.
        if ($task === 'ayuda_acciones') {
            return $this->buildAyudaAccionesHtml();
        }

        // --- Registro de facturación VeriFactu: consulta y exportación ---
        // (RD 1007/2023 / Orden HAC/1177/2024: conservación, accesibilidad,
        // legibilidad y posibilidad de volcado/exportación de los registros
        // de facturación generados en un período).
        if ($task === 'registro') {
            return $this->buildRegistroHtml();
        }

        if ($task === 'registro_datos') {
            return $this->registroDatosJson();
        }

        if ($task === 'registro_exportar_csv') {
            $this->registroExportarCsv();
            \Joomla\CMS\Factory::getApplication()->close();
        }

        if ($task === 'registro_exportar_xml') {
            $this->registroExportarXmlZip();
            \Joomla\CMS\Factory::getApplication()->close();
        }

        if ($task === 'registro_descargar_xml') {
            $this->registroDescargarXmlIndividual();
            \Joomla\CMS\Factory::getApplication()->close();
        }
        // --- fin Registro de facturación VeriFactu ---

        if ($task === 'guardar_datos') {
            $input = \Joomla\CMS\Factory::getApplication()->input;
            $nombre = trim((string) $input->post->get('declaracion_nombre', '', 'string'));
            $nif = trim((string) $input->post->get('declaracion_nif', '', 'string'));
            $direccion = trim((string) $input->post->get('declaracion_direccion', '', 'raw'));
            $lugar = trim((string) $input->post->get('declaracion_lugar', '', 'string'));

            // Si la dirección fiscal está vacía, usar store_address de HikaShop.
            if ($direccion === '') {
                $billing = $this->getHikaShopBillingData();
                $direccion = trim((string) ($billing['direccion'] ?? ''));
            }

            $override = [
                'nombre_emisor' => $nombre,
                'nif_emisor' => $nif,
                'declaracion_direccion' => $direccion,
                'declaracion_lugar' => $lugar,
            ];

            $d = $this->getDeclarationData($override);
            $declaration = $this->buildDeclarationHtml($d);
            $conditions = $this->buildConditionsHtml($d);
            $saved = $this->saveDeclarationSnapshotIfChanged($d, $declaration, $conditions);

            return (object) [
                'success' => true,
                'saved' => $saved,
                'message' => $saved
                    ? 'Sus datos se han guardado y se ha creado una nueva copia de la Declaración Responsable.'
                    : 'No se ha detectado ningún cambio. No se ha creado una copia nueva.',
                'fecha' => $d['fecha'],
                'version' => $d['version'],
            ];
        }

        if ($task === 'cargar_datos') {
            $d = $this->getDeclarationData();
            return (object) [
                'success' => true,
                'nombre' => $d['nombre'],
                'nif' => $d['nif'],
                'direccion' => $d['direccion'],
                'fecha' => $d['fecha'],
                'version' => $d['version'],
            ];
        }

        // "Ver declaración responsable" debe mostrar siempre la última
        // instantánea almacenada en la base de datos. No se deben utilizar
        // aquí los valores que tenga en ese momento el formulario del plugin
        // ni generar/guardar una nueva copia simplemente por abrirla.
        if ($task === 'declaracion') {
            $this->ensureDeclarationSchema();
            $db = $this->db();
            $query = $db->getQuery(true)
                ->select('declaracion_html')
                ->from('#__hikashop_verifactu_declaracion')
                ->order('id DESC')
                ->setLimit(1);
            $db->setQuery($query);
            $latestDeclaration = (string) $db->loadResult();

            if ($latestDeclaration !== '') {
                return $latestDeclaration;
            }

            // Compatibilidad con una instalación recién instalada en la que
            // todavía no existe ninguna copia guardada.
            $d = $this->getDeclarationData();
            return $this->buildDeclarationHtml($d);
        }

        $d = $this->getDeclarationData();
        $declaration = $this->buildDeclarationHtml($d);
        $conditions = $this->buildConditionsHtml($d);
        $this->saveDeclarationSnapshot($d, $declaration, $conditions);

        if ($task === 'condiciones') {
            return $conditions;
        }

        return $declaration;
    }

    // ================================================================
    // Registro de facturación VeriFactu: consulta, filtros y exportación
    // (RD 1007/2023 art. 8 / Orden HAC/1177/2024: conservación,
    // accesibilidad, legibilidad, trazabilidad e inalterabilidad de los
    // registros, con posibilidad de volcado a fichero externo legible).
    // ================================================================

    private const REGISTRO_ESTADOS = [
        'pendiente', 'enviado', 'aceptado', 'aceptado_con_errores', 'rechazado',
        'error', 'error_tecnico', 'error_validacion', 'error_desglose_vacio',
        'error_sustitucion_sin_nif', 'error_aviso_legal_no_aceptado',
    ];

    /**
     * Lee y normaliza los filtros de la petición (GET, para que los enlaces
     * de exportación sean simples URLs). Compartido entre la consulta
     * paginada y las dos exportaciones, para que "exportar" descargue
     * exactamente lo que se está viendo en pantalla.
     */
    private function registroFiltrosDesdeInput(): array
    {
        $input = \Joomla\CMS\Factory::getApplication()->input;

        $fechaDesde = trim((string) $input->getString('fecha_desde', ''));
        $fechaHasta = trim((string) $input->getString('fecha_hasta', ''));
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $fechaDesde)) {
            $fechaDesde = '';
        }
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $fechaHasta)) {
            $fechaHasta = '';
        }

        $estado = trim((string) $input->getCmd('estado', ''));
        if ($estado !== '' && !in_array($estado, self::REGISTRO_ESTADOS, true)) {
            $estado = '';
        }

        $tipoRegistro = trim((string) $input->getCmd('tipo_registro', ''));
        if (!in_array($tipoRegistro, ['alta', 'anulacion'], true)) {
            $tipoRegistro = '';
        }

        $q = trim((string) $input->getString('q', ''));

        $page = max(1, (int) $input->getInt('page', 1));
        $perPage = (int) $input->getInt('per_page', 50);
        $perPage = max(10, min(500, $perPage));

        return [
            'fecha_desde' => $fechaDesde,
            'fecha_hasta' => $fechaHasta,
            'estado' => $estado,
            'tipo_registro' => $tipoRegistro,
            'q' => $q,
            'page' => $page,
            'per_page' => $perPage,
        ];
    }

    /**
     * Aplica los filtros normalizados a una consulta ya iniciada con
     * ->from('#__hikashop_verifactu_registro'). No añade SELECT ni LIMIT --
     * eso lo decide cada llamador (listado paginado vs. exportación
     * completa del período).
     */
    private function registroAplicarFiltros($query, $db, array $f): void
    {
        if ($f['fecha_desde'] !== '') {
            $query->where('DATE(invoice_date) >= ' . $db->quote($f['fecha_desde']));
        }
        if ($f['fecha_hasta'] !== '') {
            $query->where('DATE(invoice_date) <= ' . $db->quote($f['fecha_hasta']));
        }
        if ($f['estado'] !== '') {
            $query->where('estado_envio = ' . $db->quote($f['estado']));
        }
        if ($f['tipo_registro'] !== '') {
            $query->where('tipo_registro = ' . $db->quote($f['tipo_registro']));
        }
        if ($f['q'] !== '') {
            $like = '%' . str_replace(['%', '_'], ['\\%', '\\_'], $f['q']) . '%';
            $query->where('(invoice_number LIKE ' . $db->quote($like) . ' OR order_id = ' . (int) $f['q'] . ')');
        }
    }

    /**
     * task=registro_datos -- listado paginado en JSON, usado por la propia
     * pantalla de consulta (task=registro) vía fetch().
     */
    private function registroDatosJson(): object
    {
        $db = $this->db();
        $f = $this->registroFiltrosDesdeInput();

        $countQuery = $db->getQuery(true)->select('COUNT(*)')->from('#__hikashop_verifactu_registro');
        $this->registroAplicarFiltros($countQuery, $db, $f);
        $db->setQuery($countQuery);
        $total = (int) $db->loadResult();

        $query = $db->getQuery(true)
            ->select(['id', 'order_id', 'invoice_number', 'invoice_date', 'tipo_registro', 'tipo_factura', 'tipo_rectificativa', 'total_factura', 'estado_envio', 'hash_actual', 'intentos', 'created', 'modified',
                '(CASE WHEN xml_enviado IS NOT NULL AND xml_enviado != \'\' THEN 1 ELSE 0 END) AS tiene_xml'])
            ->from('#__hikashop_verifactu_registro');
        $this->registroAplicarFiltros($query, $db, $f);
        $query->order('invoice_date DESC')->order('id DESC');
        $query->setLimit($f['per_page'], ($f['page'] - 1) * $f['per_page']);
        $db->setQuery($query);
        $rows = $db->loadObjectList() ?: [];

        $this->registroCompletarAnulaciones($rows);

        return (object) [
            'success' => true,
            'total' => $total,
            'page' => $f['page'],
            'per_page' => $f['per_page'],
            'rows' => $rows,
        ];
    }

    /**
     * Una anulación (tipo_registro = 'anulacion') no tiene tipo de factura ni
     * importe propios: se guarda con tipo_factura NULL y total_factura 0. Para
     * que el listado no los muestre vacíos, se completan con los de la factura
     * anulada, que es la fila 'alta' del mismo pedido y número de factura.
     * Solo afecta a lo que ve el listado: la tabla y la exportación CSV no
     * cambian (así una suma de total_factura no cuenta dos veces la misma
     * factura).
     *
     * @param object[] $rows filas de la página actual; se modifican en el sitio
     */
    private function registroCompletarAnulaciones(array $rows): void
    {
        $db = $this->db();
        $condiciones = [];

        foreach ($rows as $row) {
            if ($row->tipo_registro === 'anulacion') {
                $condiciones[] = '(order_id = ' . (int) $row->order_id . ' AND invoice_number = ' . $db->quote((string) $row->invoice_number) . ')';
            }
        }

        if (empty($condiciones)) {
            return;
        }

        $query = $db->getQuery(true)
            ->select(['order_id', 'invoice_number', 'tipo_factura', 'tipo_rectificativa', 'total_factura'])
            ->from('#__hikashop_verifactu_registro')
            ->where('tipo_registro = ' . $db->quote('alta'))
            ->where('(' . implode(' OR ', $condiciones) . ')')
            ->order('id ASC');
        $db->setQuery($query);

        $originales = [];
        foreach ($db->loadObjectList() ?: [] as $orig) {
            $originales[(int) $orig->order_id . '|' . $orig->invoice_number] = $orig; // si hubiera varias, queda la última
        }

        foreach ($rows as $row) {
            if ($row->tipo_registro !== 'anulacion') {
                continue;
            }

            $orig = $originales[(int) $row->order_id . '|' . $row->invoice_number] ?? null;

            $row->tipo_factura = $orig->tipo_factura ?? null;
            $row->tipo_rectificativa = $orig->tipo_rectificativa ?? null;
            // Sin la factura original localizada se deja en blanco en vez de mostrar el 0 de relleno.
            $row->total_factura = $orig->total_factura ?? null;
        }
    }

    /**
     * Columnas de la exportación CSV (y cabecera fija: así el fichero sale
     * igual aunque no haya ninguna fila, y no hace falta tener la primera
     * fila en memoria para deducirla).
     */
    private const REGISTRO_CSV_COLUMNAS = [
        'id', 'order_id', 'invoice_number', 'invoice_date', 'nif_emisor', 'total_factura', 'tipo_abono',
        'tipo_factura', 'tipo_rectificativa', 'factura_rectificada', 'tipo_registro', 'motivo_anulacion',
        'registro_anterior_id', 'hash_anterior', 'hash_actual', 'qr_url', 'estado_envio', 'intentos', 'created', 'modified',
    ];

    /**
     * Filas por lote en las exportaciones. Cada fila puede llevar el QR en
     * base64 (qr_url) y/o el XML firmado (xml_enviado), así que se lee por
     * lotes en vez de cargar todo el rango en memoria (memory_limit).
     */
    private const REGISTRO_LOTE_EXPORTACION = 200;

    /**
     * Siguiente lote de filas del filtro, en el mismo orden que la
     * exportación (invoice_date ASC, id ASC). Paginación por clave
     * (invoice_date, id) del último registro leído, no por OFFSET: así un
     * registro nuevo insertado mientras se exporta no desplaza el resto ni
     * duplica/omite filas. $columnas debe incluir 'id' e 'invoice_date'.
     *
     * @param  string[]     $columnas
     * @param  object|null  $ultimo   última fila del lote anterior (null = primer lote)
     * @return object[]
     */
    private function registroSiguienteLote(array $columnas, array $f, ?object $ultimo, bool $soloConXml = false): array
    {
        $db = $this->db();

        $query = $db->getQuery(true)
            ->select($columnas)
            ->from('#__hikashop_verifactu_registro');

        if ($soloConXml) {
            $query->where('xml_enviado IS NOT NULL')->where("xml_enviado != ''");
        }

        $this->registroAplicarFiltros($query, $db, $f);

        if ($ultimo !== null) {
            $fecha = $db->quote((string) $ultimo->invoice_date);
            $query->where('(invoice_date > ' . $fecha . ' OR (invoice_date = ' . $fecha . ' AND id > ' . (int) $ultimo->id . '))');
        }

        $query->order('invoice_date ASC')->order('id ASC');
        $query->setLimit(self::REGISTRO_LOTE_EXPORTACION);
        $db->setQuery($query);

        return $db->loadObjectList() ?: [];
    }

    /**
     * Carpeta temporal para el .zip: la tmp_path de Joomla (dentro del
     * open_basedir del sitio); sys_get_temp_dir() solo como último recurso.
     * Devuelve null si ninguna es escribible.
     */
    private function registroCarpetaTemporal(): ?string
    {
        $candidatas = [
            (string) \Joomla\CMS\Factory::getApplication()->get('tmp_path', ''),
            sys_get_temp_dir(),
        ];

        foreach ($candidatas as $dir) {
            if ($dir !== '' && @is_dir($dir) && @is_writable($dir)) {
                return rtrim($dir, '/\\');
            }
        }

        return null;
    }

    /**
     * task=registro_exportar_csv -- TODAS las filas que cumplen el filtro
     * (sin paginar), en CSV con codificación de texto (no el XML completo,
     * que va aparte en el ZIP de task=registro_exportar_xml para no romper
     * la estructura tabular). BOM UTF-8 para que Excel en Windows detecte
     * bien la codificación de los acentos.
     *
     * Se genera en streaming, por lotes: la memoria usada no depende del
     * tamaño del rango exportado.
     */
    private function registroExportarCsv(): void
    {
        $f = $this->registroFiltrosDesdeInput();

        $nombreFichero = 'verifactu_registros_' . ($f['fecha_desde'] ?: 'inicio') . '_a_' . ($f['fecha_hasta'] ?: 'hoy') . '.csv';

        // Sin buffers de salida intermedios que acumulen el CSV entero.
        while (ob_get_level() > 0) {
            @ob_end_clean();
        }

        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $nombreFichero . '"');
        header('Cache-Control: no-store, no-cache, must-revalidate');
        header('Pragma: no-cache');

        echo "\xEF\xBB\xBF"; // BOM UTF-8

        $out = fopen('php://output', 'w');
        fputcsv($out, self::REGISTRO_CSV_COLUMNAS, ';');

        $ultimo = null;
        do {
            $rows = $this->registroSiguienteLote(self::REGISTRO_CSV_COLUMNAS, $f, $ultimo);

            foreach ($rows as $row) {
                $linea = [];
                foreach (self::REGISTRO_CSV_COLUMNAS as $col) {
                    $linea[] = $row->$col;
                }
                fputcsv($out, $linea, ';');
            }

            if (!empty($rows)) {
                $ultimo = end($rows);
            }

            $completo = count($rows) < self::REGISTRO_LOTE_EXPORTACION;
            unset($rows);
            flush();
        } while (!$completo);

        fclose($out);
    }

    /**
     * task=registro_exportar_xml -- descarga un .zip con un fichero .xml
     * independiente por cada registro del período filtrado que SÍ tenga
     * xml_enviado guardado (ver VerifactuLibraryBridge::guardarRegistro()).
     * Se entrega uno por registro, en vez de intentar fundir varios sobres
     * SOAP en un solo XML, porque cada xml_enviado es ya un documento XML
     * completo y válido por sí mismo -- concatenarlos no produciría un XML
     * bien formado.
     *
     * ZipArchive::addFromString() retiene el contenido en memoria hasta que
     * se destruye el objeto, así que el ZIP se construye por lotes: cada
     * lote abre el fichero temporal con un ZipArchive nuevo, añade sus
     * entradas y lo cierra/destruye antes de leer el siguiente.
     */
    private function registroExportarXmlZip(): void
    {
        if (!class_exists('ZipArchive')) {
            header('Content-Type: text/plain; charset=utf-8');
            echo 'La extensión PHP "zip" no está disponible en este servidor, así que no se puede generar la exportación XML en .zip. Pide a tu proveedor de hosting que la habilite (php-zip), o usa la exportación CSV mientras tanto.';

            return;
        }

        $f = $this->registroFiltrosDesdeInput();

        $dirTmp = $this->registroCarpetaTemporal();
        $tmpPath = $dirTmp !== null ? tempnam($dirTmp, 'verifactu_xml_') : false;

        if ($tmpPath === false) {
            $this->registroExportarError('No se pudo crear un fichero temporal para el .zip: la carpeta temporal de Joomla (tmp_path) no existe o no es escribible. Revisa la ruta y los permisos en Sistema > Configuración global > Servidor.');

            return;
        }

        try {
            $nombresUsados = [];
            $hayFilas = false;
            $primerLote = true;
            $ultimo = null;

            do {
                $rows = $this->registroSiguienteLote(['id', 'invoice_date', 'invoice_number', 'tipo_registro', 'xml_enviado'], $f, $ultimo, true);
                $completo = count($rows) < self::REGISTRO_LOTE_EXPORTACION;

                // Objeto ZipArchive NUEVO en cada lote (y destruido al final):
                // PHP conserva los strings de addFromString() mientras el
                // objeto siga vivo, incluso después de close(), así que
                // reutilizarlo acumularía todo el rango en memoria.
                $zip = new \ZipArchive();
                // tempnam() deja un fichero vacío, que libzip no acepta como
                // zip válido; en el primer lote OVERWRITE lo descarta y
                // empieza uno nuevo. Los siguientes lotes lo amplían.
                $abierto = $zip->open($tmpPath, $primerLote ? (\ZipArchive::CREATE | \ZipArchive::OVERWRITE) : \ZipArchive::CREATE);

                if ($abierto !== true) {
                    $this->registroExportarError('No se pudo abrir el fichero .zip temporal (código de ZipArchive: ' . (int) $abierto . ').');

                    return;
                }

                foreach ($rows as $row) {
                    $hayFilas = true;
                    $base = preg_replace('/[^A-Za-z0-9._-]/', '_', $row->tipo_registro . '_' . $row->invoice_number);
                    $nombre = $base . '.xml';
                    $i = 2;
                    while (isset($nombresUsados[$nombre])) {
                        $nombre = $base . '_' . $i . '.xml';
                        $i++;
                    }
                    $nombresUsados[$nombre] = true;

                    if (!$zip->addFromString($nombre, (string) $row->xml_enviado)) {
                        $zip->close();
                        $this->registroExportarError('No se pudo añadir "' . $nombre . '" al .zip.');

                        return;
                    }
                }

                if ($completo && !$hayFilas) {
                    $zip->addFromString('AVISO.txt', 'No hay ningún registro con XML conservado para el período/filtro seleccionado.');
                }

                if (!empty($rows)) {
                    $ultimo = end($rows);
                }
                unset($rows);

                // close() es lo que escribe a disco.
                if (!$zip->close()) {
                    $this->registroExportarError('No se pudo escribir el fichero .zip en la carpeta temporal (¿disco lleno o sin permisos?).');

                    return;
                }
                unset($zip);

                $primerLote = false;
            } while (!$completo);

            $nombreFichero = 'verifactu_xml_' . ($f['fecha_desde'] ?: 'inicio') . '_a_' . ($f['fecha_hasta'] ?: 'hoy') . '.zip';

            while (ob_get_level() > 0) {
                @ob_end_clean();
            }

            header('Content-Type: application/zip');
            header('Content-Disposition: attachment; filename="' . $nombreFichero . '"');
            header('Content-Length: ' . filesize($tmpPath));
            header('Cache-Control: no-store, no-cache, must-revalidate');
            header('Pragma: no-cache');

            readfile($tmpPath);
        } finally {
            // Se borra siempre: al terminar bien, ante un error y ante una
            // excepción (p. ej. fallo de base de datos a mitad de la exportación).
            if (is_file($tmpPath)) {
                @unlink($tmpPath);
            }
        }
    }

    /**
     * Error de exportación como texto plano con HTTP 500, para que el
     * navegador no descargue como "fichero" un mensaje de error a medias.
     */
    private function registroExportarError(string $mensaje): void
    {
        self::log('Exportación del registro: ' . $mensaje);

        if (!headers_sent()) {
            http_response_code(500);
            header('Content-Type: text/plain; charset=utf-8');
        }

        echo $mensaje;
    }

    /**
     * task=registro_descargar_xml&id=NN -- el XML individual de un único
     * registro, para el enlace "Descargar XML" de cada fila en la pantalla
     * de consulta.
     */
    private function registroDescargarXmlIndividual(): void
    {
        $id = (int) \Joomla\CMS\Factory::getApplication()->input->getInt('id', 0);
        $db = $this->db();

        $query = $db->getQuery(true)
            ->select(['invoice_number', 'tipo_registro', 'xml_enviado'])
            ->from('#__hikashop_verifactu_registro')
            ->where('id = ' . $id);
        $db->setQuery($query);
        $row = $db->loadObject();

        if (!$row || (string) $row->xml_enviado === '') {
            header('Content-Type: text/plain; charset=utf-8');
            http_response_code(404);
            echo 'No hay XML conservado para ese registro.';

            return;
        }

        $nombre = preg_replace('/[^A-Za-z0-9._-]/', '_', $row->tipo_registro . '_' . $row->invoice_number) . '.xml';

        header('Content-Type: application/xml; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $nombre . '"');
        echo $row->xml_enviado;
    }

    /**
     * task=registro -- pantalla de consulta completa de los registros de
     * facturación: filtros (fecha, estado, tipo, búsqueda por pedido/número
     * de factura), listado paginado, y los dos botones de exportación del
     * período filtrado (CSV y XML en .zip).
     */
    /**
     * Contenido de la tabla de ayuda "Acciones Veri*Factu": qué hacer en
     * cada caso (abono/rectificación, anulación, rectificación por
     * diferencias...) y qué resultado VeriFactu genera. Es contenido de
     * referencia fijo, igual para cualquier tienda, por lo que se sirve como
     * una página HTML independiente (sin datos de base de datos) que se
     * abre en la ventana emergente del botón del listado de pedidos.
     */
    private function buildAyudaAccionesHtml(): string
    {
        $version = htmlspecialchars(VerifactuVersion::get(), ENT_QUOTES, 'UTF-8');

        $filas = [
            ['1', 'Tipo de abono', 'Seleccionar el motivo correspondiente y utilizar un pedido de abono con importe negativo.', 'Crear pedido nuevo de abono/rectificación', 'R1/R2/R3/R4/R5 según corresponda'],
            ['1.1', 'Factura', 'Pedido/factura normal. No es realmente un motivo de abono. No seleccionar motivo. Si aparece, puede dejarse en <em>Factura</em>.', '', 'F1 o F2'],
            ['1.2', 'Devolución completa', 'Se devuelve la totalidad. El pedido de abono debe tener importe negativo.', 'Crear pedido nuevo', 'R1 por diferencias (I) si el destinatario tiene NIF'],
            ['1.3', 'Devolución parcial', 'Se devuelve solamente una parte. El pedido de abono debe representar la parte devuelta.', 'Crear pedido nuevo', 'R1 por diferencias (I)'],
            ['1.4', 'Mercancía obsoleta', 'Rectificar el importe debido a mercancía que posteriormente queda obsoleta.', 'Crear pedido nuevo', 'R1 por diferencias (I)'],
            ['1.5', 'Descuento posterior', 'Aplicar un descuento posterior que modifica el importe de la factura original.', 'Crear pedido nuevo', 'R1 por diferencias (I)'],
            ['1.6', 'Error en IVA', 'Rectificar una factura cuyo tratamiento o tipo de IVA era incorrecto.', 'Crear pedido nuevo', 'R4 por diferencias (I)'],
            ['1.7', 'Concurso de acreedores', 'Modificar la base imponible como consecuencia del procedimiento concursal.', 'Crear pedido nuevo', 'R2 por diferencias (I)'],
            ['1.8', 'Crédito incobrable', 'Modificar la base imponible por crédito incobrable.', 'Crear pedido nuevo', 'R3 por diferencias (I)'],
            ['1.9', 'Otro', 'Utilizar para otra causa de rectificación no incluida anteriormente.', 'Crear pedido nuevo', 'R4 por diferencias (I)'],
            ['2', 'Comentario del abono', 'Introducir una explicación o comentario sobre el abono/rectificación.', '', 'Información complementaria'],
            ['3', 'Sustituye a simplificada (F3)', 'Primero modificar los datos del cliente y guardar. Después seleccionar <em>Sí</em> cuando se sustituye una factura simplificada F2 previamente aceptada.', 'Modificar pedido existente', 'F3'],
            ['4', 'Anular factura', 'Seleccionar <em>Sí</em> cuando sea necesario anular una factura.', 'Modificar pedido existente', 'Registro de Anulación'],
            ['5', 'Motivo de la anulación', 'Introducir el motivo de la anulación.', '', 'Información del Registro de Anulación'],
            ['6', 'Rectificar por diferencias', 'Seleccionar <em>Sí</em> para realizar una rectificación por diferencias.', 'Modificar pedido existente', 'R1 por diferencias (I) / R5 si corresponde'],
            ['7', 'Nueva base imponible', 'Introducir la <strong>base imponible final correcta</strong>, no la diferencia.', '', 'El plugin calcula la diferencia'],
            ['8', 'Nuevo tipo de IVA (%)', 'Normalmente dejar vacío para conservar el tipo de IVA original.', '', 'Mantiene el IVA original'],
            ['9', 'Nuevo tipo de recargo de equivalencia (%)', 'Normalmente dejar vacío para conservar el recargo original.', '', 'Mantiene el recargo original'],
        ];

        $filasHtml = '';
        foreach ($filas as $fila) {
            $filasHtml .= '<tr><td class="num">' . htmlspecialchars($fila[0], ENT_QUOTES, 'UTF-8') . '</td>'
                . '<td class="campo">' . htmlspecialchars($fila[1], ENT_QUOTES, 'UTF-8') . '</td>'
                . '<td>' . $fila[2] . '</td>'
                . '<td>' . htmlspecialchars($fila[3], ENT_QUOTES, 'UTF-8') . '</td>'
                . '<td class="resultado">' . htmlspecialchars($fila[4], ENT_QUOTES, 'UTF-8') . '</td></tr>';
        }

        return <<<HTML
<!doctype html><html lang="es"><head><meta charset="utf-8">
<title>Acciones Veri*Factu</title>
<style>
body{font-family:Arial,sans-serif;margin:20px;color:#222;font-size:13.5px}
h1{font-size:18px;margin:0 0 4px}
.subt{color:#666;margin:0 0 16px}
table{width:100%;border-collapse:collapse;font-size:12.5px}
th,td{border-bottom:1px solid #e2e2e2;padding:7px 9px;text-align:left;vertical-align:top}
th{background:#fafafa;position:sticky;top:0}
td.num{color:#888;white-space:nowrap}
td.campo{font-weight:600;white-space:nowrap}
td.resultado{white-space:nowrap;font-weight:600;color:#1a5da0}
tr:hover td{background:#f9f9f9}
</style></head><body>
<h1>Acciones Veri*Factu</h1>
<p class="subt">v{$version} &middot; Qué hacer en cada caso y qué resultado VeriFactu (F1/F2/F3/R.../Registro de Anulación) genera. Los campos "1.x" son las opciones del desplegable "Tipo de abono".</p>
<div style="overflow-x:auto">
<table>
  <thead><tr><th>Nº</th><th>Campo</th><th>Qué hacer</th><th>Crear / modificar</th><th>Resultado VeriFactu</th></tr></thead>
  <tbody>{$filasHtml}</tbody>
</table>
</div>
</body></html>
HTML;
    }

    private function buildRegistroHtml(): string
    {
        $version = htmlspecialchars(VerifactuVersion::get(), ENT_QUOTES, 'UTF-8');
        $ajaxBase = 'index.php?option=com_ajax&group=hikashop&plugin=verifactu&format=';

        $opcionesEstado = '<option value="">Todos</option>';
        foreach (self::REGISTRO_ESTADOS as $estado) {
            $opcionesEstado .= '<option value="' . htmlspecialchars($estado, ENT_QUOTES, 'UTF-8') . '">' . htmlspecialchars($estado, ENT_QUOTES, 'UTF-8') . '</option>';
        }

        return <<<HTML
<!doctype html><html lang="es"><head><meta charset="utf-8">
<title>Registro de facturación VeriFactu v{$version}</title>
<style>
body{font-family:Arial,sans-serif;margin:24px;color:#222;font-size:14px}
h1{font-size:19px;margin:0 0 4px}
.subt{color:#666;margin:0 0 20px}
form.filtros{display:flex;flex-wrap:wrap;gap:10px;align-items:end;background:#f5f5f5;padding:14px;border-radius:6px;margin-bottom:16px}
form.filtros label{display:block;font-size:12px;color:#444;margin-bottom:3px}
form.filtros input,form.filtros select{padding:6px 8px;border:1px solid #ccc;border-radius:4px;font-size:13px}
.acciones{display:flex;gap:8px;margin-left:auto}
button,.btn{cursor:pointer;padding:7px 14px;border-radius:4px;border:1px solid #999;background:#fff;font-size:13px;text-decoration:none;color:#222;display:inline-block}
button.primario{background:#2c5;border-color:#2a5;color:#fff}
table{width:100%;border-collapse:collapse;font-size:12.5px}
th,td{border-bottom:1px solid #e2e2e2;padding:6px 8px;text-align:left;white-space:nowrap}
th{background:#fafafa;position:sticky;top:0}
tr:hover td{background:#f9f9f9}
.estado{padding:2px 7px;border-radius:10px;font-size:11px;display:inline-block}
.estado.aceptado,.estado.aceptado_con_errores{background:#e3f6e3;color:#1a7a1a}
.estado.pendiente,.estado.enviado{background:#fff6d9;color:#8a6d00}
.estado.rechazado,.estado.error,.estado.error_tecnico,.estado.error_validacion,.estado.error_desglose_vacio,.estado.error_sustitucion_sin_nif,.estado.error_aviso_legal_no_aceptado{background:#fde3e3;color:#a01414}
.paginacion{display:flex;gap:10px;align-items:center;margin-top:14px}
.muted{color:#888}
.xmllink{font-size:12px}
</style></head><body>
<h1>Registro de facturación VeriFactu</h1>
<p class="subt">v{$version} &middot; Consulta y exportación de los registros generados (RD 1007/2023 / Orden HAC/1177/2024: conservación, legibilidad y volcado de los registros de facturación)</p>

<form class="filtros" id="filtros">
  <div><label>Fecha desde</label><input type="date" name="fecha_desde"></div>
  <div><label>Fecha hasta</label><input type="date" name="fecha_hasta"></div>
  <div><label>Estado</label><select name="estado">{$opcionesEstado}</select></div>
  <div><label>Tipo</label><select name="tipo_registro">
    <option value="">Todos</option>
    <option value="alta">Alta (factura)</option>
    <option value="anulacion">Anulación</option>
  </select></div>
  <div><label>Buscar (nº pedido o nº factura)</label><input type="text" name="q" placeholder="ej. 245 o 26-0245"></div>
  <div class="acciones">
    <button type="submit" class="primario">Buscar</button>
    <a class="btn" id="lnkCsv" href="#" target="_blank">Exportar CSV del período</a>
    <a class="btn" id="lnkXml" href="#" target="_blank">Exportar XML del período (.zip)</a>
  </div>
</form>

<div id="resumen" class="muted"></div>
<div style="overflow-x:auto">
<table>
  <thead><tr>
    <th>Factura</th><th>Fecha</th><th>Pedido</th><th>Tipo</th><th>Tipo factura</th><th>Total</th><th>Estado</th><th>Intentos</th><th>Huella (hash)</th><th>Creado</th><th>XML</th>
  </tr></thead>
  <tbody id="filas"><tr><td colspan="11" class="muted">Cargando…</td></tr></tbody>
</table>
</div>
<div class="paginacion">
  <button type="button" id="btnPrev">&laquo; Anterior</button>
  <span id="paginaInfo" class="muted"></span>
  <button type="button" id="btnNext">Siguiente &raquo;</button>
</div>

<script>
(function () {
  var ajaxBase = {$this->jsonEncodeForHtml($ajaxBase)};
  var page = 1;
  var perPage = 50;

  function paramsActuales() {
    var f = document.getElementById('filtros');
    var p = new URLSearchParams(new FormData(f));
    p.set('page', page);
    p.set('per_page', perPage);
    return p;
  }

  function actualizarEnlacesExportacion() {
    var f = document.getElementById('filtros');
    var p = new URLSearchParams(new FormData(f));
    document.getElementById('lnkCsv').href = ajaxBase + 'raw&task=registro_exportar_csv&' + p.toString();
    document.getElementById('lnkXml').href = ajaxBase + 'raw&task=registro_exportar_xml&' + p.toString();
  }

  // com_ajax envuelve la respuesta del plugin como {data:[...]} -- un
  // ARRAY que contiene nuestro objeto en la posición 0, no el objeto
  // directamente. La versión anterior de este "unwrap" no contemplaba el
  // array (solo objeto u objeto plano), así que d.total/d.rows salían
  // "undefined" SIEMPRE, aunque el registro sí existiera en la base de
  // datos -- de ahí el "undefined registro(s) encontrados" y el "No hay
  // registros para este filtro" incluso con datos reales. Mismo criterio
  // que ya usa fields/declaration.php (función unwrap()).
  function unwrap(payload) {
    if (payload && Array.isArray(payload.data)) { return payload.data[0] || {}; }
    if (payload && payload.data && typeof payload.data === 'object') { return payload.data; }
    return payload || {};
  }

  function escapeHtml(s) {
    return (s === null || s === undefined ? '' : String(s)).replace(/[&<>"']/g, function (c) {
      return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c];
    });
  }

  function cargar() {
    actualizarEnlacesExportacion();
    document.getElementById('filas').innerHTML = '<tr><td colspan="11" class="muted">Cargando…</td></tr>';
    fetch(ajaxBase + 'json&task=registro_datos&' + paramsActuales().toString())
      .then(function (r) { return r.json(); })
      .then(function (resp) {
        var d = unwrap(resp);
        var filas = document.getElementById('filas');
        if (!d || !d.rows || !d.rows.length) {
          filas.innerHTML = '<tr><td colspan="11" class="muted">No hay registros para este filtro.</td></tr>';
        } else {
          filas.innerHTML = d.rows.map(function (r) {
            var xml = r.tiene_xml == 1
              ? '<a class="xmllink" href="' + ajaxBase + 'raw&task=registro_descargar_xml&id=' + r.id + '" target="_blank">Descargar</a>'
              : '<span class="muted">&mdash;</span>';
            return '<tr>'
              + '<td>' + escapeHtml(r.invoice_number) + '</td>'
              + '<td>' + escapeHtml(r.invoice_date) + '</td>'
              + '<td>' + escapeHtml(r.order_id) + '</td>'
              + '<td>' + escapeHtml(r.tipo_registro) + '</td>'
              + '<td>' + escapeHtml(r.tipo_factura) + (r.tipo_rectificativa ? ' (' + escapeHtml(r.tipo_rectificativa) + ')' : '') + '</td>'
              + '<td>' + (r.total_factura !== null ? escapeHtml(r.total_factura) + ' €' : '') + '</td>'
              + '<td><span class="estado ' + escapeHtml(r.estado_envio) + '">' + escapeHtml(r.estado_envio) + '</span></td>'
              + '<td>' + escapeHtml(r.intentos) + '</td>'
              + '<td title="' + escapeHtml(r.hash_actual) + '">' + escapeHtml((r.hash_actual || '').substring(0, 12)) + '…</td>'
              + '<td>' + escapeHtml(r.created) + '</td>'
              + '<td>' + xml + '</td>'
              + '</tr>';
          }).join('');
        }
        var total = d ? d.total : 0;
        var desde = total === 0 ? 0 : ((page - 1) * perPage) + 1;
        var hasta = Math.min(page * perPage, total);
        document.getElementById('resumen').textContent = total + ' registro(s) encontrados';
        document.getElementById('paginaInfo').textContent = 'Mostrando ' + desde + '-' + hasta + ' de ' + total;
        document.getElementById('btnPrev').disabled = page <= 1;
        document.getElementById('btnNext').disabled = (page * perPage) >= total;
      })
      .catch(function (e) {
        document.getElementById('filas').innerHTML = '<tr><td colspan="11" class="muted">Error cargando el registro: ' + escapeHtml(e) + '</td></tr>';
      });
  }

  document.getElementById('filtros').addEventListener('submit', function (e) {
    e.preventDefault();
    page = 1;
    cargar();
  });
  document.getElementById('btnPrev').addEventListener('click', function () { if (page > 1) { page--; cargar(); } });
  document.getElementById('btnNext').addEventListener('click', function () { page++; cargar(); });

  cargar();
})();
</script>
</body></html>
HTML;
    }

    private function jsonEncodeForHtml(string $value): string
    {
        return json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP);
    }


    /**
     * Guarda una copia de la Declaración Responsable únicamente cuando los
     * datos han cambiado respecto a la última copia guardada.
     *
     * A diferencia de saveDeclarationSnapshot(), esta función NO sustituye
     * la copia anterior: cada modificación genera una nueva instantánea.
     */
    private function saveDeclarationSnapshotIfChanged(array $d, string $declaration, string $conditions): bool
    {
        $this->ensureDeclarationSchema();
        $db = $this->db();

        $dateSql = null;
        if (preg_match('/^(\d{2})\/(\d{2})\/(\d{4})$/', $d['fecha'], $m)) {
            $dateSql = $m[3] . '-' . $m[2] . '-' . $m[1];
        }

        $query = $db->getQuery(true)
            ->select('*')
            ->from('#__hikashop_verifactu_declaracion')
            ->order('id DESC')
            ->setLimit(1);
        $db->setQuery($query);
        $last = $db->loadAssoc();

        $values = [
            'version_sistema' => (string) $d['version'],
            'nombre' => (string) $d['nombre'],
            'nif' => (string) $d['nif'],
            'direccion' => (string) $d['direccion'],
            'lugar' => (string) $d['lugar'],
            'fecha_firma' => $dateSql,
            'declaracion_html' => $declaration,
            'condiciones_html' => $conditions,
        ];

        if ($last) {
            $same = true;
            foreach ($values as $key => $value) {
                $old = isset($last[$key]) ? (string) $last[$key] : '';
                $new = $value === null ? '' : (string) $value;
                if ($old !== $new) {
                    $same = false;
                    break;
                }
            }
            if ($same) {
                return false;
            }
        }

        $data = [
            'version_sistema' => $d['version'],
            'nombre' => $d['nombre'],
            'nif' => $d['nif'],
            'direccion' => $d['direccion'],
            'codigo_postal' => null,
            'localidad' => null,
            'provincia' => null,
            'pais' => null,
            'lugar' => $d['lugar'],
            'fecha_firma' => $dateSql,
            'declaracion_html' => $declaration,
            'condiciones_html' => $conditions,
            'created' => date('Y-m-d H:i:s'),
        ];

        $q = $db->getQuery(true)
            ->insert('#__hikashop_verifactu_declaracion')
            ->columns(array_keys($data))
            ->values(implode(',', array_map(
                fn($val) => $val === null ? 'NULL' : $db->quote($val),
                array_values($data)
            )));

        try {
            $db->setQuery($q)->execute();
            self::log('Nueva copia de la Declaración Responsable guardada por el usuario.');
            return true;
        } catch (\Throwable $e) {
            self::log('Error al guardar la copia de la Declaración Responsable: ' . $e->getMessage(), \Joomla\CMS\Log\Log::ERROR);
            throw $e;
        }
    }

    /**
     * CONFIRMADO contra el código de HikaShop 6.5.2 (back/classes/order.php, función save()):
     * en un cambio de estado, $order->order_status trae el estado nuevo y
     * $order->old->order_status conserva el anterior. Se dispara SOLO la
     * transición hacia el estado personalizado "invoice_generated" -- no
     * en cualquier otro cambio de estado, y no dos veces si el pedido ya
     * estaba en ese estado (para evitar reenvíos accidentales, ej. al tocar
     * otro campo del pedido estando ya en invoice_generated).
     */
    /**
     * Muestra los dos campos Veri*Factu en el formulario real de edición del
     * pedido de HikaShop. HikaShop expone el evento de vista para añadir HTML
     * sin modificar ni sobrescribir la vista order/form del core.
     *
     * Los campos siguen siendo campos personalizados nativos de HikaShop.
     * Aquí solo se fuerza su presencia en el formulario de administración de
     * HikaShop 6.x porque en algunas instalaciones la configuración de
     * field_display no los inserta en esa vista concreta.
     */

    /**
     * Comprueba la vigencia del certificado y envía, como máximo una vez por
     * certificado y umbral, los avisos de 6, 4, 2 y 1 mes.
     *
     * El certificado es del obligado tributario, por lo que el aviso se envía
     * al destinatario configurado en "Email para avisos de caducidad" (o al
     * correo del sitio Joomla si se deja vacío). No se envía ningún dato del
     * certificado adjunto al correo.
     */
    public function onAfterInitialise(): void
    {
        // No ejecutar durante instalaciones/actualizaciones CLI ni en peticiones
        // que no tengan Joomla completamente disponible.
        try {
            $this->comprobarAvisosCertificado();
        } catch (\Throwable $e) {
            self::log('Avisos de caducidad: ' . $e->getMessage(), \Joomla\CMS\Log\Log::WARNING);
        }
    }

    /**
     * v0.34.49: los intentos anteriores (v0.34.46 con $view->toolbar,
     * v0.34.47/v0.34.48 con onHikashopAfterDisplayView) para añadir el
     * botón "Acciones Veri*Factu" dependían de adivinar en qué vista/layout
     * concreto de HikaShop hacerlo, y de que ese trigger se ejecutara antes
     * de que la página terminase de generarse. La captura de pantalla
     * aportada muestra que el botón sigue sin aparecer ni siquiera con esos
     * cambios, así que se cambia de estrategia por una que no dependa de
     * adivinar nada de eso: onAfterRender() se dispara al final, sobre el
     * HTML COMPLETO ya generado de cualquier página de administración
     * (Joomla lo notifica a todos los plugins ya cargados en el dispatcher,
     * sin importar de qué grupo/carpeta sean -- por eso onAfterInitialise,
     * justo arriba, ya funciona igual de bien siendo un plugin del grupo
     * "hikashop"). Se detecta si la página es de HikaShop → Pedidos por los
     * parámetros de la URL (option=com_hikashop y ctrl=order o view=order,
     * los dos esquemas de parámetros que usa HikaShop según la pantalla) y,
     * si lo es, se inserta el script del botón justo antes de `</body>`,
     * sea cual sea la vista/layout/task exactos de esa pantalla concreta.
     */
    public function onAfterRender(): void
    {
        try {
            $app = \Joomla\CMS\Factory::getApplication();
            if (!method_exists($app, 'isClient') || !$app->isClient('administrator')) {
                return;
            }
            if (!method_exists($app, 'getBody') || !method_exists($app, 'setBody')) {
                return;
            }

            $input = $app->input;
            if ((string) $input->getCmd('option', '') !== 'com_hikashop') {
                return;
            }

            $ctrl = (string) $input->getCmd('ctrl', '');
            $view = (string) $input->getCmd('view', '');
            if ($ctrl !== 'order' && $view !== 'order') {
                return;
            }

            // v0.34.55: el botón solo debe aparecer en 3 pantallas:
            //  1) listado de pedidos ("Compras"): ctrl=order sin task (o
            //     task=listing);
            //  2) página del pedido: task=edit (o show, ver abajo) sin subtask;
            //  3) popup "Información adicional": task=edit&subtask=additional.
            // El resto de popups de la sección Pedido (Correo electrónico,
            // Factura, Albarán, direcciones, cliente, productos, estado...)
            // usan otras tareas/subtareas y quedan fuera.
            $task = (string) $input->getCmd('task', '');
            $subtask = (string) $input->getCmd('subtask', '');
            $esListado = ($task === '' || $task === 'listing') && $subtask === '';
            // OrderController::edit() sin subtask hace
            // input->set('task', 'show') antes de renderizar, así que al
            // llegar a onAfterRender la página del pedido ya no tiene
            // task=edit sino task=show (v0.34.56: por eso dejó de salir el
            // botón en v0.34.55, que solo aceptaba 'edit').
            $esPedido = ($task === 'edit' || $task === 'show') && $subtask === ''
                && (string) $input->getCmd('tmpl', '') !== 'component';
            $esInfoAdicional = $task === 'edit' && $subtask === 'additional';
            if (!$esListado && !$esPedido && !$esInfoAdicional) {
                return;
            }

            $body = $app->getBody();
            if (!is_string($body) || $body === '' || stripos($body, 'verifactu-btn-ayuda-acciones') !== false) {
                return;
            }

            $script = self::scriptBotonAyudaAcciones();

            if (stripos($body, '</body>') !== false) {
                $body = str_ireplace('</body>', $script . '</body>', $body);
            } else {
                $body .= $script;
            }

            $app->setBody($body);
        } catch (\Throwable $e) {
            self::log('Error inyectando el botón "Acciones Veri*Factu" vía onAfterRender: ' . $e->getMessage(), \Joomla\CMS\Log\Log::WARNING);
        }
    }

    private function obtenerDatosCertificado(): ?array
    {
        $path = trim((string) $this->params->get('cert_path', ''));
        $password = (string) $this->params->get('cert_pass', '');

        // Admitir rutas relativas a la raíz de Joomla, igual que en el campo
        // informativo de configuración.
        if ($path !== '' && !preg_match('#^([A-Za-z]:[\\/]|/)#', $path)) {
            $rootPath = defined('JPATH_ROOT') ? JPATH_ROOT . '/' . ltrim($path, '/\\') : '';
            if ($rootPath !== '' && is_file($rootPath)) {
                $path = $rootPath;
            }
        }

        if ($path === '' || !is_file($path)) {
            return null;
        }

        $cert = \eseperio\verifactu\services\CertificateManagerService::getCertificate($path, $password);
        if (!is_string($cert) || $cert === '') {
            return null;
        }

        $parsed = @openssl_x509_parse($cert);
        if (!is_array($parsed) || empty($parsed['validTo_time_t'])) {
            return null;
        }

        $fingerprint = @openssl_x509_fingerprint($cert, 'sha256');
        if (!$fingerprint) {
            $fingerprint = hash('sha256', $cert);
        }

        return [
            'expiry' => (new \DateTimeImmutable('@' . (int) $parsed['validTo_time_t']))->setTimezone(new \DateTimeZone(date_default_timezone_get())),
            'fingerprint' => strtolower((string) $fingerprint),
            'subject' => isset($parsed['subject']['CN']) ? (string) $parsed['subject']['CN'] : '',
        ];
    }

    private function comprobarAvisosCertificado(): void
    {
        $data = $this->obtenerDatosCertificado();
        if ($data === null) {
            return;
        }

        $db = $this->db();
        $table = $db->quoteName($db->getPrefix() . 'hikashop_verifactu_certificado_avisos');
        $now = new \DateTimeImmutable('now', new \DateTimeZone(date_default_timezone_get()));
        $thresholds = [6, 4, 2, 1];

        foreach ($thresholds as $months) {
            $trigger = $data['expiry']->modify('-' . $months . ' months');
            if ($now < $trigger || $now >= $data['expiry']) {
                continue;
            }

            $query = $db->getQuery(true)
                ->select('COUNT(*)')
                ->from($table)
                ->where($db->quoteName('huella') . ' = ' . $db->quote($data['fingerprint']))
                ->where($db->quoteName('umbral_meses') . ' = ' . (int) $months);
            $db->setQuery($query);
            if ((int) $db->loadResult() > 0) {
                continue;
            }

            $recipient = trim((string) $this->params->get('certificado_aviso_email', ''));
            if ($recipient === '') {
                $config = \Joomla\CMS\Factory::getConfig();
                $recipient = trim((string) $config->get('mailfrom', ''));
            }
            if (!filter_var($recipient, FILTER_VALIDATE_EMAIL)) {
                self::log('Aviso de caducidad: no se pudo enviar el aviso de ' . $months . ' meses porque no hay un email destinatario válido.', \Joomla\CMS\Log\Log::WARNING);
                continue;
            }

            $subject = 'Aviso: certificado digital caduca el ' . $data['expiry']->format('d/m/Y');
            $body = '<p>El certificado digital configurado en HikaShop VeriFactu caducará el <strong>'
                . htmlspecialchars($data['expiry']->format('d/m/Y'), ENT_QUOTES, 'UTF-8')
                . '</strong>.</p>'
                . '<p>Este es el aviso programado con una antelación de <strong>' . (int) $months . ' meses</strong>.</p>'
                . '<p>Revise y sustituya el certificado antes de su caducidad para evitar interrupciones en la firma y el envío a la AEAT.</p>'
                . '<p>El aviso se ha generado automáticamente por HikaShop VeriFactu.</p>';

            $mailer = \Joomla\CMS\Factory::getMailer();
            $mailer->addRecipient($recipient);
            $mailer->setSubject($subject);
            $mailer->isHtml(true);
            $mailer->setBody($body);

            if (!$mailer->send()) {
                self::log('Aviso de caducidad: error enviando el aviso de ' . $months . ' meses a ' . $recipient . '.', \Joomla\CMS\Log\Log::WARNING);
                continue;
            }

            $insert = $db->getQuery(true)
                ->insert($table)
                ->columns([$db->quoteName('huella'), $db->quoteName('umbral_meses'), $db->quoteName('fecha_envio')])
                ->values(
                    $db->quote($data['fingerprint']) . ','
                    . (int) $months . ','
                    . $db->quote($now->format('Y-m-d H:i:s'))
                );
            $db->setQuery($insert);
            $db->execute();

            self::log('Aviso de caducidad enviado: ' . $months . ' meses, certificado con caducidad ' . $data['expiry']->format('d/m/Y') . ', destinatario ' . $recipient . '.');
        }
    }

    public function onHikashopAfterDisplayView(&$view)
    {
        try {
            if (!is_object($view) || !method_exists($view, 'getName')) {
                return;
            }

            if ($view->getName() !== 'order') {
                return;
            }

            $app = \Joomla\CMS\Factory::getApplication();
            if (!$app->isClient('administrator')) {
                return;
            }

            $order = isset($view->order) && is_object($view->order) ? $view->order : null;

            /**
             * v0.34.36: los 3 campos decimales de la rectificación por
             * diferencias NO se muestran en la pantalla principal de
             * edición del pedido, sino en el popup "Información adicional"
             * de HikaShop (donde se editan cupón/envío/pago/impuestos y los
             * custom fields marcados con order_edit_fields=1) -- confirmado
             * con una captura de pantalla real de ese popup. Ese popup es
             * una vista/task distinta de la pantalla de edición principal
             * (layout distinto, posiblemente cargada dentro de un iframe
             * propio), así que el script ya NO se limita a
             * $view->getLayout() === 'edit': se inyecta en cualquier vista
             * "order" de administración en la que $view->order esté
             * disponible, sea cual sea el layout/task concreto, para
             * cubrir también ese popup. El resto de este método (el bloque
             * de campos del abono, que sí es específico de la pantalla
             * principal) sigue exigiendo el layout "edit" más abajo.
             */
            if ($order) {
                echo self::scriptDecimalesEuropeos();

                // v0.34.55: el botón "Acciones Veri*Factu" ya NO se inyecta
                // desde aquí (lo hacía en cualquier vista "order", popups de
                // Correo/Factura/Albarán/etc. incluidos). Solo se inyecta
                // desde onAfterRender(), limitado a las 3 pantallas pedidas.
            }

            if (!method_exists($view, 'getLayout') || $view->getLayout() !== 'edit') {
                return;
            }

            if (!$order) {
                return;
            }

            $fieldsClass = hikashop_get('class.field');
            $fields = $fieldsClass->getFields('', $order, 'order', 'order&task=edit');

            $wanted = ['verifactu_tipo_abono', 'verifactu_comentario_abono'];
            $html = [];

            foreach ($wanted as $namekey) {
                if (empty($fields[$namekey])) {
                    continue;
                }

                $field = $fields[$namekey];
                $value = isset($order->$namekey) ? $order->$namekey : '';
                $inputName = 'data[order][' . $namekey . ']';

                $control = $fieldsClass->display($field, $value, $inputName);
                $label = $fieldsClass->getFieldName($field);

                $html[] = '<div class="verifactu-abono-field" style="margin:10px 0;">'
                    . '<div style="font-weight:600;margin-bottom:5px;">' . $label . '</div>'
                    . $control
                    . '</div>';
            }

            if (count($html) !== 2) {
                self::log('No se pudieron cargar los dos campos VeriFactu para el formulario del pedido.');
                return;
            }

            $wrapperId = 'verifactu-abono-fields-' . (int) ($order->order_id ?? 0);
            $htmlBlock = '<div id="' . $wrapperId . '" class="verifactu-abono-fields" '
                . 'style="display:none;margin:15px 0;padding:12px 15px;border:1px solid #ddd;background:#fff;">'
                . '<div style="font-size:16px;font-weight:600;margin-bottom:10px;">Datos del abono</div>'
                . implode('', $html)
                . '</div>';

            echo $htmlBlock;
            echo '<script>(function(){'
                . 'function moveVerifactuFields(){'
                . 'var box=document.getElementById(' . json_encode($wrapperId) . ');'
                . 'if(!box)return;'
                . 'var forms=document.querySelectorAll("form");var form=null;'
                . 'for(var i=0;i<forms.length;i++){'
                . 'if(forms[i].querySelector("button[type=submit],input[type=submit]")){form=forms[i];break;}'
                . '}'
                . 'if(!form){form=document.querySelector("form#adminForm")||document.querySelector("form[name=adminForm]");}'
                . 'if(!form)return;'
                . "var comment=form.querySelector(\'input[name=\"data[order][order_comment]\"]\')||form.querySelector(\'textarea[name=\"data[order][order_comment]\"]\');"
                . 'var target=comment?comment.closest("tr,div.form-group,fieldset"):null;'
                . 'if(target&&target.parentNode){target.parentNode.insertBefore(box,target);}else{form.appendChild(box);}'
                . 'box.style.display="block";'
                . '}'
                . 'if(document.readyState==="loading"){document.addEventListener("DOMContentLoaded",moveVerifactuFields);}else{moveVerifactuFields();}'
                . '})();</script>';
        } catch (\Throwable $e) {
            self::log('Error mostrando los campos VeriFactu en la edición del pedido: ' . $e->getMessage(), \Joomla\CMS\Log\Log::WARNING);
        }
    }

    /**
     * Añade el botón "Acciones Veri*Factu" en la barra de herramientas del
     * listado de pedidos de administración (HikaShop → Pedidos → Compras) y
     * también en la página de edición de un pedido concreto, sin tocar
     * ningún archivo original de HikaShop.
     *
     * Es el mecanismo estándar que el propio equipo de HikaShop recomienda
     * para esto: el trigger onHikashopBeforeDisplayView(&$view) se dispara
     * antes de renderizar cualquier vista de HikaShop, y empujando un array
     * a la propiedad pública $view->toolbar se añade un botón más sin
     * necesidad de crear un override de vista. Como es un trigger de un
     * plugin del grupo "hikashop", si el plugin está deshabilitado o se
     * desinstala, Joomla deja de invocar este método y el botón desaparece
     * por sí solo -- no hay nada que limpiar.
     *
     * El botón no depende de seleccionar ningún pedido: es un botón de
     * ayuda general que abre, en una ventana emergente, la tabla de
     * referencia con los motivos de abono/rectificación y el resultado
     * VeriFactu (F1/F2/F3/R1.../Registro de Anulación) que le corresponde
     * a cada uno.
     */
    /**
     * v0.34.53: este método añadía el botón "Acciones Veri*Factu" al array
     * nativo $view->toolbar de HikaShop -- el intento anterior al cambio a
     * onAfterRender() (v0.34.46-48, ver comentario en onAfterRender). Con
     * onAfterRender() ya funcionando de forma fiable, dejar este método
     * activo solo duplicaba el botón (uno nativo vía toolbar, otro inyectado
     * por el script), así que se retira por completo.
     */

    /**
     * Guarda explícitamente los dos campos cuando se edita un pedido desde
     * administración. Además de incorporarlos al objeto $order, se escribe
     * directamente en las columnas personalizadas para que el guardado sea
     * fiable incluso cuando HikaShop no haya incluido esos campos en su propio
     * saveForm de esa vista.
     */
    /**
     * HikaShop no persiste de forma fiable, por el camino normal de guardado
     * del pedido, los campos personalizados que este plugin añade por SQL
     * directo (confirmado con "Tipo de abono"/"Comentario del abono": sin
     * este workaround llegaban vacíos a onAfterOrderUpdate). Por eso se
     * escriben aquí directamente contra `#__hikashop_order`, ANTES de que
     * HikaShop continúe con su propio guardado.
     *
     * Data-driven en vez de un if/set por campo: añadir un campo nuevo en el
     * futuro es una línea en este array, no repetir la misma lógica otra vez.
     */
    private const CAMPOS_PROPIOS = [
        'verifactu_tipo_abono',
        'verifactu_comentario_abono',
        'verifactu_sustituye_simplificada',
        'verifactu_anular_factura',
        'verifactu_motivo_anulacion',
        'verifactu_rectificar_diferencias',
        'verifactu_nueva_base_imponible',
        'verifactu_nuevo_tipo_iva',
        'verifactu_nuevo_tipo_recargo',
    ];

    /**
     * Los tres campos de la rectificación por diferencias son columnas
     * DECIMAL, no VARCHAR como el resto de CAMPOS_PROPIOS -- un valor vacío
     * ('') no es un decimal válido y, en el modo estricto de MySQL que
     * activa Joomla (ver comentario en script.php), el UPDATE fallaría
     * entero. Se guarda NULL en su lugar cuando el campo se deja vacío.
     */
    private const CAMPOS_DECIMALES = [
        'verifactu_nueva_base_imponible',
        'verifactu_nuevo_tipo_iva',
        'verifactu_nuevo_tipo_recargo',
    ];

    /**
     * Normaliza un número escrito en formato español/europeo (separador de
     * miles ".", separador decimal ",") al formato con punto decimal que
     * espera una columna DECIMAL de MySQL. Admite también que venga ya en
     * formato con punto decimal simple (ej. si el valor viene de la propia
     * BBDD en una recarga, o de un teclado numérico que solo permite
     * puntos), para no romper nada que ya funcionara.
     *
     * v0.34.33: antes solo se hacía str_replace(',', '.', $valor), que
     * rompía con cualquier separador de miles (ej. "1.234,56" se convertía
     * en el numéricamente inválido "1.234.56") y, peor aún, "45,95" se
     * guardaba bien pero un valor grande con miles se truncaba o fallaba.
     * Devuelve null si no se puede interpretar como número.
     */
    /**
     * Script embebido en la vista de edición del pedido (backend, com_hikashop,
     * view=order, task=edit) para que los 3 campos de la rectificación por
     * diferencias -- self::CAMPOS_DECIMALES, custom fields de HikaShop tipo
     * "text" sobre la tabla `order`, ver sql/install.mysql.utf8.sql -- admitan
     * la coma decimal española al escribir.
     *
     * v0.34.34: confirmado que el problema no es el guardado (parseNumeroEuropeo()
     * ya lo maneja bien en PHP) sino el propio widget de HikaShop en el
     * formulario: al escribir "45,95" solo llegaba "45". El input no se
     * localiza por un id fijo porque no está confirmado con qué prefijo de
     * array lo genera exactamente el field class de HikaShop para un campo de
     * la tabla "order" (puede ser "data[order][...]" u otro) -- se busca en su
     * lugar por coincidencia de texto en "name" o "id", que es válido
     * cualquiera que sea ese prefijo.
     *
     * El script hace tres cosas y nada más:
     *   1) En cuanto localiza cada input, lo libera de lo que le impida
     *      escribir la coma (típicamente <input type="number">, que el
     *      navegador filtra caracter a caracter y descarta la coma en
     *      silencio) pasándolo a type="text", sin tocar su valor.
     *   1b) (v0.34.37) Ese mismo momento, y solo una vez por input, convierte
     *      el valor que HikaShop ya ha pintado (formato con punto, tal cual
     *      sale de la columna DECIMAL) a formato con coma, para que estos 3
     *      campos se vean igual que el resto de importes nativos de
     *      HikaShop en ese mismo popup (IVA, Recargo, Total...). Puramente
     *      visual: no reescribe nada en BBDD por sí solo.
     *   2) En el submit del formulario de edición del pedido, y solo en ese
     *      momento, convierte lo escrito a formato con punto decimal simple,
     *      con la MISMA lógica que self::parseNumeroEuropeo() en PHP (mismo
     *      criterio miles/decimal -- ver ese método para el porqué de cada
     *      caso). Así software y usuario ven siempre "45,95" mientras se
     *      escribe, y lo que llega a $_POST, a onBeforeOrderUpdate y a la
     *      columna DECIMAL ya está en "45.95".
     *
     * No toca ningún otro campo del formulario ni interfiere con el submit:
     * no hace preventDefault ni valida nada, solo reescribe el value de estos
     * 3 inputs justo antes de que el navegador envíe el formulario normal de
     * HikaShop.
     *
     * v0.34.35: la v0.34.34 (liberar el input + convertir solo en el submit)
     * no era suficiente -- confirmado en producción que seguía guardándose
     * "45.00" en vez de "45.95". La causa más probable: HikaShop enlaza su
     * PROPIO listener de blur/change directamente sobre este input (algo del
     * estilo `valor = parseFloat(this.value).toFixed(2)` para redondear a 2
     * decimales), y ese listener se dispara ANTES de que nuestro submit
     * llegue a normalizar nada. `parseFloat("45,95")` en JS se detiene en la
     * coma y devuelve 45 -- de ahí el "45.00" (45 redondeado a 2 decimales),
     * no "45" a secas.
     *
     * La fase de captura de un evento SIEMPRE atraviesa primero el árbol
     * desde el document hacia abajo, antes de llegar a los listeners
     * enlazados directamente sobre el propio elemento (fase "target"),
     * sea cual sea el orden en que unos y otros se registraron. Por eso
     * ahora se añade un listener de blur/change/focusout en el document,
     * con captura, que en cuanto detecta uno de estos 3 inputs: (a)
     * convierte ya mismo la coma a punto (para que, si a continuación se
     * ejecuta el listener de HikaShop, se encuentre un número ya válido
     * tipo "45.95" y no truncable), y (b) para la propagación del evento,
     * para que ese listener de HikaShop directamente no llegue a
     * ejecutarse sobre estos 3 campos. La conversión en el submit (más
     * abajo) se mantiene como red de seguridad, por si el campo llega al
     * envío sin haber perdido el foco.
     * v0.34.38: lo anterior (readonly "a la española") solo cubre el
     * <input> editable del popup. La pantalla de resumen de solo lectura del
     * pedido usa la MISMA tabla de etiqueta/valor pero sin ningún <input> --
     * ver formatearTablaResumen() más abajo, que localiza esas 3 celdas por
     * el texto de su etiqueta en vez de por name/id de input.
     */
    /**
     * Botón "Acciones Veri*Factu" inyectado por JavaScript en la pantalla de
     * edición de un pedido concreto (ver comentario v0.34.47 más arriba).
     * Abre la misma página de ayuda que el botón del listado "Compras", en
     * una ventana emergente del propio navegador (sin depender de ningún
     * sistema de modal/popup concreto de HikaShop o de la plantilla de
     * administración).
     */
    private static function scriptBotonAyudaAcciones(): string
    {
        $url = 'index.php?option=com_ajax&group=hikashop&plugin=verifactu&format=raw&task=ayuda_acciones';
        $urlJson = json_encode($url);

        return <<<JS
<script>
(function(){
    var URL = {$urlJson};
    var BTN_ID = 'verifactu-btn-ayuda-acciones';

    function abrirAyuda(e) {
        if (e && e.preventDefault) { e.preventDefault(); }
        window.open(URL, 'verifactu_ayuda_acciones', 'width=980,height=700,resizable=yes,scrollbars=yes,menubar=no,toolbar=no,location=no,status=no');
    }

    function crearBoton(icono) {
        var b = document.createElement('button');
        b.type = 'button';
        b.id = BTN_ID;
        b.className = 'btn btn-secondary btn-sm';
        b.style.marginLeft = '6px';

        if (icono) {
            b.appendChild(icono);
            b.appendChild(document.createTextNode(' '));
        } else {
            b.appendChild(document.createTextNode('? '));
        }

        var strong = document.createElement('strong');
        strong.textContent = 'Acciones Veri*Factu';
        b.appendChild(strong);

        b.title = 'Tabla de ayuda: qué hacer y qué resultado VeriFactu genera cada acción';
        b.addEventListener('click', abrirAyuda);
        return b;
    }

    function buscarAyuda() {
        // La barra de HikaShop suele incluir ya un botón propio "Ayuda"
        // (genérico, de la propia HikaShop, sin relación con VeriFactu).
        // Buscarlo por su texto es más fiable que adivinar clases CSS,
        // porque garantiza colocar el botón exactamente junto a los demás
        // botones de esa misma barra, sea cual sea la plantilla de
        // administración instalada. También se aprovecha para clonar su
        // icono (svg/i/span de icono) y usar el mismo estilo visual en
        // nuestro botón, en vez de un simple carácter "?".
        //
        // v0.34.50: la búsqueda buscaba antes en TODA la página
        // (document.querySelectorAll('button, a')), lo que en la práctica
        // encontraba primero el enlace "Ayuda"/"Help" del propio menú
        // lateral de administración de Joomla -- un <li> de submenú oculto
        // (visibility:hidden) hasta que se despliega. El botón se colgaba
        // de ahí y heredaba ese "hidden", quedando invisible aunque existía
        // en el DOM. Ahora la búsqueda de texto se acota a contenedores que
        // ya parecen barra de herramientas, para no volver a engancharse a
        // un elemento de navegación no relacionado.
        var contenedores = document.querySelectorAll(
            '#toolbar, .subhead-collapse, .btn-toolbar, .header-actions'
        );
        for (var c = 0; c < contenedores.length; c++) {
            var candidatos = contenedores[c].querySelectorAll('button, a');
            for (var i = 0; i < candidatos.length; i++) {
                var texto = (candidatos[i].textContent || '').trim();
                if (texto === 'Ayuda' || texto === 'Help') {
                    var iconoOriginal = candidatos[i].querySelector('svg, i, span[class*="icon"]');
                    return {
                        destino: candidatos[i].parentNode,
                        icono: iconoOriginal ? iconoOriginal.cloneNode(true) : null
                    };
                }
            }
        }
        return {destino: null, icono: null};
    }

    function insertarBoton() {
        if (document.getElementById(BTN_ID)) { return; }

        var encontrado = buscarAyuda();
        var destino = encontrado.destino;

        if (!destino) {
            var selectores = [
                '.subhead-collapse .container-fluid .btn-toolbar',
                '.subhead-collapse .btn-toolbar',
                '#toolbar .btn-toolbar',
                '#toolbar',
                '.header-actions .btn-toolbar',
                'joomla-toolbar-button'
            ];

            for (var i = 0; i < selectores.length; i++) {
                var el = document.querySelector(selectores[i]);
                if (el) {
                    destino = (selectores[i] === 'joomla-toolbar-button') ? el.parentNode : el;
                    break;
                }
            }
        }

        var boton = crearBoton(encontrado.icono);

        if (destino) {
            destino.appendChild(boton);

            // v0.34.50: red de seguridad -- si tras insertarlo el botón
            // termina con tamaño 0 (heredó "visibility:hidden" o
            // "display:none" de un contenedor inesperado, como ocurrió con
            // el <li> del menú lateral antes de acotar buscarPorTextoAyuda),
            // se retira de ahí y se reinserta como flotante en su lugar, en
            // vez de quedar invisible sin que se note.
            var rect = boton.getBoundingClientRect();
            if (rect.width === 0 && rect.height === 0) {
                boton.parentNode.removeChild(boton);
                destino = null;
            }
        }

        if (!destino) {
            // No se encontró la barra de herramientas nativa: se muestra
            // igualmente como botón flotante fijo, para no dejar la ayuda
            // inaccesible en plantillas de administración no contempladas.
            boton.style.position = 'fixed';
            boton.style.top = '70px';
            boton.style.right = '16px';
            boton.style.zIndex = '99999';
            boton.style.boxShadow = '0 1px 4px rgba(0,0,0,.3)';
            document.body.appendChild(boton);
        }
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', insertarBoton);
    } else {
        insertarBoton();
    }

    // La pantalla de edición del pedido puede recargar/reconstruir bloques
    // de forma dinámica (igual que ocurre con los campos del abono); se
    // reintenta con un pequeño debounce si el botón llegara a desaparecer.
    var timeoutId = null;
    new MutationObserver(function(){
        if (timeoutId) { clearTimeout(timeoutId); }
        timeoutId = setTimeout(insertarBoton, 150);
    }).observe(document.body, {childList: true, subtree: true});
})();
</script>
JS;
    }

    private static function scriptDecimalesEuropeos(): string
    {
        $camposJson = json_encode(array_values(self::CAMPOS_DECIMALES));

        $js = <<<'JS'
(function(){
    var CAMPOS = __CAMPOS__;

    // Misma lógica que Verifactu::parseNumeroEuropeo() en PHP.
    function parseNumeroEuropeo(valor) {
        if (valor === null || valor === undefined) { return null; }
        valor = String(valor).trim();
        if (valor === '') { return null; }
        var tienePunto = valor.indexOf('.') !== -1;
        var tieneComa = valor.indexOf(',') !== -1;
        if (tienePunto && tieneComa) {
            valor = valor.split('.').join('');
            valor = valor.replace(',', '.');
        } else if (tieneComa) {
            valor = valor.replace(',', '.');
        } else if (tienePunto) {
            if (/^[1-9][0-9]{0,2}\.[0-9]{3}$/.test(valor)) {
                valor = valor.split('.').join('');
            }
        }
        return valor;
    }

    function encontrarInputs(campo, form) {
        var resultado = [];
        var porNombre = form.querySelectorAll('input[name*="' + campo + '"]');
        var porId = form.querySelectorAll('input[id*="' + campo + '"]');
        for (var i = 0; i < porNombre.length; i++) {
            if (resultado.indexOf(porNombre[i]) === -1) { resultado.push(porNombre[i]); }
        }
        for (var j = 0; j < porId.length; j++) {
            if (resultado.indexOf(porId[j]) === -1) { resultado.push(porId[j]); }
        }
        return resultado;
    }

    // v0.34.38: lo de arriba (formatearParaMostrar/liberarInput) solo
    // corrige el <input> editable del popup. Pero hay otra pantalla --
    // el resumen de solo lectura del pedido en el backend, con la misma
    // tabla de "Subtotal/Cupón/Envío/.../Nueva base imponible/..." -- donde
    // estos 3 valores NO son un <input>, sino texto plano dentro de una
    // celda/columna junto a su etiqueta. Ahí liberarInput() nunca encuentra
    // nada porque no hay ningún input que buscar, así que se necesita una
    // segunda pasada, aparte, que localice esa celda por el TEXTO de su
    // etiqueta (el mismo field_realname que sql/install.mysql.utf8.sql le da
    // a cada campo) en vez de por su name/id.
    var ETIQUETAS_RESUMEN = [
        'Nueva base imponible',
        'Nuevo tipo de IVA (%)',
        'Nuevo tipo de recargo de equivalencia (%)'
    ];

    // Texto del propio nodo, sin contar el de posibles hijos -- para no
    // confundir la etiqueta completa de una fila con la de una celda que la
    // contenga a su vez dentro de otro elemento mayor.
    function textoPropio(el) {
        var texto = '';
        for (var i = 0; i < el.childNodes.length; i++) {
            var nodo = el.childNodes[i];
            if (nodo.nodeType === 3) { texto += nodo.nodeValue; }
        }
        return texto.trim();
    }

    function buscarCeldaDeEtiqueta(etiqueta) {
        var candidatos = document.querySelectorAll('td, th, dt, dd, div, span, strong, b, label');
        for (var i = 0; i < candidatos.length; i++) {
            var el = candidatos[i];
            if (el.children.length !== 0 || textoPropio(el) !== etiqueta) { continue; }
            var fila = el.closest ? el.closest('tr') : null;
            if (fila) {
                var celdas = fila.querySelectorAll('td');
                if (celdas.length) { return celdas[celdas.length - 1]; }
            }
            if (el.nextElementSibling) { return el.nextElementSibling; }
        }
        return null;
    }

    function formatearCeldaResumen(celda) {
        if (!celda || celda.getAttribute('data-verifactu-resumen-fix') === '1') { return; }
        var texto = textoPropio(celda);
        // Solo se toca si el contenido es EXACTAMENTE un número con punto
        // decimal (nunca lleva símbolo € a diferencia de Subtotal/IVA/Total,
        // que sí son nativos de HikaShop) -- así, si esa celda no es lo que
        // se espera, no se toca nada.
        if (!/^-?\d+\.\d+$/.test(texto)) { return; }
        celda.setAttribute('data-verifactu-resumen-fix', '1');
        for (var i = 0; i < celda.childNodes.length; i++) {
            var nodo = celda.childNodes[i];
            if (nodo.nodeType === 3 && nodo.nodeValue.trim() === texto) {
                nodo.nodeValue = nodo.nodeValue.replace(texto, texto.replace('.', ','));
                break;
            }
        }
    }

    function formatearTablaResumen() {
        for (var i = 0; i < ETIQUETAS_RESUMEN.length; i++) {
            formatearCeldaResumen(buscarCeldaDeEtiqueta(ETIQUETAS_RESUMEN[i]));
        }
    }

    // v0.34.37: el valor que HikaShop pinta al cargar el popup viene tal
    // cual de la columna DECIMAL (formato con punto, ej. "99.95"), mientras
    // que sus propios campos nativos (IVA, Recargo, Total...) ya se
    // muestran "a la española" (con coma). Es solo un problema de
    // visualización -- el dato en BBDD nunca ha estado mal -- así que se
    // corrige aquí, reutilizando la MISMA parseNumeroEuropeo() que ya usa el
    // guardado, en vez de una segunda lógica de formateo por separado.
    function formatearParaMostrar(valor) {
        var normalizado = parseNumeroEuropeo(valor);
        if (normalizado === null) { return valor; }
        return normalizado.replace('.', ',');
    }

    function liberarInput(input) {
        if (!input || input.getAttribute('data-verifactu-decimal-fix') === '1') { return; }
        input.setAttribute('data-verifactu-decimal-fix', '1');
        if (input.type === 'number') { input.setAttribute('type', 'text'); }
        input.removeAttribute('pattern');
        input.removeAttribute('step');
        input.removeAttribute('min');
        input.removeAttribute('max');
        if (input.hasAttribute('inputmode')) { input.setAttribute('inputmode', 'decimal'); }
        // Se hace solo aquí (dentro de la comprobación de la marca de arriba)
        // para que se aplique una única vez por input: si se repitiera en
        // cada paso del MutationObserver, un valor ya convertido a coma
        // ("99,95") volvería a pasar por parseNumeroEuropeo() sin problema
        // (es idempotente), pero es innecesario repetirlo y esta marca ya
        // estaba ahí para evitar exactamente ese tipo de reproceso.
        var formateado = formatearParaMostrar(input.value);
        if (formateado !== input.value) { input.value = formateado; }
    }

    // v0.34.39: había un SEGUNDO sitio, aparte del popup, donde HikaShop
    // pinta el mismo campo dentro de OTRO <form> propio (la pantalla
    // principal del pedido, no el popup) -- confirmado con captura real: el
    // popup ya se veía bien ("1,95"), pero ese otro formulario seguía
    // mostrando el valor crudo con punto, sin haber pasado por liberarInput()
    // ni por formatearParaMostrar(). La causa: antes se buscaba "el
    // formulario" (uno solo, el primero que tuviera alguno de estos 3
    // campos) y se dejaban todos los demás sin tocar. Ya no se hace así:
    // prepararCampos() recorre TODOS los <form> de la página y procesa cada
    // instancia de estos campos que haya en cada uno, sin quedarse solo con
    // el primero.

    function normalizarSiCorresponde(el) {
        if (!el) { return; }
        var normalizado = parseNumeroEuropeo(el.value);
        if (normalizado !== null && normalizado !== el.value) {
            el.value = normalizado;
        }
    }

    // Se mantiene fuera de prepararCampos() para que el listener de captura
    // (definido una sola vez) pueda consultarla aunque el formulario se
    // vuelva a inspeccionar más tarde (p.ej. tras un repintado parcial).
    var elementosVigilados = [];

    function prepararCampos() {
        var forms = document.querySelectorAll('form');
        var formsConCampos = [];

        for (var f = 0; f < forms.length; f++) {
            var form = forms[f];
            var encontradoEnEsteForm = false;

            for (var i = 0; i < CAMPOS.length; i++) {
                var inputs = encontrarInputs(CAMPOS[i], form);
                for (var k = 0; k < inputs.length; k++) {
                    liberarInput(inputs[k]);
                    if (elementosVigilados.indexOf(inputs[k]) === -1) {
                        elementosVigilados.push(inputs[k]);
                    }
                    encontradoEnEsteForm = true;
                }
            }

            if (encontradoEnEsteForm) { formsConCampos.push(form); }
        }

        // El listener de submit se añade a CADA formulario que tenga alguno
        // de estos campos (no solo al primero), pero siempre normaliza la
        // lista COMPLETA de elementosVigilados -- da igual cuál de los
        // formularios sea el que se envía, así cualquier campo ya tocado en
        // cualquier otro formulario de la página queda igualmente a salvo, y
        // normalizar uno que ya esté bien no hace nada (es idempotente).
        for (var g = 0; g < formsConCampos.length; g++) {
            var elForm = formsConCampos[g];
            if (elForm.getAttribute('data-verifactu-decimal-submit') === '1') { continue; }
            elForm.setAttribute('data-verifactu-decimal-submit', '1');
            elForm.addEventListener('submit', function () {
                for (var i = 0; i < elementosVigilados.length; i++) {
                    normalizarSiCorresponde(elementosVigilados[i]);
                }
            });
        }
    }

    if (document.__verifactuDecimalesCaptura !== true) {
        document.__verifactuDecimalesCaptura = true;
        ['blur', 'change', 'focusout'].forEach(function (tipo) {
            document.addEventListener(tipo, function (e) {
                if (elementosVigilados.indexOf(e.target) === -1) { return; }
                // v0.34.41: la v0.34.40 volvía a poner la coma justo después
                // (con un setTimeout(0)) para que el campo no se quedara con
                // el punto tras salir de él. SE REVIERTE: confirmado en
                // producción que eso volvía a dejar una ventana en la que
                // HikaShop podía leer el valor todavía en coma -- exactamente
                // el mismo problema que motivó este listener en la v0.34.35
                // ("45,95" truncado a "45.00", aquí "19,95" a "19.00"), solo
                // que retrasado unos milisegundos en vez de inmediato. No hay
                // forma fiable de saber CUÁNDO ha terminado HikaShop de leer
                // el valor para hacerlo seguro reponer la coma después, así
                // que se prioriza que el importe se guarde bien sobre que se
                // vea con coma mientras se edita: el campo queda con punto
                // en cuanto se sale de él (aunque al cargar la página, y en
                // el resumen de solo lectura, se siga viendo con coma).
                normalizarSiCorresponde(e.target);
                if (typeof e.stopImmediatePropagation === 'function') {
                    e.stopImmediatePropagation();
                }
            }, true);
        });
    }

    // v0.34.36: el popup "Información adicional" de HikaShop (donde viven
    // estos 3 campos) se carga sobre la marcha -- su HTML no existe todavía
    // cuando este script corre por primera vez en la carga de la página.
    // Un MutationObserver reintenta prepararCampos() cada vez que cambia el
    // DOM (con un pequeño debounce para no recalcular en cada micro-cambio),
    // así que en cuanto el popup se inserta (o se reabre, si HikaShop
    // reconstruye su HTML cada vez) los inputs se localizan y se preparan
    // igual que si hubieran estado ahí desde el principio.
    if (typeof MutationObserver === 'function' && document.__verifactuDecimalesObserver !== true) {
        document.__verifactuDecimalesObserver = true;
        var reintentoPendiente = null;
        var observer = new MutationObserver(function () {
            if (reintentoPendiente) { return; }
            reintentoPendiente = setTimeout(function () {
                reintentoPendiente = null;
                prepararCampos();
                formatearTablaResumen();
            }, 150);
        });
        observer.observe(document.documentElement || document.body, { childList: true, subtree: true });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', function () {
            prepararCampos();
            formatearTablaResumen();
        });
    } else {
        prepararCampos();
        formatearTablaResumen();
    }
})();
JS;

        return '<script>' . str_replace('__CAMPOS__', $camposJson, $js) . '</script>';
    }

    private static function parseNumeroEuropeo(string $valor): ?string
    {
        $valor = trim($valor);
        if ($valor === '') {
            return null;
        }

        $tienePunto = strpos($valor, '.') !== false;
        $tieneComa = strpos($valor, ',') !== false;

        if ($tienePunto && $tieneComa) {
            // "1.234,56" -- el punto es de miles, la coma es el decimal.
            $valor = str_replace('.', '', $valor);
            $valor = str_replace(',', '.', $valor);
        } elseif ($tieneComa) {
            // "45,95" -- coma decimal, sin miles.
            $valor = str_replace(',', '.', $valor);
        } elseif ($tienePunto) {
            // Ambiguo: "45.95" (decimal) vs "12.345" (miles, sin decimales).
            // Un único punto seguido de EXACTAMENTE 3 dígitos y precedido de
            // 1 a 3 dígitos es casi con toda seguridad un separador de
            // miles (ej. "1.234", "12.345"); cualquier otro caso (2
            // decimales, más de un grupo, etc.) se trata como decimal.
            if (preg_match('/^[1-9][0-9]{0,2}\.[0-9]{3}$/', $valor)) {
                $valor = str_replace('.', '', $valor);
            }
        }

        return is_numeric($valor) ? $valor : null;
    }

    public function onBeforeOrderUpdate(&$order, &$do)
    {
        try {
            $app = \Joomla\CMS\Factory::getApplication();
            if (!$app->isClient('administrator')) {
                return;
            }

            $data = $app->input->get('data', [], 'array');
            $orderData = isset($data['order']) && is_array($data['order']) ? $data['order'] : [];
            // el popup "Información adicional" del pedido envía los campos en data[orderfields]
            if (isset($data['orderfields']) && is_array($data['orderfields'])) {
                $orderData = array_merge($orderData, $data['orderfields']);
            }
            if (empty($orderData)) {
                return;
            }

            $presentes = array_intersect(self::CAMPOS_PROPIOS, array_keys($orderData));
            if (empty($presentes)) {
                return;
            }

            $orderId = (int) ($order->order_id ?? 0);
            if (!$orderId) {
                return;
            }

            $db = $this->db();
            $query = $db->getQuery(true)->update('#__hikashop_order')->where('order_id = ' . $orderId);

            $resumenLog = [];
            foreach ($presentes as $campo) {
                $valor = (string) $orderData[$campo];
                $valor = ($campo === 'verifactu_comentario_abono' || $campo === 'verifactu_motivo_anulacion') ? $valor : trim($valor);

                if (in_array($campo, self::CAMPOS_DECIMALES, true)) {
                    $valorNumerico = self::parseNumeroEuropeo($valor);
                    if ($valorNumerico === null) {
                        $order->$campo = null;
                        $query->set($db->quoteName($campo) . ' = NULL');
                        $resumenLog[] = $campo . '=NULL';
                        continue;
                    }
                    $valor = $valorNumerico;
                }

                $order->$campo = $valor;
                $query->set($db->quoteName($campo) . ' = ' . $db->quote($valor));
                $resumenLog[] = $campo . '=' . $valor;
            }
            $db->setQuery($query)->execute();

            self::log('Campos propios guardados para pedido ' . $orderId . ': ' . implode(', ', $resumenLog));
        } catch (\Throwable $e) {
            self::log('Error guardando los campos propios del pedido: ' . $e->getMessage(), \Joomla\CMS\Log\Log::WARNING);
        }
    }

    public function onAfterOrderUpdate(&$order, &$send_email)
    {
        self::log('onAfterOrderUpdate disparado para el pedido ' . ($order->order_id ?? '?') . ', estado nuevo=' . ($order->order_status ?? '?') . ', estado anterior=' . ($order->old->order_status ?? '?'));
        $this->procesarPedidoVerifactu($order, 'onAfterOrderUpdate');
    }

    /**
     * HikaShop dispara este evento justo después de generar una factura.
     * Es un punto de respaldo fiable cuando el cambio de estado se produce
     * antes de que el número de factura esté disponible en onAfterOrderUpdate.
     */
    public function onAfterInvoiceCreate(&$order)
    {
        self::log('onAfterInvoiceCreate disparado para el pedido ' . ($order->order_id ?? '?'));
        $this->procesarPedidoVerifactu($order, 'onAfterInvoiceCreate');
    }

    private function procesarPedidoVerifactu(&$order, string $origen): void
    {
        $estadoObjetivo = $this->params->get('estado_disparador', 'invoice_generated');
        $estadoNuevo = $order->order_status ?? null;

        // El disparador sigue siendo el estado configurado. La diferencia es
        // que ahora también escuchamos onAfterInvoiceCreate, que ocurre cuando
        // HikaShop ya ha generado el número de factura.
        if ($estadoNuevo !== $estadoObjetivo) {
            self::log($origen . ': no aplica; estado actual=' . ($estadoNuevo ?? '?') . ', objetivo=' . ($estadoObjetivo ?? '?'));
            return;
        }

        $db = \Joomla\CMS\Factory::getDbo();

        // El objeto $order en memoria puede llegar incompleto en este punto
        // según el camino por el que se cambió el estado -- confirmado con
        // dos casos reales: primero con order_invoice_number vacío (cambiando
        // desde dentro del pedido) y después con order_full_price a 0€
        // (cambiando desde el desplegable rápido de la lista "Compras", que
        // envía solo un cambio de estado sin los datos completos del pedido).
        // Para no ir parcheando campo a campo cada vez que aparezca uno
        // nuevo, se recarga aquí el pedido completo directamente desde la
        // clase de HikaShop (que además deserializa correctamente
        // order_tax_info) y se usan esos valores como fuente de verdad.
        $orderClass = hikashop_get('class.order');
        $ordenFresco = $orderClass->get($order->order_id);

        if (!empty($ordenFresco)) {
            foreach ([
                'order_full_price',
                'order_tax_info',
                'order_invoice_number',
                'order_invoice_id',
                'order_number',
                'verifactu_tipo_abono',
                'verifactu_comentario_abono',
                'verifactu_sustituye_simplificada',
                'verifactu_anular_factura',
                'verifactu_motivo_anulacion',
                'verifactu_rectificar_diferencias',
                'verifactu_nueva_base_imponible',
                'verifactu_nuevo_tipo_iva',
                'verifactu_nuevo_tipo_recargo',
            ] as $campo) {
                if (isset($ordenFresco->$campo)) {
                    $order->$campo = $ordenFresco->$campo;
                }
            }
            // Los campos personalizados de este plugin son columnas propias de
            // esta instalación. Se leen directamente para no depender de si la
            // clase de HikaShop los incluye en el objeto en este callback --
            // confirmado con casos reales donde no llegaban completos por el
            // camino normal (ver comentario más arriba sobre order_full_price).
            try {
                $qCampos = $db->getQuery(true)
                    ->select([
                        $db->quoteName('verifactu_tipo_abono'),
                        $db->quoteName('verifactu_comentario_abono'),
                        $db->quoteName('verifactu_sustituye_simplificada'),
                        $db->quoteName('verifactu_anular_factura'),
                        $db->quoteName('verifactu_motivo_anulacion'),
                        $db->quoteName('verifactu_rectificar_diferencias'),
                        $db->quoteName('verifactu_nueva_base_imponible'),
                        $db->quoteName('verifactu_nuevo_tipo_iva'),
                        $db->quoteName('verifactu_nuevo_tipo_recargo'),
                    ])
                    ->from($db->quoteName('#__hikashop_order'))
                    ->where($db->quoteName('order_id') . ' = ' . (int) $order->order_id);
                $db->setQuery($qCampos);
                $datosCampos = $db->loadObject();
                if ($datosCampos) {
                    $order->verifactu_tipo_abono = (string) ($datosCampos->verifactu_tipo_abono ?? '');
                    $order->verifactu_comentario_abono = (string) ($datosCampos->verifactu_comentario_abono ?? '');
                    $order->verifactu_sustituye_simplificada = (string) ($datosCampos->verifactu_sustituye_simplificada ?? '');
                    $order->verifactu_anular_factura = (string) ($datosCampos->verifactu_anular_factura ?? '');
                    $order->verifactu_motivo_anulacion = (string) ($datosCampos->verifactu_motivo_anulacion ?? '');
                    $order->verifactu_rectificar_diferencias = (string) ($datosCampos->verifactu_rectificar_diferencias ?? '');
                    $order->verifactu_nueva_base_imponible = $datosCampos->verifactu_nueva_base_imponible ?? null;
                    $order->verifactu_nuevo_tipo_iva = $datosCampos->verifactu_nuevo_tipo_iva ?? null;
                    $order->verifactu_nuevo_tipo_recargo = $datosCampos->verifactu_nuevo_tipo_recargo ?? null;
                    self::log('Campos personalizados recargados directamente: tipo_abono=' . $order->verifactu_tipo_abono . ', sustituye_simplificada=' . $order->verifactu_sustituye_simplificada . ', anular_factura=' . $order->verifactu_anular_factura . ', rectificar_diferencias=' . $order->verifactu_rectificar_diferencias);
                }
            } catch (\Throwable $e) {
                self::log('No se pudieron recargar los campos personalizados directamente: ' . $e->getMessage(), \Joomla\CMS\Log\Log::WARNING);
            }

            self::log('Pedido ' . $order->order_id . ' recargado desde BBDD antes de enviar. order_full_price=' . ($order->order_full_price ?? '?') . ', order_invoice_number=' . ($order->order_invoice_number ?? '?'));
        }

        // Si tampoco hay dirección de facturación cargada en memoria, se
        // recarga también (necesaria para el NIF del cliente).
        if (empty($order->billing_address) && !empty($ordenFresco->order_billing_address_id)) {
            $addressClass = hikashop_get('class.address');
            $order->billing_address = $addressClass->get($ordenFresco->order_billing_address_id);
        }

        // Igual con la dirección de envío: la exención de Canarias/Ceuta/
        // Melilla depende de adónde van las mercancías, no de dónde se
        // factura, así que hace falta el código postal de envío aunque no
        // se haya cargado ya en memoria.
        if (empty($order->shipping_address) && !empty($ordenFresco->order_shipping_address_id)) {
            $addressClass = $addressClass ?? hikashop_get('class.address');
            $order->shipping_address = $addressClass->get($ordenFresco->order_shipping_address_id);
        }

        if (empty($order->order_invoice_number)) {
            // Sin número de factura asignado por HikaShop no hay nada que enviar a la AEAT.
            // Revisa que "invoice_generated" se alcance DESPUÉS de que HikaShop numere la factura
            // (ver la opción de configuración invoice_order_statuses del componente).
            self::log('Pedido ' . $order->order_id . ' llegó a invoice_generated sin order_invoice_number, no se envía.', \Joomla\CMS\Log\Log::WARNING);
            return;
        }

        $bridge = new VerifactuLibraryBridge($db, $this->params);

        try {
            if ($bridge->debeAnular($order)) {
                $resultado = $bridge->anularFactura($order);
                $metodo = 'anularFactura';
            } elseif ($bridge->debeRectificarPorDiferencias($order)) {
                $resultado = $bridge->rectificarPorDiferencias($order);
                $metodo = 'rectificarPorDiferencias';
            } else {
                $resultado = $bridge->registrarFactura($order);
                $metodo = 'registrarFactura';
            }
            $resultadoParaLog = $resultado;
            if (!empty($resultadoParaLog['qr'])) {
                $resultadoParaLog['qr'] = '(' . strlen($resultadoParaLog['qr']) . ' bytes, omitido del log)';
            }
            self::log('Resultado ' . $metodo . ' para pedido ' . $order->order_id . ': ' . json_encode($resultadoParaLog, JSON_INVALID_UTF8_SUBSTITUTE));
        } catch (\Throwable $e) {
            // No dejar que un fallo en VeriFactu bloquee el cambio de estado del pedido:
            // se registra el error y queda para revisión/reintento manual.
            self::log('EXCEPCIÓN en pedido ' . $order->order_id . ': ' . $e->getMessage() . ' en ' . $e->getFile() . ':' . $e->getLine(), \Joomla\CMS\Log\Log::ERROR);
            return;
        }

        if (!in_array($resultado['estado'], ['aceptado', 'aceptado_con_errores'], true)) {
            self::log('Rechazado/erróneo en pedido ' . $order->order_id . ': ' . json_encode($resultado, JSON_INVALID_UTF8_SUBSTITUTE), \Joomla\CMS\Log\Log::WARNING);
        }
    }

    /**
     * CONFIRMADO contra back/views/order/tmpl/invoice.php: HikaShop dispara este
     * evento justo antes de cerrar la tabla de la plantilla de factura, pasando
     * el contexto 'order_back_invoice'. Es el punto exacto para inyectar el QR
     * legal sin tocar ningún archivo del core de HikaShop.
     */
    public function onAfterOrderProductsListingDisplay(&$order, $context)
    {
        self::log('onAfterOrderProductsListingDisplay disparado para pedido ' . ($order->order_id ?? '?') . ' con contexto="' . $context . '"');

        if ($context !== 'order_back_invoice') {
            self::log('Contexto no es order_back_invoice, se omite la inserción del QR.');
            return;
        }

        $db = \Joomla\CMS\Factory::getDbo();
        $query = $db->getQuery(true)
            ->select('qr_url, invoice_number')
            ->from('#__hikashop_verifactu_registro')
            ->where('order_id = ' . (int) $order->order_id)
            ->where('estado_envio IN (' . $db->quote('aceptado') . ',' . $db->quote('aceptado_con_errores') . ')')
            // el QR de la factura del pedido, no el de una rectificación por diferencias posterior
            ->order('(invoice_number = ' . $db->quote((string) ($order->order_invoice_number ?? '')) . ') DESC, id DESC')
            ->setLimit(1);
        $db->setQuery($query);
        $registro = $db->loadObject();

        if (empty($registro) || empty($registro->qr_url)) {
            self::log('No se encontró registro VeriFactu con QR para el pedido ' . ($order->order_id ?? '?') . ' en este momento.');
            return;
        }

        self::log('QR encontrado para pedido ' . ($order->order_id ?? '?') . ', longitud: ' . strlen($registro->qr_url) . ' bytes. Insertando en la plantilla.');

        // $registro->qr_url contiene ahora un data URI PNG en base64 (ver
        // VerifactuLibraryBridge) -- compatible tanto con la vista del backend
        // como con el motor Html2Pdf/TCPDF del plugin Attach Invoice.
        echo '<div class="verifactu-qr" style="margin-top:15px; text-align:center; width:35mm;">'
            . '<img src="' . $registro->qr_url . '" style="width:35mm; height:35mm;" alt="QR VeriFactu" />'
            . '<p style="font-size:9px; margin-top:4px; max-width:35mm;">Factura verificable en la sede electrónica de la AEAT — VERIFACTU</p>'
            . '</div>';
    }
}
