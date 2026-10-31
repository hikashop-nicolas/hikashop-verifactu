<?php
/**
 * Fin de año y los "Número inicial manual" (v0.34.92).
 *
 * El plugin lleva un contador por serie y año; los campos "Número inicial manual"
 * (F1, F2, F3 y R) los usa para cualquier serie y año que todavía no tenga contador,
 * así que en enero influyen en la primera factura de cada serie del año nuevo.
 *
 * Esta clase:
 *  - ejecuta las dos acciones de los botones de la configuración (tareas finanio_*):
 *      · vaciar:    deja vacíos los cuatro campos (el año nuevo empieza en 0001);
 *      · continuar: deja en cada campo el último número del año en curso, sin sumar 1,
 *                   leído de la tabla de contadores;
 *  - guarda, por año, si ya se envió el aviso por email y si el propietario ya hizo
 *    una de las dos acciones (tabla #__hikashop_verifactu_aviso_fin_anio), para no
 *    avisar de nuevo ni mostrar el mensaje del panel de inicio.
 *
 * Las acciones solo las puede ejecutar un superadministrador.
 */
defined('_JEXEC') or die;

use Joomla\CMS\Factory;
use Joomla\CMS\Session\Session;

final class VerifactuFinAnio
{
    /** Serie => parámetro del plugin ("Número inicial manual"). */
    public const SEMILLAS = [
        'F1' => 'semilla_f1',
        'F2' => 'semilla_f2',
        'F3' => 'semilla_f3',
        'R' => 'semilla_r',
    ];

    /** Desde este día de diciembre (incluido) se avisa por email y en el panel de inicio. */
    public const DIA_INICIO_AVISO = 20;

    /**
     * ¿Estamos en el periodo de aviso de fin de año (del 20 al 31 de diciembre)?
     */
    public static function ventanaActiva(?\DateTimeInterface $ahora = null): bool
    {
        $ahora = $ahora ?? new \DateTimeImmutable('now');

        return (int) $ahora->format('n') === 12 && (int) $ahora->format('j') >= self::DIA_INICIO_AVISO;
    }

    /**
     * Último número usado de cada serie en el año indicado ("26" para 2026), leído de la tabla
     * de contadores. Las series sin contador de ese año no aparecen en el resultado.
     *
     * @return array<string,int>
     */
    public static function ultimosDelAnio($db, string $anioCorto): array
    {
        $db->setQuery(
            $db->getQuery(true)
                ->select([$db->quoteName('familia'), $db->quoteName('ultimo_numero')])
                ->from($db->quoteName('#__hikashop_verifactu_contador'))
                ->where($db->quoteName('anio') . ' = ' . $db->quote($anioCorto))
        );

        $salida = [];
        foreach ($db->loadObjectList() ?: [] as $fila) {
            $familia = strtoupper((string) $fila->familia);
            if (isset(self::SEMILLAS[$familia])) {
                $salida[$familia] = (int) $fila->ultimo_numero;
            }
        }

        return $salida;
    }

    /**
     * Estado del año ('2026'): ['email' => bool, 'gestionado' => bool]. Si la tabla no existe
     * todavía (actualización a medias), se devuelve "ya gestionado" para no avisar a ciegas.
     */
    public static function estado($db, string $anio): array
    {
        try {
            $db->setQuery(
                $db->getQuery(true)
                    ->select([$db->quoteName('email_enviado'), $db->quoteName('gestionado')])
                    ->from($db->quoteName('#__hikashop_verifactu_aviso_fin_anio'))
                    ->where($db->quoteName('anio') . ' = ' . $db->quote($anio))
            );
            $fila = $db->loadObject();
        } catch (\Throwable $e) {
            return ['email' => true, 'gestionado' => true];
        }

        return [
            'email' => $fila && !empty($fila->email_enviado),
            'gestionado' => $fila && !empty($fila->gestionado),
        ];
    }

