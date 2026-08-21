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
        ],
        'hikashop_verifactu_registro' => [
            'tipo_abono' => 'VARCHAR(80) NULL AFTER `total_factura`',
            'tipo_factura' => 'VARCHAR(2) NULL AFTER `tipo_abono`',
            'tipo_rectificativa' => 'VARCHAR(1) NULL AFTER `tipo_factura`',
            'factura_rectificada' => 'VARCHAR(60) NULL AFTER `tipo_rectificativa`',
        ],
    ];

    /**
     * Where HikaShop looks for an invoice layout override, and the marker that
     * tells our own copy apart from a layout written by the merchant.
     */
    private const LAYOUT_TARGET = '/media/com_hikashop/plugins/invoice.php';
    private const LAYOUT_MARKER = 'PLG_HIKASHOP_VERIFACTU';

    public function postflight($type, $parent)
    {
        if ($type === 'uninstall') {
            return true;
        }

        $this->installLayout($parent);

        $db = \Joomla\CMS\Factory::getContainer()->get('DatabaseDriver');

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

        return true;
    }

    /**
     * Copies the invoice layout override next to HikaShop's own layouts. An
     * existing file without our marker belongs to the merchant and is kept.
     */
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
}
