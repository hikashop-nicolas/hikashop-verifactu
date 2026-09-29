<?php
defined('_JEXEC') or die;

require_once __DIR__ . '/VerifactuLog.php';
require_once __DIR__ . '/VerifactuVersion.php';
require_once __DIR__ . '/../vendor/autoload.php'; // vendor/ generado por Composer dentro de la propia carpeta del plugin

use eseperio\verifactu\Verifactu;
use eseperio\verifactu\models\InvoiceSubmission;
use eseperio\verifactu\models\InvoiceCancellation;
use eseperio\verifactu\models\InvoiceId;
use eseperio\verifactu\models\Breakdown;
use eseperio\verifactu\models\BreakdownDetail;
use eseperio\verifactu\models\Chaining;
use eseperio\verifactu\models\ComputerSystem;
use eseperio\verifactu\models\LegalPerson;
use eseperio\verifactu\models\OtherID;
use eseperio\verifactu\models\InvoiceResponse;
use eseperio\verifactu\models\enums\InvoiceType;
use eseperio\verifactu\models\enums\RectificationType;
use eseperio\verifactu\models\enums\TaxType;
use eseperio\verifactu\models\enums\YesNoType;
use eseperio\verifactu\models\enums\HashType;
use eseperio\verifactu\models\enums\OperationQualificationType;
use eseperio\verifactu\models\enums\RegimeType;
use eseperio\verifactu\models\enums\ExemptOperationType;
use eseperio\verifactu\services\QrGeneratorService;
use Joomla\Registry\Registry;

/**
 * Envuelve la librería eseperio/verifactu-php para usarla desde el plugin de HikaShop.
 * Sustituye a las clases caseras (AeatClient/HashChain/QrGenerator) del primer esqueleto:
 * la firma XML, el hash encadenado y la comunicación SOAP ya las resuelve la librería.
 */
class VerifactuLibraryBridge
{
    /**
     * Código identificador del sistema informático (IdSistemaInformatico
     * del bloque SistemaInformatico, campo obligatorio en cada registro
     * enviado a la AEAT) -- el que el propio plugin, como productor, ha
     * elegido para identificarse en el censo. Es una única constante
     * porque este mismo código debe coincidir EXACTAMENTE con el que
     * figura en la Declaración Responsable (ver
     * plgHikashopVerifactu::buildDeclarationHtml() en verifactu.php, que
     * lo referencia en vez de llevar su propio valor por separado): si no
     * coinciden, la AEAT no puede casar los registros recibidos con el
     * sistema declarado.
     */
    public const ID_SISTEMA_INFORMATICO = '01';

    /**
     * Sin tipo estricto a propósito: según la versión de Joomla, \Joomla\CMS\Factory::getDbo()
     * devuelve una clase distinta (la antigua JDatabaseDriver, o la moderna
     * Joomla\Database\Mysqli\MysqliDriver / PdoDriver, etc.). Forzar un tipo
     * concreto rompe la instalación según la versión de Joomla que uses --
     * todas implementan los mismos métodos (getQuery, setQuery, loadObject...),
     * así que basta con no tipar la propiedad.
     */
    private $db;
    private Registry $params;

    public function __construct($db, Registry $params)
    {
        $this->db = $db;
        $this->params = $params;

        Verifactu::config(
            $this->params->get('cert_path'),
            $this->params->get('cert_pass'),
            Verifactu::TYPE_CERTIFICATE,
            $this->params->get('entorno', 'pre') === 'prod'
                ? Verifactu::ENVIRONMENT_PRODUCTION
                : Verifactu::ENVIRONMENT_SANDBOX
        );
    }

    /**
     * Mapa único de los motivos comerciales del campo personalizado HikaShop
     * a las claves oficiales de Veri*Factu*. El usuario solo selecciona el
     * motivo; nunca debe introducir R1/R2/R3/R4/I/S manualmente.
     *
     * v0.34.24: auditoría de seguridad, punto "Rectificativas" (prioridad
     * 🔴). 'Cancelación' YA NO es un motivo válido aquí -- coexistía con el
     * campo separado "Anular factura" (Registro de Anulación real, para una
     * operación que nunca existió de verdad) y su etiqueta invitaba a
     * confundir ambos mecanismos: un admin que ve la palabra "Cancelación"
     * puede razonablemente pensar que es el campo que necesita, cuando el
     * campo correcto para eso es otro. Se retira del mapa (ver
     * getTipoAbono(), que ahora la rechaza con un mensaje explícito) en vez
     * de reasignarla a otro código, siguiendo el mismo principio que ya
     * aplica el resto de este fichero: ante una elección ambigua, rechazar
     * explícitamente y pedir que se elija bien, no adivinar.
     */
    public static function mapTipoAbono(?string $tipoAbono): ?array
    {
        $tipoAbono = trim((string) $tipoAbono);

        $mapa = [
            'Devolución completa' => ['invoiceType' => InvoiceType::RECTIFICATION_1, 'rectificationType' => RectificationType::INCREMENTAL],
            'Devolución parcial'  => ['invoiceType' => InvoiceType::RECTIFICATION_1, 'rectificationType' => RectificationType::INCREMENTAL],
            'Mercancía obsoleta'  => ['invoiceType' => InvoiceType::RECTIFICATION_1, 'rectificationType' => RectificationType::INCREMENTAL],
            'Descuento posterior' => ['invoiceType' => InvoiceType::RECTIFICATION_1, 'rectificationType' => RectificationType::INCREMENTAL],
            'Error en IVA'        => ['invoiceType' => InvoiceType::RECTIFICATION_4, 'rectificationType' => RectificationType::INCREMENTAL],
            'Concurso de acreedores' => ['invoiceType' => InvoiceType::RECTIFICATION_2, 'rectificationType' => RectificationType::INCREMENTAL],
            'Crédito incobrable'  => ['invoiceType' => InvoiceType::RECTIFICATION_3, 'rectificationType' => RectificationType::INCREMENTAL],
            'Otro'                => ['invoiceType' => InvoiceType::RECTIFICATION_4, 'rectificationType' => RectificationType::INCREMENTAL],
        ];

        return $mapa[$tipoAbono] ?? null;
    }

    private function getTipoAbono($order): string
    {
        $fieldName = trim((string) $this->params->get('campo_tipo_abono', 'verifactu_tipo_abono'));
        if ($fieldName === '') {
            $fieldName = 'verifactu_tipo_abono';
        }

        // HikaShop añade los campos personalizados de tipo "pedido/order"
        // como propiedades del objeto del pedido. Admitimos también la variante
        // order_<nombre> para instalaciones que la expongan de esa forma.
        foreach ([$fieldName, 'order_' . $fieldName] as $property) {
            if (isset($order->{$property}) && trim((string) $order->{$property}) !== '') {
                return trim((string) $order->{$property});
            }
        }

        return '';
    }

    /**
     * Campo "Sustituye a factura simplificada (F3)" (tipo yesno de HikaShop).
     * Se marca a mano en el pedido cuando el cliente pide después una
     * factura con NIF para un pedido que ya tiene una F2 aceptada.
     */
    private function esSustitucionSimplificada($order): bool
    {
        $fieldName = trim((string) $this->params->get('campo_sustituye_simplificada', 'verifactu_sustituye_simplificada'));
        if ($fieldName === '') {
            $fieldName = 'verifactu_sustituye_simplificada';
        }

        foreach ([$fieldName, 'order_' . $fieldName] as $property) {
            if (isset($order->{$property})) {
                $valor = $order->{$property};
                if (is_bool($valor)) {
                    return $valor;
                }
                $valor = strtolower(trim((string) $valor));

                return in_array($valor, ['1', 'yes', 'si', 'sí', 'true'], true);
            }
        }

        return false;
    }

    /**
     * Última factura aceptada por la AEAT de un tipo concreto (F1/F2/F3) para
     * este mismo pedido. Se usa tanto para localizar la F2 que una F3 debe
     * referenciar como para impedir que un pedido acabe con más de una
     * factura "principal" (F1/F2/F3) que no sea, precisamente, la sustitución
     * F3 de una F2 -- eso sí es válido y esperado; una F1 conviviendo con una
     * F2/F3 del mismo pedido, no (sería una factura duplicada para la misma
     * venta).
     */
    /**
     * SistemaInformatico (identifica el software de facturación ante la
     * AEAT). Común a facturas (RegistroAlta) y anulaciones (RegistroAnulacion),
     * de ahí que esté extraído aparte y no repetido en ambos métodos.
     */
    private function construirSistemaInformatico(string $nifEmisor, \Closure $limpiarNif): ComputerSystem
    {
        $computerSystem = new ComputerSystem();
        // Identifica al sistema informático de facturación, que es este plugin
        // (no HikaShop, que no declara nada ante la AEAT), y su versión real.
        $computerSystem->systemName = 'HikaShop VeriFactu';
        $computerSystem->version = VerifactuVersion::get();
        $computerSystem->providerName = $this->params->get('nombre_desarrollador') ?: $this->params->get('nombre_emisor');
        $computerSystem->systemId = self::ID_SISTEMA_INFORMATICO;
        $computerSystem->installationNumber = '1';
        $computerSystem->onlyVerifactu = YesNoType::YES;
        $computerSystem->multipleObligations = YesNoType::NO;

        // El "proveedor" del SistemaInformatico es quien desarrolla/mantiene el
        // software -- puede ser un tercero (asesoría, programador externo) y
        // debe estar dado de alta ante la AEAT en ese papel. Si no se configura
        // por separado, se usa el emisor como fallback (caso de software propio).
        $provider = new LegalPerson();
        $provider->name = $this->params->get('nombre_desarrollador') ?: $this->params->get('nombre_emisor');
        $provider->nif = $limpiarNif($this->params->get('nif_desarrollador')) ?: $nifEmisor;
        $computerSystem->setProviderId($provider);

        return $computerSystem;
    }

    /**
     * Campo "Anular factura (error de emisión)" (singledropdown No/Sí de
     * HikaShop). Dispara un Registro de Anulación ante la AEAT -- NO una
     * rectificativa -- para una factura que nunca debió existir (error de
     * emisión, pedido duplicado...). Para devoluciones/correcciones de una
     * operación real, sigue siendo "Tipo de abono" (R1-R5).
     */
    private function esAnulacionSolicitada($order): bool
    {
        $fieldName = trim((string) $this->params->get('campo_anular_factura', 'verifactu_anular_factura'));
        if ($fieldName === '') {
            $fieldName = 'verifactu_anular_factura';
        }

        foreach ([$fieldName, 'order_' . $fieldName] as $property) {
            if (isset($order->{$property})) {
                $valor = $order->{$property};
                if (is_bool($valor)) {
                    return $valor;
                }
                $valor = strtolower(trim((string) $valor));

                return in_array($valor, ['1', 'yes', 'si', 'sí', 'true'], true);
            }
        }

        return false;
    }

    private function getMotivoAnulacion($order): string
    {
        $fieldName = trim((string) $this->params->get('campo_motivo_anulacion', 'verifactu_motivo_anulacion'));
        if ($fieldName === '') {
            $fieldName = 'verifactu_motivo_anulacion';
        }

        foreach ([$fieldName, 'order_' . $fieldName] as $property) {
            if (isset($order->{$property}) && trim((string) $order->{$property}) !== '') {
                return trim((string) $order->{$property});
            }
        }

        return '';
    }

    /**
     * Campo "Rectificar por diferencias (R1)" (singledropdown No/Sí de
     * HikaShop). Dispara una rectificativa R1 por diferencias (positiva o
     * negativa) sobre la última factura aceptada de ESTE MISMO pedido --
     * ver rectificarPorDiferencias() -- en vez de exigir un pedido de abono
     * aparte con importe negativo.
     */
    private function esRectificacionPorDiferenciasSolicitada($order): bool
    {
        $fieldName = trim((string) $this->params->get('campo_rectificar_diferencias', 'verifactu_rectificar_diferencias'));
        if ($fieldName === '') {
            $fieldName = 'verifactu_rectificar_diferencias';
        }

        foreach ([$fieldName, 'order_' . $fieldName] as $property) {
            if (isset($order->{$property})) {
                $valor = $order->{$property};
                if (is_bool($valor)) {
                    return $valor;
                }
                $valor = strtolower(trim((string) $valor));

                return in_array($valor, ['1', 'yes', 'si', 'sí', 'true'], true);
            }
        }

        return false;
    }

    /**
     * Lee un campo numérico (texto libre en HikaShop) del pedido como
     * float, o null si está vacío/no informado -- null es significativo
     * aquí (distinto de 0): "no informado" dispara mantener el valor de la
     * factura original, mientras que 0 sería un tipo/base real de cero.
     */
    private function getCampoDecimalOpcional($order, string $paramKey, string $defaultFieldName): ?float
    {
        $fieldName = trim((string) $this->params->get($paramKey, $defaultFieldName));
        if ($fieldName === '') {
            $fieldName = $defaultFieldName;
        }

        foreach ([$fieldName, 'order_' . $fieldName] as $property) {
            if (isset($order->{$property})) {
                $valor = trim((string) $order->{$property});
                if ($valor === '') {
                    return null;
                }

                // v0.34.33: mismo parseo de formato español/europeo
                // (miles con ".", decimales con ",") que
                // verifactu.php::parseNumeroEuropeo() -- se duplica aquí
                // (clase distinta) a propósito, como red de seguridad, por
                // si este valor llegara sin pasar por onBeforeOrderUpdate.
                $tienePunto = strpos($valor, '.') !== false;
                $tieneComa = strpos($valor, ',') !== false;
                if ($tienePunto && $tieneComa) {
                    $valor = str_replace('.', '', $valor);
                    $valor = str_replace(',', '.', $valor);
                } elseif ($tieneComa) {
                    $valor = str_replace(',', '.', $valor);
                } elseif ($tienePunto && preg_match('/^[1-9][0-9]{0,2}\.[0-9]{3}$/', $valor)) {
                    $valor = str_replace('.', '', $valor);
                }

                if (!is_numeric($valor)) {
                    return null;
                }

                return (float) $valor;
            }
        }

        return null;
    }

    /**
     * Igual que getCampoDecimalOpcional(), pero SOLO para "Nuevo tipo de
     * IVA"/"Nuevo tipo de recargo": en la práctica HikaShop no deja estos
     * campos numéricos realmente vacíos una vez que el pedido se ha
     * guardado una vez con "Rectificar por diferencias" en Sí (el propio
     * formulario los rellena con "0.00"), así que un merchant nunca podría
     * cumplir la instrucción de "déjalo en blanco para mantener el tipo
     * original". Se trata 0 (o prácticamente 0) igual que "no indicado" --
     * sin pérdida real de funcionalidad, porque el único caso en que un 0
     * explícito distinto del original tendría sentido ya lo bloquea de
     * todas formas la comprobación de cambio de tipo no soportado más
     * abajo en rectificarPorDiferencias().
     */
    private function getCampoDecimalOpcionalIgnorandoCero($order, string $paramKey, string $defaultFieldName): ?float
    {
        $valor = $this->getCampoDecimalOpcional($order, $paramKey, $defaultFieldName);

        return ($valor !== null && abs($valor) < 0.0001) ? null : $valor;
    }

    private function getNuevaBaseImponible($order): ?float
    {
        return $this->getCampoDecimalOpcional($order, 'campo_nueva_base_imponible', 'verifactu_nueva_base_imponible');
    }

    private function getNuevoTipoIva($order): ?float
    {
        return $this->getCampoDecimalOpcionalIgnorandoCero($order, 'campo_nuevo_tipo_iva', 'verifactu_nuevo_tipo_iva');
    }

    private function getNuevoTipoRecargo($order): ?float
    {
        return $this->getCampoDecimalOpcionalIgnorandoCero($order, 'campo_nuevo_tipo_recargo', 'verifactu_nuevo_tipo_recargo');
    }

    /**
     * Wrapper público, mismo patrón que debeAnular(): permite a
     * verifactu.php decidir si debe llamar a rectificarPorDiferencias() en
     * vez de a registrarFactura()/anularFactura(), sin duplicar el parsing
     * del campo.
     */
    public function debeRectificarPorDiferencias($order): bool
    {
        return $this->esRectificacionPorDiferenciasSolicitada($order);
    }

    /**
     * v0.34.59: importe máximo (IVA incluido) de una factura simplificada
     * F2, tomado del parámetro `limite_simplificada` del plugin. Por
     * defecto 3.000 € (ventas al por menor a consumidor final); 400 € es el
     * límite general si la tienda no vende solo a consumidores finales. Un
     * valor vacío, no numérico o <= 0 se trata como el valor por defecto.
     */
    /**
     * Importe del pedido en euros, para compararlo con el límite de la
     * simplificada: order_full_price está en la moneda del pedido. Usa los
     * tipos de cambio actuales de HikaShop; sin ellos, el importe tal cual.
     */
    private function importeEnEuros($order, float $importe): float
    {
        $monedaPedido = (int) ($order->order_currency_id ?? 0);
        if ($monedaPedido === 0) {
            return $importe;
        }

        try {
            $this->db->setQuery(
                $this->db->getQuery(true)
                    ->select(['currency_id', 'currency_code', 'currency_rate'])
                    ->from('#__hikashop_currency')
                    ->where('(currency_id = ' . $monedaPedido . ' OR currency_code = ' . $this->db->quote('EUR') . ')')
            );
            $monedas = $this->db->loadObjectList() ?: [];
        } catch (\Throwable $e) {
            return $importe;
        }

        $tasaPedido = null;
        $tasaEuro = null;
        foreach ($monedas as $moneda) {
            if ((int) $moneda->currency_id === $monedaPedido) {
                if ($moneda->currency_code === 'EUR') {
                    return $importe;
                }
                $tasaPedido = (float) $moneda->currency_rate;
            }
            if ($moneda->currency_code === 'EUR') {
                $tasaEuro = (float) $moneda->currency_rate;
            }
        }

        if (!$tasaPedido || !$tasaEuro) {
            return $importe;
        }

        // en HikaShop los tipos son relativos a la moneda principal
        return round($importe / $tasaPedido * $tasaEuro, 2);
    }

    private function getLimiteSimplificada(): float
    {
        $valor = trim((string) $this->params->get('limite_simplificada', '3000'));
        $valor = str_replace(',', '.', str_replace(' ', '', $valor));
        $limite = is_numeric($valor) ? (float) $valor : 0.0;

        return $limite > 0 ? $limite : 3000.0;
    }

    /**
     * Última factura ("alta") aceptada de este pedido, de cualquier tipo
     * (F1/F2/F3/R1-R5), que todavía no tenga una anulación aceptada. Es la
     * que "Anular factura" va a anular si no se especifica ninguna otra
     * cosa -- en la inmensa mayoría de los casos un pedido con un error de
     * emisión solo tiene una factura activa en ese momento.
     */
    private function obtenerFacturaParaAnular(int $orderId): ?object
    {
        $query = $this->db->getQuery(true)
            ->select(['invoice_number', 'invoice_date', 'nif_emisor'])
            ->from('#__hikashop_verifactu_registro')
            ->where('order_id = ' . $orderId)
            ->where('tipo_registro = ' . $this->db->quote('alta'))
            ->where('estado_envio IN (' . $this->db->quote('aceptado') . ',' . $this->db->quote('aceptado_con_errores') . ')')
            ->where(
                'invoice_number NOT IN ('
                . 'SELECT invoice_number FROM #__hikashop_verifactu_registro'
                . ' WHERE order_id = ' . $orderId
                . ' AND tipo_registro = ' . $this->db->quote('anulacion')
                . ' AND estado_envio IN (' . $this->db->quote('aceptado') . ',' . $this->db->quote('aceptado_con_errores') . ')'
                . ')'
            )
            ->order('id DESC')
            ->setLimit(1);
        $this->db->setQuery($query);

        $fila = $this->db->loadObject();

        return $fila ?: null;
    }

