<?php
defined('_JEXEC') or die;

/**
 * Creates the two order columns the plugin needs, and installs the invoice
 * layout override into the HikaShop media folder.
 *
 * The columns used to be created by the install SQL with ADD COLUMN IF NOT
 * EXISTS, which only MariaDB understands: on MySQL the statement is a syntax
 * error, the installer stops on it, and the whole install fails. The check is
 * done here against INFORMATION_SCHEMA instead, which both understand, and it
 * runs on install and on update so an existing site catches up.
 *
 * The layout used to be shipped by a <media destination="com_hikashop"> tag.
 * On uninstall Joomla deletes the whole destination folder of such a tag, which
 * took away media/com_hikashop entirely: HikaShop's css, js, images, mail
 * templates and uploaded files. It is copied and removed file by file here.
 */
class PlgHikashopVerifactuInstallerScript
{
    /**
     * The custom order fields of HikaShop are stored as columns of its order
     * table. The rows describing the fields are inserted by the install SQL.
     */
    private const COLUMNS = [
        'hikashop_order' => [
            'verifactu_tipo_abono' => 'VARCHAR(255) NULL',
            'verifactu_comentario_abono' => 'TEXT NULL',
            // v0.34.13: era TINYINT(1). El campo HikaShop es un singledropdown
            // que envía texto ('No'/'Sí'), no 0/1. Joomla 4 y posteriores
            // activan siempre el modo estricto de MySQL (STRICT_TRANS_TABLES),
            // así que guardar ese texto en una columna TINYINT da un error de
            // SQL (no se trunca en silencio a 0, como ocurría en Joomla 3).
            // VARCHAR(10) para que coincida con lo que el campo realmente
            // envía, igual que ya hace verifactu_anular_factura (mismo tipo
            // de campo). Una columna que ya existiera como TINYINT se
            // convierte en migrarSustituyeSimplificada().
            'verifactu_sustituye_simplificada' => 'VARCHAR(10) NULL',
            'verifactu_anular_factura' => 'VARCHAR(10) NULL',
            'verifactu_motivo_anulacion' => 'TEXT NULL',
            // v0.34.30: rectificación por diferencias (R1 positiva o
            // negativa) sobre una factura YA aceptada del mismo pedido, sin
            // pasar por un pedido de abono con importe negativo. Ver
            // VerifactuLibraryBridge::rectificarPorDiferencias().
            'verifactu_rectificar_diferencias' => 'VARCHAR(10) NULL',
            'verifactu_nueva_base_imponible' => 'DECIMAL(12,2) NULL',
            'verifactu_nuevo_tipo_iva' => 'DECIMAL(5,2) NULL',
            'verifactu_nuevo_tipo_recargo' => 'DECIMAL(5,2) NULL',
        ],
        'hikashop_verifactu_registro' => [
            'tipo_abono' => 'VARCHAR(80) NULL AFTER `total_factura`',
            'tipo_factura' => 'VARCHAR(2) NULL AFTER `tipo_abono`',
            'tipo_rectificativa' => 'VARCHAR(1) NULL AFTER `tipo_factura`',
            'factura_rectificada' => 'VARCHAR(60) NULL AFTER `tipo_rectificativa`',
            // v0.34.0: registro de anulación (RegistroAnulacion). tipo_registro
            // distingue una fila "alta" (factura normal, F1/F2/F3/R...) de una
            // fila "anulacion" -- ambas viven en la misma tabla a propósito,
            // porque el encadenamiento de huellas (obtenerUltimaFacturaRegistrada)
            // necesita ver las anulaciones como parte de la misma secuencia.
            'tipo_registro' => "VARCHAR(12) NOT NULL DEFAULT 'alta' AFTER `factura_rectificada`",
            'motivo_anulacion' => 'TEXT NULL AFTER `tipo_registro`',
            // v0.34.30: desglose (base/tipo/cuota de IVA y de recargo de
            // equivalencia) de ESTA factura, cuando se ha podido calcular con
            // una única línea de IVA. Se guarda en toda alta (F1/F2/F3/R),
            // no solo en rectificaciones, para que una futura rectificación
            // por diferencias sobre este mismo pedido pueda leer aquí los
            // importes de "la factura original" sin recalcularlos. NULL
            // cuando el pedido tiene varios tipos de IVA a la vez (desglose
            // no reducible a una sola línea) o cuando la factura es anterior
            // a esta versión.
            'base_imponible' => 'DECIMAL(12,2) NULL AFTER `motivo_anulacion`',
            'tipo_iva' => 'DECIMAL(5,2) NULL AFTER `base_imponible`',
            'cuota_iva' => 'DECIMAL(12,2) NULL AFTER `tipo_iva`',
            'tipo_recargo' => 'DECIMAL(5,2) NULL AFTER `cuota_iva`',
            'recargo_equivalencia' => 'DECIMAL(12,2) NULL AFTER `tipo_recargo`',
            // v0.34.57: régimen y causa de exención de la línea de desglose,
            // para rectificar por diferencias facturas exentas (E2/E5).
            'clave_regimen' => 'VARCHAR(2) NULL AFTER `recargo_equivalencia`',
            'operacion_exenta' => 'VARCHAR(2) NULL AFTER `clave_regimen`',
        ],
    ];

