<?php
defined('_JEXEC') or die;

require_once __DIR__ . '/src/VerifactuLibraryBridge.php';

class plgHikashopVerifactu extends \Joomla\CMS\Plugin\CMSPlugin
{
    /**
     * Registra el logger de Joomla para la categoría 'verifactu' (sin esto,
     * \Joomla\CMS\Log\Log::add() no escribe nada a ningún archivo -- era el bug por el que
     * no aparecía nada en administrator/logs/). Además escribe una copia
     * directa a un archivo dentro del propio plugin, como respaldo fiable
     * para depurar mientras probamos la instalación.
     */
    private static function log(string $mensaje, int $nivel = 2): void
    {
        static $loggerRegistrado = false;
        if (!$loggerRegistrado) {
            \Joomla\CMS\Log\Log::addLogger(['text_file' => 'verifactu.php'], \Joomla\CMS\Log\Log::ALL, ['verifactu']);
            $loggerRegistrado = true;
        }

        \Joomla\CMS\Log\Log::add($mensaje, $nivel, 'verifactu');

        // Respaldo directo, independiente de la configuración de logs de Joomla
        @file_put_contents(
            __DIR__ . '/debug.log',
            '[' . date('Y-m-d H:i:s') . '] ' . $mensaje . "\n",
            FILE_APPEND
        );
    }


    /**
     * Herramientas de la Declaración Responsable integradas en este mismo plugin.
     * Los datos se cargan inicialmente desde la configuración fiscal del plugin
     * y desde la dirección de tienda de HikaShop; después quedan editables en
     * la configuración del plugin.
     */
    private function getHikaShopBillingData(): array
    {
        $out = [
            'nombre' => '', 'nif' => '', 'direccion' => '', 'cp' => '',
            'localidad' => '', 'provincia' => '', 'pais' => 'España'
        ];

        // Fuente principal: configuración de HikaShop.
        try {
            if (function_exists('hikashop_config')) {
                $config = hikashop_config();
                $get = static function ($config, array $keys): string {
                    foreach ($keys as $key) {
                        $value = trim((string) $config->get($key, ''));
                        if ($value !== '') return $value;
                    }
                    return '';
                };

                $out['nombre'] = $get($config, ['store_name', 'store_company', 'company_name']);
                $out['nif'] = $get($config, [
                    'store_tax_number', 'store_vat', 'store_vat_number',
                    'store_tax_id', 'tax_number', 'vat_number'
                ]);
                $out['direccion'] = $get($config, ['store_address']);
                $out['cp'] = $get($config, ['store_postcode', 'store_post_code', 'store_zip', 'store_zipcode']);
                $out['localidad'] = $get($config, ['store_city', 'store_locality', 'store_town']);
                $out['provincia'] = $get($config, ['store_state', 'store_province', 'store_region']);
                $out['pais'] = $get($config, ['store_country', 'store_country_name']) ?: 'España';
            }
        } catch (\Throwable $e) {
            self::log('No se pudo leer la configuración de HikaShop: ' . $e->getMessage(), \Joomla\CMS\Log\Log::WARNING);
        }

        // Fallback directo a la tabla de configuración de HikaShop por si
        // hikashop_config() no expone alguna de las claves en esta versión.
        try {
            $db = $this->db();
            $table = $db->quoteName('#__hikashop_config');
            $query = $db->getQuery(true)
                ->select($db->quoteName('config_name') . ', ' . $db->quoteName('config_value'))
                ->from($table)
                ->where($db->quoteName('config_name') . ' IN (' . implode(',', array_map([$db, 'quote'], [
                    'store_name','store_company','company_name','store_tax_number','store_vat',
                    'store_vat_number','store_tax_id','tax_number','vat_number','store_address',
                    'store_postcode','store_post_code','store_zip','store_zipcode','store_city',
                    'store_locality','store_town','store_state','store_province','store_region',
                    'store_country','store_country_name'
                ])) . ')');
            $db->setQuery($query);
            $rows = $db->loadAssocList();
            $cfg = [];
            foreach ((array) $rows as $row) {
                $cfg[$row['config_name']] = trim((string) $row['config_value']);
            }
            $pick = static function(array $cfg, array $keys): string {
                foreach ($keys as $key) {
                    if (!empty($cfg[$key])) return $cfg[$key];
                }
                return '';
            };
            foreach ([
                'nombre'=>['store_name','store_company','company_name'],
                'nif'=>['store_tax_number','store_vat','store_vat_number','store_tax_id','tax_number','vat_number'],
                'direccion'=>['store_address'],
                'cp'=>['store_postcode','store_post_code','store_zip','store_zipcode'],
                'localidad'=>['store_city','store_locality','store_town'],
                'provincia'=>['store_state','store_province','store_region'],
                'pais'=>['store_country','store_country_name']
            ] as $field => $keys) {
                if ($out[$field] === '' || ($field === 'pais' && $out[$field] === 'España')) {
                    $value = $pick($cfg, $keys);
                    if ($value !== '') $out[$field] = $value;
                }
            }
        } catch (\Throwable $e) {
            // Algunas instalaciones/versiones pueden no exponer esta tabla.
        }

        // Si el NIF no está almacenado por HikaShop, el campo fiscal ya
        // existente del plugin es un respaldo válido para el titular.
        if ($out['nif'] === '') {
            $out['nif'] = trim((string) $this->params->get('nif_emisor', ''));
        }
        if ($out['nombre'] === '') {
            $out['nombre'] = trim((string) $this->params->get('nombre_emisor', ''));
        }

        // El store_address de HikaShop suele ser una dirección multilínea.
        // Si no existen campos separados, intentamos extraer CP/localidad de
        // la última línea sin alterar la dirección completa.
        if (($out['cp'] === '' || $out['localidad'] === '') && $out['direccion'] !== '') {
            $lines = preg_split('/\R+/', trim($out['direccion']));
            $last = trim((string) end($lines));
            if ($out['cp'] === '' && preg_match('/\b(\d{5})\b/', $last, $m)) {
                $out['cp'] = $m[1];
            }
            if ($out['localidad'] === '' && preg_match('/\b\d{5}\b\s+(.+)$/u', $last, $m)) {
                $out['localidad'] = trim($m[1]);
            }
        }

        return $out;
    }