    /**
     * La factura que se rectifica por diferencias: la última "alta" aceptada y
     * no anulada del pedido que no sea a su vez una rectificación por
     * diferencias (R1/R5 cuya factura rectificada es otra alta del mismo
     * pedido). Se le añaden los importes acumulados (su desglose más el de las
     * rectificaciones por diferencias ya aceptadas sobre ella) y el número de
     * esas rectificaciones: la diferencia se calcula siempre contra lo ya
     * declarado, así que volver a guardar el pedido no genera otra R1.
     */
    private function obtenerFacturaParaRectificarPorDiferencias(int $orderId): ?object
    {
        $query = $this->db->getQuery(true)
            ->select(['invoice_number', 'invoice_date', 'nif_emisor', 'tipo_factura', 'factura_rectificada', 'base_imponible', 'tipo_iva', 'cuota_iva', 'tipo_recargo', 'recargo_equivalencia', 'clave_regimen', 'operacion_exenta'])
            ->from('#__hikashop_verifactu_registro')
            ->where('order_id = ' . $orderId)
            ->where('tipo_registro = ' . $this->db->quote('alta'))
            ->where('estado_envio IN (' . $this->db->quote('aceptado') . ',' . $this->db->quote('aceptado_con_errores') . ')')
            ->where(
                'invoice_number NOT IN ('
                . 'SELECT invoice_number FROM #__hikashop_verifactu_registro'
                . ' WHERE order_id = ' . $orderId
                . ' AND tipo_registro = ' . $this->db->quote('anulacion')
                . ' AND estado_envio IN (' . $this->db->quote('aceptado') . ',' . $this->db->quote('aceptado_con_errores') . ')'
                . ')'
            )
            ->order('id ASC');
        $this->db->setQuery($query);
        $filas = $this->db->loadObjectList() ?: [];

        $numeros = [];
        foreach ($filas as $fila) {
            $numeros[(string) $fila->invoice_number] = true;
        }

        $base = null;
        $diferencias = [];
        foreach ($filas as $fila) {
            $esDiferencia = in_array((string) $fila->tipo_factura, ['R1', 'R5'], true)
                && $fila->factura_rectificada !== null
                && (string) $fila->factura_rectificada !== (string) $fila->invoice_number
                && isset($numeros[(string) $fila->factura_rectificada]);
            if ($esDiferencia) {
                $diferencias[] = $fila;
            } else {
                $base = $fila;
            }
        }

        if ($base === null) {
            return null;
        }

        $base->base_acumulada = $base->base_imponible !== null ? (float) $base->base_imponible : null;
        $base->cuota_acumulada = $base->cuota_iva !== null ? (float) $base->cuota_iva : null;
        $base->recargo_acumulado = $base->recargo_equivalencia !== null ? (float) $base->recargo_equivalencia : null;
        $base->rectificaciones = 0;
        foreach ($diferencias as $dif) {
            if ((string) $dif->factura_rectificada !== (string) $base->invoice_number) {
                continue;
            }
            $base->rectificaciones++;
            if ($base->base_acumulada !== null) {
                $base->base_acumulada += (float) $dif->base_imponible;
            }
            if ($base->cuota_acumulada !== null) {
                $base->cuota_acumulada += (float) $dif->cuota_iva;
            }
            if ($dif->recargo_equivalencia !== null) {
                $base->recargo_acumulado = (float) $base->recargo_acumulado + (float) $dif->recargo_equivalencia;
            }
        }

        return $base;
    }

    private function obtenerFacturaPorTipo(int $orderId, string $tipoFactura): ?object
    {
        $query = $this->db->getQuery(true)
            ->select(['invoice_number', 'invoice_date', 'nif_emisor'])
            ->from('#__hikashop_verifactu_registro')
            ->where('order_id = ' . $orderId)
            ->where('tipo_factura = ' . $this->db->quote($tipoFactura))
            ->where('estado_envio IN (' . $this->db->quote('aceptado') . ',' . $this->db->quote('aceptado_con_errores') . ')')
            ->order('id DESC')
            ->setLimit(1);
        $this->db->setQuery($query);

        $fila = $this->db->loadObject();

        return $fila ?: null;
    }

    private function obtenerFacturaSimplificadaPrevia(int $orderId): ?object
    {
        return $this->obtenerFacturaPorTipo($orderId, 'F2');
    }

    private function ensureSchema(): void
    {
        $table = '#__hikashop_verifactu_registro';
        $columns = [
            'tipo_abono' => "ALTER TABLE `{$table}` ADD COLUMN `tipo_abono` VARCHAR(80) NULL AFTER `total_factura`",
            'tipo_factura' => "ALTER TABLE `{$table}` ADD COLUMN `tipo_factura` VARCHAR(2) NULL AFTER `tipo_abono`",
            'tipo_rectificativa' => "ALTER TABLE `{$table}` ADD COLUMN `tipo_rectificativa` VARCHAR(1) NULL AFTER `tipo_factura`",
            'factura_rectificada' => "ALTER TABLE `{$table}` ADD COLUMN `factura_rectificada` VARCHAR(60) NULL AFTER `tipo_rectificativa`",
            // v0.34.22: ver comentario en guardarRegistro() -- referencia al
            // `id` (ahora sí, secuencia cronológica real de generación) del
            // registro inmediatamente anterior con el que se encadenó éste.
            'registro_anterior_id' => "ALTER TABLE `{$table}` ADD COLUMN `registro_anterior_id` INT UNSIGNED NULL AFTER `factura_rectificada`",
            // v0.34.30: ver el comentario junto a estas mismas columnas en
            // script.php::COLUMNS -- desglose de la factura (cuando se
            // reduce a una sola línea de IVA), guardado para que una
            // rectificación por diferencias posterior pueda leerlo.
            'base_imponible' => "ALTER TABLE `{$table}` ADD COLUMN `base_imponible` DECIMAL(12,2) NULL AFTER `motivo_anulacion`",
            'tipo_iva' => "ALTER TABLE `{$table}` ADD COLUMN `tipo_iva` DECIMAL(5,2) NULL AFTER `base_imponible`",
            'cuota_iva' => "ALTER TABLE `{$table}` ADD COLUMN `cuota_iva` DECIMAL(12,2) NULL AFTER `tipo_iva`",
            'tipo_recargo' => "ALTER TABLE `{$table}` ADD COLUMN `tipo_recargo` DECIMAL(5,2) NULL AFTER `cuota_iva`",
            'recargo_equivalencia' => "ALTER TABLE `{$table}` ADD COLUMN `recargo_equivalencia` DECIMAL(12,2) NULL AFTER `tipo_recargo`",
            // v0.34.57: régimen (ClaveRegimen) y causa de exención
            // (OperacionExenta) de la línea de desglose, para que una
            // rectificación por diferencias sobre una factura EXENTA
            // (exportación E2 / entrega intracomunitaria E5) repita el mismo
            // tratamiento fiscal en vez de declarar IVA.
            'clave_regimen' => "ALTER TABLE `{$table}` ADD COLUMN `clave_regimen` VARCHAR(2) NULL AFTER `recargo_equivalencia`",
            'operacion_exenta' => "ALTER TABLE `{$table}` ADD COLUMN `operacion_exenta` VARCHAR(2) NULL AFTER `clave_regimen`",
        ];

        foreach ($columns as $column => $sql) {
            $check = $this->db->getQuery(true)
                ->select('COUNT(*)')
                ->from('INFORMATION_SCHEMA.COLUMNS')
                ->where('TABLE_SCHEMA = DATABASE()')
                ->where('TABLE_NAME = ' . $this->db->quote(str_replace('#__', $this->db->getPrefix(), $table)))
                ->where('COLUMN_NAME = ' . $this->db->quote($column));
            $this->db->setQuery($check);
            if (!(int) $this->db->loadResult()) {
                try {
                    $this->db->setQuery($sql);
                    $this->db->execute();
                } catch (\Throwable $e) {
                    // No bloquear una factura ya existente por una migración de esquema.
                    VerifactuLog::add('Error creando columna ' . $column . ': ' . $e->getMessage(), \Joomla\CMS\Log\Log::WARNING);
                }
            }
        }
    }

