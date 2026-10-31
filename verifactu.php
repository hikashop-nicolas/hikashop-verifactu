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

        // v0.34.88: copia de seguridad y restauración del registro (tareas backup_*).
        // La clase comprueba por su cuenta que el usuario sea superadministrador.
        if (strpos($task, 'backup_') === 0) {
            require_once __DIR__ . '/src/VerifactuBackup.php';

            return VerifactuBackup::dispatch($task);
        }

        // v0.34.92: botones de fin de año (vaciar / dejar el último número) de
        // la configuración del plugin. La clase comprueba el token y que el
        // usuario sea superadministrador.
        if (strpos($task, 'finanio_') === 0) {
            require_once __DIR__ . '/src/VerifactuFinAnio.php';

            return VerifactuFinAnio::dispatch($task);
        }

        // Tabla de ayuda "Acciones Veri*Factu", abierta desde el botón del
        // listado de pedidos (HikaShop → Pedidos → Compras). No depende de
        // ningún pedido concreto: es contenido de referencia estático.
        if ($task === 'ayuda_acciones') {
            return $this->buildAyudaAccionesHtml();
        }

        // v0.34.70: columna "VeriFactu" inyectada en el listado de pedidos
        // (HikaShop -> Pedidos -> Compras) -- ver onAfterRender() e
        // scriptColumnaEstadoListado(). Devuelve, para cada order_id
        // solicitado, el estado del ÚLTIMO intento de envío (o 'excepcion'
        // si el fallo más reciente ni siquiera llegó a guardarse en el
        // registro, ver estadosPorPedido()).
        if ($task === 'estados_pedidos') {
            $inputIds = \Joomla\CMS\Factory::getApplication()->input;
            $ids = $inputIds->get('order_ids', [], 'array');
            $ids = array_values(array_unique(array_filter(array_map('intval', (array) $ids))));
            $ids = array_slice($ids, 0, 200);

            $rectPedidos = $this->rectificacionesPorPedido($ids);

            return (object) [
                'success' => true,
                'estados' => empty($ids) ? new \stdClass() : $this->estadosPorPedido($ids),
                'rectificaciones' => $rectPedidos['rectificaciones'],
                'anuladas' => $rectPedidos['anuladas'],
            ];
        }

        // Documento de una rectificación por diferencias, para el cliente.
        if ($task === 'rectificativa') {
            $inputR = \Joomla\CMS\Factory::getApplication()->input;

            return $this->buildRectificativaHtml($inputR->getInt('order_id'), $inputR->getInt('id'));
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
            // escribe la configuración: solo desde el propio formulario del plugin
            if (!\Joomla\CMS\Session\Session::checkToken('post')) {
                throw new \RuntimeException('Token de formulario no válido', 403);
            }
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
        'error_concurrencia', 'error_id_destinatario', 'error_pais_destinatario', 'error_simplificada_exenta',
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

        // v0.34.77: filtro por tipo de factura (F1, F2, R1, R5...) para sacar, por
        // ejemplo, solo las rectificativas expedidas en un trimestre.
        $tipoFactura = strtoupper(trim((string) $input->getCmd('tipo_factura', '')));
        if (!preg_match('/^[A-Z][0-9]$/', $tipoFactura)) {
            $tipoFactura = '';
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
            'tipo_factura' => $tipoFactura,
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
        if ($f['tipo_factura'] !== '') {
            $query->where('tipo_factura = ' . $db->quote($f['tipo_factura']));
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
        // v0.34.84: columnas añadidas después, al final para no desplazar las posiciones
        // de quien importa el CSV por posición.
        'base_imponible', 'tipo_iva', 'cuota_iva', 'tipo_recargo', 'recargo_equivalencia',
    ];

    /**
     * Filas por lote en las exportaciones. Cada fila puede llevar el QR en
     * base64 (qr_url) y/o el XML firmado (xml_enviado), así que se lee por
     * lotes en vez de cargar todo el rango en memoria (memory_limit).
     */
    private const REGISTRO_LOTE_EXPORTACION = 200;

    /**
     * v0.34.83: columnas del CSV que son importes o porcentajes; salen con coma
     * decimal y punto de miles (1.234,56). El XML enviado a la AEAT no cambia: su
     * esquema exige el punto decimal.
     */
    private const REGISTRO_CSV_IMPORTES = ['total_factura', 'base_imponible', 'tipo_iva', 'cuota_iva', 'tipo_recargo', 'recargo_equivalencia'];

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
                    $valor = $row->$col;
                    if ($valor !== null && $valor !== '' && in_array($col, self::REGISTRO_CSV_IMPORTES, true) && is_numeric($valor)) {
                        $valor = number_format((float) $valor, 2, ',', '.');
                    }
                    $linea[] = $valor;
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
            ['1', 'Tipo abono', 'Seleccionar el motivo correspondiente y utilizar un pedido de abono con importe negativo.', 'Crear pedido nuevo de abono/rectificación', 'R1/R2/R3/R4/R5 según corresponda'],
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
            ['3', 'Sustituye F2 (F3)', 'Primero modificar los datos del cliente y guardar. Después seleccionar <em>Sí</em> cuando se sustituye una factura simplificada F2 previamente aceptada.', 'Modificar pedido existente', 'F3'],
            ['4', 'Anular factura', 'Seleccionar <em>Sí</em> cuando sea necesario anular una factura.', 'Modificar pedido existente', 'Registro de Anulación'],
            ['5', 'Motivo anulación', 'Introducir el motivo de la anulación.', '', 'Información del Registro de Anulación'],
            ['6', 'Rectificar R1', 'Seleccionar <em>Sí</em> para realizar una rectificación por diferencias.', 'Modificar pedido existente', 'R1 por diferencias (I) / R5 si corresponde'],
            ['7', 'Nueva base', 'Introducir la <strong>base imponible final correcta</strong>, no la diferencia.', '', 'El plugin calcula la diferencia'],
            ['8', 'Nuevo IVA %', 'Normalmente dejar vacío para conservar el tipo de IVA original.', '', 'Mantiene el IVA original'],
            ['9', 'Nuevo recargo %', 'Normalmente dejar vacío para conservar el recargo original.', '', 'Mantiene el recargo original'],
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
<p class="subt">v{$version} &middot; Qué hacer en cada caso y qué resultado VeriFactu (F1/F2/F3/R.../Registro de Anulación) genera. Los campos "1.x" son las opciones del desplegable "Tipo abono".</p>
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
  <div><label>Tipo de factura</label><select name="tipo_factura">
    <option value="">Todas</option>
    <option value="F1">F1 (factura)</option>
    <option value="F2">F2 (simplificada)</option>
    <option value="R1">R1 (rectificativa)</option>
    <option value="R5">R5 (rectificativa simplificada)</option>
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

  // v0.34.83: importes con coma decimal y punto de miles (1.234,56)
  function importeEs(v) {
    var n = Number(v);
    if (isNaN(n)) { return String(v); }
    var partes = n.toFixed(2).split('.');
    partes[0] = partes[0].replace(/\B(?=(\d{3})+(?!\d))/g, '.');
    return partes[0] + ',' + partes[1];
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
              + '<td>' + (r.total_factura !== null ? escapeHtml(importeEs(r.total_factura)) + ' €' : '') + '</td>'
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
            $app = \Joomla\CMS\Factory::getApplication();

            // v0.34.92: aviso de fin de año (email, en cualquier petición, y
            // mensaje en el panel de inicio de Joomla). Fallar aquí nunca debe
            // afectar al resto de avisos ni a la página.
            try {
                $this->avisarFinDeAnio($app);
            } catch (\Throwable $e) {
                self::log('Aviso de fin de año: ' . $e->getMessage(), \Joomla\CMS\Log\Log::WARNING);
            }

            if (!$app->isClient('administrator')) {
                return;
            }

            // v0.34.67: aviso de "aceptación pendiente", en CADA página de la
            // administración (sin el marcador diario de abajo, que es solo
            // para no repetir la lectura del certificado). Mientras no se
            // acepte, no se envía nada a la AEAT -- ver el comentario de
            // avisarAceptacionAvisoLegalPendiente() en script.php sobre el
            // porqué de no auto-aceptarlo en una migración.
            if ((string) $this->params->get('aviso_responsabilidad_aceptado', '0') !== '1') {
                $app->enqueueMessage(
                    'VeriFactu: el aviso legal y las condiciones de uso todavía no se han aceptado, así'
                    . ' que el plugin NO envía nada a la AEAT. Entra en Complementos > Hikashop Verifactu'
                    . ' plugin, léelo y marca "He leído y acepto el aviso legal y las condiciones de uso".',
                    'warning'
                );
            }

            // v0.34.68: el aviso que ya se mostraba al guardar un pedido con
            // envío fallido (procesarPedidoVerifactu(), más abajo) depende de
            // que esa misma petición HTTP termine renderizando una página
            // completa de Joomla -- y varios de los caminos que disparan el
            // envío NO lo hacen: el desplegable rápido de estado en el
            // listado "Compras" y el popup "Información adicional" del
            // pedido (donde se marca Rectificar por diferencias / Anular
            // factura) guardan por AJAX, sin recargar la página, así que el
            // enqueueMessage() de esa petición nunca llega a mostrarse -- y
            // un pedido nuevo puede cambiar de estado automáticamente desde
            // el propio checkout, sin ninguna petición de administración de
            // por medio. En los tres casos el aviso solo quedaba en el log.
            //
            // Por eso este aviso NO depende de la petición que falló: se
            // consulta la tabla de registros (fuente de verdad, la que ya
            // usa el listado de facturas para el color rojo) y se muestra en
            // la SIGUIENTE página de administración que se cargue, sea cual
            // sea, igual que el aviso de aceptación pendiente de más arriba.
            $this->avisarEnviosFallidosRecientes($app);

            // abre el certificado y consulta la BBDD: en la administración y una vez al día basta
            $marca = rtrim((string) $app->get('tmp_path', JPATH_ROOT . '/tmp'), '/\\') . '/verifactu_certificado_' . date('Ymd');
            if (is_file($marca)) {
                return;
            }
            @touch($marca);
            $this->comprobarAvisosCertificado();
        } catch (\Throwable $e) {
            self::log('Avisos de caducidad: ' . $e->getMessage(), \Joomla\CMS\Log\Log::WARNING);
        }
    }

    /**
     * v0.34.68: aviso persistente de pedidos cuyo ÚLTIMO intento de envío a
     * la AEAT (factura, rectificación o anulación) quedó en error o
     * rechazado. "Último intento" = la fila más reciente de
     * #__hikashop_verifactu_registro para ese order_id: si se ha reenviado
     * después con éxito, el pedido deja de aparecer solo, sin necesidad de
     * marcar nada a mano.
     *
     * Limitado a 6 pedidos en el mensaje (con recuento del resto) para que
     * el aviso no crezca sin límite en una tienda con muchos pendientes.
     */
    /**
     * v0.34.69: limpia las incidencias (excepciones no registradas) de un
     * pedido tras un intento que SÍ ha llegado a completarse (con éxito o
     * con un error propio ya reflejado en el registro principal). No falla
     * el proceso si la tabla no existe todavía (actualización a medias).
     */
    private function borrarIncidencias($db, int $orderId): void
    {
        try {
            $db->setQuery(
                $db->getQuery(true)
                    ->delete('#__hikashop_verifactu_incidencias')
                    ->where('order_id = ' . $orderId)
            );
            $db->execute();
        } catch (\Throwable $e) {
            self::log('No se pudieron borrar las incidencias del pedido ' . $orderId . ': ' . $e->getMessage(), \Joomla\CMS\Log\Log::WARNING);
        }
    }

    /**
     * v0.34.70: estado del ÚLTIMO intento de envío por pedido, para la
     * columna "VeriFactu" del listado (ver scriptColumnaEstadoListado()) y
     * para el endpoint AJAX 'estados_pedidos'. Mismo criterio de "último
     * intento" que avisarEnviosFallidosRecientes(): fila más reciente de
     * #__hikashop_verifactu_registro por order_id, y una incidencia
     * (excepción sin fila propia) gana si es más reciente que esa fila o si
     * no hay ninguna fila todavía.
     *
     * @param int[] $orderIds
     * @return \stdClass propiedades dinámicas order_id => estado (string)
     */
    private function estadosPorPedido(array $orderIds): \stdClass
    {
        $db = $this->db();
        $idsSql = implode(',', array_map('intval', $orderIds));
        $porPedido = [];

        $sub = $db->getQuery(true)
            ->select(['order_id', 'MAX(id) AS max_id'])
            ->from('#__hikashop_verifactu_registro')
            ->where('order_id IN (' . $idsSql . ')')
            ->group('order_id');
        $query = $db->getQuery(true)
            ->select(['r.order_id', 'r.estado_envio', 'r.created'])
            ->from('#__hikashop_verifactu_registro AS r')
            ->innerJoin('(' . (string) $sub . ') AS ult ON ult.max_id = r.id');
        $db->setQuery($query);
        foreach ($db->loadObjectList() as $fila) {
            $porPedido[(int) $fila->order_id] = ['estado' => (string) $fila->estado_envio, 'fecha' => (string) $fila->created];
        }

        try {
            $query2 = $db->getQuery(true)
                ->select(['order_id', 'MAX(created) AS ultima'])
                ->from('#__hikashop_verifactu_incidencias')
                ->where('order_id IN (' . $idsSql . ')')
                ->group('order_id');
            $db->setQuery($query2);
            foreach ($db->loadObjectList() as $fila) {
                $orderId = (int) $fila->order_id;
                $ultima = (string) $fila->ultima;
                if (!isset($porPedido[$orderId]) || strtotime($ultima) > strtotime($porPedido[$orderId]['fecha'])) {
                    $porPedido[$orderId] = ['estado' => 'excepcion', 'fecha' => $ultima];
                }
            }
        } catch (\Throwable $e) {
            // tabla de incidencias todavía sin crear (actualización a medias): no
            // impide devolver los estados que sí se han podido calcular.
        }

        $out = new \stdClass();
        foreach ($porPedido as $orderId => $info) {
            $out->{(string) $orderId} = $info['estado'];
        }

        return $out;
    }

    private function avisarEnviosFallidosRecientes($app): void
    {
        $db = $this->db();

        $sub = $db->getQuery(true)
            ->select(['order_id', 'MAX(id) AS max_id'])
            ->from('#__hikashop_verifactu_registro')
            ->group('order_id');

        $query = $db->getQuery(true)
            ->select(['r.order_id', 'r.invoice_number', 'r.estado_envio'])
            ->from('#__hikashop_verifactu_registro AS r')
            ->innerJoin('(' . (string) $sub . ') AS ult ON ult.max_id = r.id')
            ->where('(r.estado_envio LIKE ' . $db->quote('error\_%') . " ESCAPE '\\' OR r.estado_envio = " . $db->quote('rechazado') . ')')
            ->order('r.id DESC');
        $db->setQuery($query);
        $pendientes = $db->loadObjectList();

        $detalle = [];
        foreach ($pendientes as $fila) {
            $numero = trim((string) $fila->invoice_number) !== '' ? $fila->invoice_number : ('pedido ' . $fila->order_id);
            $detalle[] = $numero . ' (' . $fila->estado_envio . ')';
        }

        // v0.34.69: incidencias -- intentos que ni siquiera llegaron a
        // guardar una fila en el registro principal (excepción antes de
        // tiempo, ver el catch de procesarPedidoVerifactu()). Se listan
        // aparte porque no tienen invoice_number ni un estado_envio con el
        // que encajen en la consulta de arriba.
        try {
            $db->setQuery(
                $db->getQuery(true)
                    ->select(['order_id'])
                    ->from('#__hikashop_verifactu_incidencias')
                    ->group('order_id')
                    ->order('MAX(id) DESC')
            );
            $incidencias = $db->loadColumn();
        } catch (\Throwable $e) {
            // Tabla todavía no creada (actualización a medias) u otro fallo: no
            // deja de mostrarse el aviso de los pendientes normales por esto.
            $incidencias = [];
        }
        foreach ($incidencias as $orderId) {
            $detalle[] = 'pedido ' . $orderId . ' (excepción, ver log)';
        }

        if (empty($detalle)) {
            return;
        }

        $total = count($detalle);
        $muestra = array_slice($detalle, 0, 6);
        $resto = $total - count($muestra);

        $app->enqueueMessage(
            'VeriFactu: el último envío a la AEAT ha fallado o ha sido rechazado en ' . $total
            . ($total === 1 ? ' pedido: ' : ' pedidos: ') . implode(', ', $muestra)
            . ($resto > 0 ? ' y ' . $resto . ' más' : '') . '. Revisa el listado de facturas del plugin'
            . ' (o el pedido correspondiente) para ver el motivo y reenviar. Este aviso desaparece solo'
            . ' cuando el reenvío se acepta.',
            'warning'
        );
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
            if (!is_string($body) || $body === '') {
                return;
            }

            $script = '';
            if (stripos($body, 'verifactu-btn-ayuda-acciones') === false) {
                $script .= self::scriptBotonAyudaAcciones();
            }
            // v0.34.70: columna "VeriFactu" con el estado del último envío,
            // SOLO en el listado (no en la página de un pedido ni en el
            // popup "Información adicional" -- ahí no hay tabla de filas que
            // ampliar). "Por defecto" en el sentido pedido: no depende de
            // que el usuario la active en ningún selector de columnas de
            // HikaShop, porque no es una columna de ese selector -- se
            // añade por JavaScript en cada carga de la página, así que
            // siempre está.
            if ($esListado && stripos($body, 'verifactu-col-estado-script') === false) {
                $script .= self::scriptColumnaEstadoListado(
                    $this->nombreCampoSeguro('campo_rectificar_diferencias', 'verifactu_rectificar_diferencias')
                );
            }
            // v0.34.85: en el popup "Información adicional" (task=edit&subtask=additional),
            // si el pedido ya tiene una R1/R5 aceptada, aviso junto al botón "Guardar"
            // cuando "Rectificar R1" está en Sí y "Nueva base" está vacía.
            if ($esInfoAdicional && stripos($body, 'verifactu-aviso-rectificar-script') === false) {
                $cid = $input->get('cid', [], 'array');
                $orderIdAviso = (int) (is_array($cid) ? reset($cid) : $cid) ?: $input->getInt('order_id');
                if ($orderIdAviso > 0 && $this->rectificativasDelPedido($orderIdAviso)) {
                    $script .= self::scriptAvisoRectificarAdicional(
                        $this->nombreCampoSeguro('campo_rectificar_diferencias', 'verifactu_rectificar_diferencias'),
                        $this->nombreCampoSeguro('campo_nueva_base_imponible', 'verifactu_nueva_base_imponible')
                    );
                }
            }
            if ($esPedido && stripos($body, 'verifactu-rectificativas-script') === false) {
                $cid = $input->get('cid', [], 'array');
                $orderId = (int) (is_array($cid) ? reset($cid) : $cid) ?: $input->getInt('order_id');
                $rectificativas = $orderId > 0 ? $this->rectificativasDelPedido($orderId) : [];
                if ($rectificativas) {
                    $script .= self::scriptRectificativasPedido($orderId, $rectificativas);
                }
            }

            if ($script !== '') {
                $body = (stripos($body, '</body>') !== false)
                    ? str_ireplace('</body>', $script . '</body>', $body)
                    : $body . $script;
                $app->setBody($body);
            }
        } catch (\Throwable $e) {
            self::log('Error inyectando contenido de VeriFactu vía onAfterRender: ' . $e->getMessage(), \Joomla\CMS\Log\Log::WARNING);
        }
    }

    /**
     * v0.34.85: nombre interno de un campo personalizado según su parámetro del plugin
     * (el mismo que usa el puente), o el nombre por defecto si está vacío o no es un
     * nombre válido. Solo letras, números y guion bajo: se usa dentro de JavaScript.
     */
    private function nombreCampoSeguro(string $paramKey, string $defecto): string
    {
        $nombre = trim((string) $this->params->get($paramKey, $defecto));

        return ($nombre !== '' && preg_match('/^[A-Za-z0-9_]+$/', $nombre)) ? $nombre : $defecto;
    }

    /**
     * v0.34.85: aviso junto al botón "Guardar" del popup "Información adicional".
     * Solo se genera para pedidos que ya tienen una R1/R5 aceptada. En el navegador
     * se muestra mientras "Rectificar R1" esté en Sí y "Nueva base" esté vacía (se
     * actualiza al cambiar los campos): en ese caso, guardar no envía ninguna
     * rectificación. Si los campos o el botón no se encuentran, no hace nada.
     */
    private static function scriptAvisoRectificarAdicional(string $campoRectificar, string $campoBase): string
    {
        $flags = JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT;
        $nombreRectJs = json_encode('data[orderfields][' . $campoRectificar . ']', $flags);
        $nombreBaseJs = json_encode('data[orderfields][' . $campoBase . ']', $flags);

        return <<<HTML
<script id="verifactu-aviso-rectificar-script">
(function () {
  try {
    var NOMBRE_RECT = {$nombreRectJs};
    var NOMBRE_BASE = {$nombreBaseJs};
    var ID = 'verifactu-aviso-rectificar-r1';
    // [negrita, texto]
    var PARTES = [
      [true, '\u26a0 Atenci\u00f3n: '],
      [false, 'si quiere rectificar de nuevo, '],
      [true, 'debe poner una nueva base imponible'],
      [false, '. Con \u00abNueva base\u00bb en blanco '],
      [true, 'no se enviar\u00e1 ninguna rectificaci\u00f3n'],
      [false, '.']
    ];

    function campos(nombre) {
      return document.querySelectorAll('[name="' + nombre + '"]');
    }

    function valorRectificar() {
      var els = campos(NOMBRE_RECT);
      if (!els.length) { return null; }
      var valor = '';
      Array.prototype.forEach.call(els, function (el) {
        if (el.type === 'radio' || el.type === 'checkbox') {
          if (el.checked) { valor = el.value; }
        } else if (el.tagName === 'SELECT') {
          var op = el.options[el.selectedIndex];
          valor = (op ? (op.value !== '' ? op.value : op.text) : '');
        } else {
          valor = el.value;
        }
      });
      return String(valor).trim().toLowerCase().replace('\u00ed', 'i');
    }

    function actualizar() {
      var aviso = document.getElementById(ID);
      if (!aviso) { return; }
      var rect = valorRectificar();
      var base = campos(NOMBRE_BASE);
      var visible = rect !== null && base.length > 0
        && ['1', 'yes', 'si', 'true'].indexOf(rect) !== -1
        && String(base[0].value).trim() === '';
      aviso.style.display = visible ? 'inline-block' : 'none';
    }

    function iniciar() {
      if (document.getElementById(ID)) { return true; }
      var form = document.getElementById('hikashop_order_additional_form');
      if (!form) { return false; }

      var guardar = null;
      var enlaces = form.querySelectorAll('a.btn-success');
      Array.prototype.forEach.call(enlaces, function (a) {
        var oc = a.getAttribute('onclick') || '';
        if (oc.indexOf('hikashop_order_notify') === -1 && oc.indexOf("submitform('save'") !== -1) { guardar = a; }
      });
      if (!guardar && enlaces.length) { guardar = enlaces[enlaces.length - 1]; }
      if (!guardar) { return false; }

      var aviso = document.createElement('span');
      aviso.id = ID;
      aviso.setAttribute('role', 'alert');
      PARTES.forEach(function (p) {
        var nodo = document.createElement(p[0] ? 'strong' : 'span');
        nodo.textContent = p[1];
        aviso.appendChild(nodo);
      });
      aviso.style.cssText = 'display:none;margin-left:12px;padding:10px 14px;border:2px solid #c2410c;border-left:8px solid #c2410c;background:#ffedd5;color:#7c2d12;border-radius:6px;font-size:15px;line-height:1.45;max-width:580px;vertical-align:middle;white-space:normal;box-shadow:0 2px 6px rgba(194,65,12,.25);';
      guardar.insertAdjacentElement('afterend', aviso);

      ['change', 'input', 'keyup'].forEach(function (ev) { form.addEventListener(ev, actualizar); });
      actualizar();
      return true;
    }

    function arrancar() {
      if (iniciar()) { return; }
      if (typeof MutationObserver === 'function') {
        var obs = new MutationObserver(function () { if (iniciar()) { obs.disconnect(); } });
        obs.observe(document.documentElement || document.body, { childList: true, subtree: true });
      }
    }

    if (document.readyState === 'loading') {
      document.addEventListener('DOMContentLoaded', arrancar);
    } else {
      arrancar();
    }
  } catch (e) {
    // el aviso es solo informativo: nunca debe romper el popup
  }
})();
</script>
HTML;
    }

    /**
     * v0.34.70: columna "VeriFactu" en el listado de pedidos (HikaShop ->
     * Pedidos -> Compras), con el estado del ÚLTIMO envío a la AEAT de cada
     * pedido visible. No existe un hook fiable de HikaShop para añadir una
     * columna real al listado (ver el comentario de v0.34.49 sobre el botón
     * "Acciones Veri*Factu", que tuvo el mismo problema) -- así que, igual
     * que ese botón, se hace con JavaScript sobre el HTML ya renderizado:
     *
     *  1. Localiza las casillas de selección de fila (name="cid[]"), que es
     *     el patrón estándar de los listados de administración de Joomla
     *     (también los usa HikaShop) -- de ahí se saca la fila (<tr>) y el
     *     order_id (value de la casilla) de cada pedido visible en la
     *     página, sin depender de ninguna URL ni columna concreta.
     *  2. Añade una celda de cabecera "VeriFactu" y una celda vacía (con un
     *     indicador de carga) en cada fila encontrada.
     *  3. Pide de una sola vez (un solo AJAX, no uno por fila) el estado de
     *     todos los order_id visibles a la tarea 'estados_pedidos', y
     *     rellena cada celda con la misma insignia de color que ya usa el
     *     registro de facturación (clases .estado.*).
     *
     * Si la página no tiene ninguna casilla cid[] (estructura de listado no
     * reconocida, ninguna fila visible, vista distinta a la esperada...) no
     * hace nada: el listado se queda exactamente como sin este script, sin
     * ningún error visible para el usuario.
     */
    private static function scriptColumnaEstadoListado(string $campoRectificar = 'verifactu_rectificar_diferencias'): string
    {
        // v0.34.84: HikaShop da a cada cabecera de campo personalizado la clase
        // hikashop_order_<nombre del campo>_title: es estable aunque el campo se
        // traduzca o cambie de etiqueta. El texto "Rectificar R1" queda de respaldo.
        $claseRectJs = json_encode('hikashop_order_' . $campoRectificar . '_title', JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
        $ajaxUrl = 'index.php?option=com_ajax&group=hikashop&plugin=verifactu&format=json&task=estados_pedidos';

        return <<<HTML
<style id="verifactu-col-estado-style">
.estado{padding:2px 7px;border-radius:10px;font-size:11px;display:inline-block}
.estado.aceptado,.estado.aceptado_con_errores{background:#e3f6e3;color:#1a7a1a}
.estado.pendiente,.estado.enviado{background:#fff6d9;color:#8a6d00}
.estado.excepcion,.estado.rechazado,.estado.error,.estado.error_tecnico,.estado.error_validacion,.estado.error_desglose_vacio,.estado.error_sustitucion_sin_nif,.estado.error_aviso_legal_no_aceptado,.estado.error_simplificada_exenta,.estado.error_concurrencia,.estado.error_id_destinatario,.estado.error_pais_destinatario{background:#fde3e3;color:#a01414}
</style>
<script id="verifactu-col-estado-script">
(function () {
  function listo(fn) {
    if (document.readyState !== 'loading') { fn(); } else { document.addEventListener('DOMContentLoaded', fn); }
  }

  function escapeHtml(s) {
    return (s === null || s === undefined ? '' : String(s)).replace(/[&<>"']/g, function (c) {
      return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c];
    });
  }

  function unwrap(payload) {
    if (payload && Array.isArray(payload.data)) { return payload.data[0] || {}; }
    if (payload && payload.data && typeof payload.data === 'object') { return payload.data; }
    return payload || {};
  }

  listo(function () {
    try {
      var casillas = document.querySelectorAll('input[type="checkbox"][name="cid[]"]');
      if (!casillas.length) { return; }

      var filas = [];
      casillas.forEach(function (casilla) {
        var id = parseInt(casilla.value, 10);
        var tr = casilla.closest('tr');
        if (!id || !tr) { return; }
        filas.push({ tr: tr, id: id });
      });
      if (!filas.length) { return; }

      var tabla = filas[0].tr.closest('table');
      if (!tabla) { return; }

      var inicios = ['Sustituye', 'Anular', 'Tipo de abono', 'Tipo abono', 'Motivo', 'Rectificar', 'Nueva base', 'Nuevo IVA', 'Nuevo recargo'];
      tabla.querySelectorAll('th').forEach(function (th) {
        var t = th.textContent.trim();
        var coincide = inicios.some(function (i) { return t.indexOf(i) === 0; });
        if (!coincide) { return; }
        th.style.width = '90px';
        th.style.maxWidth = '90px';
        th.style.whiteSpace = 'normal';
      });

      // v0.34.80/81: la columna del campo "Rectificar R1" del listado pasa a llamarse
      // "Rectificaciones R1" (en dos líneas, Rectifi-/caciones R1) y muestra solo el
      // número de rectificaciones vigentes de cada pedido (vacía si no tiene ninguna), o
      // "Anulado" si su factura está anulada (el campo del pedido en sí conserva su nombre: es el que se pone
      // en Sí para pedir una rectificación).
      var colRect = -1;
      var claseRect = {$claseRectJs};
      tabla.querySelectorAll('th').forEach(function (th) {
        if (colRect !== -1) { return; }
        var porClase = !!(th.classList && th.classList.contains(claseRect));
        var porTexto = th.textContent.trim().indexOf('Rectificar R1') === 0;
        if (!porClase && !porTexto) { return; }
        colRect = Array.prototype.indexOf.call(th.parentNode.children, th);
        var enlace = th.querySelector('a');
        (enlace || th).innerHTML = 'Rectifi-<br>caciones R1';
      });

      var filaCabecera = tabla.tHead ? tabla.tHead.rows[0] : tabla.rows[0];
      if (filaCabecera && !filaCabecera.querySelector('.verifactu-col-cabecera')) {
        var esTh = filaCabecera.parentNode && filaCabecera.parentNode.tagName === 'THEAD';
        var celda = document.createElement(esTh ? 'th' : 'td');
        celda.className = 'verifactu-col-cabecera';
        celda.textContent = 'VeriFactu';
        filaCabecera.appendChild(celda);
      }

      filas.forEach(function (f) {
        var td = document.createElement('td');
        td.className = 'verifactu-col-estado';
        td.setAttribute('data-verifactu-order', String(f.id));
        td.innerHTML = '<span class="estado pendiente">&hellip;</span>';
        f.tr.appendChild(td);
      });

      var qs = filas.map(function (f) { return 'order_ids[]=' + encodeURIComponent(f.id); }).join('&');
      fetch('{$ajaxUrl}&' + qs, { credentials: 'same-origin' })
        .then(function (r) { return r.json(); })
        .then(function (resp) {
          var d = unwrap(resp);
          var estados = (d && d.estados) || {};
          var rectificaciones = (d && d.rectificaciones) || {};
          var anuladas = (d && d.anuladas) || {};
          if (colRect >= 0) {
            filas.forEach(function (f) {
              var celdaRect = f.tr.children[colRect];
              if (!celdaRect) { return; }
              if (anuladas[f.id] || anuladas[String(f.id)]) {
                celdaRect.innerHTML = '<span style="color:#a01414;font-weight:600">Anulado</span>';
              } else {
                var nRect = parseInt(rectificaciones[f.id] || rectificaciones[String(f.id)] || 0, 10);
                celdaRect.textContent = nRect > 0 ? String(nRect) : '';
              }
            });
          }
          filas.forEach(function (f) {
            var td = tabla.querySelector('td[data-verifactu-order="' + f.id + '"]');
            if (!td) { return; }
            var estado = estados[f.id] || estados[String(f.id)];
            td.innerHTML = estado
              ? '<span class="estado ' + escapeHtml(estado) + '">' + escapeHtml(estado) + '</span>'
              : '<span class="estado muted" style="background:#eee;color:#888">sin envíos</span>';
          });
        })
        .catch(function () {
          // no bloquear el listado si el AJAX falla: se deja el indicador de
          // carga tal cual, el resto del listado sigue funcionando con
          // normalidad
        });
    } catch (e) {
      // no bloquear el listado por un fallo de este script
    }
  });
})();
</script>
HTML;
    }

    /**
     * Rectificaciones por diferencias aceptadas (R1/R5) del pedido. Un pedido
     * de abono (tipo_abono) es su propia factura rectificativa en HikaShop,
     * así que solo se listan las que no tienen otro documento.
     */
    /**
     * v0.34.80/81: para la columna "Rectificaciones R1" del listado de pedidos.
     * Devuelve, por pedido: el número de rectificaciones por diferencias (R1/R5)
     * vigentes (aceptadas por la AEAT y no anuladas, mismo criterio que
     * rectificativasDelPedido()) y si su factura está anulada (el último
     * movimiento de la factura del pedido, sin contar las rectificativas, es una
     * anulación aceptada).
     *
     * @param int[] $orderIds
     * @return array{rectificaciones:\stdClass, anuladas:\stdClass} propiedades dinámicas order_id => número / true
     */
    private function rectificacionesPorPedido(array $orderIds): array
    {
        $rect = new \stdClass();
        $anul = new \stdClass();
        $idsSql = implode(',', array_map('intval', $orderIds));
        if ($idsSql === '') {
            return ['rectificaciones' => $rect, 'anuladas' => $anul];
        }

        try {
            $db = $this->db();
            $query = $db->getQuery(true)
                ->select(['id', 'order_id', 'invoice_number', 'tipo_registro', 'tipo_factura', 'tipo_abono', 'factura_rectificada', 'estado_envio'])
                ->from('#__hikashop_verifactu_registro')
                ->where('order_id IN (' . $idsSql . ')')
                ->order('id ASC');
            $db->setQuery($query);
            $filas = $db->loadObjectList() ?: [];

            $aceptado = static fn($f): bool => in_array((string) $f->estado_envio, ['aceptado', 'aceptado_con_errores'], true);
            $esRectificativa = static fn($f): bool => (string) $f->tipo_registro === 'alta'
                && in_array((string) $f->tipo_factura, ['R1', 'R5'], true)
                && $f->tipo_abono === null
                && $f->factura_rectificada !== null
                && (string) $f->factura_rectificada !== (string) $f->invoice_number;

            $porPedido = [];
            foreach ($filas as $f) {
                $porPedido[(int) $f->order_id][] = $f;
            }

            foreach ($porPedido as $oid => $regs) {
                $numerosRect = [];
                foreach ($regs as $f) {
                    if ($esRectificativa($f)) {
                        $numerosRect[(string) $f->invoice_number] = true;
                    }
                }
                $anuladasNum = [];
                foreach ($regs as $f) {
                    if ((string) $f->tipo_registro === 'anulacion' && $aceptado($f)) {
                        $anuladasNum[(string) $f->invoice_number] = true;
                    }
                }

                $n = 0;
                foreach ($regs as $f) {
                    if ($esRectificativa($f) && $aceptado($f) && empty($anuladasNum[(string) $f->invoice_number])) {
                        $n++;
                    }
                }
                if ($n > 0) {
                    $rect->{(string) $oid} = $n;
                }

                // factura del pedido anulada: el último movimiento que no es de una
                // rectificativa es una anulación aceptada (una R1 anulada no cuenta).
                $anulada = false;
                foreach ($regs as $f) {
                    if ((string) $f->tipo_registro === 'anulacion') {
                        if (!isset($numerosRect[(string) $f->invoice_number])) {
                            $anulada = $aceptado($f);
                        }
                    } elseif ((string) $f->tipo_registro === 'alta' && !$esRectificativa($f)) {
                        $anulada = false;
                    }
                }
                if ($anulada) {
                    $anul->{(string) $oid} = true;
                }
            }
        } catch (\Throwable $e) {
            self::log('No se pudo calcular las rectificaciones de los pedidos del listado: ' . $e->getMessage(), \Joomla\CMS\Log\Log::WARNING);
        }

        return ['rectificaciones' => $rect, 'anuladas' => $anul];
    }

    private function rectificativasDelPedido(int $orderId, int $id = 0): array
    {
        $db = $this->db();
        $aceptado = $db->quote('aceptado') . ',' . $db->quote('aceptado_con_errores');
        $query = $db->getQuery(true)
            ->select('*')
            ->from('#__hikashop_verifactu_registro')
            ->where('order_id = ' . $orderId)
            ->where('tipo_registro = ' . $db->quote('alta'))
            ->where('tipo_factura IN (' . $db->quote('R1') . ',' . $db->quote('R5') . ')')
            ->where('tipo_abono IS NULL')
            ->where('factura_rectificada IS NOT NULL')
            ->where('factura_rectificada <> invoice_number')
            ->where('estado_envio IN (' . $aceptado . ')')
            ->where(
                'invoice_number NOT IN (SELECT a.invoice_number FROM #__hikashop_verifactu_registro AS a'
                . ' WHERE a.order_id = ' . $orderId . ' AND a.tipo_registro = ' . $db->quote('anulacion')
                . ' AND a.estado_envio IN (' . $aceptado . '))'
            )
            ->order('id ASC');
        if ($id > 0) {
            $query->where('id = ' . $id);
        }
        $db->setQuery($query);

        return $db->loadObjectList() ?: [];
    }

    private static function importeEs(?float $valor): string
    {
        return $valor === null ? '' : number_format($valor, 2, ',', '.') . ' €';
    }

    private static function fechaEs(string $fecha): string
    {
        $t = strtotime($fecha);

        return $t ? date('d/m/Y', $t) : $fecha;
    }

    /**
     * task=rectificativa -- la factura rectificativa por diferencias como
     * documento: emisor, destinatario, factura rectificada, solo la
     * diferencia y el QR de la propia R1. La factura del pedido sigue
     * siendo la original.
     */
    private function buildRectificativaHtml(int $orderId, int $id): string
    {
        $e = static fn($v): string => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');

        $filas = ($orderId > 0 && $id > 0) ? $this->rectificativasDelPedido($orderId, $id) : [];
        if (!$filas) {
            return '<!doctype html><html lang="es"><head><meta charset="utf-8"></head><body style="font-family:Arial,sans-serif">'
                . '<p>No hay ninguna rectificación por diferencias aceptada con esos datos.</p></body></html>';
        }
        $r = $filas[0];

        $db = $this->db();
        $db->setQuery(
            $db->getQuery(true)
                ->select(['invoice_date', 'base_imponible'])
                ->from('#__hikashop_verifactu_registro')
                ->where('order_id = ' . $orderId)
                ->where('invoice_number = ' . $db->quote((string) $r->factura_rectificada))
                ->where('tipo_registro = ' . $db->quote('alta'))
                ->where('estado_envio IN (' . $db->quote('aceptado') . ',' . $db->quote('aceptado_con_errores') . ')')
                ->order('id ASC')
                ->setLimit(1)
        );
        $original = $db->loadObject();

        // base de la factura tras esta rectificación (original + diferencias hasta esta)
        $baseResultante = null;
        if ($original && $original->base_imponible !== null) {
            $baseResultante = (float) $original->base_imponible;
            foreach ($this->rectificativasDelPedido($orderId) as $dif) {
                if ((string) $dif->factura_rectificada === (string) $r->factura_rectificada && (int) $dif->id <= (int) $r->id) {
                    $baseResultante += (float) $dif->base_imponible;
                }
            }
        }

        $order = null;
        try {
            if (!function_exists('hikashop_get')) {
                require_once JPATH_ADMINISTRATOR . '/components/com_hikashop/helpers/helper.php';
            }
            $order = hikashop_get('class.order')->loadFullOrder($orderId, true, false);
        } catch (\Throwable $ex) {
            self::log('No se pudo cargar el pedido ' . $orderId . ' para la factura rectificativa: ' . $ex->getMessage(), \Joomla\CMS\Log\Log::WARNING);
        }

        $destinatario = '';
        if ($order && !empty($order->billing_address)) {
            try {
                $destinatario = hikashop_get('class.address')->displayAddress($order->fields, $order->billing_address, 'order');
            } catch (\Throwable $ex) {
                $destinatario = '';
            }
            $nifCliente = trim((string) ($order->billing_address->address_vat ?? ''));
            if ($nifCliente !== '' && stripos(strip_tags($destinatario), $nifCliente) === false) {
                $destinatario .= '<br/>NIF: ' . $e($nifCliente);
            }
        }

        $descripcion = '';
        if (!empty($r->xml_enviado) && preg_match('#<(?:[A-Za-z0-9]+:)?DescripcionOperacion>(.*?)</#s', (string) $r->xml_enviado, $m)) {
            $descripcion = html_entity_decode($m[1], ENT_QUOTES | ENT_XML1, 'UTF-8');
        }

        $titulo = $r->tipo_factura === 'R5' ? 'Factura rectificativa simplificada' : 'Factura rectificativa';

        // v0.34.76: mismo diseño que la factura del pedido (plantilla de factura de
        // HikaShop): mismos textos traducidos, logo y dirección de la tienda de la
        // configuración, bloques con recuadro y "Información adicional" con los
        // campos del pedido (p. ej. el comentario). No se modifica ningún dato.
        // v0.34.79: el resto del documento está en español, así que los títulos solo se
        // toman de la traducción de HikaShop cuando el idioma del back-end es el español;
        // con otro idioma se usa el texto español fijo (el documento no queda mezclado).
        $idiomaEs = false;
        try {
            $idiomaEs = stripos((string) \Joomla\CMS\Factory::getApplication()->getLanguage()->getTag(), 'es') === 0;
        } catch (\Throwable $ex) {
            $idiomaEs = false;
        }
        $t = static function (string $clave, string $porDefecto) use ($idiomaEs): string {
            if (!$idiomaEs) {
                return $porDefecto;
            }
            try {
                $v = \Joomla\CMS\Language\Text::_($clave);
            } catch (\Throwable $ex) {
                $v = $clave;
            }

            return ($v === '' || $v === $clave) ? $porDefecto : $v;
        };
        $fd = static function (string $fecha): string {
            $ts = strtotime($fecha);

            return $ts ? date('d-m-Y', $ts) : $fecha;
        };
        $pct = static fn($v): string => rtrim(rtrim(number_format((float) $v, 2, ',', '.'), '0'), ',');

        $config = function_exists('hikashop_config') ? hikashop_config() : null;

        // logo (igual que la factura del pedido)
        $logo = '';
        if ($config) {
            $ruta = trim((string) $config->get('image_address_path', ''));
            if ($ruta !== '') {
                if (function_exists('hikashop_cleanURL')) {
                    $ruta = hikashop_cleanURL($ruta);
                }
                $estilo = trim(strip_tags((string) $config->get('img_style_css', '')));
                $logo = '<div class="hika_invoice_img"><img src="' . $e($ruta) . '" style="' . $e($estilo) . '"></div>';
            }
        }

        // emisor: la dirección de la tienda de HikaShop, como en la factura del pedido
        $emisor = '';
        if ($config) {
            $store = str_replace(["\r\n", "\n", "\r"], '<br/>', (string) $config->get('store_address', ''));
            $emisor = $t($store, $store);
        }
        if (trim(strip_tags($emisor)) === '') {
            $emisor = $e($this->params->get('nombre_emisor'));
            $direccionTienda = $this->getHikaShopBillingData()['direccion'];
            if ($direccionTienda !== '') {
                $emisor .= '<br/>' . nl2br($e($direccionTienda));
            }
        }
        $normaliza = static fn(string $s): string => preg_replace('/[^A-Z0-9]/', '', strtoupper(strip_tags(str_replace('<br/>', ' ', $s))));
        // v0.34.79: la razón social sale de la dirección de la tienda; si esa dirección no
        // lleva el nombre configurado en el plugin, se antepone para que nunca falte.
        $nombreEmisor = trim((string) $this->params->get('nombre_emisor', ''));
        $normalizaNombre = static fn(string $s): string => preg_replace('/[^\p{L}\p{N}]/u', '', mb_strtolower(strip_tags(str_replace('<br/>', ' ', $s)), 'UTF-8'));
        if ($nombreEmisor !== '' && strpos($normalizaNombre($emisor), $normalizaNombre($nombreEmisor)) === false) {
            $emisor = $e($nombreEmisor) . '<br/>' . $emisor;
        }
        if ($r->nif_emisor && strpos($normaliza($emisor), $normaliza((string) $r->nif_emisor)) === false) {
            $emisor .= '<br/>NIF: ' . $e($r->nif_emisor);
        }

        // "Información adicional": los mismos campos del pedido que muestra la factura (comentario, etc.)
        $adicional = '';
        if ($order) {
            try {
                $fieldsClass = hikashop_get('class.field');
                $campos = $fieldsClass->getFields('display:invoice=1', $order, 'order');
                if (!empty($campos)) {
                    $filas = '';
                    foreach ($campos as $nombreCampo => $campo) {
                        $campo->currentElement = $order;
                        $filas .= '<tr><td class="key">' . $fieldsClass->getFieldName($campo) . '</td><td>'
                            . $fieldsClass->show($campo, @$order->$nombreCampo) . '</td></tr>';
                    }
                    $adicional = '<fieldset class="adicional"><legend>' . $e($t('ADDITIONAL_INFORMATION', 'Información adicional')) . '</legend>'
                        . '<table class="campos" width="100%">' . $filas . '</table></fieldset>';
                }
            } catch (\Throwable $ex) {
                $adicional = '';
            }
        }

        $exenta = $r->tipo_iva === null && $r->cuota_iva === null;
        $llevaRecargo = $r->recargo_equivalencia !== null && (float) $r->recargo_equivalencia != 0.0;
        $filaIva = $exenta
            ? '<tr><td class="key">IVA</td><td>Exenta (' . $e($r->operacion_exenta) . ')</td></tr>'
            : '<tr><td class="key">IVA ' . $e($pct($r->tipo_iva)) . '%</td><td>' . $e(self::importeEs((float) $r->cuota_iva)) . '</td></tr>';
        $filaRecargo = $llevaRecargo
            ? '<tr><td class="key">Recargo Equivalencia ' . $e($pct($r->tipo_recargo)) . '%</td><td>' . $e(self::importeEs((float) $r->recargo_equivalencia)) . '</td></tr>'
            : '';

        $resultado = $baseResultante !== null
            ? '<p>Base imponible de la factura ' . $e($r->factura_rectificada) . ' tras esta rectificación: <strong>' . $e(self::importeEs($baseResultante)) . '</strong></p>'
            : '';

        $qr = '';
        if (!empty($r->qr_url)) {
            $qr = '<div class="qr"><img src="' . $e(trim((string) $r->qr_url)) . '" alt="QR VeriFactu"/>'
                . '<p>Factura verificable en la sede electrónica de la AEAT<br/>VERI*FACTU</p></div>';
        }

        $fechaOriginal = $original ? ' de fecha ' . $e($fd((string) $original->invoice_date)) : '';
        $pedido = $order ? $e($order->order_number) : (string) $orderId;

        $lblFecha = $e($t('DATE', 'Fecha'));
        $lblFactura = $e($t('INVOICE', 'Factura'));
        $lblFacturacion = $e($t('HIKASHOP_BILLING_ADDRESS', 'Dirección de facturación'));
        $lblLista = $e($t('PRODUCT_LIST', 'Lista de productos'));
        $lblImprimir = $e($t('HIKA_PRINT', 'Imprimir'));

        return <<<HTML
<!doctype html><html lang="es"><head><meta charset="utf-8">
<title>{$e($titulo)} {$e($r->invoice_number)}</title>
<style>
body{font-family:Roboto,"Helvetica Neue",Arial,sans-serif;margin:24px;color:#222;font-size:12px;line-height:1.4}
h1{font-size:24px;font-weight:400;text-align:center;margin:10px 0 36px;color:#1f2d3d}
fieldset{border:1px solid #ccc;padding:8px 14px 12px;margin:0 0 14px}
legend{width:auto;border:0;font-size:15px;padding:0 6px;margin:0;color:#1f2d3d}
table.lista{width:100%;border-collapse:collapse}
table.lista th{text-align:left;font-size:14px;padding:8px;border-bottom:2px solid #b8b8b8}
table.lista td{padding:7px 8px;text-align:left}
table.lista th:last-child,table.lista td:last-child{text-align:left;width:14%}
table.lista tbody tr:nth-child(odd){background:#f2f2f2}
table.lista tr.sub td{border-top:2px solid #b8b8b8}
td.key{text-align:right;color:#1d3557}
table.campos td{padding:4px 8px}
.datos{float:right;width:15em;padding-top:12px}
.qr{width:40mm;text-align:center;margin-top:18px}
.qr img{width:40mm;height:40mm}
.qr p{font-size:9px;margin:4px 0 0}
#imprimir{float:right}
@media print{#imprimir{display:none}body{margin:0}fieldset,tr{page-break-inside:avoid}}
</style></head><body>
<a id="imprimir" href="#" onclick="document.getElementById('imprimir').style.visibility='hidden';window.focus();window.print();return false;">{$lblImprimir}</a>
<h1>{$e($titulo)}</h1>
{$logo}
<div class="datos">
  {$lblFecha}: {$e($fd((string) $r->invoice_date))}<br/>
  {$lblFactura}: {$e($r->invoice_number)}<br/>
  Pedido: {$pedido}
</div>
<p>{$emisor}</p>
<br/><br/>
<fieldset><legend>{$lblFacturacion}</legend>{$destinatario}</fieldset>
<p>Rectifica la factura <strong>{$e($r->factura_rectificada)}</strong>{$fechaOriginal}, por diferencias: los importes de este documento son la diferencia que se suma a los de esa factura.</p>
<p>{$e($descripcion)}</p>
<fieldset><legend>{$lblLista}</legend>
<table class="lista">
  <thead><tr><th>Concepto</th><th>Base imponible</th></tr></thead>
  <tbody>
    <tr><td>Rectificación de la factura {$e($r->factura_rectificada)}</td><td>{$e(self::importeEs((float) $r->base_imponible))}</td></tr>
    <tr class="sub"><td class="key">Subtotal</td><td>{$e(self::importeEs((float) $r->base_imponible))}</td></tr>
    {$filaIva}
    {$filaRecargo}
    <tr><td class="key">Total</td><td><strong>{$e(self::importeEs((float) $r->total_factura))}</strong></td></tr>
  </tbody>
</table>
</fieldset>
{$resultado}
{$adicional}
{$qr}
</body></html>
HTML;
    }

    /**
     * v0.34.92: aviso de fin de año sobre los «Número inicial manual».
     *
     * Del 20 al 31 de diciembre, si la numeración propia está activada y el propietario todavía
     * no ha pulsado ninguno de los dos botones de fin de año de la configuración del plugin:
     *  - se le envía UN email (al mismo destinatario que los avisos de caducidad del
     *    certificado: «Email para avisos de caducidad», o el correo del sitio Joomla si está
     *    vacío), diciéndole que la acción debe hacerse después de la última factura del año;
     *  - cada vez que un superadministrador abre el panel de inicio de Joomla se muestra un
     *    mensaje que indica que hay que tomar una acción en este plugin.
     *
     * Un marcador diario en tmp evita consultar la base de datos en cada petición.
     */
    private function avisarFinDeAnio($app): void
    {
        require_once __DIR__ . '/src/VerifactuFinAnio.php';

        if (!VerifactuFinAnio::ventanaActiva()
            || (string) $this->params->get('numeracion_propia_activa', '0') !== '1') {
            return;
        }

        $esAdmin = $app->isClient('administrator');
        $enPanelInicio = $esAdmin && $app->input->getCmd('option') === 'com_cpanel';
        $marca = rtrim((string) $app->get('tmp_path', JPATH_ROOT . '/tmp'), '/\\') . '/verifactu_finanio_' . date('Ymd');
        $intentarEmail = !is_file($marca);
        if (!$intentarEmail && !$enPanelInicio) {
            return;
        }

        $db = $this->db();
        $anio = date('Y');
        $estado = VerifactuFinAnio::estado($db, $anio);
        if ($estado['gestionado']) {
            @touch($marca);

            return;
        }

        if ($enPanelInicio) {
            // usuario de la sesión (como en onAjaxVerifactu): en onAfterInitialise la identidad
            // de la aplicación puede no estar cargada todavía
            $user = \Joomla\CMS\Factory::getUser();
            if ($user && !$user->guest && $user->authorise('core.admin')) {
                $db->setQuery(
                    $db->getQuery(true)
                        ->select($db->quoteName('extension_id'))
                        ->from($db->quoteName('#__extensions'))
                        ->where($db->quoteName('type') . ' = ' . $db->quote('plugin'))
                        ->where($db->quoteName('folder') . ' = ' . $db->quote('hikashop'))
                        ->where($db->quoteName('element') . ' = ' . $db->quote('verifactu')),
                    0,
                    1
                );
                $extensionId = (int) $db->loadResult();
                $enlace = $extensionId > 0
                    ? 'index.php?option=com_plugins&task=plugin.edit&extension_id=' . $extensionId
                    : 'index.php?option=com_plugins&view=plugins&filter[search]=verifactu';

                $app->enqueueMessage(
                    '<strong>VeriFactu: hay que tomar una acción antes de que acabe ' . $anio . '.</strong> '
                    . 'Cuando hayas emitido la <strong>última factura de ' . $anio . '</strong>, abre '
                    . '<a href="' . htmlspecialchars($enlace, ENT_QUOTES, 'UTF-8') . '">la configuración del plugin Hikashop Verifactu</a>, '
                    . 'bloque «Copia de seguridad del registro» → «Fin de año», y pulsa «Vaciar los campos» '
                    . '(' . ((int) $anio + 1) . ' empieza en 0001) o «Dejar el último número de ' . $anio . '» '
                    . '(la numeración continúa). El mensaje desaparece al hacerlo.',
                    'warning'
                );
            }
        }

        if (!$intentarEmail) {
            return;
        }
        @touch($marca);
        if ($estado['email']) {
            return;
        }

        $destinatario = trim((string) $this->params->get('certificado_aviso_email', ''));
        if ($destinatario === '') {
            $destinatario = trim((string) \Joomla\CMS\Factory::getConfig()->get('mailfrom', ''));
        }
        if (!filter_var($destinatario, FILTER_VALIDATE_EMAIL)) {
            self::log('Aviso de fin de año: no se pudo enviar porque no hay un email destinatario válido.', \Joomla\CMS\Log\Log::WARNING);

            return;
        }

        if (!VerifactuFinAnio::reservarEmail($db, $anio)) {
            return;
        }

        $siguiente = (int) $anio + 1;
        $url = \Joomla\CMS\Uri\Uri::root() . 'administrator/index.php?option=com_plugins&view=plugins&filter%5Bsearch%5D=verifactu';
        $cuerpo = '<p>Este es un aviso de <strong>HikaShop VeriFactu</strong> sobre la numeración de las facturas de <strong>' . $siguiente . '</strong>.</p>'
            . '<p>El plugin lleva un contador por serie y año. Los campos «Número inicial manual» (F1, F2, F3 y R) los usa para cualquier serie y año que todavía no tenga contador, así que influyen en la primera factura de cada serie de ' . $siguiente . '.</p>'
            . '<p><strong>Esta acción debe hacerse después de emitir la última factura de ' . $anio . '</strong> y antes de la primera de ' . $siguiente . '.</p>'
            . '<p>Entra en <a href="' . htmlspecialchars($url, ENT_QUOTES, 'UTF-8') . '">Complementos &gt; Hikashop Verifactu plugin</a>, '
            . 'bloque «Copia de seguridad del registro» &rarr; «Fin de año», y elige:</p>'
            . '<ul>'
            . '<li><strong>Vaciar los campos</strong>: ' . $siguiente . ' empieza en ' . substr((string) $siguiente, 2) . '-0001 (o ' . substr((string) $siguiente, 2) . 'FS-0001, ' . substr((string) $siguiente, 2) . 'F3-0001, ' . substr((string) $siguiente, 2) . 'R-0001).</li>'
            . '<li><strong>Dejar el último número de ' . $anio . '</strong>: cada campo se rellena con el último número de ' . $anio . ' de su serie, sin sumar 1, y la numeración continúa (si la última fue la ' . substr($anio, 2) . '-0320, la primera de ' . $siguiente . ' será la ' . substr((string) $siguiente, 2) . '-0321).</li>'
            . '</ul>'
            . '<p>Si no haces nada y los campos tienen un valor antiguo, ' . $siguiente . ' empezará en ese número más uno.</p>'
            . '<p>En el panel de inicio de Joomla verás un mensaje hasta que lo hagas. Elige el criterio que corresponda a tu numeración de facturas; si tienes dudas, consúltalo con tu asesor.</p>'
            . '<p>El aviso se ha generado automáticamente por HikaShop VeriFactu y se envía a la dirección de «Email para avisos de caducidad» (o al correo del sitio si está vacía).</p>';

        $mailer = \Joomla\CMS\Factory::getMailer();
        $mailer->addRecipient($destinatario);
        $mailer->setSubject('Aviso: acción de fin de año en HikaShop VeriFactu (numeración de ' . $siguiente . ')');
        $mailer->isHtml(true);
        $mailer->setBody($cuerpo);

        if (!$mailer->send()) {
            VerifactuFinAnio::liberarEmail($db, $anio);
            self::log('Aviso de fin de año: error enviando el email a ' . $destinatario . '.', \Joomla\CMS\Log\Log::WARNING);

            return;
        }

        self::log('Aviso de fin de año enviado a ' . $destinatario . ' (numeración de ' . $siguiente . ').');
    }

    /**
     * Página del pedido: una línea "Factura rectificativa" bajo el número de
     * factura, con un enlace por cada rectificación por diferencias aceptada.
     */
    private static function scriptRectificativasPedido(int $orderId, array $rectificativas): string
    {
        $base = 'index.php?option=com_ajax&group=hikashop&plugin=verifactu&format=raw&task=rectificativa&order_id=' . $orderId . '&id=';
        $enlaces = [];
        foreach ($rectificativas as $r) {
            $enlaces[] = '<a href="' . htmlspecialchars($base . (int) $r->id, ENT_QUOTES, 'UTF-8') . '" target="_blank">'
                . htmlspecialchars((string) $r->invoice_number, ENT_QUOTES, 'UTF-8') . '</a>';
        }
        $html = json_encode(implode(', ', $enlaces), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);

        return <<<HTML
<script id="verifactu-rectificativas-script">
(function () {
  function insertar() {
    var fila = document.querySelector('tr.hikashop_order_invoicenumber');
    if (!fila || document.querySelector('tr.verifactu_rectificativas')) { return; }
    var tr = document.createElement('tr');
    tr.className = 'verifactu_rectificativas';
    tr.innerHTML = '<td class="key"><label>Factura rectificativa</label></td><td></td>';
    tr.lastChild.innerHTML = {$html};
    fila.parentNode.insertBefore(tr, fila.nextSibling);
  }
  if (document.readyState === 'loading') { document.addEventListener('DOMContentLoaded', insertar); } else { insertar(); }
  // el bloque general se recarga por AJAX al editarlo
  new MutationObserver(insertar).observe(document.body, { childList: true, subtree: true });
})();
</script>
HTML;
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

        // solo el aviso más cercano: en una primera comprobación a 1 mes de la
        // caducidad, un correo y no cuatro; los anteriores se dan por enviados
        $aplicables = [];
        foreach ($thresholds as $months) {
            $trigger = $data['expiry']->modify('-' . $months . ' months');
            if ($now >= $trigger && $now < $data['expiry']) {
                $aplicables[] = $months;
            }
        }
        if (!$aplicables) {
            return;
        }
        $anteriores = array_diff($aplicables, [min($aplicables)]);

        foreach ([min($aplicables)] as $months) {

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

            foreach ($anteriores as $anterior) {
                $db->setQuery(
                    'INSERT IGNORE INTO ' . $table . ' (' . $db->quoteName('huella') . ', ' . $db->quoteName('umbral_meses') . ', ' . $db->quoteName('fecha_envio') . ') VALUES ('
                    . $db->quote($data['fingerprint']) . ', ' . (int) $anterior . ', ' . $db->quote($now->format('Y-m-d H:i:s')) . ')'
                );
                $db->execute();
            }

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
        'Nueva base',
        'Nuevo IVA %',
        'Nuevo recargo %',
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
                'order_user_id',
                'order_currency_id',
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

            // v0.34.69: se ha llegado hasta aquí sin excepción, así que este
            // intento ha quedado reflejado en #__hikashop_verifactu_registro
            // (aceptado o con un estado de error propio) -- eso es lo que ve
            // avisarEnviosFallidosRecientes(). Cualquier incidencia previa de
            // ESTE pedido (una excepción de un intento anterior que no llegó
            // a guardarse) ya no aporta nada por encima de ese registro real:
            // se retira para no duplicar el aviso.
            $this->borrarIncidencias($db, (int) $order->order_id);
        } catch (\Throwable $e) {
            // No dejar que un fallo en VeriFactu bloquee el cambio de estado del pedido:
            // se registra el error y queda para revisión/reintento manual.
            self::log('EXCEPCIÓN en pedido ' . $order->order_id . ': ' . $e->getMessage() . ' en ' . $e->getFile() . ':' . $e->getLine(), \Joomla\CMS\Log\Log::ERROR);

            // v0.34.69: esta excepción ha cortado el envío ANTES de que
            // registrarFactura()/rectificarPorDiferencias()/anularFactura()
            // pudieran guardar una fila en #__hikashop_verifactu_registro, así
            // que sin esto no queda ningún rastro visible para el
            // administrador -- solo el log de Joomla, que es precisamente lo
            // que se reportó como problema. Se guarda en la tabla aparte de
            // incidencias (no en el registro principal, que exige columnas
            // NOT NULL -- número de factura, fecha, NIF emisor -- que en este
            // punto pueden no existir todavía) para que avisarEnviosFallidosRecientes()
            // la recoja igualmente.
            try {
                $db->insertObject('#__hikashop_verifactu_incidencias', (object) [
                    'order_id' => (int) $order->order_id,
                    'mensaje' => mb_substr($e->getMessage(), 0, 1000),
                    'created' => date('Y-m-d H:i:s'),
                ]);
            } catch (\Throwable $e2) {
                self::log('No se pudo guardar la incidencia del pedido ' . $order->order_id . ': ' . $e2->getMessage(), \Joomla\CMS\Log\Log::WARNING);
            }

            return;
        }

        if (!in_array($resultado['estado'], ['aceptado', 'aceptado_con_errores'], true)) {
            self::log('Rechazado/erróneo en pedido ' . $order->order_id . ': ' . json_encode($resultado, JSON_INVALID_UTF8_SUBSTITUTE), \Joomla\CMS\Log\Log::WARNING);

            // que el administrador que guarda el pedido lo vea, no solo el log
            $estado = (string) $resultado['estado'];
            $app = \Joomla\CMS\Factory::getApplication();
            if ($app->isClient('administrator') && (strpos($estado, 'error_') === 0 || $estado === 'rechazado')) {
                $errores = array_filter((array) ($resultado['errores'] ?? []), 'is_string');
                $app->enqueueMessage(
                    'VeriFactu, pedido ' . htmlspecialchars((string) ($order->order_number ?? $order->order_id), ENT_QUOTES, 'UTF-8') . ': '
                    . htmlspecialchars($errores ? implode(' ', $errores) : $estado, ENT_QUOTES, 'UTF-8'),
                    'warning'
                );
            }
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
            . '<p style="font-size:9px; margin-top:4px; max-width:35mm;">Factura verificable en la sede electrónica de la AEAT — VERI*FACTU</p>'
            . '</div>';
    }
}