    private function getDeclarationData(): array
    {
        $billing = $this->getHikaShopBillingData();

        $name = trim((string) $this->params->get('declaracion_nombre', '')) ?: $billing['nombre'];
        $nif = trim((string) $this->params->get('declaracion_nif', '')) ?: $billing['nif'];
        $address = trim((string) $this->params->get('declaracion_direccion', '')) ?: $billing['direccion'];
        $cp = trim((string) $this->params->get('declaracion_cp', '')) ?: $billing['cp'];
        $localidad = trim((string) $this->params->get('declaracion_localidad', '')) ?: $billing['localidad'];
        $provincia = trim((string) $this->params->get('declaracion_provincia', '')) ?: $billing['provincia'];
        $pais = trim((string) $this->params->get('declaracion_pais', '')) ?: $billing['pais'];
        $lugar = trim((string) $this->params->get('declaracion_lugar', '')) ?: $localidad;
        $fecha = trim((string) $this->params->get('declaracion_fecha', '')) ?: date('d/m/Y');
        $version = trim((string) $this->params->get('declaracion_version', '0.30.1'));

        return compact('name','nif','address','cp','localidad','provincia','pais','lugar','fecha','version') + [
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
        $componentes = 'Joomla, HikaShop y el plugin propio PLG_HIKASHOP_VERIFACTU. '
            . 'Joomla y HikaShop son componentes de terceros; el componente propio '
            . 'desarrollado específicamente para la integración Veri*Factu* es '
            . 'PLG_HIKASHOP_VERIFACTU.';

        return '<!doctype html><html lang="es"><head><meta charset="utf-8">'
            . '<title>Declaración Responsable - PLG_HIKASHOP_VERIFACTU</title>'
            . '<style>body{font-family:Arial,sans-serif;max-width:900px;margin:40px auto;padding:0 24px;line-height:1.55;color:#222}'
            . 'h1{font-size:22px;text-align:center;margin-bottom:35px}h2{font-size:17px;margin-top:28px}'
            . '.box{border:1px solid #ccc;padding:18px;margin:18px 0}dt{font-weight:bold;margin-top:10px}dd{margin-left:0}'
            . '.print{position:fixed;right:20px;top:20px;padding:9px 14px;cursor:pointer}</style></head><body>'
            . '<button class="print" onclick="window.print()">Imprimir</button>'
            . '<h1>DECLARACIÓN RESPONSABLE DEL SISTEMA INFORMÁTICO DE FACTURACIÓN</h1>'
            . '<div class="box"><h2>1. Identificación del sistema informático</h2>'
            . '<dl><dt>Nombre del sistema informático</dt><dd>PLG_HIKASHOP_VERIFACTU</dd>'
            . '<dt>Código identificador del sistema informático</dt><dd>PLG_HIKASHOP_VERIFACTU</dd>'
            . '<dt>Versión concreta</dt><dd>' . $e($d['version']) . '</dd>'
            . '<dt>Componentes</dt><dd>' . $e($componentes) . '</dd>'
            . '<dt>Modalidad de funcionamiento</dt><dd>VERI*FACTU</dd>'
            . '<dt>Uso por varios obligados tributarios</dt><dd>' . ((string)$this->params->get('declaracion_uso_propio', '1') === '1'
                ? 'No. El sistema se utiliza exclusivamente por el obligado tributario identificado en esta declaración.'
                : 'La configuración permite su utilización por más de un obligado tributario.') . '</dd></dl></div>'
            . '<div class="box"><h2>2. Productor del sistema informático</h2>'
            . '<dl><dt>Nombre / razón social</dt><dd>' . $e($d['nombre']) . '</dd>'
            . '<dt>NIF</dt><dd>' . $e($d['nif']) . '</dd>'
            . '<dt>Dirección</dt><dd>' . nl2br($e($d['direccion'])) . '</dd>'
            . '<dt>Código postal</dt><dd>' . $e($d['cp']) . '</dd>'
            . '<dt>Localidad</dt><dd>' . $e($d['localidad']) . '</dd>'
            . '<dt>Provincia</dt><dd>' . $e($d['provincia']) . '</dd>'
            . '<dt>País</dt><dd>' . $e($d['pais']) . '</dd></dl></div>'
            . '<div class="box"><h2>3. Declaración</h2>'
            . '<p>El productor del sistema informático declara bajo su responsabilidad que la versión del sistema informático identificada en la presente declaración cumple los requisitos establecidos en el artículo 29.2.j) de la Ley 58/2003, de 17 de diciembre, General Tributaria, en el Real Decreto 1007/2023, de 5 de diciembre, y en su normativa de desarrollo aplicable.</p>'
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
        return '<!doctype html><html lang="es"><head><meta charset="utf-8">'
            . '<title>Condiciones de uso - PLG_HIKASHOP_VERIFACTU</title>'
            . '<style>body{font-family:Arial,sans-serif;max-width:900px;margin:40px auto;padding:0 24px;line-height:1.55;color:#222}h1{font-size:22px;text-align:center}.print{position:fixed;right:20px;top:20px;padding:9px 14px;cursor:pointer}</style></head><body>'
            . '<button class="print" onclick="window.print()">Imprimir</button>'
            . '<h1>CONDICIONES DE USO DEL SOFTWARE</h1>'
            . '<p><strong>PLG_HIKASHOP_VERIFACTU es software freeware.</strong> Se distribuye gratuitamente y se desarrolla para uso propio del titular identificado en la Declaración Responsable.</p>'
            . '<p>El software se proporciona sin garantía comercial y sin compromiso de mantenimiento, soporte técnico, actualización o adaptación futura.</p>'
            . '<p>El desarrollador no garantiza que una versión concreta continúe funcionando ante futuros cambios normativos, técnicos o funcionales de la Agencia Estatal de Administración Tributaria (AEAT), Veri*Factu*, sus servicios, especificaciones o procedimientos.</p>'
            . '<p>Tampoco se garantiza la compatibilidad futura con Joomla, HikaShop, PHP, certificados digitales, servidores u otros componentes externos de los que dependa el funcionamiento del sistema.</p>'
            . '<p>El desarrollador no asume el compromiso de publicar nuevas versiones, actualizaciones, correcciones o adaptaciones cuando se produzcan cambios en cualquiera de los elementos anteriores.</p>'
            . '<p>El desarrollador no garantiza que el software permita realizar correctamente el envío de registros a la AEAT en todo momento ni que la AEAT acepte los registros generados o enviados por el sistema.</p>'
            . '<p>El usuario es responsable de comprobar periódicamente que la versión utilizada continúa siendo adecuada para sus obligaciones fiscales y de adoptar las medidas necesarias cuando deje de ser compatible o adecuada.</p>'
            . '<p>La utilización del software se realiza bajo la responsabilidad del usuario. No existe obligación de prestación de soporte técnico, mantenimiento, actualización ni desarrollo de nuevas versiones por parte del desarrollador por el mero hecho de utilizar o haber utilizado el software.</p>'
            . '<p><strong>Versión del sistema:</strong> ' . $e($d['version']) . '</p>'
            . '<p><strong>Fecha:</strong> ' . $e($d['fecha']) . '</p>'
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
            // La tabla ya puede existir por la instalación/migración.
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
            'codigo_postal' => $d['cp'],
            'localidad' => $d['localidad'],
            'provincia' => $d['provincia'],
            'pais' => $d['pais'],
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
        $db->setQuery($q)->execute();
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

        if ($task === 'cargar_datos') {
            $d = $this->getDeclarationData();
            return (object) [
                'success' => true,
                'nombre' => $d['nombre'],
                'nif' => $d['nif'],
                'direccion' => $d['direccion'],
                'cp' => $d['cp'],
                'localidad' => $d['localidad'],
                'provincia' => $d['provincia'],
                'pais' => $d['pais'],
            ];
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
    public function onHikashopAfterDisplayView(&$view)
    {
        try {
            if (!is_object($view) || !method_exists($view, 'getName') || !method_exists($view, 'getLayout')) {
                return;
            }

            if ($view->getName() !== 'order' || $view->getLayout() !== 'edit') {
                return;
            }

            $app = \Joomla\CMS\Factory::getApplication();
            if (!$app->isClient('administrator')) {
                return;
            }

            $order = isset($view->order) && is_object($view->order) ? $view->order : null;
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
     * Guarda explícitamente los dos campos cuando se edita un pedido desde
     * administración. Además de incorporarlos al objeto $order, se escribe
     * directamente en las columnas personalizadas para que el guardado sea
     * fiable incluso cuando HikaShop no haya incluido esos campos en su propio
     * saveForm de esa vista.
     */
    public function onBeforeOrderUpdate(&$order, &$do)
    {
        try {
            $app = \Joomla\CMS\Factory::getApplication();
            if (!$app->isClient('administrator')) {
                return;
            }

            $data = $app->input->get('data', [], 'array');
            $orderData = isset($data['order']) && is_array($data['order']) ? $data['order'] : [];
            if (empty($orderData)) {
                return;
            }

            $hasTipo = array_key_exists('verifactu_tipo_abono', $orderData);
            $hasComentario = array_key_exists('verifactu_comentario_abono', $orderData);
            if (!$hasTipo && !$hasComentario) {
                return;
            }

            if ($hasTipo) {
                $order->verifactu_tipo_abono = trim((string) $orderData['verifactu_tipo_abono']);
            }
            if ($hasComentario) {
                $order->verifactu_comentario_abono = (string) $orderData['verifactu_comentario_abono'];
            }

            $orderId = (int) ($order->order_id ?? 0);
            if (!$orderId) {
                return;
            }

            $db = $this->db();
            $query = $db->getQuery(true)->update('#__hikashop_order')->where('order_id = ' . $orderId);
            if ($hasTipo) {
                $query->set($db->quoteName('verifactu_tipo_abono') . ' = ' . $db->quote($order->verifactu_tipo_abono));
            }
            if ($hasComentario) {
                $query->set($db->quoteName('verifactu_comentario_abono') . ' = ' . $db->quote($order->verifactu_comentario_abono));
            }
            $db->setQuery($query)->execute();

            self::log('Campos de abono guardados para pedido ' . $orderId . ': tipo=' . ($hasTipo ? $order->verifactu_tipo_abono : '[sin cambio]'));
        } catch (\Throwable $e) {
            self::log('Error guardando los campos de abono del pedido: ' . $e->getMessage(), \Joomla\CMS\Log\Log::WARNING);
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
                'verifactu_comentario_abono'
            ] as $campo) {
                if (isset($ordenFresco->$campo)) {
                    $order->$campo = $ordenFresco->$campo;
                }
            }
            // Los campos personalizados de abono son columnas propias de esta
            // instalación. Los leemos directamente para no depender de si la
            // clase de HikaShop los incluye en el objeto en este callback.
            try {
                $qAbono = $db->getQuery(true)
                    ->select([
                        $db->quoteName('verifactu_tipo_abono'),
                        $db->quoteName('verifactu_comentario_abono')
                    ])
                    ->from($db->quoteName('#__hikashop_order'))
                    ->where($db->quoteName('order_id') . ' = ' . (int) $order->order_id);
                $db->setQuery($qAbono);
                $datosAbono = $db->loadObject();
                if ($datosAbono) {
                    $order->verifactu_tipo_abono = (string) ($datosAbono->verifactu_tipo_abono ?? '');
                    $order->verifactu_comentario_abono = (string) ($datosAbono->verifactu_comentario_abono ?? '');
                    self::log('Datos de abono recargados directamente: tipo=' . $order->verifactu_tipo_abono . ', comentario=' . ($order->verifactu_comentario_abono !== '' ? '[presente]' : '[vacío]'));
                }
            } catch (\Throwable $e) {
                self::log('No se pudieron recargar los campos de abono directamente: ' . $e->getMessage(), \Joomla\CMS\Log\Log::WARNING);
            }

            self::log('Pedido ' . $order->order_id . ' recargado desde BBDD antes de enviar. order_full_price=' . ($order->order_full_price ?? '?') . ', order_invoice_number=' . ($order->order_invoice_number ?? '?'));
        }

        // Si tampoco hay dirección de facturación cargada en memoria, se
        // recarga también (necesaria para el NIF del cliente).
        if (empty($order->billing_address) && !empty($ordenFresco->order_billing_address_id)) {
            $addressClass = hikashop_get('class.address');
            $order->billing_address = $addressClass->get($ordenFresco->order_billing_address_id);
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
            $resultado = $bridge->registrarFactura($order);
            $resultadoParaLog = $resultado;
            if (!empty($resultadoParaLog['qr'])) {
                $resultadoParaLog['qr'] = '(' . strlen($resultadoParaLog['qr']) . ' bytes, omitido del log)';
            }
            self::log('Resultado registrarFactura para pedido ' . $order->order_id . ': ' . json_encode($resultadoParaLog, JSON_INVALID_UTF8_SUBSTITUTE));
        } catch (\Throwable $e) {
            // No dejar que un fallo en VeriFactu bloquee el cambio de estado del pedido:
            // se registra el error y queda para revisión/reintento manual.
            self::log('EXCEPCIÓN en pedido ' . $order->order_id . ': ' . $e->getMessage() . ' en ' . $e->getFile() . ':' . $e->getLine(), \Joomla\CMS\Log\Log::ERROR);
            return;
        }

        if ($resultado['estado'] !== 'aceptado') {
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
            ->order('id DESC')
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
