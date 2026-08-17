<?php
defined('_JEXEC') or die;

require_once __DIR__ . '/../vendor/autoload.php'; // vendor/ generado por Composer dentro de la propia carpeta del plugin

use eseperio\verifactu\Verifactu;
use eseperio\verifactu\models\InvoiceSubmission;
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
     * Sin tipo estricto a propósito: según la versión de Joomla, JFactory::getDbo()
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
            'Cancelación'         => ['invoiceType' => InvoiceType::RECTIFICATION_1, 'rectificationType' => RectificationType::INCREMENTAL],
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

    private function ensureSchema(): void
    {
        $table = '#__hikashop_verifactu_registro';
        $columns = [
            'tipo_abono' => "ALTER TABLE `{$table}` ADD COLUMN `tipo_abono` VARCHAR(80) NULL AFTER `total_factura`",
            'tipo_factura' => "ALTER TABLE `{$table}` ADD COLUMN `tipo_factura` VARCHAR(2) NULL AFTER `tipo_abono`",
            'tipo_rectificativa' => "ALTER TABLE `{$table}` ADD COLUMN `tipo_rectificativa` VARCHAR(1) NULL AFTER `tipo_factura`",
            'factura_rectificada' => "ALTER TABLE `{$table}` ADD COLUMN `factura_rectificada` VARCHAR(60) NULL AFTER `tipo_rectificativa`",
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
                    @file_put_contents(
                        __DIR__ . '/../debug.log',
                        '[' . date('Y-m-d H:i:s') . '] Error creando columna ' . $column . ': ' . $e->getMessage() . "\n",
                        FILE_APPEND
                    );
                }
            }
        }
    }

    /**
     * Busca la factura HikaShop que probablemente se rectifica.
     * El patrón habitual de este proyecto es que el abono sea la factura
     * inmediatamente posterior a la original (número + 1). No se fuerza la
     * relación si no puede determinarse con seguridad.
     */
    private function obtenerFacturaRectificada($order, string $invoiceNumber): ?object
    {
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
            ->select('order_id, order_invoice_number, order_full_price, order_created')
            ->from('#__hikashop_order')
            ->where('order_invoice_number = ' . $this->db->quote($candidato))
            ->where('order_id <> ' . (int) $order->order_id)
            ->setLimit(1);

        $this->db->setQuery($query);
        $original = $this->db->loadObject();

        if (!$original || (float) $original->order_full_price <= 0) {
            return null;
        }

        return $original;
    }

    /**
     * Registra una factura de HikaShop en la AEAT usando la librería,
     * y guarda el resultado (incluido el hash devuelto por la propia librería)
     * en la tabla de encadenamiento local para la siguiente factura.
     */
    public function registrarFactura($order): array
    {
        // Idempotencia: si este pedido ya tiene un registro aceptado por la AEAT,
        // no se vuelve a enviar (ej. si el pedido se guarda de nuevo estando ya
        // en el estado invoice_generated, o se dispara el evento más de una vez).
        if ($this->yaRegistradoYAceptado($order->order_id)) {
            return ['estado' => 'ya_registrado', 'csv' => null, 'errores' => [], 'qr' => null];
        }

        $this->ensureSchema();

        // Determinar si el pedido es factura normal o un abono/rectificativa.
        // Una factura negativa sin motivo seleccionado se bloquea para evitar
        // enviarla accidentalmente como F1/F2.
        $totalPedido = (float) ($order->order_full_price ?? 0);
        $tipoAbono = $this->getTipoAbono($order);
        $esNegativa = $totalPedido < 0;

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
        $nifCliente = $limpiarNif($billing->address_vat ?? '');
        $tieneNifCliente = !empty($billing) && !empty($nifCliente);

        if ($esNegativa && !$tieneNifCliente) {
            return [
                'estado' => 'error_destinatario_rectificativa',
                'csv' => null,
                'errores' => ['Las facturas rectificativas R1-R4 requieren identificar al destinatario. El pedido no tiene NIF/CIF/NIE en la dirección de facturación.'],
                'qr' => null,
            ];
        }

        $invoice = new InvoiceSubmission();

        $invoiceId = new InvoiceId();
        $invoiceId->issuerNif = $nifEmisor;
        $invoiceId->seriesNumber = $order->order_invoice_number ?? $order->order_number;
        // NOTA: va en formato Y-m-d porque la propia validación de la librería
        // lo exige así ("The issue date must be in YYYY-MM-DD format"). El
        // desajuste real de la huella (ver más abajo) se corrige parcheando
        // HashGeneratorService.php de la librería para que formatee la fecha
        // igual que el serializador XML antes de calcular el hash.
        $invoiceId->issueDate = date('Y-m-d');
        $invoice->setInvoiceId($invoiceId);

        $invoice->issuerName = $this->params->get('nombre_emisor');

        if ($esNegativa) {
            // Factura rectificativa por diferencias (I), según el motivo elegido.
            $invoice->invoiceType = $clasificacion['invoiceType'];
            $invoice->rectificationType = $clasificacion['rectificationType'];
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
                // El propio HikaShop guarda la base imponible real en ->amount
                // (confirmado contra un pedido real: 165 × 0.21 = 34.65 y
                // 165 × 0.052 = 8.58) -- se usa directamente en vez de
                // recalcularla, evitando errores de redondeo.
                $baseReal = (float) ($tax->amount ?? 0);

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
                @file_put_contents(
                    __DIR__ . '/../debug.log',
                    '[' . date('Y-m-d H:i:s') . '] Recargo de equivalencia sin IVA emparejado en pedido ' . ($order->order_id ?? '?') . ': ' . json_encode($lineasRecargo) . "\n",
                    FILE_APPEND
                );
            }
        }

        if ($breakdown->getDetails() === []) {
            // Fallback si el pedido no trae order_tax_info desglosado o no se
            // pudo resolver ningún tipo real (revisar caso a caso; esto NO
            // debería pasar en un pedido normal con impuestos configurados).
            $detail = new BreakdownDetail();
            $detail->taxType = TaxType::IVA;
            $detail->regimeKey = RegimeType::GENERAL;
            $detail->taxRate = 21.00;
            $detail->taxableBase = (float) $order->order_full_price;
            $detail->taxAmount = 0.0;
            $detail->operationQualification = OperationQualificationType::SUBJECT_NO_EXEMPT_NO_REVERSE;
            $breakdown->addDetail($detail);
        }

        $invoice->setBreakdown($breakdown);
        $invoice->taxAmount = $totalTax;

        // Encadenamiento: si ya hay una factura previa registrada, se referencia;
        // si es la primera del NIF, se marca como firstRecord.
        $chaining = new Chaining();
        $anterior = $this->obtenerUltimaFacturaRegistrada($nifEmisor);
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

        $computerSystem = new ComputerSystem();
        $computerSystem->systemName = 'HikaShop';
        $computerSystem->version = '1.0';
        $computerSystem->providerName = $this->params->get('nombre_desarrollador') ?: $this->params->get('nombre_emisor');
        $computerSystem->systemId = '01';
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
        $invoice->setSystemInfo($computerSystem);

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
            $esPersonaFisica = (bool) preg_match('/^([0-9]{8}[A-Za-z]|[XYZ][0-9]{7}[A-Za-z])$/', $nifCliente);

            if ($esPersonaFisica) {
                $nombreDestinatario = $nombrePersona ?: $nombreEmpresa;
            } else {
                $nombreDestinatario = $nombreEmpresa ?: $nombrePersona;
            }

            $recipient = new LegalPerson();
            $recipient->name = $nombreDestinatario;

            // Identificación del destinatario según su país:
            // - ES: NIF español en <NIF>.
            // - Extranjero: <IDOtro> con país ISO, IDType 02 e identificador sin prefijo ISO.
            $codigoPaisCliente = $this->obtenerCodigoPaisFactura($billing);

            if ($codigoPaisCliente === 'ES') {
                $recipient->nif = $nifCliente;
            } elseif ($codigoPaisCliente !== '') {
                $otherId = new OtherID();
                $otherId->countryCode = $codigoPaisCliente;
                $otherId->idType = '02';

                // Para IDType 02 (NIF-IVA), conservar el identificador
                // intracomunitario completo tal como lo proporciona HikaShop
                // (por ejemplo, PT503890278). CodigoPais se informa además
                // en su campo propio. La AEAT ha rechazado 503890278 con
                // error 1103 en este caso concreto.
                $idExtranjero = strtoupper(trim((string) $nifCliente));
                if ($idExtranjero === '') {
                    return [
                        'estado' => 'error_id_destinatario',
                        'csv' => null,
                        'errores' => ['El destinatario extranjero no tiene identificación fiscal.'],
                        'qr' => null,
                    ];
                }

                $otherId->id = $idExtranjero;
                $recipient->setOtherId($otherId);
            } else {
                // No asumir España si el país no se puede determinar.
                return [
                    'estado' => 'error_pais_destinatario',
                    'csv' => null,
                    'errores' => ['No se ha podido determinar el país del destinatario; no se envía su identificación como NIF español.'],
                    'qr' => null,
                ];
            }

            $invoice->addRecipient($recipient);
        } else {
            // Factura simplificada (F2): no exige destinatario identificado.
            $invoice->invoiceWithoutRecipient = YesNoType::YES;
        }

        // No se busca ni se fuerza una relación con otra factura de HikaShop.
        // El motivo y el comentario del abono se toman del propio pedido.
        $facturaRectificada = null;

        // validate() de la librería SIEMPRE devuelve un array; vacío significa
        // que la validación pasó sin errores (nunca devuelve un booleano true).
        $validationResult = $invoice->validate();
        if (!empty($validationResult)) {
            return [
                'estado' => 'error_validacion',
                'detalle' => $validationResult,
            ];
        }

        $response = Verifactu::registerInvoice($invoice);

        // La AEAT puede responder 3000 cuando el mismo registro ya existe.
        // Si el registro duplicado original figura como "Correcta", la factura
        // ya está registrada y no debe tratarse como un rechazo real.
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
            $estado = 'aceptado';
        } elseif ($response->submissionStatus === InvoiceResponse::STATUS_OK) {
            $estado = 'aceptado';
        } elseif (!empty($response->csv)) {
            $estado = 'aceptado_con_errores';
        } else {
            $estado = 'rechazado';
        }

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
            $facturaRectificada
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
    }

    /**
     * Obtiene el código ISO 3166-1 alpha-2 del país de la dirección de facturación.
     * HikaShop puede exponerlo directamente o como ID/nombre en address_country.
     */
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

    private function yaRegistradoYAceptado(int $orderId): bool
    {
        $query = $this->db->getQuery(true)
            ->select('id')
            ->from('#__hikashop_verifactu_registro')
            ->where('order_id = ' . (int) $orderId)
            ->where('estado_envio IN (' . $this->db->quote('aceptado') . ',' . $this->db->quote('aceptado_con_errores') . ')')
            ->setLimit(1);

        $this->db->setQuery($query);
        return (bool) $this->db->loadResult();
    }

    private function obtenerUltimaFacturaRegistrada(string $nifEmisor): ?object
    {
        $query = $this->db->getQuery(true)
            ->select('invoice_number, invoice_date, hash_actual')
            ->from('#__hikashop_verifactu_registro')
            ->where('nif_emisor = ' . $this->db->quote($nifEmisor))
            ->where('estado_envio IN (' . $this->db->quote('aceptado') . ',' . $this->db->quote('aceptado_con_errores') . ')')
            ->order('id DESC')
            ->setLimit(1);

        $this->db->setQuery($query);
        return $this->db->loadObject();
    }

    private function logQr(string $mensaje): void
    {
        @file_put_contents(
            __DIR__ . '/../debug.log',
            '[' . date('Y-m-d H:i:s') . '] QR: ' . $mensaje . "\n",
            FILE_APPEND
        );
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

    private function guardarRegistro($order, InvoiceSubmission $invoice, $response, string $estado, ?string $qrImage, string $tipoAbono = '', ?object $facturaRectificada = null): void
    {
        $fila = (object) [
            'order_id' => $order->order_id,
            'invoice_number' => $invoice->getInvoiceId()->seriesNumber,
            'invoice_date' => $invoice->getInvoiceId()->issueDate,
            'nif_emisor' => $invoice->getInvoiceId()->issuerNif,
            'total_factura' => $invoice->totalAmount,
            'tipo_abono' => $tipoAbono !== '' ? $tipoAbono : null,
            'tipo_factura' => $invoice->invoiceType?->value ?? null,
            'tipo_rectificativa' => $invoice->rectificationType?->value ?? null,
            'factura_rectificada' => $facturaRectificada->order_invoice_number ?? null,
            'hash_actual' => $invoice->hash ?? '',
            'qr_url' => $qrImage,
            'estado_envio' => $estado,
            'respuesta_aeat' => json_encode($response),
            'intentos' => 1,
            'created' => date('Y-m-d H:i:s'),
            'modified' => date('Y-m-d H:i:s'),
        ];

        try {
            $ok = $this->db->insertObject('#__hikashop_verifactu_registro', $fila);
            if (!$ok) {
                $error = method_exists($this->db, 'getErrorMsg') ? $this->db->getErrorMsg() : 'error SQL no disponible';
                throw new \RuntimeException('No se pudo insertar el registro VeriFactu: ' . $error);
            }
        } catch (\Throwable $e) {
            @file_put_contents(
                __DIR__ . '/../debug.log',
                '[' . date('Y-m-d H:i:s') . '] ERROR INSERT VeriFactu pedido ' . ($order->order_id ?? '?') . ': ' . $e->getMessage() . "\n",
                FILE_APPEND
            );
            throw $e;
        }
    }
}