    /**
     * Reserva el envío del email del año: devuelve true solo a la primera petición que lo
     * reserva (INSERT IGNORE + filas afectadas), para que dos peticiones simultáneas no envíen
     * dos correos.
     */
    public static function reservarEmail($db, string $anio): bool
    {
        try {
            $ahora = $db->quote(date('Y-m-d H:i:s'));
            $db->setQuery(
                'INSERT IGNORE INTO ' . $db->quoteName('#__hikashop_verifactu_aviso_fin_anio')
                . ' (' . $db->quoteName('anio') . ', ' . $db->quoteName('email_enviado') . ') VALUES ('
                . $db->quote($anio) . ', ' . $ahora . ')'
            );
            $db->execute();
            if ($db->getAffectedRows() > 0) {
                return true;
            }

            // la fila existe (p. ej. creada al pulsar un botón antes del aviso) pero sin email
            $db->setQuery(
                'UPDATE ' . $db->quoteName('#__hikashop_verifactu_aviso_fin_anio')
                . ' SET ' . $db->quoteName('email_enviado') . ' = ' . $ahora
                . ' WHERE ' . $db->quoteName('anio') . ' = ' . $db->quote($anio)
                . ' AND ' . $db->quoteName('email_enviado') . ' IS NULL'
            );
            $db->execute();

            return $db->getAffectedRows() > 0;
        } catch (\Throwable $e) {
            return false;
        }
    }

    /** Libera la reserva si el correo no se pudo enviar (se reintentará otro día). */
    public static function liberarEmail($db, string $anio): void
    {
        try {
            $db->setQuery(
                'UPDATE ' . $db->quoteName('#__hikashop_verifactu_aviso_fin_anio')
                . ' SET ' . $db->quoteName('email_enviado') . ' = NULL'
                . ' WHERE ' . $db->quoteName('anio') . ' = ' . $db->quote($anio)
            );
            $db->execute();
        } catch (\Throwable $e) {
            // sin consecuencias: como mucho se reintenta el envío
        }
    }

    private static function marcarGestionado($db, string $anio, string $accion): void
    {
        $ahora = $db->quote(date('Y-m-d H:i:s'));
        $db->setQuery(
            'INSERT INTO ' . $db->quoteName('#__hikashop_verifactu_aviso_fin_anio')
            . ' (' . $db->quoteName('anio') . ', ' . $db->quoteName('gestionado') . ', ' . $db->quoteName('accion') . ') VALUES ('
            . $db->quote($anio) . ', ' . $ahora . ', ' . $db->quote($accion) . ')'
            . ' ON DUPLICATE KEY UPDATE ' . $db->quoteName('gestionado') . ' = VALUES(' . $db->quoteName('gestionado') . '), '
            . $db->quoteName('accion') . ' = VALUES(' . $db->quoteName('accion') . ')'
        );
        $db->execute();
    }

    /**
     * Punto de entrada desde onAjaxVerifactu(): tareas finanio_vaciar y finanio_continuar
     * (POST con el token del formulario). Devuelve JSON.
     */
    public static function dispatch(string $task): string
    {
        $app = Factory::getApplication();
        $user = method_exists($app, 'getIdentity') ? $app->getIdentity() : Factory::getUser();

        if (!$user || $user->guest || !$user->authorise('core.admin')) {
            return self::json(['ok' => false, 'mensaje' => 'Solo un superadministrador puede hacer esto.']);
        }
        if (!Session::checkToken('post')) {
            return self::json(['ok' => false, 'mensaje' => 'El token de seguridad no es válido. Recarga la configuración del plugin e inténtalo otra vez.']);
        }

        $modo = ($task === 'finanio_continuar') ? 'continuar' : (($task === 'finanio_vaciar') ? 'vaciar' : '');
        if ($modo === '') {
            return self::json(['ok' => false, 'mensaje' => 'Acción desconocida.']);
        }

        try {
            return self::json(self::ejecutar($modo));
        } catch (\Throwable $e) {
            return self::json(['ok' => false, 'mensaje' => 'No se pudo guardar: ' . $e->getMessage()]);
        }
    }

