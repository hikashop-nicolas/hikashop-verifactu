<?php
defined('_JEXEC') or die;

/**
 * Creates the two order columns the plugin needs.
 *
 * They used to be created by the install SQL with ADD COLUMN IF NOT EXISTS,
 * which only MariaDB understands: on MySQL the statement is a syntax error, the
 * installer stops on it, and the whole install fails. The check is done here
 * against INFORMATION_SCHEMA instead, which both understand, and it runs on
 * install and on update so an existing site catches up.
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

    public function postflight($type, $parent)
    {
        if ($type === 'uninstall') {
            return true;
        }

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