    /**
     * Where HikaShop looks for an invoice layout override, and the marker that
     * tells our own copy apart from a layout written by the merchant.
     */
    private const LAYOUT_TARGET = '/media/com_hikashop/plugins/invoice.php';
    private const LAYOUT_MARKER = 'PLG_HIKASHOP_VERIFACTU';

    /**
     * Tablas de la numeración propia F2/R (ver VerifactuLibraryBridge::
     * aplicarNumeracionPropia). CREATE TABLE IF NOT EXISTS es válido tanto en
     * MySQL como en MariaDB, así que no hace falta el rodeo de
     * INFORMATION_SCHEMA que sí necesitan las columnas de COLUMNS.
     */
    private const TABLES = [
        'hikashop_verifactu_contador' => "CREATE TABLE IF NOT EXISTS `%s` (
            `familia` VARCHAR(2) NOT NULL,
            `anio` VARCHAR(4) NOT NULL,
            `ultimo_numero` INT UNSIGNED NOT NULL DEFAULT 0,
            PRIMARY KEY (`familia`, `anio`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
        'hikashop_verifactu_numeracion_asignada' => "CREATE TABLE IF NOT EXISTS `%s` (
            `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
            `order_id` INT UNSIGNED NOT NULL,
            `familia` VARCHAR(10) NOT NULL,
            `numero` VARCHAR(60) NOT NULL,
            `numero_original_hikashop` VARCHAR(60) NULL,
            `created` DATETIME NOT NULL,
            PRIMARY KEY (`id`),
            UNIQUE KEY `idx_order_familia` (`order_id`, `familia`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
        // v0.34.21: FechaExpedicionFactura congelada por pedido+familia (ver
        // VerifactuLibraryBridge::obtenerFechaExpedicionCongelada). Evita que
        // un reintento días después del primer intento envíe la factura con
        // la fecha de "hoy" en vez de la fecha real de expedición.
        'hikashop_verifactu_fecha_expedicion' => "CREATE TABLE IF NOT EXISTS `%s` (
            `order_id` INT UNSIGNED NOT NULL,
            `familia` VARCHAR(10) NOT NULL,
            `fecha_expedicion` DATE NOT NULL,
            `created` DATETIME NOT NULL,
            PRIMARY KEY (`order_id`, `familia`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
        'hikashop_verifactu_certificado_avisos' => "CREATE TABLE IF NOT EXISTS `%s` (
            `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
            `huella` VARCHAR(128) NOT NULL,
            `umbral_meses` TINYINT UNSIGNED NOT NULL,
            `fecha_envio` DATETIME NOT NULL,
            PRIMARY KEY (`id`),
            UNIQUE KEY `idx_huella_umbral` (`huella`, `umbral_meses`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    ];

    public function postflight($type, $parent)
    {
        if ($type === 'uninstall') {
            return true;
        }

        if ($type === 'update') {
            $this->migrarAceptacionAvisoLegal();
            $this->migrarOpcionCancelacionTipoAbono();
            $this->migrarPantallasCamposAbono();
            $this->migrarPlaceholderComentarioAbono();
        }

        $this->installLayout($parent);

        $db = \Joomla\CMS\Factory::getContainer()->get('DatabaseDriver');

        foreach (self::TABLES as $suffix => $sql) {
            $table = $db->getPrefix() . $suffix;
            try {
                $db->setQuery(sprintf($sql, $table));
                $db->execute();
            } catch (\Throwable $e) {
                \Joomla\CMS\Factory::getApplication()->enqueueMessage(
                    'VeriFactu: no se pudo crear la tabla ' . $table . ' (' . $e->getMessage() . ')',
                    'warning'
                );
            }
        }

        foreach (self::COLUMNS as $suffix => $columns) {
            $table = $db->getPrefix() . $suffix;

            foreach ($columns as $column => $definition) {
                try {
                    if ($this->columnExists($db, $table, $column)) {
                        continue;
                    }

                    $db->setQuery('ALTER TABLE ' . $db->quoteName($table) . ' ADD COLUMN ' . $db->quoteName($column) . ' ' . $definition);
                    $db->execute();
                } catch (\Throwable $e) {
                    \Joomla\CMS\Factory::getApplication()->enqueueMessage(
                        'VeriFactu: no se pudo crear la columna ' . $column . ' en ' . $table . ' (' . $e->getMessage() . ')',
                        'warning'
                    );
                }
            }
        }

        $this->migrarSustituyeSimplificada($db);
        $this->migrarFamiliaAncha($db);

        return true;
    }

    /**
     * Cada rectificación por diferencias de un pedido tiene su propio número y
     * su propia fecha, guardados con la clave RD1, RD2... en vez de R: la
     * columna familia pasa de VARCHAR(2) a VARCHAR(10). Idempotente.
     */
    private function migrarFamiliaAncha($db): void
    {
        foreach (['hikashop_verifactu_numeracion_asignada', 'hikashop_verifactu_fecha_expedicion'] as $suffix) {
            $table = $db->getPrefix() . $suffix;
            try {
                $db->setQuery(
                    'SELECT CHARACTER_MAXIMUM_LENGTH FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE()'
                    . ' AND TABLE_NAME = ' . $db->quote($table) . ' AND COLUMN_NAME = ' . $db->quote('familia')
                );
                $largo = $db->loadResult();
                if ($largo === null || (int) $largo >= 10) {
                    continue;
                }
                $db->setQuery('ALTER TABLE ' . $db->quoteName($table) . ' MODIFY COLUMN `familia` VARCHAR(10) NOT NULL');
                $db->execute();
            } catch (\Throwable $e) {
                \Joomla\CMS\Factory::getApplication()->enqueueMessage(
                    'VeriFactu: no se pudo ampliar la columna familia de ' . $table . ' (' . $e->getMessage() . ')',
                    'warning'
                );
            }
        }
    }

    /**
     * v0.34.60: el campo "Comentario del abono" (verifactu_comentario_abono)
     * lleva ahora "Indicar número de factura original" como Marcador de
     * posición (placeholder). La instalación nueva ya lo siembra (ver
     * sql/install.mysql.utf8.sql); esta migración lo añade en las que ya
     * tenían el campo creado.
     *
     * HikaShop guarda las opciones del campo (columna field_options) como un
     * array PHP serializado y lee el placeholder de la clave 'placeholder'.
     * Idempotente y respetuosa: si el campo ya tiene un marcador de posición
     * (el de esta migración o uno que haya puesto el propio usuario), no se
     * toca. Si field_options no se puede leer como array, tampoco.
     */
    private function migrarPlaceholderComentarioAbono(): void
    {
        $texto = 'Indicar número de factura original';

        try {
            $db = \Joomla\CMS\Factory::getContainer()->get('DatabaseDriver');

            $query = $db->getQuery(true)
                ->select(['field_id', 'field_options'])
                ->from('#__hikashop_field')
                ->where('field_namekey = ' . $db->quote('verifactu_comentario_abono'))
                ->where('field_table = ' . $db->quote('order'));
            $db->setQuery($query);
            $row = $db->loadObject();

            if (!$row) {
                return;
            }

            $opciones = ($row->field_options === null || trim((string) $row->field_options) === '')
                ? []
                : @unserialize((string) $row->field_options, ['allowed_classes' => false]);

            if (!is_array($opciones)) {
                return;
            }

            if (!empty($opciones['placeholder'])) {
                return;
            }

            $opciones['placeholder'] = $texto;

            $update = $db->getQuery(true)
                ->update('#__hikashop_field')
                ->set('field_options = ' . $db->quote(serialize($opciones)))
                ->where('field_id = ' . (int) $row->field_id);
            $db->setQuery($update);
            $db->execute();
        } catch (\Throwable $e) {
            \Joomla\CMS\Factory::getApplication()->enqueueMessage(
                'VeriFactu: no se pudo añadir el marcador de posición al campo "Comentario del abono" (' . $e->getMessage() . ').',
                'warning'
            );
        }
    }

    /**
     * v0.34.19: cambio de tipo que antes hacía sql/updates/mysql/0.34.13.sql.
     * En el SQL de actualización fallaba con "Unknown column" en tiendas que
     * venían de una versión anterior a la 0.33.0 (la columna no existe hasta
     * que este mismo postflight la crea, y Joomla ejecuta el postflight
     * después de los SQL), y eso abortaba toda la actualización.
     *
     * Aquí la columna ya está creada como VARCHAR(10) si faltaba, así que solo
     * se convierte si existe y todavía es TINYINT (la creó una versión
     * 0.33.0-0.34.12). Es idempotente: no hace nada en el resto de casos.
     */
    private function migrarSustituyeSimplificada($db): void
    {
        $table = $db->getPrefix() . 'hikashop_order';
        $column = 'verifactu_sustituye_simplificada';

        try {
            if ($this->columnDataType($db, $table, $column) !== 'tinyint') {
                return;
            }

            $db->setQuery(
                'ALTER TABLE ' . $db->quoteName($table)
                . ' MODIFY COLUMN ' . $db->quoteName($column) . ' VARCHAR(10) NULL DEFAULT NULL'
            );
            $db->execute();
        } catch (\Throwable $e) {
            \Joomla\CMS\Factory::getApplication()->enqueueMessage(
                'VeriFactu: no se pudo convertir la columna ' . $column . ' a VARCHAR(10) en ' . $table . ' (' . $e->getMessage() . ')',
                'warning'
            );
        }
    }

    /**
     * v0.34.7 añadió el campo "aviso_responsabilidad_aceptado", con un guard
     * en verifactu.php que bloquea TODO envío a la AEAT (y ni siquiera
     * registra el pedido localmente) mientras no valga "1". El problema real
     * visto en producción: en una ACTUALIZACIÓN, Joomla nunca reabre ni
     * vuelve a guardar el formulario de configuración del plugin -- el JSON
     * de parámetros ya guardado simplemente no tiene esa clave, así que en
     * tiempo de ejecución $params->get() devuelve el valor por defecto ("0")
     * y el plugin se queda bloqueando pedidos en silencio hasta que alguien
     * entra a la configuración a mano. Resultado real: 8 facturas (26-0237 a
     * 26-0244) numeradas con normalidad por HikaShop pero NUNCA enviadas a
     * la AEAT ni registradas por el plugin, sin ningún aviso visible salvo
     * el log de Joomla.
     *
     * Para una tienda que YA tenía el plugin instalado y en producción, es
     * razonable asumir que la aceptación ya se dio en su momento (cuando se
     * configuró por primera vez) -- así que en una actualización se
     * completa automáticamente esa clave a "1" si no existía todavía,
     * dejando el comportamiento exactamente como antes de la 0.34.7. Una
     * instalación NUEVA (type=install/discover_install) no pasa por aquí:
     * ahí sí debe verse el aviso y marcarse a mano antes de que el plugin
     * haga nada, que es la protección que se pretendía añadir.
     */
    private function migrarAceptacionAvisoLegal(): void
    {
        try {
            $db = \Joomla\CMS\Factory::getContainer()->get('DatabaseDriver');

            $query = $db->getQuery(true)
                ->select(['extension_id', 'params'])
                ->from('#__extensions')
                ->where('type = ' . $db->quote('plugin'))
                ->where('element = ' . $db->quote('verifactu'))
                ->where('folder = ' . $db->quote('hikashop'));
            $db->setQuery($query);
            $row = $db->loadObject();

            if (!$row) {
                return;
            }

            $params = json_decode((string) ($row->params ?? '{}'), true);
            if (!is_array($params)) {
                $params = [];
            }

            if (array_key_exists('aviso_responsabilidad_aceptado', $params)) {
                // Ya existe (instalación ya actualizada antes, o vuelta a
                // instalar sobre una configuración existente) -- no se toca,
                // se respeta lo que haya decidido el propio usuario.
                return;
            }

            $params['aviso_responsabilidad_aceptado'] = '1';

            $update = $db->getQuery(true)
                ->update('#__extensions')
                ->set('params = ' . $db->quote(json_encode($params)))
                ->where('extension_id = ' . (int) $row->extension_id);
            $db->setQuery($update);
            $db->execute();

            \Joomla\CMS\Factory::getApplication()->enqueueMessage(
                'VeriFactu: se ha mantenido activo el envío a la AEAT tras la actualización (instalación ya existente). Puedes revisar el aviso legal completo desde la configuración del plugin cuando quieras.',
                'message'
            );
        } catch (\Throwable $e) {
            \Joomla\CMS\Factory::getApplication()->enqueueMessage(
                'VeriFactu: no se pudo comprobar/migrar la aceptación del aviso legal (' . $e->getMessage() . '). Si el envío a la AEAT deja de funcionar tras esta actualización, entra en la configuración del plugin y marca "He leído y acepto el aviso legal y las condiciones de uso".',
                'warning'
            );
        }
    }

    /**
     * v0.34.24: auditoría de seguridad, punto "Rectificativas" (prioridad
     * 🔴). Retira la opción 'Cancelación' del desplegable "Tipo de abono"
     * en instalaciones que ya la tenían sembrada por una versión anterior
     * (ver sql/install.mysql.utf8.sql y el comentario en
     * VerifactuLibraryBridge::mapTipoAbono() para el porqué: coexistía con
     * el campo separado "Anular factura" y confundía ambos mecanismos).
     *
     * Solo toca el campo con field_namekey 'verifactu_tipo_abono' (el
     * nombre por defecto que crea la instalación de este plugin); si el
     * usuario ha renombrado el campo interno de HikaShop a otra clave,
     * esta migración no lo alcanza -- caso fuera de alcance de una
     * migración automática segura, y se avisa en el log si no se
     * encuentra el campo.
     *
     * Idempotente: si la línea ya no está (instalación nueva desde
     * v0.34.24, o esta migración ya se aplicó antes), no hace nada.
     */
    private function migrarOpcionCancelacionTipoAbono(): void
    {
        try {
            $db = \Joomla\CMS\Factory::getContainer()->get('DatabaseDriver');

            $query = $db->getQuery(true)
                ->select(['field_id', 'field_options'])
                ->from('#__hikashop_field')
                ->where('field_namekey = ' . $db->quote('verifactu_tipo_abono'))
                ->where('field_table = ' . $db->quote('order'));
            $db->setQuery($query);
            $row = $db->loadObject();

            if (!$row || $row->field_options === null) {
                return;
            }

            $opciones = str_replace("\r\n", "\n", (string) $row->field_options);
            $lineas = explode("\n", $opciones);
            $filtradas = array_values(array_filter(
                $lineas,
                static fn(string $linea): bool => trim($linea) !== 'Cancelación::Cancelación::0'
            ));

            if (count($filtradas) === count($lineas)) {
                // Ya no estaba: instalación nueva desde v0.34.24, o esta
                // migración ya se aplicó antes (o el admin ya la quitó a mano).
                return;
            }

            $update = $db->getQuery(true)
                ->update('#__hikashop_field')
                ->set('field_options = ' . $db->quote(implode("\n", $filtradas)))
                ->where('field_id = ' . (int) $row->field_id);
            $db->setQuery($update);
            $db->execute();

            \Joomla\CMS\Factory::getApplication()->enqueueMessage(
                'VeriFactu: se ha retirado la opción "Cancelación" del desplegable "Tipo de abono" (ver auditoría de seguridad, punto Rectificativas) -- para una factura que nunca debió existir, usa el campo "Anular factura" en vez de "Tipo de abono".',
                'notice'
            );
        } catch (\Throwable $e) {
            \Joomla\CMS\Factory::getApplication()->enqueueMessage(
                'VeriFactu: no se pudo retirar la opción "Cancelación" del desplegable "Tipo de abono" (' . $e->getMessage() . '). Puedes quitarla a mano desde Componentes > HikaShop > Campos personalizados > Tipo de abono.',
                'warning'
            );
        }
    }


    /**
     * v0.34.54: los 9 campos personalizados de VeriFactu (Tipo de abono,
     * Comentario del abono, Sustituye a simplificada, Anular factura, Motivo
     * de la anulación, Rectificar por diferencias, Nueva base imponible,
     * Nuevo tipo de IVA, Nuevo tipo de recargo) nacían, en todas las
     * versiones anteriores, sin activar por defecto 2 de las 3 pantallas
     * pedidas ("Listado del Back-end" e "Información adicional del
     * pedido"). Revisando el código real de HikaShop (views/field/
     * view.html.php y views/field/tmpl/form.php) se confirma que:
     *   - "Formulario del back-end"          -> columna `field_backend`
     *     (ya nacía en 1/Sí, no hacía falta ningún cambio).
     *   - "Listado del Back-end"             -> columna
     *     `field_backend_listing` (nacía en 0/No).
     *   - "Información adicional del pedido" -> bandera `order_edit` dentro
     *     de `field_display`. El instalador usaba por error el nombre
     *     `order_edit_fields` (basado en la clave de idioma
     *     FIELD_ORDER_EDIT_FIELDS), que HikaShop nunca comprueba -- el
     *     nombre corto real es `order_edit`, así que esa bandera nunca tuvo
     *     ningún efecto en ninguna instalación.
     *
     * Instalaciones nuevas desde v0.34.54 ya nacen bien (ver
     * sql/install.mysql.utf8.sql); esta migración corrige las que ya
     * tuvieran los 9 campos creados con los valores antiguos. Es
     * idempotente: si un campo ya tiene las 3 pantallas activas (por
     * haberse corregido a mano, o por ejecutarse esta migración más de una
     * vez), no se toca.
     */
    private function migrarPantallasCamposAbono(): void
    {
        static $namekeys = [
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

        try {
            $db = \Joomla\CMS\Factory::getContainer()->get('DatabaseDriver');

            $query = $db->getQuery(true)
                ->select(['field_id', 'field_namekey', 'field_backend_listing', 'field_display'])
                ->from('#__hikashop_field')
                ->where('field_table = ' . $db->quote('order'))
                ->where('field_namekey IN (' . implode(',', array_map([$db, 'quote'], $namekeys)) . ')');
            $db->setQuery($query);
            $filas = $db->loadObjectList();

            if (empty($filas)) {
                return;
            }

            $corregidos = 0;

            foreach ($filas as $fila) {
                $nuevoBackendListing = (int) $fila->field_backend_listing;
                $necesitaUpdate = false;

                if ($nuevoBackendListing !== 1) {
                    $nuevoBackendListing = 1;
                    $necesitaUpdate = true;
                }

                $display = (string) $fila->field_display;
                $normalizado = ';' . trim($display, ';') . ';';

                if (strpos($normalizado, ';order_edit=1;') !== false) {
                    $nuevoDisplay = $display;
                } elseif (strpos($normalizado, 'order_edit_fields=') !== false) {
                    $nuevoDisplay = str_replace(
                        ['order_edit_fields=1', 'order_edit_fields=0'],
                        ['order_edit=1', 'order_edit=1'],
                        $display
                    );
                    $necesitaUpdate = true;
                } elseif (strpos($normalizado, ';order_edit=0;') !== false) {
                    $nuevoDisplay = str_replace('order_edit=0', 'order_edit=1', $display);
                    $necesitaUpdate = true;
                } else {
                    $nuevoDisplay = ';' . trim($display, ';') . ';order_edit=1;';
                    $necesitaUpdate = true;
                }

                if (!$necesitaUpdate) {
                    continue;
                }

                $update = $db->getQuery(true)
                    ->update('#__hikashop_field')
                    ->set('field_backend_listing = ' . $nuevoBackendListing)
                    ->set('field_display = ' . $db->quote($nuevoDisplay))
                    ->where('field_id = ' . (int) $fila->field_id);
                $db->setQuery($update);
                $db->execute();
                $corregidos++;
            }

            if ($corregidos > 0) {
                \Joomla\CMS\Factory::getApplication()->enqueueMessage(
                    'VeriFactu: se han activado por defecto, para ' . $corregidos . ' campo(s) personalizado(s) de VeriFactu, las pantallas "Listado del Back-end" e "Información adicional del pedido" (Componentes > HikaShop > Campos personalizados).',
                    'notice'
                );
            }
        } catch (\Throwable $e) {
            \Joomla\CMS\Factory::getApplication()->enqueueMessage(
                'VeriFactu: no se pudieron activar automáticamente las pantallas "Listado del Back-end"/"Información adicional del pedido" en los campos personalizados (' . $e->getMessage() . '). Puedes activarlas a mano desde Componentes > HikaShop > Campos personalizados, editando cada uno de los 9 campos VeriFactu.',
                'warning'
            );
        }
    }


    private function installLayout($parent): bool
    {
        $source = $parent->getParent()->getPath('extension_root') . '/layouts/invoice.php';
        $target = JPATH_ROOT . self::LAYOUT_TARGET;

        if (!is_file($source)) {
            return false;
        }

        if (is_file($target) && strpos((string) file_get_contents($target), self::LAYOUT_MARKER) === false) {
            \Joomla\CMS\Factory::getApplication()->enqueueMessage(
                'VeriFactu: ya existe una plantilla de factura en ' . self::LAYOUT_TARGET . '. Se ha conservado, el QR no se añadirá automáticamente.',
                'warning'
            );

            return false;
        }

        $folder = \dirname($target);

        if (!is_dir($folder) && !@mkdir($folder, 0755, true) && !is_dir($folder)) {
            \Joomla\CMS\Factory::getApplication()->enqueueMessage(
                'VeriFactu: no se pudo crear la carpeta ' . $folder . ' para la plantilla de factura.',
                'warning'
            );

            return false;
        }

        if (!@copy($source, $target)) {
            \Joomla\CMS\Factory::getApplication()->enqueueMessage(
                'VeriFactu: no se pudo instalar la plantilla de factura en ' . self::LAYOUT_TARGET . '.',
                'warning'
            );

            return false;
        }

        return true;
    }

    /**
     * Removes our layout override, and nothing else. Left behind it would call
     * a table that no longer exists on every invoice.
     */
    public function uninstall($parent)
    {
        $target = JPATH_ROOT . self::LAYOUT_TARGET;

        if (!is_file($target)) {
            return true;
        }

        if (strpos((string) file_get_contents($target), self::LAYOUT_MARKER) === false) {
            return true;
        }

        @unlink($target);

        // Only when nothing else lives there: other layouts may be the merchant's.
        @rmdir(\dirname($target));

        return true;
    }

    private function columnExists($db, string $table, string $column): bool
    {
        $query = $db->getQuery(true)
            ->select('COUNT(*)')
            ->from('INFORMATION_SCHEMA.COLUMNS')
            ->where('TABLE_SCHEMA = DATABASE()')
            ->where('TABLE_NAME = ' . $db->quote($table))
            ->where('COLUMN_NAME = ' . $db->quote($column));

        $db->setQuery($query);

        return (bool) (int) $db->loadResult();
    }

    /**
     * Tipo de dato (INFORMATION_SCHEMA.DATA_TYPE, en minúsculas: 'tinyint',
     * 'varchar'...) de una columna, o null si la tabla o la columna no existen.
     */
    private function columnDataType($db, string $table, string $column): ?string
    {
        $query = $db->getQuery(true)
            ->select('DATA_TYPE')
            ->from('INFORMATION_SCHEMA.COLUMNS')
            ->where('TABLE_SCHEMA = DATABASE()')
            ->where('TABLE_NAME = ' . $db->quote($table))
            ->where('COLUMN_NAME = ' . $db->quote($column));

        $db->setQuery($query);
        $tipo = $db->loadResult();

        return $tipo === null || $tipo === false ? null : strtolower((string) $tipo);
    }
}