    /**
     * @return array{ok:bool,mensaje:string,valores?:array<string,string>}
     */
    private static function ejecutar(string $modo): array
    {
        $db = Factory::getContainer()->get('DatabaseDriver');

        $db->setQuery(
            $db->getQuery(true)
                ->select([$db->quoteName('extension_id'), $db->quoteName('params')])
                ->from($db->quoteName('#__extensions'))
                ->where($db->quoteName('type') . ' = ' . $db->quote('plugin'))
                ->where($db->quoteName('folder') . ' = ' . $db->quote('hikashop'))
                ->where($db->quoteName('element') . ' = ' . $db->quote('verifactu')),
            0,
            1
        );
        $fila = $db->loadObject();
        if (!$fila) {
            throw new \RuntimeException('No se encuentra la configuración del plugin VeriFactu (#__extensions).');
        }
        $params = json_decode((string) $fila->params, true);
        $params = is_array($params) ? $params : [];

        $anio = date('Y');
        $anioCorto = date('y');
        $valores = [];
        $sinContador = [];

        if ($modo === 'vaciar') {
            foreach (self::SEMILLAS as $parametro) {
                $valores[$parametro] = '';
            }
        } else {
            $ultimos = self::ultimosDelAnio($db, $anioCorto);
            foreach (self::SEMILLAS as $familia => $parametro) {
                if (isset($ultimos[$familia])) {
                    $valores[$parametro] = str_pad((string) $ultimos[$familia], 4, '0', STR_PAD_LEFT);
                } else {
                    // sin facturas de esta serie este año: no hay nada que continuar, así que
                    // el campo se vacía y la serie del año nuevo empezará en 0001
                    $valores[$parametro] = '';
                    $sinContador[] = $familia;
                }
            }
        }

        foreach ($valores as $parametro => $valor) {
            $params[$parametro] = $valor;
        }
        $json = json_encode($params, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        $db->setQuery(
            $db->getQuery(true)
                ->update($db->quoteName('#__extensions'))
                ->set($db->quoteName('params') . ' = ' . $db->quote($json))
                ->where($db->quoteName('extension_id') . ' = ' . (int) $fila->extension_id)
        )->execute();

        try {
            $factory = Factory::getContainer()->get(\Joomla\CMS\Cache\CacheControllerFactoryInterface::class);
            foreach (['com_plugins', '_system'] as $grupo) {
                $factory->createCacheController('callback', ['defaultgroup' => $grupo])->clean();
            }
        } catch (\Throwable $e) {
            // la caché se renueva sola; si no, se puede vaciar desde Sistema > Borrar caché
        }

        $enPeriodo = self::ventanaActiva();
        if ($enPeriodo) {
            self::marcarGestionado($db, $anio, $modo);
        }

        if ($modo === 'vaciar') {
            $mensaje = 'Hecho: los cuatro «Número inicial manual» se han vaciado. Las series de ' . ((int) $anio + 1) . ' empezarán en 0001.';
        } else {
            $mensaje = 'Hecho: cada «Número inicial manual» tiene ahora el último número de ' . $anio . ' de su serie (sin sumar 1).';
            if ($sinContador) {
                $mensaje .= ' Las series ' . implode(', ', $sinContador) . ' no han emitido facturas en ' . $anio . ' y se han dejado vacías.';
            }
        }
        if (!$enPeriodo) {
            $mensaje .= ' Aviso: esto se ha hecho fuera de los últimos días de diciembre; si todavía vas a emitir facturas de ' . $anio
                . ', vuelve a pulsar el botón después de la última.';
        }

        return ['ok' => true, 'mensaje' => $mensaje, 'valores' => $valores];
    }

    private static function json(array $datos): string
    {
        return json_encode($datos, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
}