    /**
     * Red de seguridad por si script.php no llegó a crear las tablas de la
     * numeración propia (instalación manual, restauración de BBDD, etc.) --
     * mismo patrón que ensureSchema(). CREATE TABLE IF NOT EXISTS es idempotente,
     * así que no pasa nada por llamarlo en cada factura.
     */
    private function ensureNumeracionSchema(): void
    {
        $prefix = $this->db->getPrefix();

        $tablas = [
            $prefix . 'hikashop_verifactu_contador' => "CREATE TABLE IF NOT EXISTS `%s` (
                `familia` VARCHAR(2) NOT NULL,
                `anio` VARCHAR(4) NOT NULL,
                `ultimo_numero` INT UNSIGNED NOT NULL DEFAULT 0,
                PRIMARY KEY (`familia`, `anio`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
            $prefix . 'hikashop_verifactu_numeracion_asignada' => "CREATE TABLE IF NOT EXISTS `%s` (
                `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
                `order_id` INT UNSIGNED NOT NULL,
                `familia` VARCHAR(10) NOT NULL,
                `numero` VARCHAR(60) NOT NULL,
                `numero_original_hikashop` VARCHAR(60) NULL,
                `created` DATETIME NOT NULL,
                PRIMARY KEY (`id`),
                UNIQUE KEY `idx_order_familia` (`order_id`, `familia`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
        ];

        foreach ($tablas as $tabla => $sql) {
            try {
                $this->db->setQuery(sprintf($sql, $tabla));
                $this->db->execute();
            } catch (\Throwable $e) {
                VerifactuLog::add('Error creando tabla ' . $tabla . ': ' . $e->getMessage(), \Joomla\CMS\Log\Log::WARNING);
            }
        }

        $this->ensancharFamilia($prefix . 'hikashop_verifactu_numeracion_asignada');
    }

    /**
     * Red de seguridad de script.php::migrarFamiliaAncha(): las claves RD1,
     * RD2... de las rectificaciones por diferencias no caben en VARCHAR(2).
     */
    private function ensancharFamilia(string $tabla): void
    {
        try {
            $this->db->setQuery(
                'SELECT CHARACTER_MAXIMUM_LENGTH FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE()'
                . ' AND TABLE_NAME = ' . $this->db->quote($tabla) . ' AND COLUMN_NAME = ' . $this->db->quote('familia')
            );
            $largo = $this->db->loadResult();
            if ($largo !== null && (int) $largo < 10) {
                $this->db->setQuery('ALTER TABLE ' . $this->db->quoteName($tabla) . ' MODIFY COLUMN `familia` VARCHAR(10) NOT NULL');
                $this->db->execute();
            }
        } catch (\Throwable $e) {
            VerifactuLog::add('Error ampliando la columna familia de ' . $tabla . ': ' . $e->getMessage(), \Joomla\CMS\Log\Log::WARNING);
        }
    }

    /**
     * Red de seguridad por si script.php no llegó a crear la tabla de fecha
     * de expedición congelada -- mismo patrón que ensureNumeracionSchema().
     */
    private function ensureFechaExpedicionSchema(): void
    {
        $tabla = $this->db->getPrefix() . 'hikashop_verifactu_fecha_expedicion';
        $sql = "CREATE TABLE IF NOT EXISTS `%s` (
            `order_id` INT UNSIGNED NOT NULL,
            `familia` VARCHAR(10) NOT NULL,
            `fecha_expedicion` DATE NOT NULL,
            `created` DATETIME NOT NULL,
            PRIMARY KEY (`order_id`, `familia`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";

        try {
            $this->db->setQuery(sprintf($sql, $tabla));
            $this->db->execute();
        } catch (\Throwable $e) {
            VerifactuLog::add('Error creando tabla ' . $tabla . ': ' . $e->getMessage(), \Joomla\CMS\Log\Log::WARNING);
        }

        $this->ensancharFamilia($tabla);
    }

    /**
     * FechaExpedicionFactura congelada para este pedido+familia: se fija la
     * PRIMERA vez que se procesa (éxito o fracaso -- ver comentario en la
     * llamada, en registrarFactura()) y se reutiliza en cualquier reintento
     * posterior, en vez de recalcularse con date('Y-m-d') cada vez. Formato
     * "Y-m-d", igual que antes.
     */
    private function obtenerFechaExpedicionCongelada(int $orderId, string $familia): string
    {
        $this->ensureFechaExpedicionSchema();

        $query = $this->db->getQuery(true)
            ->select($this->db->quoteName('fecha_expedicion'))
            ->from('#__hikashop_verifactu_fecha_expedicion')
            ->where('order_id = ' . $orderId)
            ->where('familia = ' . $this->db->quote($familia));
        $this->db->setQuery($query);
        $existente = $this->db->loadResult();

        if (!empty($existente)) {
            // DATE vuelve de la BBDD como "Y-m-d" ya (sin parte horaria).
            return (string) $existente;
        }

        $hoy = date('Y-m-d');

        try {
            // insertObject() recibe el objeto por referencia: un literal da un Error en Joomla 4+
            $fila = (object) [
                'order_id' => $orderId,
                'familia' => $familia,
                'fecha_expedicion' => $hoy,
                'created' => date('Y-m-d H:i:s'),
            ];
            $this->db->insertObject('#__hikashop_verifactu_fecha_expedicion', $fila);
        } catch (\Throwable $e) {
            // Condición de carrera (dos procesos casi simultáneos) u otro
            // fallo de escritura: no debe bloquear la emisión de la
            // factura por esto. Si ya existe una fila (carrera ganada por
            // el otro proceso), se relee para no desincronizar número y
            // fecha entre ambos intentos.
            VerifactuLog::add('Error grabando fecha de expedición congelada pedido ' . $orderId . ' familia ' . $familia . ': ' . $e->getMessage(), \Joomla\CMS\Log\Log::WARNING);

            $this->db->setQuery($query);
            $reintento = $this->db->loadResult();
            if (!empty($reintento)) {
                return (string) $reintento;
            }
        }

        return $hoy;
    }

    /**
     * F1 (ordinaria), F2 (simplificada) y R (rectificativa, R1-R4 comparten
     * una sola serie) obtienen, si la opción está activada en la
     * configuración del plugin, su propia serie correlativa con prefijo:
     * {año 2 dígitos}{prefijo}-{contador con 4 dígitos}, por ejemplo
     * 26-0214, 26FS-0001 o 26R-0001. Las tres se gestionan igual a
     * propósito: si solo F2 y R tuvieran serie propia y F1 siguiera con el
     * contador nativo de HikaShop, ese contador seguiría avanzando también
     * en los pedidos F2/R (para un número que luego se descarta), dejando
     * huecos en la serie F1.
     *
     * Es idempotente por pedido+familia: si el pedido ya tiene un número
     * propio asignado (ej. reintento tras corregir el NIF), se reutiliza el
     * mismo en vez de consumir otro número de la serie. El contador se
     * incrementa dentro de una transacción con SELECT ... FOR UPDATE para
     * que dos facturas de la misma familia procesadas casi a la vez (ej. un
     * envío en lote) no puedan recibir el mismo número.
     *
     * También actualiza `#__hikashop_order`.order_invoice_number, para que
     * la factura que ve/descarga el cliente muestre el mismo número que se
     * registra en la AEAT.
     */
    /**
     * Busca, entre los pedidos de HikaShop, el número más alto ya usado con
     * el prefijo exacto dado (ej. "26-", "26FS-", "26R-") y devuelve ese
     * número para continuar la serie a partir de ahí, en vez de arrancar en
     * 1. Es lo que evita que la primera factura F1 tras activar esta función
     * vuelva a empezar por "26-0001" y choque con las que ya existían.
     *
     * Usa LIKE + filtrado en PHP en vez de REGEXP para no depender de
     * peculiaridades de escapado entre motores/versiones de MySQL/MariaDB.
     */
    private function obtenerNumeroSemilla(string $prefijoCompleto): int
    {
        $likeSeguro = str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $prefijoCompleto) . '%';

        $query = $this->db->getQuery(true)
            ->select($this->db->quoteName('order_invoice_number'))
            ->from('#__hikashop_order')
            ->where($this->db->quoteName('order_invoice_number') . ' LIKE ' . $this->db->quote($likeSeguro));
        $this->db->setQuery($query);

        $valores = $this->db->loadColumn() ?: [];
        $len = strlen($prefijoCompleto);
        $max = 0;

        foreach ($valores as $valor) {
            if ($valor === null || strncmp((string) $valor, $prefijoCompleto, $len) !== 0) {
                continue;
            }
            $resto = substr((string) $valor, $len);
            if ($resto !== '' && ctype_digit($resto)) {
                $max = max($max, (int) $resto);
            }
        }

        return $max;
    }

    /**
     * $clave: clave de la asignación cuando no es la familia (RD1, RD2... para
     * cada rectificación por diferencias de un pedido, que numeran en la serie
     * R). $actualizarPedido: false para esas rectificaciones, que no son la
     * factura del pedido y no deben cambiar su order_invoice_number.
     */
    private function aplicarNumeracionPropia($order, string $familia, string $fechaOperacion, ?string $clave = null, bool $actualizarPedido = true): string
    {
        $original = (string) ($order->order_invoice_number ?? $order->order_number);
        $clave = $clave ?? $familia;

        if ((string) $this->params->get('numeracion_propia_activa', '0') !== '1') {
            return $original;
        }

        $this->ensureNumeracionSchema();

        $orderId = (int) $order->order_id;

        $query = $this->db->getQuery(true)
            ->select($this->db->quoteName('numero'))
            ->from('#__hikashop_verifactu_numeracion_asignada')
            ->where('order_id = ' . $orderId)
            ->where('familia = ' . $this->db->quote($clave));
        $this->db->setQuery($query);
        $existente = $this->db->loadResult();

        if (!empty($existente)) {
            return (string) $existente;
        }

        // El año de la numeración SIEMPRE sale de la misma fecha que se usa
        // como FechaExpedicionFactura de la factura (ver registrarFactura) --
        // nunca de una llamada a date() independiente -- para que una serie
        // "26-..." no pueda darse nunca con una factura fechada en 2025.
        $anio = substr($fechaOperacion, 2, 2);
        $defaults = ['F1' => '', 'F2' => 'FS', 'F3' => 'F3', 'R' => 'R'];
        $prefijo = trim((string) $this->params->get('prefijo_' . strtolower($familia), $defaults[$familia] ?? ''));
        $prefijoCompleto = $anio . $prefijo . '-';
        $tablaContador = $this->db->quoteName('#__hikashop_verifactu_contador');

        $this->db->transactionStart();

        try {
            // 1) Se comprueba primero si la fila del contador ya existe (caso
            // normal, ya se ha emitido antes alguna factura de esta
            // familia+año). FOR UPDATE la bloquea para el resto de esta
            // transacción.
            $this->db->setQuery(
                'SELECT ultimo_numero FROM ' . $tablaContador
                . ' WHERE familia = ' . $this->db->quote($familia)
                . ' AND anio = ' . $this->db->quote($anio)
                . ' FOR UPDATE'
            );
            $ultimo = $this->db->loadResult();

            if ($ultimo === null) {
                // 2) Primera vez que esta familia+año necesita un número
                // propio. Para no arrancar en 1 y chocar (o retroceder) con
                // números ya usados anteriormente -- típicamente el caso de
                // F1 la primera vez que se activa esta función, que hasta
                // ahora vivía en la numeración nativa de HikaShop -- se
                // busca el número más alto ya usado con este mismo prefijo
                // y se continúa a partir de ahí. Un ajuste manual
                // (semilla_<familia>) tiene prioridad sobre la búsqueda
                // automática, para las tiendas cuyo formato de factura nativo
                // de HikaShop no sea "{año}{prefijo}-{dígitos}" -- en ese
                // caso la búsqueda automática no reconocería ningún número
                // existente y arrancaría en 1, así que hace falta poder
                // indicarlo a mano.
                $semillaManual = trim((string) $this->params->get('semilla_' . strtolower($familia), ''));
                $semilla = $semillaManual !== '' && ctype_digit($semillaManual)
                    ? (int) $semillaManual
                    : $this->obtenerNumeroSemilla($prefijoCompleto);

                // INSERT ... ON DUPLICATE KEY UPDATE es atómico: si dos
                // pedidos de la misma familia+año llegan casi a la vez y
                // ninguno tenía fila todavía, esto evita que choquen por la
                // clave primaria sea cual sea el orden de llegada.
                $this->db->setQuery(
                    'INSERT INTO ' . $tablaContador . ' (familia, anio, ultimo_numero) VALUES ('
                    . $this->db->quote($familia) . ', ' . $this->db->quote($anio) . ', ' . $semilla . ')'
                    . ' ON DUPLICATE KEY UPDATE familia = familia'
                );
                $this->db->execute();

                // Se vuelve a leer con FOR UPDATE por si otro proceso ganó
                // la carrera e insertó primero con una semilla distinta.
                $this->db->setQuery(
                    'SELECT ultimo_numero FROM ' . $tablaContador
                    . ' WHERE familia = ' . $this->db->quote($familia)
                    . ' AND anio = ' . $this->db->quote($anio)
                    . ' FOR UPDATE'
                );
                $ultimo = $this->db->loadResult();
            }

            // 3) Aunque el contador propio avance sin huecos, un prefijo
            // vacío (F1 por defecto, para conservar el aspecto nativo de
            // HikaShop) produce el MISMO formato que la numeración nativa de
            // HikaShop -- y HikaShop sigue asignando su propio número a
            // CUALQUIER factura que numera, llegue o no a procesarse por
            // este plugin (ej. una factura que nunca alcanza el estado
            // disparador). Esas dos series independientes pueden acabar
            // generando el mismo número por coincidencia. Para que eso no
            // se traduzca nunca en un choque real, antes de dar por bueno un
            // número se comprueba que NINGÚN OTRO pedido lo tenga ya como
            // order_invoice_number, y si lo tiene, se salta hacia adelante
            // hasta encontrar uno libre. El tope de 10000 intentos es solo
            // una red de seguridad para no buclear infinitamente ante un
            // fallo inesperado.
            $intentos = 0;
            do {
                $siguiente = (int) $ultimo + 1;
                $numero = $prefijoCompleto . str_pad((string) $siguiente, 4, '0', STR_PAD_LEFT);
                $ultimo = $siguiente;
                $intentos++;

                $this->db->setQuery(
                    'SELECT 1 FROM ' . $this->db->quoteName('#__hikashop_order')
                    . ' WHERE ' . $this->db->quoteName('order_invoice_number') . ' = ' . $this->db->quote($numero)
                    . ' AND order_id != ' . $orderId
                    . ' LIMIT 1'
                );
                $enUso = $this->db->loadResult();
                if ($enUso === null) {
                    // las rectificaciones por diferencias solo dejan su número en el registro
                    $this->db->setQuery(
                        'SELECT 1 FROM ' . $this->db->quoteName('#__hikashop_verifactu_registro')
                        . ' WHERE ' . $this->db->quoteName('invoice_number') . ' = ' . $this->db->quote($numero)
                        . ' LIMIT 1'
                    );
                    $enUso = $this->db->loadResult();
                }
            } while ($enUso !== null && $intentos < 10000);

            if ($enUso !== null) {
                throw new \RuntimeException('No se encontró un número libre para ' . $familia . '/' . $anio . ' tras 10000 intentos.');
            }

            $this->db->setQuery(
                'UPDATE ' . $tablaContador . ' SET ultimo_numero = ' . $siguiente
                . ' WHERE familia = ' . $this->db->quote($familia)
                . ' AND anio = ' . $this->db->quote($anio)
            );
            $this->db->execute();

            $asignacion = (object) [
                'order_id' => $orderId,
                'familia' => $clave,
                'numero' => $numero,
                'numero_original_hikashop' => $original,
                'created' => date('Y-m-d H:i:s'),
            ];
            $this->db->insertObject('#__hikashop_verifactu_numeracion_asignada', $asignacion);

            $this->db->transactionCommit();
        } catch (\Throwable $e) {
            $this->db->transactionRollback();
            VerifactuLog::add('ERROR asignando numeración propia (' . $familia . ') al pedido ' . $orderId . ': ' . $e->getMessage() . '. Se usa el número nativo de HikaShop (' . $original . ') como fallback.', \Joomla\CMS\Log\Log::ERROR);

            return $original;
        }

        try {
            if ($actualizarPedido) {
                $this->db->setQuery(
                    'UPDATE ' . $this->db->quoteName('#__hikashop_order')
                    . ' SET order_invoice_number = ' . $this->db->quote($numero)
                    . ' WHERE order_id = ' . $orderId
                );
                $this->db->execute();
            }
        } catch (\Throwable $e) {
            VerifactuLog::add('ERROR actualizando order_invoice_number del pedido ' . $orderId . ' a ' . $numero . ': ' . $e->getMessage(), \Joomla\CMS\Log\Log::WARNING);
        }

        VerifactuLog::add('Numeración propia asignada al pedido ' . $orderId . ': ' . $numero . ' (familia ' . $familia . ', número nativo de HikaShop: ' . $original . ').');

        return $numero;
    }

    /**
     * La factura que rectifica un pedido de abono, para FacturasRectificadas.
     * Primero la indicada en "Comentario del abono" (el placeholder del campo
     * ya lo pide): un número que el registro tenga como alta aceptada de otro
     * pedido. Si no, la factura inmediatamente anterior (número - 1), pero solo
     * si es del mismo cliente: con la numeración nativa de HikaShop suele ser la
     * de otro cliente, y declarar que se rectifica una factura ajena es peor que
     * no informar ninguna.
     */
    private function obtenerFacturaRectificada($order, string $invoiceNumber): ?object
    {
        $original = $this->buscarFacturaEnComentario($order);

        if ($original === null) {
            if (!preg_match('/^(.*?)(\d+)$/', trim($invoiceNumber), $m)) {
                return null;
            }

            $prefijo = $m[1];
            $numero = (int) $m[2];
            if ($numero <= 0) {
                return null;
            }

            $numeroAnterior = $numero - 1;
            $numeroAnteriorFormateado = str_pad((string) $numeroAnterior, strlen($m[2]), '0', STR_PAD_LEFT);
            $candidato = $prefijo . $numeroAnteriorFormateado;

            $query = $this->db->getQuery(true)
                ->select('order_id, order_invoice_number, order_full_price, order_created, order_user_id')
                ->from('#__hikashop_order')
                ->where('order_invoice_number = ' . $this->db->quote($candidato))
                ->where('order_id <> ' . (int) $order->order_id)
                ->setLimit(1);

            $this->db->setQuery($query);
            $original = $this->db->loadObject();

            if (!$original || (float) $original->order_full_price <= 0) {
                return null;
            }

            $usuario = (int) ($order->order_user_id ?? 0);
            if ($usuario === 0 || (int) $original->order_user_id !== $usuario) {
                VerifactuLog::add('Pedido ' . (int) $order->order_id . ': la factura anterior ' . $candidato . ' es de otro cliente, no se informa como factura rectificada. Indica el número de la factura original en "Comentario del abono".', \Joomla\CMS\Log\Log::WARNING);

                return null;
            }
        }

        // v0.34.21: la fecha que hay que informar a la AEAT en
        // FacturasRectificadas es la FechaExpedicionFactura real con la que
        // se emitió esa factura original -- no necesariamente la fecha del
        // PEDIDO (order_created), que puede ser anterior si la factura se
        // emitió más tarde. Se busca en el registro local (que guarda la
        // fecha de expedición real usada en su día); si no se encuentra
        // (factura emitida antes de que este registro existiera, o
        // pérdida de datos), se recurre a order_created como aproximación,
        // igual que antes.
        $this->db->setQuery(
            $this->db->getQuery(true)
                ->select('invoice_date')
                ->from('#__hikashop_verifactu_registro')
                ->where('order_id = ' . (int) $original->order_id)
                ->where('invoice_number = ' . $this->db->quote($original->order_invoice_number))
                ->where('tipo_registro = ' . $this->db->quote('alta'))
                ->where('estado_envio IN (' . $this->db->quote('aceptado') . ',' . $this->db->quote('aceptado_con_errores') . ')')
                ->order('id DESC')
                ->setLimit(1)
        );
        $fechaReal = $this->db->loadResult();
        // invoice_date es DATETIME ("2026-09-17 00:00:00"); se recorta a
        // los 10 primeros caracteres como en el resto del fichero.
        $original->fecha_expedicion_real = $fechaReal !== null
            ? substr((string) $fechaReal, 0, 10)
            : date('Y-m-d', (int) $original->order_created);

        return $original;
    }

    /**
     * Un número de factura escrito en "Comentario del abono" que el registro
     * tenga como alta aceptada de otro pedido, o null.
     */
    private function buscarFacturaEnComentario($order): ?object
    {
        $comentario = (string) ($order->verifactu_comentario_abono ?? '');
        if (!preg_match_all('#[A-Za-z0-9][A-Za-z0-9/_.\-]*[A-Za-z0-9]#', $comentario, $m) || empty($m[0])) {
            return null;
        }

        $candidatos = array_slice(array_unique($m[0]), 0, 20);
        $query = $this->db->getQuery(true)
            ->select('DISTINCT r.invoice_number, r.order_id')
            ->from('#__hikashop_verifactu_registro AS r')
            ->where('r.invoice_number IN (' . implode(',', array_map([$this->db, 'quote'], $candidatos)) . ')')
            ->where('r.order_id <> ' . (int) $order->order_id)
            ->where('r.tipo_registro = ' . $this->db->quote('alta'))
            ->where('r.estado_envio IN (' . $this->db->quote('aceptado') . ',' . $this->db->quote('aceptado_con_errores') . ')');
        $this->db->setQuery($query);
        $filas = $this->db->loadObjectList() ?: [];
        if (count($filas) !== 1) {
            return null;
        }

        $this->db->setQuery(
            $this->db->getQuery(true)
                ->select('order_id, order_invoice_number, order_full_price, order_created, order_user_id')
                ->from('#__hikashop_order')
                ->where('order_id = ' . (int) $filas[0]->order_id)
        );
        $original = $this->db->loadObject();
        if (!$original) {
            return null;
        }
        // el número registrado en la AEAT, aunque el pedido tenga otro
        $original->order_invoice_number = $filas[0]->invoice_number;

        return $original;
    }

    /**
     * Registra una factura de HikaShop en la AEAT usando la librería,
     * y guarda el resultado (incluido el hash devuelto por la propia librería)
     * en la tabla de encadenamiento local para la siguiente factura.
     */
    /**
     * A partir de aplicarNumeracionPropia() dentro de registrarFactura(), el
     * pedido ya tiene un número de la serie F1/F2/R consumido y grabado en
     * `#__hikashop_order`.order_invoice_number. Si a partir de ahí el método
     * termina (por validación local, por un error de la AEAT o por una
     * excepción técnica) sin pasar por guardarRegistro(), ese número
     * desaparece sin dejar rastro: no hay fila en
     * hikashop_verifactu_registro que lo explique, y la serie muestra un
     * salto que parece un hueco/factura borrada (bug real visto en
     * producción: 26-0214 y 26-0215 "quemados" sin ninguna fila).
     *
     * Por eso, cualquier `return` de error posterior a la asignación del
     * número debe pasar por aquí en vez de devolver el array directamente:
     * deja constancia en el registro local (estado != aceptado, así que
     * yaRegistradoYAceptado() no lo bloqueará) y, si el pedido se corrige y
     * se reintenta, aplicarNumeracionPropia() reutilizará el MISMO número en
     * vez de consumir uno nuevo.
     */
    private function abortarConNumeroAsignado($order, InvoiceSubmission $invoice, string $tipoAbono, ?object $facturaRectificada, string $estado, array $resultado, ?string $xmlEnviado = null): array
    {
        $this->guardarRegistro(
            $order,
            $invoice,
            ['error' => $resultado['errores'] ?? ($resultado['detalle'] ?? $estado)],
            $estado,
            null,
            $tipoAbono,
            $facturaRectificada,
            'alta',
            null,
            $xmlEnviado
        );

        return $resultado;
    }

    /**
     * Traduce la respuesta de la AEAT (factura o anulación) a nuestro propio
     * estado interno. Común a ambas operaciones porque la AEAT usa la misma
     * estructura de respuesta (submissionStatus/csv/lineResponses) para las
     * dos.
     *
     * La AEAT puede responder 3000 cuando el mismo registro ya existe. Si el
     * registro duplicado original figura como "Correcta", el registro ya
     * está aceptado y no debe tratarse como un rechazo real.
     */
    private function decidirEstadoDesdeRespuesta($response): string
    {
        $registroDuplicadoCorrecto = false;
        foreach ((array) ($response->lineResponses ?? []) as $lineResponse) {
            $codigoError = (string) ($lineResponse->CodigoErrorRegistro ?? '');
            $duplicado = $lineResponse->RegistroDuplicado ?? null;

            if (is_object($duplicado)) {
                $estadoDuplicado = (string) ($duplicado->EstadoRegistroDuplicado ?? '');
            } elseif (is_array($duplicado)) {
                $estadoDuplicado = (string) ($duplicado['EstadoRegistroDuplicado'] ?? '');
            } else {
                $estadoDuplicado = '';
            }

            if ($codigoError === '3000' && strcasecmp(trim($estadoDuplicado), 'Correcta') === 0) {
                $registroDuplicadoCorrecto = true;
                break;
            }
        }

        if ($registroDuplicadoCorrecto) {
            return 'aceptado';
        }
        if ($response->submissionStatus === InvoiceResponse::STATUS_OK) {
            return 'aceptado';
        }
        if (!empty($response->csv)) {
            return 'aceptado_con_errores';
        }

        return 'rechazado';
    }

    /**
     * Wrapper público de esAnulacionSolicitada(), para que verifactu.php
     * pueda decidir si debe llamar a anularFactura() en vez de
     * registrarFactura() sin duplicar el parsing del campo.
     */
    public function debeAnular($order): bool
    {
        return $this->esAnulacionSolicitada($order);
    }

    public function registrarFactura($order): array
    {
        $this->ensureSchema();

        // Determinar si el pedido es factura normal, un abono/rectificativa,
        // o una sustitución de una simplificada previa (F3). Una factura
        // negativa sin motivo seleccionado se bloquea para evitar enviarla
        // accidentalmente como F1/F2.
        $totalPedido = (float) ($order->order_full_price ?? 0);
        $tipoAbono = $this->getTipoAbono($order);
        $esNegativa = $totalPedido < 0;
        $sustituyeSimplificada = !$esNegativa && $this->esSustitucionSimplificada($order);

        if ($esNegativa && $tipoAbono === '') {
            return [
                'estado' => 'error_tipo_abono',
                'csv' => null,
                'errores' => ['La factura tiene importe negativo y no se ha seleccionado el Tipo de abono.'],
                'qr' => null,
            ];
        }

        if (!$esNegativa && $tipoAbono !== '' && $tipoAbono !== 'Factura') {
            // No bloquear facturas normales por un valor residual salvo que se
            // haya seleccionado expresamente un motivo de rectificación.
            return [
                'estado' => 'error_tipo_abono',
                'csv' => null,
                'errores' => ['El Tipo de abono solo puede utilizarse con una factura de importe negativo.'],
                'qr' => null,
            ];
        }

        $clasificacion = $esNegativa ? self::mapTipoAbono($tipoAbono) : null;
        if ($esNegativa && $clasificacion === null) {
            // v0.34.24: mensaje específico para el valor retirado
            // 'Cancelación' (ver comentario en mapTipoAbono()) -- en vez del
            // genérico "no válido", se explica el porqué y se dirige al
            // campo correcto, porque es precisamente la confusión entre
            // ambos mecanismos lo que motivó retirarlo.
            if ($tipoAbono === 'Cancelación') {
                return [
                    'estado' => 'error_tipo_abono',
                    'csv' => null,
                    'errores' => ['"Cancelación" ya no es un Tipo de abono válido, porque se confundía con el mecanismo de Anulación. Si la factura nunca debió existir (error de emisión, pedido duplicado), usa el campo "Anular factura" en vez de "Tipo de abono". Si en cambio se trata de una devolución o corrección de una venta real, elige un motivo más específico (ej. "Devolución completa", "Devolución parcial").'],
                    'qr' => null,
                ];
            }

            return [
                'estado' => 'error_tipo_abono',
                'csv' => null,
                'errores' => ['Tipo de abono no válido: ' . $tipoAbono],
                'qr' => null,
            ];
        }

        // La AEAT exige el NIF en formato limpio: 8 dígitos + letra (o X/Y/Z + 7
        // dígitos + letra para NIE), sin guiones ni espacios. HikaShop no obliga
        // a los clientes a introducirlo así (confirmado: un cliente real tenía
        // "12345678-Z" con guión), así que se limpia siempre antes de enviarlo.
        $limpiarNif = fn(?string $nif): string => strtoupper(preg_replace('/[^A-Za-z0-9]/', '', (string) $nif));

        $nifEmisor = $limpiarNif($this->params->get('nif_emisor'));

        // El NIF del cliente decide si podemos emitir F1 (factura normal, exige
        // destinatario identificado) o hay que emitir F2 (factura simplificada,
        // válida sin identificar al destinatario -- confirmado con la AEAT: el
        // código de error 1189 rechaza F1 sin bloque Destinatarios cumplimentado).
        $billing = $order->billing_address ?? null;
        $shipping = $order->shipping_address ?? null;
        $nifCliente = $limpiarNif($billing->address_vat ?? '');
        $tieneNifCliente = !empty($billing) && !empty($nifCliente);

        // Una rectificativa sin NIF ya NO se bloquea: se emite como R5
        // (rectificativa de una factura simplificada), que por definición no
        // exige destinatario identificado -- igual que la F2 que rectifica.

        $facturaSimplificadaPrevia = null;
        if ($sustituyeSimplificada) {
            if (!$tieneNifCliente) {
                return [
                    'estado' => 'error_sustitucion_sin_nif',
                    'csv' => null,
                    'errores' => ['Una factura F3 (sustituye a una simplificada) exige identificar al destinatario. El pedido no tiene NIF/CIF/NIE en la dirección de facturación.'],
                    'qr' => null,
                ];
            }

            $facturaSimplificadaPrevia = $this->obtenerFacturaSimplificadaPrevia((int) $order->order_id);
            if ($facturaSimplificadaPrevia === null) {
                return [
                    'estado' => 'error_sin_factura_simplificada_previa',
                    'csv' => null,
                    'errores' => ['Se ha marcado el pedido para sustituir una factura simplificada (F3), pero no hay ninguna F2 aceptada previamente para este pedido.'],
                    'qr' => null,
                ];
            }
        }

        // Un pedido no puede acabar con una F1 Y una F2/F3 aceptadas a la
        // vez -- serían dos facturas distintas para la misma venta. La F2 y
        // su F3 (sustitución) SÍ pueden y deben coexistir (es el mecanismo
        // normal de F3), así que esto solo bloquea la combinación F1 +
        // F2/F3, no F2 + F3.
        if (!$esNegativa && !$sustituyeSimplificada && $tieneNifCliente) {
            $yaSimplificada = $this->obtenerFacturaPorTipo((int) $order->order_id, 'F2')
                ?? $this->obtenerFacturaPorTipo((int) $order->order_id, 'F3');
            if ($yaSimplificada !== null) {
                return [
                    'estado' => 'error_ya_simplificada',
                    'csv' => null,
                    'errores' => ['Este pedido ya tiene una factura simplificada (F2) o su sustitución (F3) aceptada (' . $yaSimplificada->invoice_number . '). No se puede emitir también una F1 para el mismo pedido -- si el cliente pide factura con NIF, marca "Sustituye a factura simplificada" en vez de generar una factura normal.'],
                    'qr' => null,
                ];
            }
        }

        // Familia de numeración: F1 (normal), F2 (simplificada), F3
        // (sustituye una simplificada previa) o R (rectificativa -- R1-R4 y
        // R5 comparten una sola serie). Se decide con los mismos datos ya
        // calculados arriba, antes de tocar order_invoice_number, para no
        // asignar/consumir un número propio en pedidos que todavía pueden
        // devolver un error_tipo_abono más abajo... pero como esos casos ya
        // han hecho `return` antes de llegar aquí, en este punto el envío va
        // a continuar sí o sí.
        $familia = $sustituyeSimplificada ? 'F3' : ($esNegativa ? 'R' : ($tieneNifCliente ? 'F1' : 'F2'));

        // Idempotencia: si este pedido YA tiene un registro aceptado para
        // ESTA MISMA familia, no se vuelve a enviar (ej. si el pedido se
        // guarda de nuevo estando ya en el estado invoice_generated, o se
        // dispara el evento más de una vez). Es importante que sea por
        // familia y no por pedido en general: una F3 solo tiene sentido
        // precisamente cuando ese pedido YA tiene una F2 aceptada, así que un
        // guard "¿ya tiene algo aceptado este pedido?" bloquearía la F3
        // siempre. (Bug real: así estaba antes, y F3 nunca llegaba a
        // procesarse.)
        if ($this->yaRegistradoYAceptado((int) $order->order_id, $familia)) {
            return ['estado' => 'ya_registrado', 'csv' => null, 'errores' => [], 'qr' => null];
        }

        // v0.34.59: límite de importe de la factura simplificada (art. 4
        // RD 1619/2012, manual práctico del IVA de la AEAT): hasta 400 € IVA
        // incluido en general, o hasta 3.000 € IVA incluido en ventas al por
        // menor a consumidor final. Una rectificativa (R5) puede ser
        // simplificada sin límite de importe, por eso solo se comprueba la
        // F2 (pedido no negativo, sin NIF, que no sustituye a otra). Se
        // rechaza ANTES de asignar número, para no consumir uno de la serie
        // F2. Por encima del límite hace falta factura completa (F1), que
        // exige identificar al destinatario.
        if ($familia === 'F2') {
            $limiteSimplificada = $this->getLimiteSimplificada();
            $totalEnEuros = $this->importeEnEuros($order, $totalPedido);
            if (abs($totalEnEuros) > $limiteSimplificada) {
                return [
                    'estado' => 'error_simplificada_supera_limite',
                    'csv' => null,
                    'errores' => ['El pedido no tiene NIF/identificación fiscal del cliente, así que saldría como factura simplificada (F2), pero su importe (' . number_format(abs($totalEnEuros), 2, ',', '.') . ' € IVA incluido) supera el límite permitido para una simplificada (' . number_format($limiteSimplificada, 2, ',', '.') . ' €; 400 € en general, 3.000 € en ventas al por menor a consumidor final). Indica el NIF/identificación fiscal del cliente en la dirección de facturación para emitirla como factura completa (F1) y reenvía.'],
                    'qr' => null,
                ];
            }
        }

        // v0.34.21: FechaExpedicionFactura -- auditoría de seguridad, punto
        // "Fecha de expedición" (prioridad 🔴): antes esta fecha se
        // recalculaba con date('Y-m-d') en CADA llamada a este método,
        // incluido un reintento días después de un primer intento fallido
        // (aviso legal no aceptado, error de red, excepción técnica...). Con
        // numeración propia activa eso podía dar una factura con serie
        // "26-0214" (año fijado en el primer intento, vía
        // hikashop_verifactu_numeracion_asignada) pero FechaExpedicionFactura
        // de otro día distinto -- justo el escenario que describe la
        // auditoría (pedido creado 20/09, factura "expedida" 21/09, proceso
        // VeriFactu reintentado 24/09, registro enviado con fecha 24/09).
        // obtenerFechaExpedicionCongelada() fija esta fecha la PRIMERA vez
        // que se procesa este pedido+familia (éxito o fracaso, da igual: la
        // fecha de expedición de una factura no cambia porque el envío a la
        // AEAT falle y se reintente) y la reutiliza siempre después, con lo
        // que numeración, año de la serie y FechaExpedicionFactura quedan
        // atados al mismo momento por construcción, igual que antes, pero
        // también a través de reintentos.
        //
        // Esta fecha de expedición es, además, conceptualmente distinta de:
        //  - la fecha de OPERACIÓN/pedido (order_created de HikaShop, que
        //    puede ser anterior -- ver order_created más abajo, p.ej. en
        //    obtenerFacturaRectificada());
        //  - la fecha/hora de GENERACIÓN DEL REGISTRO VeriFactu
        //    (recordTimestamp, más abajo), que sí debe ser siempre "ahora",
        //    porque es literalmente el instante en que se genera el envío.
        $fechaOperacion = $this->obtenerFechaExpedicionCongelada((int) $order->order_id, $familia);

        $order->order_invoice_number = $this->aplicarNumeracionPropia($order, $familia, $fechaOperacion);

        // v0.34.17: para una R1-R5 se busca la factura original que se está
        // rectificando (obtenerFacturaRectificada() ya existía pero nunca se
        // llegaba a invocar, así que FacturasRectificadas no se informaba
        // nunca en el XML enviado a la AEAT). Se busca con el número YA
        // asignado al abono (línea de arriba), porque la heurística de
        // obtenerFacturaRectificada() es "número - 1" -- el patrón habitual
        // de este proyecto. Si no se encuentra con garantías (pedido no
        // localizado, o localizado pero con importe <= 0), se sigue
        // enviando la rectificativa igual: no encontrar la referencia local
        // no debe bloquear una R1-R5 con motivo/importe ya validados, solo
        // significa que el bloque FacturasRectificadas queda sin informar
        // para esa factura. (Se declara aquí, antes de construir el resto
        // de la factura, para que esté disponible en cualquier salida de
        // error posterior --ver abortarConNumeroAsignado()-- sin depender
        // de dónde se declaraba originalmente más abajo.)
        $facturaRectificada = $esNegativa
            ? $this->obtenerFacturaRectificada($order, (string) $order->order_invoice_number)
            : null;

        $invoice = new InvoiceSubmission();

        $invoiceId = new InvoiceId();
        $invoiceId->issuerNif = $nifEmisor;
        $invoiceId->seriesNumber = $order->order_invoice_number ?? $order->order_number;
        // NOTA: va en formato Y-m-d porque la propia validación de la librería
        // lo exige así ("The issue date must be in YYYY-MM-DD format"). El
        // desajuste real de la huella (ver más abajo) se corrige parcheando
        // HashGeneratorService.php de la librería para que formatee la fecha
        // igual que el serializador XML antes de calcular el hash.
        $invoiceId->issueDate = $fechaOperacion;
        $invoice->setInvoiceId($invoiceId);

        // v0.34.8: comprobación movida aquí desde verifactu.php. Colocarla
        // en procesarPedidoVerifactu() (antes de llamar siquiera a
        // registrarFactura()) hacía que un pedido bloqueado por esto no
        // dejase NINGÚN rastro local -- ni número, ni fila en
        // hikashop_verifactu_registro, nada; solo una línea en el log de
        // Joomla que en la práctica nadie mira. Aquí, en cambio, el número
        // ya está asignado (línea de arriba) y $invoice/$facturaRectificada
        // ya existen, así que abortarConNumeroAsignado() deja una fila
        // local visible (estado 'error_aviso_legal_no_aceptado') igual que
        // cualquier otro rechazo -- y si se reintenta el mismo pedido una
        // vez aceptado el aviso, se reutiliza el mismo número en vez de
        // saltarse uno nuevo.
        // v0.34.19: este bloque estaba ANTES de construir $invoiceId, así que
        // guardarRegistro() leía getInvoiceId() == null y la fila salía sin
        // número de factura, fecha ni NIF del emisor (columnas NOT NULL): el
        // INSERT fallaba, la excepción solo llegaba al log y el número ya
        // consumido quedaba sin rastro local. Ahora se comprueba una vez que
        // $invoice ya lleva su InvoiceId (número, fecha y NIF).
        if ((string) $this->params->get('aviso_responsabilidad_aceptado', '0') !== '1') {
            return $this->abortarConNumeroAsignado($order, $invoice, $tipoAbono, $facturaRectificada, 'error_aviso_legal_no_aceptado', [
                'estado' => 'error_aviso_legal_no_aceptado',
                'csv' => null,
                'errores' => ['No se ha aceptado el aviso legal y las condiciones de uso en la configuración del plugin (campo "He leído y acepto..."), así que no se envía nada a la AEAT. Entra en Complementos > Hikashop Verifactu plugin, léelo y márcalo para poder enviar facturas.'],
                'qr' => null,
            ]);
        }

        $invoice->issuerName = $this->params->get('nombre_emisor');

        if ($esNegativa) {
            // Factura rectificativa por diferencias (I), según el motivo
            // elegido. Si el pedido no tiene NIF, la rectificativa es de una
            // factura simplificada (R5) en vez de R1-R4 -- la AEAT exige que
            // R5 NO lleve destinatario, igual que la F2 original.
            $invoice->invoiceType = $tieneNifCliente ? $clasificacion['invoiceType'] : InvoiceType::RECTIFICATION_SIMPLIFIED;
            $invoice->rectificationType = $clasificacion['rectificationType'];

            // FacturasRectificadas: solo se informa cuando
            // obtenerFacturaRectificada() ha podido localizar con garantías
            // la factura original (ver comentario más arriba). issuerNif es
            // siempre el nuestro (nifEmisor): la factura rectificada la
            // emitimos nosotros mismos, no el cliente. fecha_expedicion_real
            // es la FechaExpedicionFactura real de esa factura original
            // (calculada en obtenerFacturaRectificada(); ver v0.34.21 más
            // abajo), no la fecha del pedido -- que puede ser distinta si la
            // factura se emitió días después del pedido.
            if ($facturaRectificada !== null) {
                $invoice->addRectifiedInvoice(
                    $nifEmisor,
                    (string) $facturaRectificada->order_invoice_number,
                    $facturaRectificada->fecha_expedicion_real
                );
            }
        } elseif ($sustituyeSimplificada) {
            // F3: sustituye a la F2 localizada arriba. Hay que referenciarla
            // explícitamente (FacturasSustituidas) para que la AEAT entienda
            // que reemplaza, y no duplica, aquella simplificada.
            //
            // invoice_date es DATETIME en la BBDD, así que vuelve de la
            // consulta como "2026-09-17 00:00:00". El formateador de fechas
            // de la librería (InvoiceSerializer::formatDate) solo reconoce
            // "YYYY-MM-DD" exacto (con regex estricta); si no coincide, la
            // deja tal cual en vez de convertirla a DD-MM-YYYY, y la AEAT la
            // rechaza con el error 1100 "Valor o tipo incorrecto... :
            // FechaExpedicionFactura" -- pero sobre la fecha de la factura
            // SUSTITUIDA, no la de la F3 (que si va bien formateada, por eso
            // en el error solo se ve la fecha de hoy, correcta, en el bloque
            // IDFactura principal). Por eso aquí se recorta a los 10
            // primeros caracteres antes de pasarla a la librería.
            $invoice->invoiceType = InvoiceType::REPLACEMENT;
            $invoice->rectificationType = null;
            $invoice->addSubstitutedInvoice(
                $facturaSimplificadaPrevia->nif_emisor,
                $facturaSimplificadaPrevia->invoice_number,
                substr((string) $facturaSimplificadaPrevia->invoice_date, 0, 10)
            );
        } else {
            // "Factura": mantener el comportamiento actual de F1/F2 según la
            // identificación del destinatario.
            $invoice->invoiceType = $tieneNifCliente ? InvoiceType::STANDARD : InvoiceType::SIMPLIFIED;
            $invoice->rectificationType = null;
        }

        $comentarioAbono = trim((string) ($order->verifactu_comentario_abono ?? ''));
        if ($esNegativa) {
            $descripcionAbono = 'Abono HikaShop pedido #' . $order->order_number;
            if ($tipoAbono !== '') {
                $descripcionAbono .= ' - ' . $tipoAbono;
            }
            if ($comentarioAbono !== '') {
                $descripcionAbono .= ': ' . $comentarioAbono;
            }
            $invoice->operationDescription = mb_substr($descripcionAbono, 0, 500);
        } elseif ($sustituyeSimplificada) {
            $invoice->operationDescription = mb_substr(
                'Venta HikaShop pedido #' . $order->order_number . ' (sustituye a factura simplificada ' . $facturaSimplificadaPrevia->invoice_number . ')',
                0,
                500
            );
        } else {
            $invoice->operationDescription = 'Venta HikaShop pedido #' . $order->order_number;
        }
        $invoice->totalAmount = $totalPedido;

        // Desglose de impuestos: HikaShop guarda en order_tax_info el IMPORTE de cada
        // impuesto aplicado (order_tax_info es un array asociativo indexado por
        // tax_namekey), pero NO el tipo (%) -- ese vive aparte, en la tabla
        // #__hikashop_tax (columnas tax_namekey, tax_rate), guardado como FRACCIÓN
        // decimal (0.21 = 21%), no como porcentaje entero. Confirmado contra el
        // esquema y código real de HikaShop 6.5.2.
        //
        // El Recargo de Equivalencia (5,2% / 1,4% / 0,5%) NO se declara como una
        // línea de IVA independiente -- la AEAT lo rechaza (error 1124, tipo no
        // permitido). Va como dos campos adicionales (equivalenceSurchargeRate/
        // Amount) DENTRO de la línea del IVA al que acompaña. Se empareja por el
        // tipo de IVA general correspondiente (5,2%→21%, 1,4%→10%, 0,5%→4%).
        //
        // Lista simple de pares [rate_recargo, rate_iva], SIN usarlos como claves
        // de array -- ya tuvimos dos bugs seguidos por eso: primero PHP trunca
        // claves flotantes a enteros, y luego las claves de texto no coincidían
        // por tener distinto número de decimales. Todo por comparación numérica
        // directa con tolerancia, cero claves de por medio.
        $paresRecargoIva = [
            [0.052, 0.21],
            [0.014, 0.10],
            [0.005, 0.04],
        ];

        $esRecargo = function (float $tipoFraccion) use ($paresRecargoIva): bool {
            foreach ($paresRecargoIva as [$rRecargo, $rIva]) {
                if (abs($tipoFraccion - $rRecargo) < 0.0001) {
                    return true;
                }
            }
            return false;
        };

        $ivaAsociadoDeRecargo = function (float $tipoRecargoFraccion) use ($paresRecargoIva): ?float {
            foreach ($paresRecargoIva as [$rRecargo, $rIva]) {
                if (abs($tipoRecargoFraccion - $rRecargo) < 0.0001) {
                    return $rIva;
                }
            }
            return null;
        };

        $breakdown = new Breakdown();
        $totalTax = 0.0;
        $taxInfo = is_array($order->order_tax_info) ? $order->order_tax_info : (array) $order->order_tax_info;

        if (!empty($taxInfo)) {
            // Cargar de una vez los tipos reales de IVA usados en este pedido
            $namekeys = array_map(fn($t) => $t->tax_namekey ?? '', $taxInfo);
            $namekeys = array_filter($namekeys);
            $tiposPorNamekey = [];
            if (!empty($namekeys)) {
                $query = $this->db->getQuery(true)
                    ->select('tax_namekey, tax_rate')
                    ->from('#__hikashop_tax')
                    ->where('tax_namekey IN (' . implode(',', array_map([$this->db, 'quote'], $namekeys)) . ')');
                $this->db->setQuery($query);
                foreach ($this->db->loadObjectList() as $fila) {
                    $tiposPorNamekey[$fila->tax_namekey] = (float) $fila->tax_rate;
                }
            }

            // Primera pasada: separar líneas de IVA normal de líneas de recargo,
            // en listas simples (no arrays indexados por número) para evitar
            // cualquier problema de coincidencia de claves.
            $lineasIva = [];     // [tipoFraccion, importe, base]
            $lineasRecargo = []; // [tipoFraccion, importe]

            foreach ($taxInfo as $tax) {
                $importeImpuesto = (float) ($tax->tax_amount ?? 0);
                if ($importeImpuesto == 0) {
                continue;
            }
                $namekey = $tax->tax_namekey ?? '';
                $tipoFraccion = $tiposPorNamekey[$namekey] ?? 0.0;
                if ($tipoFraccion <= 0) {
                    continue;
                }
                // La base se deduce de la propia cuota: base = cuota / tipo.
                //
                // El campo ->amount de HikaShop no sirve como base imponible
                // cuando el pedido lleva gastos de envío o de pago con
                // impuesto: HikaShop guarda esos gastos con el impuesto ya
                // incluido y además les vuelve a sumar la cuota al calcular
                // ->amount, así que la base sale inflada justo en el doble del
                // impuesto de esos gastos. Comprobado sobre un pedido real
                // (producto 100 + cargo 20 + envío 12,10 + pago 6,05 al 21 %):
                // HikaShop guarda amount = 141,30 cuando la base es 135,00, y
                // base + cuota daba 169,65 frente a un total de 163,35, que es
                // justo la descuadre que la AEAT rechaza.
                $baseReal = $importeImpuesto / $tipoFraccion;

                if ($esRecargo($tipoFraccion)) {
                    $lineasRecargo[] = ['tipo' => $tipoFraccion, 'importe' => $importeImpuesto];
                } else {
                    // Buscar si ya existe una línea de IVA con este mismo tipo (por si
                    // hay varios productos con el mismo tipo, se suman en una sola línea)
                    $encontrada = false;
                    foreach ($lineasIva as &$linea) {
                        if (abs($linea['tipo'] - $tipoFraccion) < 0.0001) {
                            $linea['importe'] += $importeImpuesto;
                            $linea['base'] += $baseReal;
                            $encontrada = true;
                            break;
                        }
                    }
                    unset($linea);
                    if (!$encontrada) {
                        $lineasIva[] = ['tipo' => $tipoFraccion, 'importe' => $importeImpuesto, 'base' => $baseReal];
                    }
                }

                $totalTax += $importeImpuesto;
            }

            // Segunda pasada: generar una línea de desglose por cada tipo de IVA,
            // fusionando el recargo correspondiente si existe
            foreach ($lineasIva as $linea) {
                $tipoFraccion = $linea['tipo'];
                $importeImpuesto = $linea['importe'];
                $base = round($linea['base'], 2);
                $tipoPorcentaje = round($tipoFraccion * 100, 2);

                $detail = new BreakdownDetail();
                $detail->taxType = TaxType::IVA;
                $detail->regimeKey = RegimeType::GENERAL;
                $detail->taxRate = $tipoPorcentaje;
                $detail->taxableBase = $base;
                $detail->taxAmount = round($importeImpuesto, 2);
                $detail->operationQualification = OperationQualificationType::SUBJECT_NO_EXEMPT_NO_REVERSE;

                // Buscar y fusionar el recargo emparejado con este tipo de IVA, si existe
                foreach ($lineasRecargo as $idx => $recargo) {
                    if (abs(($ivaAsociadoDeRecargo($recargo['tipo']) ?? -1) - $tipoFraccion) < 0.0001) {
                        $detail->equivalenceSurchargeRate = round($recargo['tipo'] * 100, 2);
                        $detail->equivalenceSurchargeAmount = round($recargo['importe'], 2);
                        unset($lineasRecargo[$idx]);
                        break;
                    }
                }

                $breakdown->addDetail($detail);
            }

            // Cualquier recargo que no se haya podido emparejar con un IVA presente
            // en este pedido se registra para revisión manual (caso raro/edge).
            if (!empty($lineasRecargo)) {
                VerifactuLog::add('Recargo de equivalencia sin IVA emparejado en pedido ' . ($order->order_id ?? '?') . ': ' . json_encode($lineasRecargo), \Joomla\CMS\Log\Log::WARNING);
            }
        }

        if ($breakdown->getDetails() === []) {
            // Compatibilidad con la versión v40_v0.30.29:
            // cuando HikaShop no proporciona un desglose de impuestos utilizable
            // (caso habitual en facturas extranjeras sin IVA español), no se
            // interrumpe el flujo antes de enviar la factura.
            //
            // Esta salida es importante porque permite que AEAT devuelva su
            // respuesta y que el plugin la registre en
            // #__hikashop_verifactu_registro. En 0.31.9 el retorno anticipado
            // impedía tanto el registro en BBDD como la generación posterior
            // del QR.
            // Importante: NO declarar esto como IVA español al 21% con cuota 0
            // (S1 + TipoImpositivo=21 + CuotaRepercutida=0). La AEAT exige que
            // CuotaRepercutida = BaseImponible x TipoImpositivo / 100, y 0 no
            // cuadra con una base > 0 al 21 %; eso es justo el error 1142
            // ("El campo CuotaRepercutida tiene un valor incorrecto..."), que
            // se dispara por ejemplo en facturas a clientes con NIF de
            // Portugal sin desglose de IVA español en order_tax_info.
            //
            // v0.34.2: declarar N2 (no sujeta por reglas de localización)
            // SOLO tiene sentido cuando el destinatario está realmente fuera
            // de España -- que es el caso real que motivó este fallback
            // (Portugal). Si se aplicara a cualquier pedido sin desglose sin
            // más, un pedido ESPAÑOL cuyos impuestos estén simplemente mal
            // configurados en HikaShop (huecos en order_tax_info, una regla
            // de impuestos rota, etc.) también se declararía "no sujeta" --
            // y la AEAT la registraría igual de bien, ocultando en silencio
            // lo que en realidad es un fallo de configuración, no una venta
            // fuera de ámbito. Por eso: destinatario fuera de España -> N2
            // como hasta ahora; destinatario español (o país no
            // determinado) -> se rechaza el envío sin llamar a la AEAT, pero
            // SIN perder el número asignado ni dejar el pedido sin rastro
            // (abortarConNumeroAsignado ya se encarga de guardar la fila
            // local, igual que cualquier otro error posterior a la
            // numeración).
            //
            // v0.34.3: Canarias, Ceuta y Melilla son un tercer caso, distinto
            // de los dos anteriores -- su código de país sigue siendo "ES"
            // (así que caerían en la rama "español, se rechaza" de arriba)
            // pero NO forman parte del territorio de aplicación del IVA, así
            // que un pedido con envío a estos territorios y sin desglose de
            // IVA español es exactamente el comportamiento correcto de
            // HikaShop (no hay IVA que declarar), no un fallo de
            // configuración. Se comprueba ANTES que $esDestinatarioExtranjero
            // para no caer en el rechazo automático.
            //
            // v0.34.5: N2 (no sujeta por reglas de localización) es la
            // calificación correcta para SERVICIOS cuyo destinatario está
            // fuera de España -- NO para la venta de BIENES, que es lo que
            // vende esta tienda. Para bienes sin IVA español y destinatario
            // extranjero hay dos casos reales, y ninguno es N2:
            //   - Destinatario en otro país de la UE (p.ej. Portugal):
            //     entrega intracomunitaria de bienes, sujeta y EXENTA por
            //     el artículo 25 LIVA (causa E5), régimen general (01).
            //   - Destinatario fuera de la UE: exportación de bienes,
            //     sujeta y exenta por el artículo 21 LIVA (causa E2),
            //     régimen 02 -- el mismo tratamiento que Canarias/Ceuta/
            //     Melilla (que a efectos de IVA son, igual que un país no
            //     UE, territorio fuera del TAI).
            // Ninguno de los dos casos usa CalificacionOperacion: en ambos
            // la exención (OperacionExenta) es la que corresponde.
            $esCanariasCeutaMelilla = $this->esDestinoCanariasCeutaMelilla($shipping, $billing);

            $codigoPaisCliente = $this->obtenerCodigoPaisFactura($billing);
            $esDestinatarioExtranjero = $codigoPaisCliente !== '' && $codigoPaisCliente !== 'ES';

            if (!$esDestinatarioExtranjero && !$esCanariasCeutaMelilla) {
                return $this->abortarConNumeroAsignado($order, $invoice, $tipoAbono, $facturaRectificada, 'error_desglose_vacio', [
                    'estado' => 'error_desglose_vacio',
                    'csv' => null,
                    'errores' => ['No se ha podido calcular ningún desglose de IVA para este pedido y el destinatario no está fuera de España, así que no se declara "no sujeta" (podría ocultar una venta española con los impuestos mal configurados). Revisa la configuración de impuestos de HikaShop para este pedido (order_tax_info) y reenvía.'],
                    'qr' => null,
                ]);
            }

            // v0.34.58: la AEAT ha comunicado que TODAS las facturas
            // simplificadas deben llevar IVA. Una factura simplificada
            // (F2, o R5 rectificativa de una simplificada) debe indicar el
            // tipo impositivo aplicado, así que no puede documentar una
            // operación exenta (exportación E2 / entrega intracomunitaria
            // E5), que va sin tipo ni cuota. Esas operaciones exigen
            // factura completa: identificar al destinatario (NIF/
            // identificación fiscal) para emitir F1 (o R1-R4). Se rechaza el
            // envío en vez de declarar IVA español a una operación exenta.
            if ($invoice->invoiceType === InvoiceType::SIMPLIFIED || $invoice->invoiceType === InvoiceType::RECTIFICATION_SIMPLIFIED) {
                return $this->abortarConNumeroAsignado($order, $invoice, $tipoAbono, $facturaRectificada, 'error_simplificada_exenta', [
                    'estado' => 'error_simplificada_exenta',
                    'csv' => null,
                    'errores' => ['Este pedido es una operación exenta de IVA (' . ($esCanariasCeutaMelilla ? 'Canarias, Ceuta o Melilla' : 'destinatario fuera de España') . ') y no tiene NIF/identificación fiscal del cliente, así que saldría como factura simplificada (' . $invoice->invoiceType->value . '). Una factura simplificada debe llevar IVA (tipo impositivo aplicado), por lo que no puede documentar una operación exenta y no se envía a la AEAT. Indica el NIF/identificación fiscal del cliente en la dirección de facturación para emitirla como factura completa (F1/R1) y reenvía.'],
                    'qr' => null,
                ]);
            }

            // v0.34.6: la exención E5 (entrega intracomunitaria) solo es
            // válida cuando el destinatario es una EMPRESA con NIF-IVA
            // registrado en el VIES -- no un particular. Hasta ahora esta
            // rama se fiaba ciegamente de que "sin IVA español + país UE"
            // implicaba B2B, sin comprobar nada. Se añade una validación de
            // formato del NIF-IVA (billing->address_vat): si no tiene pinta
            // de NIF-IVA de la UE, se rechaza el envío en vez de declarar
            // E5 a ciegas -- igual que ya se hace con un pedido español mal
            // configurado. Esto es solo una validación de FORMATO (no
            // comprueba contra el VIES de la Comisión Europea que el NIF
            // exista de verdad); sigue siendo responsabilidad del
            // comerciante validar el NIF-IVA del cliente en el checkout.
            //
            // v0.34.14: la comprobación exigía forma de NIF-IVA de
            // CUALQUIER país UE, no del país de facturación concreto que se
            // está declarando -- un NIF-IVA con prefijo de un Estado
            // miembro distinto al de la dirección de facturación (p.ej.
            // billing en Portugal pero NIF con prefijo DE) colaba como
            // "válido" para la exención E5. Ahora se exige el prefijo del
            // propio $codigoPaisCliente (con Grecia como caso especial: EL,
            // no GR), igual que en la identificación del destinatario más
            // abajo.
            //
            // Exportación (fuera del territorio de aplicación del IVA):
            // Canarias/Ceuta/Melilla, o un país extranjero que no es de la UE.
            $esExportacion = $esCanariasCeutaMelilla || !self::esPaisUE($codigoPaisCliente);

            if (!$esExportacion && !self::llevaPrefijoNifIvaDeSuPais($nifCliente, $codigoPaisCliente)) {
                return $this->abortarConNumeroAsignado($order, $invoice, $tipoAbono, $facturaRectificada, 'error_desglose_vacio', [
                    'estado' => 'error_desglose_vacio',
                    'csv' => null,
                    'errores' => ['El pedido va sin IVA español y el destinatario está en la UE (' . $codigoPaisCliente . '), pero no tiene un NIF-IVA con formato válido en la dirección de facturación. Sin NIF-IVA no se puede declarar como entrega intracomunitaria exenta (E5) -- revisa si el cliente es realmente una empresa con NIF-IVA (y corrígelo en el pedido) o si en realidad debería habérsele cargado IVA, y reenvía.'],
                    'qr' => null,
                ]);
            }

            $detail = new BreakdownDetail();
            $detail->taxType = TaxType::IVA;
            $detail->taxRate = null;
            $detail->taxableBase = (float) $order->order_full_price;
            $detail->taxAmount = null;

            if ($esExportacion) {
                // Exportación de mercancías: régimen 02, sujeta y exenta
                // por el artículo 21 LIVA (causa E2). No se informa
                // CalificacionOperacion en esta línea -- OperacionExenta la
                // sustituye (son mutuamente excluyentes en el esquema de la
                // AEAT).
                $detail->regimeKey = RegimeType::EXPORT;
                $detail->exemptOperation = ExemptOperationType::E2;
            } else {
                // Entrega intracomunitaria de bienes a otro país de la UE:
                // régimen general (01), sujeta y exenta por el artículo 25
                // LIVA (causa E5). Ya se ha comprobado arriba que el
                // destinatario tiene un NIF-IVA con formato de operador
                // intracomunitario -- si no lo tuviera, no se llegaría
                // hasta aquí (ver validación v0.34.6).
                $detail->regimeKey = RegimeType::GENERAL;
                $detail->exemptOperation = ExemptOperationType::E5;
            }

            $breakdown->addDetail($detail);
        }

        $invoice->setBreakdown($breakdown);
        $invoice->taxAmount = $totalTax;

        // v0.34.30: resumen del desglose para guardarRegistro() -- solo
        // cuando se reduce a UNA línea de IVA (caso de la inmensa mayoría
        // de pedidos, y el único para el que "un tipo/base originales"
        // tiene sentido). Con varios tipos de IVA a la vez se deja en null:
        // una futura rectificación por diferencias de este pedido no podrá
        // calcularse automáticamente y lo dirá explícitamente en vez de
        // adivinar contra qué línea rectificar.
        $desgloseParaGuardar = [null, null, null, null, null, null, null];
        $detallesBreakdown = $breakdown->getDetails();
        if (count($detallesBreakdown) === 1) {
            $unicoDetalle = $detallesBreakdown[0];
            $desgloseParaGuardar = [
                $unicoDetalle->taxableBase,
                $unicoDetalle->taxRate,
                $unicoDetalle->taxAmount,
                $unicoDetalle->equivalenceSurchargeRate ?? null,
                $unicoDetalle->equivalenceSurchargeAmount ?? null,
                // v0.34.57: régimen y causa de exención de la línea.
                $unicoDetalle->regimeKey?->value ?? null,
                $unicoDetalle->exemptOperation?->value ?? null,
            ];
        }

        // v0.34.26: auditoría de seguridad, punto 8 "Concurrencia y
        // generación de hash" (prioridad 5/5) -- ver el comentario junto a
        // adquirirLockSecuencia() para el porqué de este lock nombrado en
        // vez de una transacción con FOR UPDATE. Se adquiere aquí, justo
        // antes de leer el último registro generado de este emisor, y se
        // libera en el finally de más abajo pase lo que pase (aceptado,
        // rechazado, error de validación o excepción técnica), para que dos
        // facturas del mismo emisor nunca puedan encadenarse contra el
        // mismo anterior.
        if (!$this->adquirirLockSecuencia($nifEmisor)) {
            VerifactuLog::add('ERROR: no se pudo adquirir el lock de secuencia del emisor ' . $nifEmisor . ' (pedido ' . $order->order_id . '); otra factura del mismo emisor se está generando en este momento.', \Joomla\CMS\Log\Log::ERROR);

            // el número ya está asignado: que quede en el registro, como los demás errores
            return $this->abortarConNumeroAsignado($order, $invoice, $tipoAbono, $facturaRectificada, 'error_concurrencia', [
                'estado' => 'error_concurrencia',
                'csv' => null,
                'errores' => ['No se pudo generar la factura porque otra factura del mismo emisor se está procesando en este momento. Vuelve a intentarlo en unos segundos.'],
                'qr' => null,
            ]);
        }

        try {
            // Encadenamiento: se referencia el registro de facturación
            // inmediatamente ANTERIOR GENERADO (cualquier estado_envio, alta o
            // anulación -- ver obtenerUltimoRegistroGenerado()); si este emisor
            // no tiene ninguno todavía, se marca como firstRecord.
            $chaining = new Chaining();
            $anterior = $this->obtenerUltimoRegistroGenerado($nifEmisor);
            if ($anterior === null) {
                $chaining->firstRecord = YesNoType::YES;
            } else {
                $chaining->setPreviousInvoice([
                    'seriesNumber' => $anterior->invoice_number,
                    'issuerNif' => $nifEmisor,
                    // invoice_date es un DATETIME en nuestra tabla (ej. "2026-07-29 00:00:00");
                    // aquí se recorta a solo la fecha (Y-m-d), porque el formateador de la
                    // librería solo reconoce y convierte correctamente el patrón exacto
                    // YYYY-MM-DD -- con la hora pegada lo deja tal cual y la AEAT lo rechaza
                    // (error 1174: formato de FechaExpedicionFactura incorrecto en RegistroAnteriores).
                    'issueDate' => substr((string) $anterior->invoice_date, 0, 10),
                    'hash' => $anterior->hash_actual,
                ]);
            }
            $invoice->setChaining($chaining);

        // v0.34.23: auditoría de seguridad, punto "Registros rechazados y
        // reintentos" (prioridad 🔴). Si la AEAT ya rechazó antes un intento
        // de facturación para ESTE MISMO pedido (con hash generado -- ver
        // guardarRegistro()), este envío es una subsanación de ese rechazo,
        // no un registro de facturación independiente sin relación con el
        // anterior. Hay que declararlo así explícitamente (Subsanacion=S +
        // RechazoPrevio=S -- ver scripts/patch-subsanacion-alta.php, campos
        // que la librería, tal y como viene de upstream, no soportaba para
        // altas) porque la AEAT valida activamente la relación entre ambos
        // campos (ErrorRegistry 1153/1161/1171): no basta con que el
        // encadenamiento (más arriba) ya referencie el intento rechazado
        // como "anterior".
        //
        // Solo cuenta un rechazo EXPLÍCITO de la AEAT (estado_envio =
        // 'rechazado'): un error técnico local o de red, donde la AEAT nunca
        // llegó a responder, no es un "rechazo previo por la AEAT" en el
        // sentido de este campo.
        if ($this->huboRechazoPrevioAeat((int) $order->order_id, 'alta', (string) $invoice->getInvoiceId()->seriesNumber)) {
            $invoice->subsanacion = YesNoType::YES;
            $invoice->previousRejection = YesNoType::YES;
        }

        $invoice->setSystemInfo($this->construirSistemaInformatico($nifEmisor, $limpiarNif));

        $invoice->recordTimestamp = date('c');
        $invoice->hashType = HashType::SHA_256;

        // Destinatario (cliente de la tienda). Campo confirmado contra HikaShop 6.5.2:
        // la dirección de facturación vive en $order->billing_address, con el NIF/CIF
        // en address_vat (no "vat_number", que no existe en el esquema real).
        if ($tieneNifCliente) {
            $nombrePersona = trim(($billing->address_firstname ?? '') . ' ' . ($billing->address_lastname ?? ''));
            $nombreEmpresa = trim($billing->address_company ?? '');

            // La AEAT valida el nombre contra el titular REAL registrado para ese
            // NIF -- si es un NIF de persona física (DNI: 8 dígitos + letra, o
            // NIE: letra+7 dígitos+letra) hay que mandar el nombre de la persona,
            // NO el nombre comercial, aunque el cliente lo tenga rellenado en el
            // checkout. Un CIF de empresa siempre empieza por una letra distinta
            // de X/Y/Z. Confirmado con un caso real: un NIF de autónoma (formato
            // DNI) fue rechazado por la AEAT al enviar el nombre comercial
            // (el nombre comercial) en vez de su nombre legal.
            // Un NIF español escrito con el prefijo de país ("ES12345678Z", como
            // en un NIF-IVA) no es un NIF válido para la AEAT: se quita el "ES"
            // solo cuando lo que queda son exactamente 9 caracteres. Un NIF
            // español sin prefijo nunca empieza por "ES" con 11 caracteres, así
            // que no hay riesgo de recortar uno legítimo.
            $nifSinPrefijoEs = preg_replace('/^ES(?=[0-9A-Z]{9}$)/', '', $nifCliente);

            $esPersonaFisica = (bool) preg_match('/^([0-9]{8}[A-Za-z]|[XYZ][0-9]{7}[A-Za-z])$/', $nifSinPrefijoEs);

            if ($esPersonaFisica) {
                $nombreDestinatario = $nombrePersona ?: $nombreEmpresa;
            } else {
                $nombreDestinatario = $nombreEmpresa ?: $nombrePersona;
            }

            $recipient = new LegalPerson();
            $recipient->name = $nombreDestinatario;

            // Identificación del destinatario según su país:
            // - ES: NIF español en <NIF>.
            // - Extranjero: <IDOtro> con CodigoPais ISO siempre informado, e
            //   IDType que depende de si el identificador lleva el prefijo
            //   NIF-IVA (VIES) de SU PROPIO país -- no de si el país es o
            //   no de la UE en abstracto.
            $codigoPaisCliente = $this->obtenerCodigoPaisFactura($billing);

            if ($codigoPaisCliente === 'ES') {
                $recipient->nif = $nifSinPrefijoEs;
            } elseif ($codigoPaisCliente !== '') {
                $otherId = new OtherID();
                $otherId->countryCode = $codigoPaisCliente;

                $idExtranjero = strtoupper(trim((string) $nifCliente));
                if ($idExtranjero === '') {
                    return $this->abortarConNumeroAsignado($order, $invoice, $tipoAbono, $facturaRectificada, 'error_id_destinatario', [
                        'estado' => 'error_id_destinatario',
                        'csv' => null,
                        'errores' => ['El destinatario extranjero no tiene identificación fiscal.'],
                        'qr' => null,
                    ]);
                }

                // IDType 02 (NIF-IVA) es lo que corresponde cuando el
                // identificador lleva el prefijo VIES de SU PROPIO país
                // (por ejemplo PT503890278 para Portugal; EL, no GR, para
                // Grecia) -- la AEAT lo contrasta contra el VIES. Fijar 02
                // para todo el extranjero se rompe en dos casos reales:
                // el cliente que escribe su NIF-IVA sin el prefijo de país
                // (503890278 en vez de PT503890278) y cualquier
                // destinatario de fuera de la UE (que no tiene NIF-IVA/VIES
                // en absoluto) -- ambos se rechazan con el error 1103. En
                // esos casos se usa 04 (documento oficial de identificación
                // del país de residencia) en su lugar. CodigoPais se
                // informa siempre, sea cual sea el IDType.
                $otherId->idType = self::llevaPrefijoNifIvaDeSuPais($idExtranjero, $codigoPaisCliente) ? '02' : '04';
                $otherId->id = $idExtranjero;
                $recipient->setOtherId($otherId);
            } else {
                // No asumir España si el país no se puede determinar.
                return $this->abortarConNumeroAsignado($order, $invoice, $tipoAbono, $facturaRectificada, 'error_pais_destinatario', [
                    'estado' => 'error_pais_destinatario',
                    'csv' => null,
                    'errores' => ['No se ha podido determinar el país del destinatario; no se envía su identificación como NIF español.'],
                    'qr' => null,
                ]);
            }

            $invoice->addRecipient($recipient);
        } else {
            // Factura simplificada (F2): no exige destinatario identificado.
            $invoice->invoiceWithoutRecipient = YesNoType::YES;
        }

        // validate() de la librería SIEMPRE devuelve un array; vacío significa
        // que la validación pasó sin errores (nunca devuelve un booleano true).
        //
        // El envío real a la AEAT (Verifactu::registerInvoice) va en el mismo
        // try/catch: una excepción técnica aquí (timeout, fallo SOAP, error
        // interno de la librería, etc.) no debe abortar el método en silencio
        // dejando "quemado" el número que ya asignó aplicarNumeracionPropia()
        // más arriba -- se deja constancia en el registro local igual que
        // cualquier otro rechazo, y un reintento posterior reutilizará el
        // mismo número.
        try {
            $validationResult = $invoice->validate();
            if (!empty($validationResult)) {
                return $this->abortarConNumeroAsignado($order, $invoice, $tipoAbono, $facturaRectificada, 'error_validacion', [
                    'estado' => 'error_validacion',
                    'detalle' => $validationResult,
                ]);
            }

            $response = Verifactu::registerInvoice($invoice);
            $xmlEnviado = Verifactu::getLastRequestXml();
        } catch (\Throwable $e) {
            VerifactuLog::add('ERROR técnico registrando factura AEAT pedido ' . $order->order_id . ' (' . ($order->order_invoice_number ?? '') . '): ' . $e->getMessage(), \Joomla\CMS\Log\Log::ERROR);

            // getLastRequestXml() sobrevive a la excepción (se captura ANTES
            // de la llamada SOAP dentro de la librería -- ver
            // VerifactuService::registerInvoice()), así que un fallo de red
            // o un SoapFault no deja sin conservar el XML que sí se llegó a
            // construir y a intentar enviar.
            return $this->abortarConNumeroAsignado($order, $invoice, $tipoAbono, $facturaRectificada, 'error_tecnico', [
                'estado' => 'error_tecnico',
                'csv' => null,
                'errores' => ['Error técnico al comunicar con la AEAT: ' . $e->getMessage()],
                'qr' => null,
            ], Verifactu::getLastRequestXml());
        }

        $estado = $this->decidirEstadoDesdeRespuesta($response);

        // IMPORTANTE: el registro local NO debe depender de que el QR pueda
        // generarse. AEAT ya ha respondido y, si la respuesta es aceptada,
        // primero guardamos el registro. Después intentamos generar el QR y
        // actualizamos solamente la columna qr_url.
        //
        // Esto evita que una dependencia opcional del servidor (GD/Imagick)
        // provoque que una factura aceptada por AEAT desaparezca del registro
        // local. La versión anterior generaba el QR ANTES del INSERT y una
        // excepción aquí impedía completamente guardar la factura.
        $qrImage = null;

        $this->guardarRegistro(
            $order,
            $invoice,
            $response,
            $estado,
            null,
            $tipoAbono,
            $facturaRectificada,
            'alta',
            null,
            $xmlEnviado,
            ...$desgloseParaGuardar
        );

        if (in_array($estado, ['aceptado', 'aceptado_con_errores'], true)) {
            $invoice->csv = $response->csv;

            try {
                // Preferimos PNG con GD cuando está disponible porque es el
                // formato más compatible con los motores PDF de HikaShop.
                if (extension_loaded('gd')) {
                    $qrPng = Verifactu::generateInvoiceQr(
                        $invoice,
                        QrGeneratorService::DESTINATION_STRING,
                        300,
                        QrGeneratorService::RENDERER_GD
                    );

                    if ($qrPng !== null && $qrPng !== '') {
                        $qrImage = 'data:image/png;base64,' . base64_encode($qrPng);
                    }
                }

                // Si GD no existe, usamos SVG. Esto no requiere ninguna
                // extensión gráfica de PHP y permite generar igualmente el QR
                // conforme a la URL de verificación de AEAT.
                if ($qrImage === null) {
                    $qrSvg = Verifactu::generateInvoiceQr(
                        $invoice,
                        QrGeneratorService::DESTINATION_STRING,
                        300,
                        QrGeneratorService::RENDERER_SVG
                    );

                    if ($qrSvg !== null && $qrSvg !== '') {
                        $qrImage = 'data:image/svg+xml;base64,' . base64_encode($qrSvg);
                    }
                }

                if ($qrImage !== null) {
                    $this->actualizarQrRegistro($order->order_id, $invoice->getInvoiceId()->seriesNumber, $qrImage);
                } else {
                    $this->logQr('No se pudo generar QR para pedido ' . $order->order_id . ' aunque el registro AEAT ya fue guardado.');
                }
            } catch (\Throwable $e) {
                // El fallo del QR nunca debe borrar ni impedir el registro
                // de una factura ya enviada/aceptada por AEAT.
                $this->logQr('Error generando QR para pedido ' . $order->order_id . ': ' . $e->getMessage() . ' en ' . $e->getFile() . ':' . $e->getLine());
            }
        }

            return [
                'estado' => $estado,
                'csv' => $response->csv ?? null,
                'errores' => $response->lineResponses ?? [],
                'qr' => $qrImage,
            ];
        } finally {
            // Se libera pase lo que pase: aceptado, rechazado, error de
            // validación devuelto por abortarConNumeroAsignado() (que
            // también hace `return` dentro de este try) o una excepción no
            // capturada -- ver el comentario de adquirirLockSecuencia().
            $this->liberarLockSecuencia($nifEmisor);
        }
    }

    /**
     * Obtiene el código ISO 3166-1 alpha-2 del país de la dirección de facturación.
     * HikaShop puede exponerlo directamente o como ID/nombre en address_country.
     */
    /**
     * Anula ante la AEAT la última factura aceptada de este pedido mediante
     * un Registro de Anulación (RegistroAnulacion) -- NO una factura
     * rectificativa con importe negativo. Es una operación distinta:
     * referencia la factura anulada y su huella, encadenada con el resto de
     * registros de este emisor, y la AEAT la trata como "esta factura nunca
     * debió emitirse", no como una devolución de una venta real.
     *
     * Por eso NO consume número de ninguna serie (F1/F2/F3/R): la factura
     * anulada conserva su número para siempre, marcado como anulado, y ese
     * número no vuelve a usarse jamás -- reutilizarlo produciría un registro
     * de facturación duplicado ante la AEAT.
     */
    public function anularFactura($order): array
    {
        $this->ensureSchema();

        $orderId = (int) $order->order_id;
        $motivo = $this->getMotivoAnulacion($order);

        $facturaAAnular = $this->obtenerFacturaParaAnular($orderId);
        if ($facturaAAnular === null) {
            return [
                'estado' => 'error_sin_factura_para_anular',
                'csv' => null,
                'errores' => ['No hay ninguna factura aceptada pendiente de anular para este pedido (o ya está anulada).'],
                'qr' => null,
            ];
        }

        $limpiarNif = fn(?string $nif): string => strtoupper(preg_replace('/[^A-Za-z0-9]/', '', (string) $nif));
        $nifEmisor = $limpiarNif($this->params->get('nif_emisor'));

        $cancellation = new InvoiceCancellation();

        $invoiceId = new InvoiceId();
        $invoiceId->issuerNif = $nifEmisor;
        $invoiceId->seriesNumber = $facturaAAnular->invoice_number;
        // invoice_date es DATETIME en la BBDD ("2026-09-17 00:00:00"); se
        // recorta a Y-m-d por el mismo motivo que en addSubstitutedInvoice
        // (ver registrarFactura): el formateador de la librería solo
        // reconoce ese patrón exacto.
        $invoiceId->issueDate = substr((string) $facturaAAnular->invoice_date, 0, 10);
        $cancellation->setInvoiceId($invoiceId);

        // Mismo bloqueo que en registrarFactura() -- ver el comentario
        // v0.34.8 allí para el porqué. Aquí no hay número nuevo que
        // proteger (una anulación referencia una factura YA registrada),
        // así que basta con guardarRegistro() directamente, sin pasar por
        // abortarConNumeroAsignado().
        // v0.34.19: antes este bloque iba ANTES de setInvoiceId(), así que la
        // fila de la anulación salía sin número, fecha ni NIF y el INSERT
        // fallaba (solo quedaba en el log). Ahora $cancellation ya lleva su
        // InvoiceId cuando se guarda.
        if ((string) $this->params->get('aviso_responsabilidad_aceptado', '0') !== '1') {
            $this->guardarRegistro($order, $cancellation, [
                'error' => ['No se ha aceptado el aviso legal y las condiciones de uso en la configuración del plugin, así que no se envía la anulación a la AEAT.'],
            ], 'error_aviso_legal_no_aceptado', null, '', null, 'anulacion', $motivo);

            return [
                'estado' => 'error_aviso_legal_no_aceptado',
                'csv' => null,
                'errores' => ['No se ha aceptado el aviso legal y las condiciones de uso en la configuración del plugin (campo "He leído y acepto..."), así que no se envía nada a la AEAT. Entra en Complementos > Hikashop Verifactu plugin, léelo y márcalo para poder enviar facturas.'],
                'qr' => null,
            ];
        }

        $cancellation->issuerName = $this->params->get('nombre_emisor');
        $cancellation->setSystemInfo($this->construirSistemaInformatico($nifEmisor, $limpiarNif));
        $cancellation->recordTimestamp = date('c');
        $cancellation->hashType = HashType::SHA_256;

        // v0.34.26: mismo lock nombrado que en registrarFactura() -- ver el
        // comentario junto a adquirirLockSecuencia(). Una anulación también
        // consume un hueco en la cadena de este emisor (ver
        // obtenerUltimoRegistroGenerado()), así que necesita la misma
        // protección frente a otra factura o anulación concurrente del
        // mismo emisor.
        if (!$this->adquirirLockSecuencia($nifEmisor)) {
            VerifactuLog::add('ERROR: no se pudo adquirir el lock de secuencia del emisor ' . $nifEmisor . ' (anulación pedido ' . $orderId . '); otra factura del mismo emisor se está generando en este momento.', \Joomla\CMS\Log\Log::ERROR);

            return [
                'estado' => 'error_concurrencia',
                'csv' => null,
                'errores' => ['No se pudo generar la anulación porque otra factura del mismo emisor se está procesando en este momento. Vuelve a intentarlo en unos segundos.'],
                'qr' => null,
            ];
        }

        try {
            // Mismo encadenamiento que una factura normal: se referencia el
            // registro inmediatamente anterior GENERADO de este emisor (factura
            // O anulación, cualquier estado_envio, cualquiera de las dos, sea
            // cual sea el pedido al que pertenezca) -- ver
            // obtenerUltimoRegistroGenerado().
            $chaining = new Chaining();
            $anterior = $this->obtenerUltimoRegistroGenerado($nifEmisor);
            if ($anterior === null) {
                $chaining->firstRecord = YesNoType::YES;
            } else {
                $chaining->setPreviousInvoice([
                    'seriesNumber' => $anterior->invoice_number,
                    'issuerNif' => $nifEmisor,
                    'issueDate' => substr((string) $anterior->invoice_date, 0, 10),
                    'hash' => $anterior->hash_actual,
                ]);
            }
            $cancellation->setChaining($chaining);

            // v0.34.23: mismo razonamiento que en registrarFactura() -- ver el
            // comentario allí. RechazoPrevio ya lo soportaba la librería para
            // anulaciones (a diferencia de altas); aquí solo se fija el valor
            // cuando corresponde, cosa que antes no se hacía nunca. El XSD de
            // anulación no lleva Subsanacion (RechazoPrevioAnulacionType es un
            // campo independiente, sin la validación cruzada 1153/1161 de altas).
            if ($this->huboRechazoPrevioAeat((int) $order->order_id, 'anulacion', (string) $facturaAAnular->invoice_number)) {
                $cancellation->previousRejection = YesNoType::YES;
            }

            try {
                $validationResult = $cancellation->validate();
                if (!empty($validationResult)) {
                    $this->guardarRegistro($order, $cancellation, ['error' => $validationResult], 'error_validacion', null, '', null, 'anulacion', $motivo);

                    return [
                        'estado' => 'error_validacion',
                        'csv' => null,
                        'detalle' => $validationResult,
                    ];
                }

                $response = Verifactu::cancelInvoice($cancellation);
                $xmlEnviado = Verifactu::getLastRequestXml();
            } catch (\Throwable $e) {
                VerifactuLog::add('ERROR técnico anulando factura AEAT pedido ' . $orderId . ' (' . $facturaAAnular->invoice_number . '): ' . $e->getMessage(), \Joomla\CMS\Log\Log::ERROR);

                $this->guardarRegistro($order, $cancellation, ['error_tecnico' => $e->getMessage()], 'error_tecnico', null, '', null, 'anulacion', $motivo, Verifactu::getLastRequestXml());

                return [
                    'estado' => 'error_tecnico',
                    'csv' => null,
                    'errores' => ['Error técnico al comunicar con la AEAT: ' . $e->getMessage()],
                    'qr' => null,
                ];
            }

            $estado = $this->decidirEstadoDesdeRespuesta($response);

            $this->guardarRegistro($order, $cancellation, $response, $estado, null, '', null, 'anulacion', $motivo, $xmlEnviado);

            VerifactuLog::add('Anulación de la factura ' . $facturaAAnular->invoice_number . ' del pedido ' . $orderId . ': ' . $estado . ($motivo !== '' ? ' (motivo: ' . $motivo . ')' : ''));

            return [
                'estado' => $estado,
                'csv' => $response->csv ?? null,
                'errores' => $response->lineResponses ?? [],
                'qr' => null,
            ];
        } finally {
            $this->liberarLockSecuencia($nifEmisor);
        }
    }

    /**
     * Rectificativa R1 por diferencias (positiva o negativa) sobre la
     * última factura aceptada de ESTE MISMO pedido -- ej. una base que pasa
     * de 1.000 € a 1.200 € genera una R1 positiva por +200 € de base (más
     * el IVA y, si corresponde, el recargo de equivalencia proporcionales a
     * esa diferencia), no una factura nueva por 1.200 €.
     *
     * A diferencia de la rectificación por "Tipo de abono" (que exige un
     * pedido de abono aparte con order_full_price negativo -- ver
     * registrarFactura()), esta vía NO depende en ningún momento del signo
     * del importe del pedido: es lo que permite una rectificación positiva,
     * que la otra vía bloquea a propósito (un "Tipo de abono" solo es
     * válido con una factura de importe negativo).
     *
     * "Por diferencias" (TipoRectificativa=I, RectificationType::INCREMENTAL)
     * es siempre el tipo usado aquí: el registro que se envía a la AEAT
     * lleva los IMPORTES DE LA DIFERENCIA, nunca el importe total nuevo de
     * la factura -- igual que ya hace el resto de rectificativas de este
     * plugin.
     */
    public function rectificarPorDiferencias($order): array
    {
        $this->ensureSchema();

        $orderId = (int) $order->order_id;

        $facturaOriginal = $this->obtenerFacturaParaRectificarPorDiferencias($orderId);
        if ($facturaOriginal !== null) {
            // guardarRegistro() espera `order_invoice_number` (mismo nombre
            // que usa obtenerFacturaRectificada(), que lee de
            // #__hikashop_order); aquí la columna se llama `invoice_number`
            // porque viene de #__hikashop_verifactu_registro. Se normaliza
            // aquí, una sola vez, en vez de tocar guardarRegistro().
            $facturaOriginal->order_invoice_number = $facturaOriginal->invoice_number;
        }
        if ($facturaOriginal === null) {
            return [
                'estado' => 'error_sin_factura_para_rectificar',
                'csv' => null,
                'errores' => ['No hay ninguna factura aceptada para este pedido sobre la que calcular una rectificación por diferencias.'],
                'qr' => null,
            ];
        }

        // v0.34.57: una factura original EXENTA (exportación E2 a Canarias/
        // Ceuta/Melilla o país no UE; entrega intracomunitaria E5) se guarda
        // con base pero SIN tipo ni cuota de IVA. Antes se trataba como
        // "sin desglose" y la rectificación fallaba; ahora se rectifica solo
        // la base, repitiendo régimen y causa de exención de la original.
        $esExenta = $facturaOriginal->base_imponible !== null
            && $facturaOriginal->tipo_iva === null
            && $facturaOriginal->cuota_iva === null;

        if ($facturaOriginal->base_imponible === null || (!$esExenta && $facturaOriginal->tipo_iva === null)) {
            return [
                'estado' => 'error_sin_desglose_original',
                'csv' => null,
                'errores' => ['La factura ' . $facturaOriginal->invoice_number . ' no tiene guardado un desglose de IVA reducible a un único tipo (o es anterior a esta funcionalidad), así que no se puede calcular automáticamente la diferencia. Revisa manualmente los importes y, si hace falta, usa "Tipo de abono" con un pedido de abono en su lugar.'],
                'qr' => null,
            ];
        }

        $nuevaBase = $this->getNuevaBaseImponible($order);
        if ($nuevaBase === null) {
            return [
                'estado' => 'error_rectificacion_sin_datos',
                'csv' => null,
                'errores' => ['"Rectificar por diferencias" está en Sí pero no se ha indicado la Nueva base imponible.'],
                'qr' => null,
            ];
        }

        $claveRegimenExenta = null;
        $causaExencion = null;

        if ($esExenta) {
            // Una operación exenta no lleva tipo ni cuota: no tiene sentido
            // indicar un nuevo tipo de IVA/recargo. Se rechaza en vez de
            // ignorarlo en silencio, para no ocultar un intento de cambiar
            // el tratamiento fiscal (eso no es una rectificación por
            // diferencias de la base).
            if ($this->getNuevoTipoIva($order) !== null || $this->getNuevoTipoRecargo($order) !== null) {
                return [
                    'estado' => 'error_rectificacion_exenta_con_tipo',
                    'csv' => null,
                    'errores' => ['La factura ' . $facturaOriginal->invoice_number . ' es una operación exenta de IVA (sin tipo ni cuota), así que "Nuevo tipo de IVA" y "Nuevo tipo de recargo" no aplican: déjalos en blanco. Si el tratamiento fiscal era incorrecto (p. ej. debía llevar IVA), usa "Tipo de abono" = "Error en IVA" con un pedido de abono.'],
                    'qr' => null,
                ];
            }

            $tratamiento = $this->resolverTratamientoExento($facturaOriginal, $order);
            if ($tratamiento === null) {
                return [
                    'estado' => 'error_rectificacion_exenta_sin_regimen',
                    'csv' => null,
                    'errores' => ['No se ha podido determinar el régimen y la causa de exención de la factura ' . $facturaOriginal->invoice_number . ' (no están guardados y no se puede deducir el país de destino del pedido), así que no se envía la rectificación. Usa "Tipo de abono" con un pedido de abono en su lugar.'],
                    'qr' => null,
                ];
            }
            [$claveRegimenExenta, $causaExencion] = $tratamiento;

            $tipoIva = null;
            $tipoRecargo = null;
            $llevaRecargo = false;
            $baseOriginal = (float) $facturaOriginal->base_acumulada;
            $diferenciaBase = round($nuevaBase - $baseOriginal, 2);
            $diferenciaIva = 0.0;
            $diferenciaRecargo = 0.0;
        } else {
            // Tipo de IVA y de recargo: si no se ha indicado uno nuevo, se
            // mantiene EXACTAMENTE el de la factura original -- "manteniendo su
            // porcentaje correspondiente". Sin recargo en la original (null) y
            // sin uno nuevo indicado, la rectificación tampoco lleva recargo.
            $tipoIva = $this->getNuevoTipoIva($order) ?? (float) $facturaOriginal->tipo_iva;
            $tipoRecargo = $this->getNuevoTipoRecargo($order) ?? ($facturaOriginal->tipo_recargo !== null ? (float) $facturaOriginal->tipo_recargo : null);

            // v0.34.31: un cambio de TIPO real (no solo de base) no se puede
            // representar de forma matemáticamente consistente en una única
            // línea de diferencia -- ahí "importe = base × tipo" dejaría de
            // cumplirse dentro de esa línea (el importe es una diferencia de
            // dos importes calculados a tipos distintos, no base_diferencia ×
            // un único tipo), lo que la AEAT puede rechazar o aceptar con datos
            // incoherentes. Se bloquea explícitamente en vez de enviarlo.
            $tipoIvaOriginalComparacion = (float) $facturaOriginal->tipo_iva;
            $tipoRecargoOriginalComparacion = $facturaOriginal->tipo_recargo !== null ? (float) $facturaOriginal->tipo_recargo : 0.0;
            $tipoRecargoComparacion = $tipoRecargo ?? 0.0;
            if (abs($tipoIva - $tipoIvaOriginalComparacion) > 0.001 || abs($tipoRecargoComparacion - $tipoRecargoOriginalComparacion) > 0.001) {
                return [
                    'estado' => 'error_rectificacion_cambio_tipo_no_soportado',
                    'csv' => null,
                    'errores' => ['"Rectificar por diferencias" no admite todavía un cambio real de tipo de IVA/recargo respecto a la factura original (' . $tipoIvaOriginalComparacion . '% / ' . $tipoRecargoOriginalComparacion . '%): solo admite corregir la BASE manteniendo los mismos tipos. Para un cambio de tipo real usa "Tipo de abono" = "Error en IVA" con un pedido de abono en su lugar. Deja "Nuevo tipo de IVA" y "Nuevo tipo de recargo" en blanco para esta rectificación.'],
                    'qr' => null,
                ];
            }

            // lo ya declarado: la factura más las rectificaciones por diferencias aceptadas
            $baseOriginal = (float) $facturaOriginal->base_acumulada;
            $cuotaIvaOriginal = (float) $facturaOriginal->cuota_acumulada;
            $recargoOriginal = $facturaOriginal->recargo_acumulado !== null ? (float) $facturaOriginal->recargo_acumulado : 0.0;

            $diferenciaBase = round($nuevaBase - $baseOriginal, 2);
            $nuevaCuotaIva = round($nuevaBase * $tipoIva / 100, 2);
            $diferenciaIva = round($nuevaCuotaIva - $cuotaIvaOriginal, 2);

            // v0.34.31: OJO, bug corregido aquí -- antes se exigía además
            // "$tipoRecargo > 0" para considerar que había recargo, lo que
            // ignoraba por completo el recargo ORIGINAL cuando el merchant
            // corrige explícitamente el tipo de recargo a 0% (ej. "esta venta
            // nunca debió llevar recargo de equivalencia, corrijo a 0"): el
            // diff_recargo se quedaba en 0.0 en vez de restar el recargo
            // original ya facturado. Lo único que debe decidir si "hay
            // dimensión de recargo que declarar" es si $tipoRecargo es
            // conocido (no null) -- null solo ocurre cuando ni el merchant ha
            // indicado uno nuevo NI la factura original llevaba recargo, que es
            // el único caso real en el que no hay nada que declarar.
            $llevaRecargo = $tipoRecargo !== null;
            $nuevoRecargo = $llevaRecargo ? round($nuevaBase * $tipoRecargo / 100, 2) : 0.0;
            $diferenciaRecargo = $llevaRecargo ? round($nuevoRecargo - $recargoOriginal, 2) : 0.0;
        }

        if (abs($diferenciaBase) < 0.005 && abs($diferenciaIva) < 0.005 && abs($diferenciaRecargo) < 0.005) {
            return [
                'estado' => 'error_rectificacion_sin_diferencia',
                'csv' => null,
                'errores' => ['La Nueva base imponible (y, en su caso, el nuevo tipo) coincide con lo ya declarado para la factura ' . $facturaOriginal->invoice_number . ' (la factura más sus rectificaciones por diferencias aceptadas): no hay ninguna diferencia que rectificar.'],
                'qr' => null,
            ];
        }

        $limpiarNif = fn(?string $nif): string => strtoupper(preg_replace('/[^A-Za-z0-9]/', '', (string) $nif));
        $nifEmisor = $limpiarNif($this->params->get('nif_emisor'));

        $billing = $order->billing_address ?? null;
        $nifCliente = $limpiarNif($billing->address_vat ?? '');
        $tieneNifCliente = !empty($billing) && !empty($nifCliente);

        // R5 si la factura rectificada es simplificada (F2, o R5), R1 si no;
        // sin tipo guardado (registros antiguos), según el NIF como antes.
        $tipoRectificada = (string) ($facturaOriginal->tipo_factura ?? '');
        $esR5 = $tipoRectificada !== ''
            ? in_array($tipoRectificada, ['F2', 'R5'], true)
            : !$tieneNifCliente;

        if (!$esR5 && !$tieneNifCliente) {
            return [
                'estado' => 'error_rectificacion_sin_nif',
                'csv' => null,
                'errores' => ['La factura ' . $facturaOriginal->invoice_number . ' es una factura completa (' . $tipoRectificada . '), así que su rectificación es una R1, que exige identificar al destinatario, y el pedido ya no tiene NIF/identificación fiscal en la dirección de facturación. Vuelve a indicarlo y reenvía.'],
                'qr' => null,
            ];
        }

        // v0.34.58: sin NIF la rectificativa saldría como R5 (simplificada),
        // y una factura simplificada debe llevar IVA (tipo impositivo
        // aplicado) -- no puede rectificar una operación exenta. Se rechaza
        // ANTES de asignar número, para no consumir uno de la serie R.
        if ($esExenta && $esR5) {
            return [
                'estado' => 'error_simplificada_exenta',
                'csv' => null,
                'errores' => ['La factura ' . $facturaOriginal->invoice_number . ' es una operación exenta de IVA y el pedido no tiene NIF/identificación fiscal del cliente, así que la rectificación saldría como factura simplificada (R5). Una factura simplificada debe llevar IVA (tipo impositivo aplicado), por lo que no puede documentar una operación exenta y no se envía a la AEAT. Indica el NIF/identificación fiscal del cliente en la dirección de facturación y reenvía.'],
                'qr' => null,
            ];
        }

        // Cada rectificación por diferencias es una factura propia, con su
        // número y su fecha (clave RD1, RD2...), y no cambia el número de la
        // factura del pedido. Un reintento de la misma R1 (aún no aceptada)
        // reutiliza su clave, y con ella su número y su fecha.
        $clave = 'RD' . ($facturaOriginal->rectificaciones + 1);
        $fechaOperacion = $this->obtenerFechaExpedicionCongelada($orderId, $clave);
        if ((string) $this->params->get('numeracion_propia_activa', '0') === '1') {
            $numeroR = $this->aplicarNumeracionPropia($order, 'R', $fechaOperacion, $clave, false);
            if ($numeroR === (string) ($order->order_invoice_number ?? $order->order_number)) {
                return [
                    'estado' => 'error_numeracion',
                    'csv' => null,
                    'errores' => ['No se pudo asignar un número de la serie R a la rectificación por diferencias (ver el log de VeriFactu). No se envía nada a la AEAT.'],
                    'qr' => null,
                ];
            }
        } else {
            $numeroR = $facturaOriginal->invoice_number . '-R' . ($facturaOriginal->rectificaciones + 1);
        }

        $invoice = new InvoiceSubmission();

        $invoiceId = new InvoiceId();
        $invoiceId->issuerNif = $nifEmisor;
        $invoiceId->seriesNumber = $numeroR;
        $invoiceId->issueDate = $fechaOperacion;
        $invoice->setInvoiceId($invoiceId);

        // Mismo bloqueo que registrarFactura()/anularFactura() -- ver el
        // comentario v0.34.8 en registrarFactura() para el porqué de
        // guardar la fila local (vía abortarConNumeroAsignado) en vez de
        // devolver el error sin más: el número ya asignado arriba no debe
        // quedar "quemado" sin rastro.
        if ((string) $this->params->get('aviso_responsabilidad_aceptado', '0') !== '1') {
            return $this->abortarConNumeroAsignado($order, $invoice, '', $facturaOriginal, 'error_aviso_legal_no_aceptado', [
                'estado' => 'error_aviso_legal_no_aceptado',
                'csv' => null,
                'errores' => ['No se ha aceptado el aviso legal y las condiciones de uso en la configuración del plugin (campo "He leído y acepto..."), así que no se envía nada a la AEAT. Entra en Complementos > Hikashop Verifactu plugin, léelo y márcalo para poder enviar facturas.'],
                'qr' => null,
            ]);
        }

        $invoice->issuerName = $this->params->get('nombre_emisor');
        // R5 (rectificativa de una simplificada) o R1, según $esR5 más arriba.
        $invoice->invoiceType = $esR5 ? InvoiceType::RECTIFICATION_SIMPLIFIED : InvoiceType::RECTIFICATION_1;
        $invoice->rectificationType = RectificationType::INCREMENTAL;
        $invoice->addRectifiedInvoice($nifEmisor, (string) $facturaOriginal->invoice_number, substr((string) $facturaOriginal->invoice_date, 0, 10));

        $comentarioAbono = trim((string) ($order->verifactu_comentario_abono ?? ''));
        $descripcion = 'Rectificación por diferencias del pedido #' . $order->order_number . ' sobre la factura ' . $facturaOriginal->invoice_number;
        if ($comentarioAbono !== '') {
            $descripcion .= ': ' . $comentarioAbono;
        }
        $invoice->operationDescription = mb_substr($descripcion, 0, 500);

        $totalDiferencia = round($diferenciaBase + $diferenciaIva + $diferenciaRecargo, 2);
        $invoice->totalAmount = $totalDiferencia;

        $detail = new BreakdownDetail();
        $detail->taxType = TaxType::IVA;
        if ($esExenta) {
            // Mismo tratamiento que la factura original: sujeta y exenta,
            // sin tipo ni cuota. OperacionExenta y CalificacionOperacion
            // son mutuamente excluyentes en el esquema de la AEAT.
            $detail->regimeKey = RegimeType::from($claveRegimenExenta);
            $detail->exemptOperation = ExemptOperationType::from($causaExencion);
            $detail->taxRate = null;
            $detail->taxableBase = $diferenciaBase;
            $detail->taxAmount = null;
        } else {
            $detail->regimeKey = RegimeType::GENERAL;
            $detail->taxRate = $tipoIva;
            $detail->taxableBase = $diferenciaBase;
            $detail->taxAmount = $diferenciaIva;
            $detail->operationQualification = OperationQualificationType::SUBJECT_NO_EXEMPT_NO_REVERSE;
            if ($llevaRecargo) {
                $detail->equivalenceSurchargeRate = $tipoRecargo;
                $detail->equivalenceSurchargeAmount = $diferenciaRecargo;
            }
        }

        $breakdown = new Breakdown();
        $breakdown->addDetail($detail);
        $invoice->setBreakdown($breakdown);
        $invoice->taxAmount = $diferenciaIva;

        if (!$esR5) {
            $resultadoDestinatario = $this->construirDestinatario($order, $billing, $nifCliente);
            if (isset($resultadoDestinatario['error'])) {
                return $this->abortarConNumeroAsignado($order, $invoice, '', $facturaOriginal, $resultadoDestinatario['estado'], $resultadoDestinatario['error']);
            }
            $invoice->addRecipient($resultadoDestinatario['recipient']);
        } else {
            $invoice->invoiceWithoutRecipient = YesNoType::YES;
        }

        if (!$this->adquirirLockSecuencia($nifEmisor)) {
            VerifactuLog::add('ERROR: no se pudo adquirir el lock de secuencia del emisor ' . $nifEmisor . ' (rectificación por diferencias pedido ' . $orderId . '); otra factura del mismo emisor se está generando en este momento.', \Joomla\CMS\Log\Log::ERROR);

            return $this->abortarConNumeroAsignado($order, $invoice, '', $facturaOriginal, 'error_concurrencia', [
                'estado' => 'error_concurrencia',
                'csv' => null,
                'errores' => ['No se pudo generar la rectificación porque otra factura del mismo emisor se está procesando en este momento. Vuelve a intentarlo en unos segundos.'],
                'qr' => null,
            ]);
        }

        try {
            $chaining = new Chaining();
            $anterior = $this->obtenerUltimoRegistroGenerado($nifEmisor);
            if ($anterior === null) {
                $chaining->firstRecord = YesNoType::YES;
            } else {
                $chaining->setPreviousInvoice([
                    'seriesNumber' => $anterior->invoice_number,
                    'issuerNif' => $nifEmisor,
                    'issueDate' => substr((string) $anterior->invoice_date, 0, 10),
                    'hash' => $anterior->hash_actual,
                ]);
            }
            $invoice->setChaining($chaining);

            if ($this->huboRechazoPrevioAeat($orderId, 'alta', $numeroR)) {
                $invoice->subsanacion = YesNoType::YES;
                $invoice->previousRejection = YesNoType::YES;
            }

            $invoice->setSystemInfo($this->construirSistemaInformatico($nifEmisor, $limpiarNif));
            $invoice->recordTimestamp = date('c');
            $invoice->hashType = HashType::SHA_256;

            try {
                $validationResult = $invoice->validate();
                if (!empty($validationResult)) {
                    return $this->abortarConNumeroAsignado($order, $invoice, '', $facturaOriginal, 'error_validacion', [
                        'estado' => 'error_validacion',
                        'detalle' => $validationResult,
                    ]);
                }

                $response = Verifactu::registerInvoice($invoice);
                $xmlEnviado = Verifactu::getLastRequestXml();
            } catch (\Throwable $e) {
                VerifactuLog::add('ERROR técnico en rectificación por diferencias pedido ' . $orderId . ' (' . $numeroR . '): ' . $e->getMessage(), \Joomla\CMS\Log\Log::ERROR);

                return $this->abortarConNumeroAsignado($order, $invoice, '', $facturaOriginal, 'error_tecnico', [
                    'estado' => 'error_tecnico',
                    'csv' => null,
                    'errores' => ['Error técnico al comunicar con la AEAT: ' . $e->getMessage()],
                    'qr' => null,
                ], Verifactu::getLastRequestXml());
            }

            $estado = $this->decidirEstadoDesdeRespuesta($response);
            $qrImage = null;

            // El desglose que se guarda aquí es el de ESTA rectificación
            // (la diferencia): obtenerFacturaParaRectificarPorDiferencias()
            // lo suma al de la factura para la siguiente.
            $this->guardarRegistro(
                $order,
                $invoice,
                $response,
                $estado,
                null,
                '',
                $facturaOriginal,
                'alta',
                null,
                $xmlEnviado,
                $diferenciaBase,
                $esExenta ? null : $tipoIva,
                $esExenta ? null : $diferenciaIva,
                $tipoRecargo,
                $llevaRecargo ? $diferenciaRecargo : null,
                $esExenta ? $claveRegimenExenta : RegimeType::GENERAL->value,
                $esExenta ? $causaExencion : null
            );

            if (in_array($estado, ['aceptado', 'aceptado_con_errores'], true)) {
                $this->limpiarCamposRectificacion($orderId);
                $invoice->csv = $response->csv;

                try {
                    if (extension_loaded('gd')) {
                        $qrPng = Verifactu::generateInvoiceQr($invoice, QrGeneratorService::DESTINATION_STRING, 300, QrGeneratorService::RENDERER_GD);
                        if ($qrPng !== null && $qrPng !== '') {
                            $qrImage = 'data:image/png;base64,' . base64_encode($qrPng);
                        }
                    }

                    if ($qrImage === null) {
                        $qrSvg = Verifactu::generateInvoiceQr($invoice, QrGeneratorService::DESTINATION_STRING, 300, QrGeneratorService::RENDERER_SVG);
                        if ($qrSvg !== null && $qrSvg !== '') {
                            $qrImage = 'data:image/svg+xml;base64,' . base64_encode($qrSvg);
                        }
                    }

                    if ($qrImage !== null) {
                        $this->actualizarQrRegistro($orderId, $invoice->getInvoiceId()->seriesNumber, $qrImage);
                    } else {
                        $this->logQr('No se pudo generar QR para pedido ' . $orderId . ' (rectificación por diferencias) aunque el registro AEAT ya fue guardado.');
                    }
                } catch (\Throwable $e) {
                    $this->logQr('Error generando QR para pedido ' . $orderId . ' (rectificación por diferencias): ' . $e->getMessage() . ' en ' . $e->getFile() . ':' . $e->getLine());
                }
            }

            VerifactuLog::add('Rectificación por diferencias del pedido ' . $orderId . ' sobre ' . $facturaOriginal->invoice_number . ': ' . $estado . ' (base ' . $diferenciaBase . ', iva ' . $diferenciaIva . ', recargo ' . $diferenciaRecargo . ', total ' . $totalDiferencia . ')');

            return [
                'estado' => $estado,
                'csv' => $response->csv ?? null,
                'errores' => $response->lineResponses ?? [],
                'qr' => $qrImage,
            ];
        } finally {
            $this->liberarLockSecuencia($nifEmisor);
        }
    }

    /**
     * Una vez aceptada la rectificación por diferencias, el pedido vuelve a
     * "Rectificar por diferencias" = No y sin importes, para que el siguiente
     * guardado no la repita.
     */
    private function limpiarCamposRectificacion(int $orderId): void
    {
        $campo = function (string $paramKey, string $defecto): string {
            $nombre = trim((string) $this->params->get($paramKey, $defecto));

            return $nombre !== '' ? $nombre : $defecto;
        };

        try {
            $query = $this->db->getQuery(true)
                ->update('#__hikashop_order')
                ->set($this->db->quoteName($campo('campo_rectificar_diferencias', 'verifactu_rectificar_diferencias')) . ' = ' . $this->db->quote('No'))
                ->set($this->db->quoteName($campo('campo_nueva_base_imponible', 'verifactu_nueva_base_imponible')) . ' = NULL')
                ->set($this->db->quoteName($campo('campo_nuevo_tipo_iva', 'verifactu_nuevo_tipo_iva')) . ' = NULL')
                ->set($this->db->quoteName($campo('campo_nuevo_tipo_recargo', 'verifactu_nuevo_tipo_recargo')) . ' = NULL')
                ->where('order_id = ' . $orderId);
            $this->db->setQuery($query);
            $this->db->execute();
        } catch (\Throwable $e) {
            VerifactuLog::add('No se pudieron limpiar los campos de rectificación del pedido ' . $orderId . ': ' . $e->getMessage(), \Joomla\CMS\Log\Log::WARNING);
        }
    }

    /**
     * v0.34.57: régimen y causa de exención que debe repetir una
     * rectificación por diferencias sobre una factura original EXENTA.
     *
     * Primero se usan los valores guardados en el registro de la factura
     * original (clave_regimen / operacion_exenta, desde esta versión). Para
     * facturas exentas emitidas con versiones anteriores, que no los
     * guardaron, se deducen con el mismo criterio que registrarFactura():
     * Canarias/Ceuta/Melilla o país no UE -> exportación (02 + E2); país UE
     * -> entrega intracomunitaria (01 + E5).
     *
     * @return array{0: string, 1: string}|null [clave de régimen, causa de exención]
     */
    private function resolverTratamientoExento(object $facturaOriginal, $order): ?array
    {
        $regimenGuardado = trim((string) ($facturaOriginal->clave_regimen ?? ''));
        $exencionGuardada = trim((string) ($facturaOriginal->operacion_exenta ?? ''));

        if ($regimenGuardado !== '' && $exencionGuardada !== ''
            && RegimeType::tryFrom($regimenGuardado) !== null
            && ExemptOperationType::tryFrom($exencionGuardada) !== null) {
            return [$regimenGuardado, $exencionGuardada];
        }

        $billing = $order->billing_address ?? null;
        $shipping = $order->shipping_address ?? null;

        if ($this->esDestinoCanariasCeutaMelilla($shipping, $billing)) {
            return [RegimeType::EXPORT->value, ExemptOperationType::E2->value];
        }

        $codigoPais = $this->obtenerCodigoPaisFactura($billing);
        if ($codigoPais === '' || $codigoPais === 'ES') {
            return null;
        }

        return self::esPaisUE($codigoPais)
            ? [RegimeType::GENERAL->value, ExemptOperationType::E5->value]
            : [RegimeType::EXPORT->value, ExemptOperationType::E2->value];
    }

    /**
     * Bloque de identificación del destinatario, extraído de
     * registrarFactura() para poder reutilizarlo tal cual en
     * rectificarPorDiferencias() -- misma lógica exacta (persona física vs.
     * empresa, NIF español vs. extranjero, NIF-IVA) para que una
     * rectificación identifique al cliente igual que la factura que
     * rectifica.
     *
     * @return array{recipient: LegalPerson}|array{error: array, estado: string}
     */
    private function construirDestinatario($order, $billing, string $nifCliente): array
    {
        $nombrePersona = trim(($billing->address_firstname ?? '') . ' ' . ($billing->address_lastname ?? ''));
        $nombreEmpresa = trim($billing->address_company ?? '');

        $nifSinPrefijoEs = preg_replace('/^ES(?=[0-9A-Z]{9}$)/', '', $nifCliente);
        $esPersonaFisica = (bool) preg_match('/^([0-9]{8}[A-Za-z]|[XYZ][0-9]{7}[A-Za-z])$/', $nifSinPrefijoEs);

        $nombreDestinatario = $esPersonaFisica
            ? ($nombrePersona ?: $nombreEmpresa)
            : ($nombreEmpresa ?: $nombrePersona);

        $recipient = new LegalPerson();
        $recipient->name = $nombreDestinatario;

        $codigoPaisCliente = $this->obtenerCodigoPaisFactura($billing);

        if ($codigoPaisCliente === 'ES') {
            $recipient->nif = $nifSinPrefijoEs;
        } elseif ($codigoPaisCliente !== '') {
            $otherId = new OtherID();
            $otherId->countryCode = $codigoPaisCliente;

            $idExtranjero = strtoupper(trim($nifCliente));
            if ($idExtranjero === '') {
                return [
                    'error' => [
                        'estado' => 'error_id_destinatario',
                        'csv' => null,
                        'errores' => ['El destinatario extranjero no tiene identificación fiscal.'],
                        'qr' => null,
                    ],
                    'estado' => 'error_id_destinatario',
                ];
            }

            $otherId->idType = self::llevaPrefijoNifIvaDeSuPais($idExtranjero, $codigoPaisCliente) ? '02' : '04';
            $otherId->id = $idExtranjero;
            $recipient->setOtherId($otherId);
        } else {
            return [
                'error' => [
                    'estado' => 'error_pais_destinatario',
                    'csv' => null,
                    'errores' => ['No se ha podido determinar el país del destinatario; no se envía su identificación como NIF español.'],
                    'qr' => null,
                ],
                'estado' => 'error_pais_destinatario',
            ];
        }

        return ['recipient' => $recipient];
    }

    private function obtenerCodigoPaisFactura($billing): string
    {
        if (empty($billing)) {
            return '';
        }

        // HikaShop puede exponer directamente el código ISO en algunas
        // versiones/objetos de dirección. Si está disponible, es la opción
        // más directa.
        foreach ([
            $billing->address_country_code ?? null,
            $billing->address_country_2_code ?? null,
            $billing->country_code ?? null,
            $billing->country_2_code ?? null,
        ] as $valor) {
            $codigo = strtoupper(trim((string) $valor));
            if (preg_match('/^[A-Z]{2}$/', $codigo)) {
                return $codigo;
            }
        }

        $valorPais = $billing->address_country ?? ($billing->country ?? null);
        if ($valorPais === null || $valorPais === '') {
            return '';
        }

        // HikaShop NO utiliza una tabla hikashop_country. Los países y
        // estados se almacenan en #__hikashop_zone. En las direcciones,
        // address_country normalmente contiene el zone_namekey, por ejemplo
        // country_Portugal_XXX.
        $query = $this->db->getQuery(true)
            ->select($this->db->quoteName('zone_code_2'))
            ->from($this->db->quoteName('#__hikashop_zone'))
            ->where($this->db->quoteName('zone_type') . ' = ' . $this->db->quote('country'))
            ->setLimit(1);

        if (is_numeric($valorPais)) {
            $query->where($this->db->quoteName('zone_id') . ' = ' . (int) $valorPais);
        } else {
            $valorPais = trim((string) $valorPais);

            // Caso normal de HikaShop: address_country = zone_namekey.
            $query->where($this->db->quoteName('zone_namekey') . ' = ' . $this->db->quote($valorPais));
        }

        $this->db->setQuery($query);
        $codigo = strtoupper(trim((string) $this->db->loadResult()));

        if (preg_match('/^[A-Z]{2}$/', $codigo)) {
            return $codigo;
        }

        // Compatibilidad adicional: algunas integraciones pueden entregar
        // el nombre del país en lugar del zone_namekey.
        if (!is_numeric($valorPais)) {
            $query = $this->db->getQuery(true)
                ->select($this->db->quoteName('zone_code_2'))
                ->from($this->db->quoteName('#__hikashop_zone'))
                ->where($this->db->quoteName('zone_type') . ' = ' . $this->db->quote('country'))
                ->where(
                    '(' .
                    $this->db->quoteName('zone_name') . ' = ' . $this->db->quote((string) $valorPais) .
                    ' OR ' .
                    $this->db->quoteName('zone_name_english') . ' = ' . $this->db->quote((string) $valorPais) .
                    ')'
                )
                ->setLimit(1);

            $this->db->setQuery($query);
            $codigo = strtoupper(trim((string) $this->db->loadResult()));

            if (preg_match('/^[A-Z]{2}$/', $codigo)) {
                return $codigo;
            }
        }

        return '';
    }

    /**
     * ¿El pedido tiene como destino Canarias, Ceuta o Melilla?
     *
     * A efectos de IVA, estos territorios NO forman parte del "territorio
     * de aplicación del impuesto" (TAI) aunque su código de país siga
     * siendo "ES" -- por eso obtenerCodigoPaisFactura() por sí solo no
     * sirve para detectarlos (ver comentario en registrarFactura() sobre
     * $esDestinatarioExtranjero). Una venta de mercancías desde Península
     * o Baleares hacia estos territorios se trata fiscalmente como una
     * exportación (régimen 02), exenta por el artículo 21 de la Ley
     * 37/1992 (causa E2), no como una operación "no sujeta por reglas de
     * localización" (N2, reservada a destinatarios realmente extranjeros)
     * ni como régimen general con IVA (01/S1).
     *
     * Se detecta por el código postal en vez de por el zone_id/zone_name
     * de #__hikashop_zone porque el campo de código postal es un valor de
     * texto libre estable y no depende de cómo tenga cada instalación
     * nombrados o anidados sus estados/provincias en HikaShop:
     *   - 35xxx / 38xxx -> provincias de Las Palmas y Santa Cruz de
     *     Tenerife (Canarias, IGIC).
     *   - 51xxx / 52xxx -> Ceuta y Melilla (IPSI).
     *
     * Nota: esto cubre la venta de BIENES con transporte a estos
     * territorios (el caso típico de un pedido de HikaShop con envío
     * físico). La prestación de SERVICIOS localizados allí sigue otra
     * calificación (N2 + régimen 08) que esta función no contempla.
     *
     * v0.34.4: el campo real de HikaShop es address_post_code (confirmado
     * contra la tabla #__hikashop_address) -- la primera versión de esta
     * función miraba address_zip, que no existe, así que el CP nunca se
     * leía y todo pedido caía en el rechazo por "destinatario no está
     * fuera de España" aunque fuera de Canarias/Ceuta/Melilla.
     *
     * v0.34.17: la exención depende de a DÓNDE van las mercancías, no de
     * dónde se factura -- un cliente que factura en Madrid pero recibe el
     * pedido en Tenerife no debe pagar IVA, y hasta ahora esta función solo
     * miraba el código postal de $billing (la dirección de facturación),
     * así que ese pedido se declaraba erróneamente con IVA peninsular y
     * acababa rechazado por desglose vacío. Ahora se mira primero el
     * código postal de la dirección de ENVÍO ($shipping) y solo se recurre
     * a la de facturación cuando el pedido no tiene dirección de envío (o
     * esta llega vacía) -- HikaShop siempre calcula los impuestos sobre el
     * envío por defecto, así que esto alinea la detección de Canarias/
     * Ceuta/Melilla con el mismo criterio.
     */
    private function esDestinoCanariasCeutaMelilla($shipping, $billing): bool
    {
        $direccion = !empty($shipping) && trim((string) ($shipping->address_post_code ?? $shipping->address_zip ?? $shipping->address_zip_code ?? $shipping->zip ?? '')) !== ''
            ? $shipping
            : $billing;

        if (empty($direccion)) {
            return false;
        }

        $codigoPais = $this->obtenerCodigoPaisFactura($direccion);
        if ($codigoPais !== '' && $codigoPais !== 'ES') {
            return false;
        }

        $cp = trim((string) ($direccion->address_post_code ?? $direccion->address_zip ?? $direccion->address_zip_code ?? $direccion->zip ?? ''));

        return (bool) preg_match('/^(35|38|51|52)\d{3}$/', $cp);
    }

    /**
     * ¿El código de país (ISO 3166-1 alpha-2) es un Estado miembro de la
     * Unión Europea? Se usa para distinguir, en ventas de mercancías sin
     * IVA español y destinatario extranjero, entre una entrega
     * intracomunitaria (UE, exenta E5) y una exportación propiamente dicha
     * (fuera de la UE, exenta E2). Lista de los 27 Estados miembros
     * (Reino Unido excluido tras el Brexit).
     */
    private static function esPaisUE(string $codigoPais): bool
    {
        static $paisesUE = [
            'AT', 'BE', 'BG', 'HR', 'CY', 'CZ', 'DK', 'EE', 'FI', 'FR',
            'DE', 'GR', 'HU', 'IE', 'IT', 'LV', 'LT', 'LU', 'MT', 'NL',
            'PL', 'PT', 'RO', 'SK', 'SI', 'ES', 'SE',
        ];

        return in_array(strtoupper($codigoPais), $paisesUE, true);
    }

    /**
     * Prefijo NIF-IVA (VIES) que corresponde a un código de país ISO-3166
     * de un Estado miembro de la UE. Coincide con el código ISO en casi
     * todos los casos, salvo Grecia: su código ISO es "GR" pero el
     * prefijo que usa en el NIF-IVA/VIES es "EL".
     */
    private static function prefijoNifIvaUE(string $codigoPaisIso): string
    {
        $codigoPaisIso = strtoupper($codigoPaisIso);

        return $codigoPaisIso === 'GR' ? 'EL' : $codigoPaisIso;
    }

    /**
     * ¿El identificador lleva el prefijo NIF-IVA (VIES) de SU PROPIO país
     * (el país de facturación del destinatario), y no el de cualquier otro
     * Estado miembro? Esta función NO consulta el servicio VIES de la
     * Comisión Europea -- solo comprueba el formato: 2 letras (el prefijo
     * exacto del país declarado, no cualquier prefijo UE) + entre 2 y 12
     * caracteres alfanuméricos. $identificador se espera ya limpio
     * (mayúsculas, sin espacios/guiones -- ver $limpiarNif en
     * registrarFactura()). Se usa tanto para decidir si aplica la exención
     * E5 (entrega intracomunitaria) como para elegir el IDType (02 vs 04)
     * del destinatario extranjero en el XML.
     */
    private static function llevaPrefijoNifIvaDeSuPais(string $identificador, string $codigoPaisIso): bool
    {
        if (!self::esPaisUE($codigoPaisIso)) {
            return false;
        }

        $prefijo = self::prefijoNifIvaUE($codigoPaisIso);

        return (bool) preg_match('/^' . preg_quote($prefijo, '/') . '[A-Z0-9]{2,12}$/', $identificador);
    }

    /**
     * ¿Ya hay un registro aceptado por la AEAT para este pedido, dentro de
     * esta familia concreta (F1/F2/F3/R)? Deliberadamente por familia y no
     * por pedido en general: un mismo pedido puede legítimamente tener una
     * F2 aceptada Y, más tarde, una F3 aceptada (la F3 sustituye a la F2,
     * pero ambas quedan registradas). Si se comprobara "¿ya tiene algo
     * aceptado este pedido?" sin más, la F3 nunca podría procesarse porque
     * la F2 anterior ya haría saltar el guard.
     */
    private function yaRegistradoYAceptado(int $orderId, string $familia): bool
    {
        $tiposPorFamilia = [
            'F1' => ['F1'],
            'F2' => ['F2'],
            'F3' => ['F3'],
            'R' => ['R1', 'R2', 'R3', 'R4', 'R5'],
        ];
        $tipos = $tiposPorFamilia[$familia] ?? [$familia];

        $query = $this->db->getQuery(true)
            ->select('id')
            ->from('#__hikashop_verifactu_registro')
            ->where('order_id = ' . $orderId)
            ->where('tipo_factura IN (' . implode(',', array_map(fn($t) => $this->db->quote($t), $tipos)) . ')')
            ->where('estado_envio IN (' . $this->db->quote('aceptado') . ',' . $this->db->quote('aceptado_con_errores') . ')')
            ->setLimit(1);

        $this->db->setQuery($query);
        return (bool) $this->db->loadResult();
    }

    /**
     * v0.34.26: auditoría de seguridad, punto 8 "Concurrencia y generación
     * de hash" (prioridad 5/5). obtenerUltimoRegistroGenerado() + el envío a
     * la AEAT + guardarRegistro() no son atómicos entre sí: si dos pedidos
     * del MISMO emisor se procesan a la vez, ambos pueden leer el mismo
     * "último registro generado", encadenar su hash contra el mismo
     * anterior y guardar en un orden que no coincide con el orden real de
     * generación -- rompiendo el encadenamiento que exige el Reglamento
     * VERI*FACTU (AEAT rechaza o, peor, acepta una cadena que en realidad
     * está corrompida).
     *
     * La sección crítica (leer el anterior -> construir Chaining -> enviar
     * a la AEAT -> guardar el registro) incluye una llamada SOAP externa de
     * duración variable, así que NO se protege con una transacción de base
     * de datos: un SELECT ... FOR UPDATE aquí mantendría la fila/tabla
     * bloqueada durante todo ese tiempo, con riesgo de agotar el pool de
     * conexiones o producir lock wait timeouts en pedidos de OTROS
     * emisores que no deberían verse afectados en absoluto. En su lugar se
     * usa un lock con NOMBRE de MySQL (GET_LOCK/RELEASE_LOCK): no bloquea
     * ninguna fila ni tabla, solo a quien pida el mismo nombre -- aquí,
     * "este NIF emisor" -- y sobrevive sin problema a una espera larga por
     * la red. Esto serializa la generación de registros de un mismo
     * emisor (uno a la vez) sin afectar a otros emisores ni a consultas no
     * relacionadas. Ver su uso en registrarFactura() y anularFactura(),
     * envolviendo desde la lectura del anterior hasta guardarRegistro().
     */
    private function nombreLockSecuencia(string $nifEmisor): string
    {
        // GET_LOCK admite como máximo 64 caracteres en MySQL; se usa un
        // hash corto del NIF en vez del NIF tal cual para no acercarse a
        // ese límite ni depender de que ya venga limpio de caracteres raros.
        return 'verifactu_secuencia_' . substr(md5($nifEmisor), 0, 32);
    }

    private function adquirirLockSecuencia(string $nifEmisor, int $timeoutSegundos = 30): bool
    {
        $nombre = $this->nombreLockSecuencia($nifEmisor);
        $this->db->setQuery('SELECT GET_LOCK(' . $this->db->quote($nombre) . ', ' . (int) $timeoutSegundos . ')');
        $resultado = $this->db->loadResult();

        // GET_LOCK() devuelve 1 (conseguido), 0 (timeout, otro proceso lo
        // tiene) o NULL (error interno de MySQL); solo 1 cuenta como éxito.
        return (int) $resultado === 1;
    }

    private function liberarLockSecuencia(string $nifEmisor): void
    {
        $nombre = $this->nombreLockSecuencia($nifEmisor);

        try {
            $this->db->setQuery('SELECT RELEASE_LOCK(' . $this->db->quote($nombre) . ')');
            $this->db->execute();
        } catch (\Throwable $e) {
            // RELEASE_LOCK() no debe poder tumbar el flujo: si esto falla,
            // el lock nombrado de MySQL se libera igualmente en cuanto la
            // conexión se cierra al final de la petición.
            VerifactuLog::add('Aviso: fallo liberando lock de secuencia (' . $nifEmisor . '): ' . $e->getMessage());
        }
    }

    /**
     * v0.34.22: auditoría de seguridad, punto "Encadenamiento incorrectamente
     * basado en registros aceptados" (prioridad 🔴). Antes se filtraba por
     * estado_envio IN ('aceptado','aceptado_con_errores'), lo que no es lo
     * que exige el modelo de trazabilidad de la AEAT: el encadenamiento debe
     * hacerse con el registro de facturación inmediatamente ANTERIOR
     * GENERADO (por orden cronológico de generación, alta o anulación),
     * independientemente de si la AEAT lo terminó aceptando o rechazando --
     * lo relevante es que el SIF calculó su hash localmente y lo envió (ver
     * VerifactuService::registerInvoice() en la librería: el hash se genera
     * ANTES de la llamada SOAP, así que existe aunque la AEAT rechace o
     * falle la comunicación después).
     *
     * Ahora se filtra solo por hash_actual != '' (hubo un hash realmente
     * generado -- un aborto antes de ese punto, ej. aviso legal no aceptado
     * o validación local fallida, no es un RF real y no debe encadenarse) y
     * se ordena por `id DESC`: desde que guardarRegistro() inserta SIEMPRE
     * una fila nueva por intento (tabla append-only, ver ese método), `id`
     * es por construcción la secuencia cronológica real de generación, sin
     * necesidad de una columna de secuencia aparte.
     */
    private function obtenerUltimoRegistroGenerado(string $nifEmisor): ?object
    {
        $query = $this->db->getQuery(true)
            ->select('id, invoice_number, invoice_date, hash_actual')
            ->from('#__hikashop_verifactu_registro')
            ->where('nif_emisor = ' . $this->db->quote($nifEmisor))
            ->where('hash_actual != ' . $this->db->quote(''))
            ->order('id DESC')
            ->setLimit(1);

        $this->db->setQuery($query);
        return $this->db->loadObject();
    }

    /**
     * v0.34.23: ¿hay algún intento anterior de este pedido, para este mismo
     * tipo_registro ('alta'/'anulacion'), que la AEAT haya rechazado
     * explícitamente? Ver el comentario junto a su única llamada, en
     * registrarFactura(), para el porqué (Subsanacion/RechazoPrevio).
     *
     * Se filtra por el número de la factura que se envía: un pedido puede
     * tener varias (F2 y F3, la factura y sus rectificaciones por
     * diferencias), y el rechazo de una no convierte el envío de otra en una
     * subsanación.
     */
    private function huboRechazoPrevioAeat(int $orderId, string $tipoRegistro, string $invoiceNumber = ''): bool
    {
        $query = $this->db->getQuery(true)
            ->select('id')
            ->from('#__hikashop_verifactu_registro')
            ->where('order_id = ' . $orderId)
            ->where('tipo_registro = ' . $this->db->quote($tipoRegistro))
            ->where('estado_envio = ' . $this->db->quote('rechazado'))
            ->setLimit(1);
        if ($invoiceNumber !== '') {
            $query->where('invoice_number = ' . $this->db->quote($invoiceNumber));
        }

        $this->db->setQuery($query);

        return $this->db->loadResult() !== null;
    }

    private function logQr(string $mensaje): void
    {
        VerifactuLog::add('QR: ' . $mensaje);
    }

    private function actualizarQrRegistro(int $orderId, string $invoiceNumber, string $qrImage): void
    {
        try {
            $query = $this->db->getQuery(true)
                ->update('#__hikashop_verifactu_registro')
                ->set($this->db->quoteName('qr_url') . ' = ' . $this->db->quote($qrImage))
                ->set($this->db->quoteName('modified') . ' = ' . $this->db->quote(date('Y-m-d H:i:s')))
                ->where($this->db->quoteName('order_id') . ' = ' . (int) $orderId)
                ->where($this->db->quoteName('invoice_number') . ' = ' . $this->db->quote($invoiceNumber));

            $this->db->setQuery($query);
            $this->db->execute();

            $this->logQr('QR guardado para pedido ' . $orderId . ', factura ' . $invoiceNumber . '.');
        } catch (\Throwable $e) {
            $this->logQr('Error guardando QR para pedido ' . $orderId . ': ' . $e->getMessage());
        }
    }

    /**
     * $invoice acepta tanto InvoiceSubmission (factura) como InvoiceCancellation
     * (anulación) -- ambas comparten getInvoiceId()/hash a través de su base
     * común InvoiceRecord, pero solo InvoiceSubmission tiene totalAmount/
     * invoiceType/rectificationType, de ahí los `instanceof` de abajo.
     *
     * v0.34.22: auditoría de seguridad, punto "Encadenamiento incorrectamente
     * basado en registros aceptados" (prioridad 🔴). Antes, un reintento
     * sobre un registro no aceptado (ej. "rechazado" por NIF inválido)
     * ACTUALIZABA la misma fila en vez de insertar una nueva -- lo que,
     * combinado con que el encadenamiento solo miraba registros aceptados,
     * podía hacer desaparecer sin rastro un intento rechazado si el
     * reintento posterior sí se aceptaba, y dejaba sin forma de referenciarse
     * nunca a un intento rechazado que NO llegaba a reintentarse con éxito
     * (aun teniendo un hash generado localmente y enviado a la AEAT). Ahora
     * esta función SIEMPRE inserta una fila nueva (tabla append-only): cada
     * intento con hash generado -- aceptado, rechazado o con error técnico
     * después de generarlo -- queda como su propio registro inmutable, y el
     * `id` autoincremental pasa a ser, por construcción, la secuencia
     * cronológica de generación que exige el modelo de encadenamiento de la
     * AEAT (ver obtenerUltimoRegistroGenerado()), sin necesidad de una
     * columna de secuencia aparte.
     *
     * `hash_anterior` (ya existía en el esquema, pero nunca se rellenaba) y
     * `registro_anterior_id` documentan contra qué registro concreto se
     * encadenó ESTE intento, tal y como se envió realmente a la AEAT -- se
     * leen del propio objeto $invoice (su Chaining, ya fijado más arriba en
     * registrarFactura()/anularFactura()) en vez de recibirse por parámetro,
     * para no tener que tocar los numerosos puntos de llamada de este
     * método. Si nunca se llegó a fijar el encadenamiento (aborto antes de
     * ese punto, ej. aviso legal no aceptado), quedan en null -- consistente
     * con que tampoco hay hash_actual real en ese caso.
     */
    private function guardarRegistro($order, $invoice, $response, string $estado, ?string $qrImage, string $tipoAbono = '', ?object $facturaRectificada = null, string $tipoRegistro = 'alta', ?string $motivoAnulacion = null, ?string $xmlEnviado = null, ?float $baseImponible = null, ?float $tipoIva = null, ?float $cuotaIva = null, ?float $tipoRecargo = null, ?float $recargoEquivalencia = null, ?string $claveRegimen = null, ?string $operacionExenta = null): void
    {
        $orderId = (int) $order->order_id;
        $invoiceNumber = (string) $invoice->getInvoiceId()->seriesNumber;
        $esFactura = $invoice instanceof InvoiceSubmission;

        $chaining = method_exists($invoice, 'getChaining') ? $invoice->getChaining() : null;
        $anteriorHash = $chaining?->getPreviousInvoice()?->hash;
        $anteriorId = null;

        if (!empty($anteriorHash)) {
            $this->db->setQuery(
                $this->db->getQuery(true)
                    ->select('id')
                    ->from('#__hikashop_verifactu_registro')
                    ->where('hash_actual = ' . $this->db->quote($anteriorHash))
                    ->order('id DESC')
                    ->setLimit(1)
            );
            $encontrado = $this->db->loadResult();
            $anteriorId = $encontrado !== null ? (int) $encontrado : null;
        }

        $fila = (object) [
            'order_id' => $orderId,
            'invoice_number' => $invoiceNumber,
            'invoice_date' => $invoice->getInvoiceId()->issueDate,
            'nif_emisor' => $invoice->getInvoiceId()->issuerNif,
            // La columna es DECIMAL NOT NULL sin valor por defecto: una anulación
            // no tiene importe, y una factura rechazada antes de calcularlo
            // (ej. aviso legal sin aceptar) todavía no lo tiene. Con NULL el
            // INSERT fallaba en modo estricto y no quedaba ningún registro.
            'total_factura' => $esFactura ? ($invoice->totalAmount ?? 0) : 0,
            'tipo_abono' => $tipoAbono !== '' ? $tipoAbono : null,
            'tipo_factura' => $esFactura ? ($invoice->invoiceType?->value ?? null) : null,
            'tipo_rectificativa' => $esFactura ? ($invoice->rectificationType?->value ?? null) : null,
            'factura_rectificada' => $facturaRectificada->order_invoice_number ?? null,
            'registro_anterior_id' => $anteriorId,
            'tipo_registro' => $tipoRegistro,
            'motivo_anulacion' => $motivoAnulacion,
            // v0.34.30: desglose de esta factura, para que una rectificación
            // por diferencias posterior sobre este mismo pedido pueda
            // leerlo -- ver obtenerFacturaParaRectificarPorDiferencias().
            'base_imponible' => $baseImponible,
            'tipo_iva' => $tipoIva,
            'cuota_iva' => $cuotaIva,
            'tipo_recargo' => $tipoRecargo,
            'recargo_equivalencia' => $recargoEquivalencia,
            'clave_regimen' => $claveRegimen,
            'operacion_exenta' => $operacionExenta,
            'hash_anterior' => (!empty($anteriorHash)) ? $anteriorHash : null,
            'hash_actual' => $invoice->hash ?? '',
            'qr_url' => $qrImage,
            'xml_enviado' => $xmlEnviado,
            'estado_envio' => $estado,
            'respuesta_aeat' => json_encode($response),
            'created' => date('Y-m-d H:i:s'),
            'modified' => date('Y-m-d H:i:s'),
        ];

        // Ya no controla si se actualiza o se inserta (ver comentario de la
        // función): es puramente informativo, "cuántos intentos van ya para
        // este pedido+factura+tipo de registro". Un fallo al contarlos no
        // debe impedir guardar el registro en sí.
        try {
            $this->db->setQuery(
                $this->db->getQuery(true)
                    ->select('COUNT(*)')
                    ->from('#__hikashop_verifactu_registro')
                    ->where('order_id = ' . $orderId)
                    ->where('invoice_number = ' . $this->db->quote($invoiceNumber))
                    ->where('tipo_registro = ' . $this->db->quote($tipoRegistro))
            );
            $fila->intentos = 1 + (int) $this->db->loadResult();
        } catch (\Throwable $e) {
            $fila->intentos = 1;
        }

        try {
            $ok = $this->db->insertObject('#__hikashop_verifactu_registro', $fila);
            if (!$ok) {
                $error = method_exists($this->db, 'getErrorMsg') ? $this->db->getErrorMsg() : 'error SQL no disponible';
                throw new \RuntimeException('No se pudo insertar el registro VeriFactu: ' . $error);
            }
        } catch (\Throwable $e) {
            VerifactuLog::add('ERROR guardando registro VeriFactu pedido ' . $orderId . ': ' . $e->getMessage(), \Joomla\CMS\Log\Log::ERROR);
            throw $e;
        }
    }
}
