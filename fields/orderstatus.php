<?php
defined('_JEXEC') or die;

/**
 * Campo de formulario que muestra un desplegable con los estados de pedido
 * reales configurados en HikaShop (tabla #__hikashop_orderstatus), en vez de
 * tener el nombre del estado fijado a mano en el código del plugin.
 */
class JFormFieldOrderstatus extends JFormField
{
    protected $type = 'Orderstatus';

    protected function getInput()
    {
        $db = JFactory::getDbo();
        $query = $db->getQuery(true)
            ->select('orderstatus_namekey, orderstatus_name')
            ->from('#__hikashop_orderstatus')
            ->where('orderstatus_published = 1')
            ->order('orderstatus_ordering ASC');

        $db->setQuery($query);
        $estados = $db->loadObjectList();

        $opciones = '';
        foreach ($estados as $estado) {
            $seleccionado = ($estado->orderstatus_namekey === (string) $this->value) ? ' selected="selected"' : '';
            $opciones .= '<option value="' . htmlspecialchars($estado->orderstatus_namekey, ENT_QUOTES) . '"' . $seleccionado . '>'
                . htmlspecialchars($estado->orderstatus_name, ENT_QUOTES) . ' (' . htmlspecialchars($estado->orderstatus_namekey, ENT_QUOTES) . ')'
                . '</option>';
        }

        return '<select name="' . $this->name . '" id="' . $this->id . '" class="form-select">'
            . $opciones
            . '</select>';
    }
}
