<?php
/**
 * Copia de seguridad y restauración del registro VeriFactu (v0.34.88).
 *
 * Exporta las tablas del plugin y los ajustes de numeración a un único fichero
 * (.json.gz) y los restaura en otra web. Se usa desde los botones de la configuración
 * del plugin (campo copiaregistro) a través de onAjaxVerifactu(), tareas backup_*.
 * Solo superadministradores.
 */
defined('_JEXEC') or die;

use Joomla\CMS\Factory;
use Joomla\CMS\Session\Session;
use Joomla\CMS\Uri\Uri;

final class VerifactuBackup
{
    private const PREFIJO = '#__hikashop_verifactu_';
    // Orden de restauración (ninguna tabla depende de otra por clave foránea).
    private const TABLAS = ['declaracion', 'contador', 'registro', 'numeracion_asignada'];
    private const FORMATO = 'verifactu-backup-1';
    private const MAPA = '#__hikashop_hkimport_order';
    private const SESION = 'com_vfb_archivo';
    private const SESION_REMAP = 'com_vfb_remap';

    // Ajustes de numeración del plugin VeriFactu que se guardan en la copia y se pueden
    // volver a poner en la web nueva (clave del parámetro => etiqueta del plugin).
    private const AJUSTES = [
        'prefijo_f1' => 'Prefijo de la serie F1 (ordinarias)',
        'prefijo_f2' => 'Prefijo de la serie F2 (simplificadas)',
        'prefijo_r' => 'Prefijo de la serie R (rectificativas)',
        'prefijo_f3' => 'Prefijo de la serie F3 (sustituye simplificada)',
        'semilla_f1' => 'Número inicial manual -- serie F1',
        'semilla_f2' => 'Número inicial manual -- serie F2',
        'semilla_f3' => 'Número inicial manual -- serie F3',
        'semilla_r' => 'Número inicial manual -- serie R',
    ];
    // Solo informativo: se guarda y se muestra, pero esta herramienta no lo cambia.
    private const AJUSTE_ACTIVA = 'numeracion_propia_activa';

    /**
     * Punto de entrada desde onAjaxVerifactu(): tareas backup_pantalla, backup_export,
     * backup_check, backup_restore, backup_ajustes y backup_cancel. Devuelve una página
     * HTML completa (la descarga de la copia se envía directamente y cierra la aplicación).
     */
    public static function dispatch(string $task): string
    {
        $app = Factory::getApplication();
        $user = method_exists($app, 'getIdentity') ? $app->getIdentity() : Factory::getUser();

        if (!$user || $user->guest || !$user->authorise('core.admin')) {
            return self::pagina(self::alerta('danger', 'Solo un superadministrador puede usar esta herramienta.'));
        }

        $tarea = (strpos($task, 'backup_') === 0) ? substr($task, 7) : $task;
        $nivel = ob_get_level();

        try {
            if ($tarea === 'export') {
                if (!Session::checkToken('get')) {
                    return self::pagina(self::alerta('danger', 'El token de seguridad no es válido. Abre de nuevo la configuración del plugin e inténtalo otra vez.'));
                }
                self::exportar($app);

                return '';
            }

            ob_start();
            echo '<h2>VeriFactu: copia y restauración del registro</h2>';

            if (in_array($tarea, ['check', 'restore', 'cancel', 'ajustes'], true)) {
                if (!Session::checkToken('post')) {
                    echo self::alerta('danger', 'El token de seguridad no es válido. Vuelve a intentarlo.');
                    self::pantalla();
                } elseif ($tarea === 'check') {
                    self::comprobar($app);
                } elseif ($tarea === 'restore') {
                    self::restaurar($app);
                } elseif ($tarea === 'ajustes') {
                    self::aplicarAjustes($app);
                } else {
                    self::borrarArchivoSesion($app);
                    echo self::alerta('info', 'Se ha borrado el fichero subido. No se ha cambiado nada.');
                    self::pantalla();
                }
            } else {
                self::pantalla();
            }

            return self::pagina((string) ob_get_clean());
        } catch (\Throwable $e) {
            $parcial = '';
            while (ob_get_level() > $nivel) {
                $parcial = ob_get_clean() . $parcial;
            }

            return self::pagina($parcial . self::alerta('danger', '<strong>Error:</strong> ' . self::h($e->getMessage())));
        }
    }

    private static function input()
    {
        $app = Factory::getApplication();

        return method_exists($app, 'getInput') ? $app->getInput() : $app->input;
    }

    private static function token(): string
    {
        return '<input type="hidden" name="' . self::h(Session::getFormToken()) . '" value="1">';
    }

    /**
     * Página HTML completa y autónoma (com_ajax con format=raw no pasa por la plantilla de Joomla).
     */
    private static function pagina(string $cuerpo): string
    {
        $css = <<<'CSS'
*{box-sizing:border-box}
body{margin:0;padding:18px 22px;font:14px/1.5 -apple-system,"Segoe UI",Roboto,Arial,sans-serif;color:#212529;background:#f8f9fa}
h2{margin:0 0 14px;font-size:20px}
h3{margin:18px 0 8px;font-size:17px}
h4{margin:14px 0 6px;font-size:15px}
.row{display:flex;flex-wrap:wrap;gap:16px}
.col-lg-6{flex:1 1 380px}
.col-12{flex:1 1 100%}
.card{background:#fff;border:1px solid #dee2e6;border-radius:6px;height:100%}
.card-body{padding:16px}
.card-title{margin:0 0 10px;font-size:16px}
.alert{padding:10px 14px;border-radius:5px;border:1px solid;margin:10px 0}
.alert-danger{background:#f8d7da;border-color:#f1aeb5;color:#58151c}
.alert-warning{background:#fff3cd;border-color:#ffe69c;color:#664d03}
.alert-info{background:#cff4fc;border-color:#9eeaf9;color:#055160}
.alert-success{background:#d1e7dd;border-color:#a3cfbb;color:#0a3622}
.alert-secondary{background:#e2e3e5;border-color:#c4c8cb;color:#2b2f32}
.btn{display:inline-block;padding:7px 14px;border:1px solid transparent;border-radius:5px;font-size:14px;cursor:pointer;text-decoration:none;color:#fff}
.btn-primary{background:#0d6efd;border-color:#0d6efd}
.btn-secondary{background:#6c757d;border-color:#6c757d}
.btn-danger{background:#dc3545;border-color:#dc3545}
table{border-collapse:collapse;width:100%;background:#fff;margin:8px 0}
th,td{border:1px solid #dee2e6;padding:6px 9px;text-align:left;vertical-align:middle}
th{background:#f1f3f5}
.table-warning td{background:#fff3cd}
.form-check{margin:0 0 12px}
.form-check input{margin-right:6px}
.form-control{display:block;width:100%;padding:6px 9px;border:1px solid #ced4da;border-radius:5px;background:#fff}
.mb-3{margin-bottom:12px}
.mt-3{margin-top:12px}
.small{font-size:12.5px}
.text-muted{color:#6c757d}
form{margin:0 0 10px}
CSS;

        return '<!DOCTYPE html><html lang="es"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">'
            . '<title>VeriFactu: copia del registro</title><style>' . $css . '</style></head><body>' . $cuerpo . '</body></html>';
    }

    // ------------------------------------------------------------------ utilidades

    private static function h($texto): string
    {
        return htmlspecialchars((string) $texto, ENT_QUOTES, 'UTF-8');
    }

    private static function alerta(string $tipo, string $html): string
    {
        return '<div class="alert alert-' . $tipo . '" role="alert">' . $html . '</div>';
    }

    private static function db()
    {
        return Factory::getContainer()->get('DatabaseDriver');
    }

    /**
     * URL de una tarea de copia (com_ajax del plugin). Conserva modo=restaurar si la pantalla
     * está abierta dentro de la ventana emergente de la configuración del plugin.
     */
    private static function enlace(string $task): string
    {
        $url = 'index.php?option=com_ajax&amp;group=hikashop&amp;plugin=verifactu&amp;format=raw&amp;task=backup_' . $task;
        if (self::input()->getCmd('modo', '') === 'restaurar') {
            $url .= '&amp;modo=restaurar';
        }

        return $url;
    }

    /**
     * Si la pantalla está dentro de la ventana emergente del plugin, avisa a la página de
     * configuración de que se han cambiado datos, para que se recargue al cerrar la ventana
     * (así un "Guardar" posterior no escribe de nuevo valores antiguos del formulario).
     */
    private static function avisarPadre(): void
    {
        echo '<script>try{if(window.parent&&window.parent!==window){window.parent.postMessage("vfb-restaurado",window.location.origin);}}catch(e){}</script>';
    }

    private static function tablaCambios(array $cambios): string
    {
        $html = '<table class="table table-sm table-bordered"><thead><tr><th>Campo</th><th>Antes</th><th>Ahora</th></tr></thead><tbody>';
        foreach ($cambios as $c) {
            $html .= '<tr><td>' . self::h($c[0]) . '</td><td>' . self::verValor($c[1]) . '</td><td>' . self::verValor($c[2]) . '</td></tr>';
        }

        return $html . '</tbody></table>';
    }

    private static function existeTabla($db, string $nombre): bool
    {
        $real = strtolower($db->replacePrefix($nombre));
        $lista = array_map('strtolower', $db->getTableList());

        return in_array($real, $lista, true);
    }

    private static function contar($db, string $nombre): int
    {
        $db->setQuery('SELECT COUNT(*) FROM ' . $db->quoteName($nombre));

        return (int) $db->loadResult();
    }

    private static function tmpDir(): string
    {
        $dir = (string) Factory::getApplication()->get('tmp_path', '');
        if ($dir === '' || !is_dir($dir)) {
            $dir = sys_get_temp_dir();
        }

        return rtrim($dir, '/\\');
    }

    // ------------------------------------------------------------------ ajustes de numeración

    /**
     * Fila del plugin VeriFactu en #__extensions: ['id' => int, 'params' => array] o null.
     */
    private static function leerAjustesPlugin($db): ?array
    {
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
            return null;
        }

        $params = json_decode((string) $fila->params, true);

        return ['id' => (int) $fila->extension_id, 'params' => is_array($params) ? $params : []];
    }

    /**
     * Valores actuales de los ajustes de numeración (solo los que están guardados en el plugin).
     */
    private static function ajustesParaCopia($db): array
    {
        $plugin = self::leerAjustesPlugin($db);
        if (!$plugin) {
            return [];
        }

        $salida = [];
        foreach (array_merge(array_keys(self::AJUSTES), [self::AJUSTE_ACTIVA]) as $clave) {
            if (array_key_exists($clave, $plugin['params'])) {
                $salida[$clave] = (string) $plugin['params'][$clave];
            }
        }

        return $salida;
    }

    private static function verValor(?string $valor): string
    {
        if ($valor === null) {
            return '<em>(por defecto)</em>';
        }

        return $valor === '' ? '<em>(vacío)</em>' : self::h($valor);
    }

    /**
     * Devuelve null si el valor es válido para ese ajuste, o el motivo si no lo es.
     */
    private static function validarAjuste(string $clave, string $valor): ?string
    {
        if (strpos($clave, 'prefijo_') === 0) {
            return preg_match('/^[A-Za-z0-9_\-\.\/]{0,20}$/', $valor) ? null : 'solo letras, números y - _ . / (máximo 20)';
        }

        return preg_match('/^[0-9]{0,9}$/', $valor) ? null : 'debe ser solo un número, o estar vacío';
    }

    private static function limpiarCachePlugins(): void
    {
        try {
            $factory = Factory::getContainer()->get(\Joomla\CMS\Cache\CacheControllerFactoryInterface::class);
            foreach (['com_plugins', '_system'] as $grupo) {
                $factory->createCacheController('callback', ['defaultgroup' => $grupo])->clean();
            }
        } catch (\Throwable $e) {
            // la caché se renueva sola; si no, se puede vaciar desde Sistema > Borrar caché
        }
    }

    /**
     * Ajustes de numeración que se escribirían en el plugin de esta web a partir de la copia:
     *  - Prefijos de serie: los de la copia.
     *  - "Número inicial manual": NO se copian las semillas antiguas del plugin, sino el ÚLTIMO número
     *    usado de cada serie, leído de la tabla de contadores de la copia (el plugin suma 1 al crear
     *    la serie). Solo se rellenan si el contador de la copia es del año en curso: si es de otro año
     *    quedan vacíos (la serie del año nuevo empieza en 0001).
     *  - Con $conContadores = true (se restauran también los contadores) quedan vacíos, porque no
     *    hacen falta y dejarlos rellenos rompería la numeración de un año nuevo.
     *
     * @return array{valores: array, ultimos: array, avisos: array}
     */
    private static function ajustesDeNumeracion(array $data, bool $conContadores): array
    {
        $ajustes = (isset($data['ajustes']) && is_array($data['ajustes'])) ? $data['ajustes'] : [];
        $valores = [];
        $avisos = [];
        $ultimos = [];

        foreach (array_keys(self::AJUSTES) as $clave) {
            if (strpos($clave, 'prefijo_') === 0 && array_key_exists($clave, $ajustes)) {
                $valores[$clave] = (string) $ajustes[$clave];
            }
        }

        $filas = $data['tablas']['contador']['filas'] ?? [];
        foreach (is_array($filas) ? $filas : [] as $fila) {
            $familia = strtoupper((string) ($fila['familia'] ?? ''));
            $anio = (string) ($fila['anio'] ?? '');
            if (!in_array($familia, ['F1', 'F2', 'F3', 'R'], true) || !ctype_digit($anio)) {
                continue;
            }
            if (!isset($ultimos[$familia]) || (int) $anio > (int) $ultimos[$familia]['anio']) {
                $ultimos[$familia] = ['anio' => $anio, 'numero' => (int) ($fila['ultimo_numero'] ?? 0)];
            }
        }

        $anioActual = date('y');
        foreach (['F1', 'F2', 'F3', 'R'] as $familia) {
            $clave = 'semilla_' . strtolower($familia);
            if ($conContadores) {
                $valores[$clave] = '';
                continue;
            }
            if (!isset($ultimos[$familia])) {
                continue; // sin contador en la copia: no se toca el campo
            }
            if ($ultimos[$familia]['anio'] === $anioActual) {
                $valores[$clave] = str_pad((string) $ultimos[$familia]['numero'], 4, '0', STR_PAD_LEFT);
            } else {
                $valores[$clave] = '';
                $avisos[] = 'La serie ' . $familia . ' de la copia llega hasta el año 20' . $ultimos[$familia]['anio']
                    . '; este año es 20' . $anioActual . ', así que su número inicial se deja vacío (la serie empezará en 0001).';
            }
        }

        return ['valores' => $valores, 'ultimos' => $ultimos, 'avisos' => $avisos];
    }

    /**
     * Bloque "Ajustes de numeración" del informe: valores que se aplicarían frente a los actuales y botón.
     */
    private static function bloqueAjustes(array $copia, array $efectivos): void
    {
        echo '<h3 class="mt-4">Ajustes de numeración del plugin</h3>';

        if (!$efectivos['valores']) {
            echo self::alerta('secondary', 'La copia no incluye ajustes ni contadores de numeración que se puedan aplicar (se hizo con una versión anterior de esta herramienta, o el plugin antiguo no los tenía guardados).');
            return;
        }

        $db = self::db();
        $plugin = self::leerAjustesPlugin($db);
        if (!$plugin) {
            echo self::alerta('warning', 'No se encuentra la configuración del plugin VeriFactu en esta web, así que no se pueden aplicar estos ajustes.');
            return;
        }

        foreach ($efectivos['avisos'] as $aviso) {
            echo self::alerta('warning', self::h($aviso));
        }

        echo '<table class="table table-sm table-bordered align-middle"><thead><tr><th>Campo del plugin</th><th>Se aplicaría (de la copia)</th><th>Ahora en esta web</th></tr></thead><tbody>';
        foreach (self::AJUSTES as $clave => $etiqueta) {
            $enCopia = array_key_exists($clave, $efectivos['valores']) ? (string) $efectivos['valores'][$clave] : null;
            $ahora = array_key_exists($clave, $plugin['params']) ? (string) $plugin['params'][$clave] : null;
            $distinto = ($enCopia !== null && $enCopia !== (string) $ahora);
            $nota = '';
            if (strpos($clave, 'semilla_') === 0 && $enCopia !== null && $enCopia !== '') {
                $nota = ' <span class="small text-muted">(último nº usado)</span>';
            }
            echo '<tr' . ($distinto ? ' class="table-warning"' : '') . '><td>' . self::h($etiqueta) . '</td><td>' . self::verValor($enCopia) . $nota . '</td><td>' . self::verValor($ahora) . '</td></tr>';
        }

        if (array_key_exists(self::AJUSTE_ACTIVA, $copia)) {
            $enCopia = ((string) $copia[self::AJUSTE_ACTIVA] === '1') ? 'Sí' : 'No';
            $ahoraRaw = array_key_exists(self::AJUSTE_ACTIVA, $plugin['params']) ? (string) $plugin['params'][self::AJUSTE_ACTIVA] : '0';
            $ahora = ($ahoraRaw === '1') ? 'Sí' : 'No';
            echo '<tr' . ($enCopia !== $ahora ? ' class="table-warning"' : '') . '><td>Numeración propia por tipo de factura (solo informativo)</td><td>' . $enCopia . '</td><td>' . $ahora . '</td></tr>';
        }
        echo '</tbody></table>';

        echo self::alerta(
            'info',
            'Los <strong>prefijos</strong> se copian tal cual. En los <strong>números iniciales manuales</strong> no se copian las semillas antiguas del plugin, sino el '
            . '<strong>último número usado</strong> de cada serie, leído de los contadores de la copia: el plugin suma 1 al crear la serie '
            . '(si aquí pone 0284, la primera factura será la 0285). Solo se rellenan si el contador de la copia es del año en curso. '
            . 'Si restauras también el registro, los contadores van en la copia y estos campos se dejan vacíos. '
            . 'Si los rellenas, <strong>vacíalos</strong> cuando la web ya haya emitido una factura de cada serie y, en cualquier caso, antes del 1 de enero: '
            . 'el plugin los usa para cualquier serie y año que todavía no tenga contador. '
            . 'Esta herramienta no cambia la opción «Numeración propia por tipo de factura»: actívala en el plugin si la web antigua la usaba.'
        );

        echo '<form action="' . self::enlace('ajustes') . '" method="post" class="mb-3">' . self::token()
            . '<button class="btn btn-primary" type="submit" onclick="return confirm(\'Se escribirán en el plugin VeriFactu de esta web los prefijos y los números iniciales (último número usado) de la copia. ¿Continuar?\');">Aplicar ajustes de numeración</button></form>';
    }

    /**
     * Escribe en el plugin VeriFactu los ajustes de numeración de la copia (solo los 8 permitidos y
     * válidos). No limpia la caché: lo hace quien llama cuando ya ha terminado. Lanza excepción si
     * no encuentra el plugin.
     *
     * @return array{cambios: array, avisos: array}
     */
    private static function guardarAjustes($db, array $copia): array
    {
        $plugin = self::leerAjustesPlugin($db);
        if (!$plugin) {
            throw new \RuntimeException('No se encuentra la configuración del plugin VeriFactu en esta web (#__extensions).');
        }

        $cambios = [];
        $avisos = [];
        foreach (self::AJUSTES as $clave => $etiqueta) {
            if (!array_key_exists($clave, $copia)) {
                continue;
            }
            $nuevo = trim((string) $copia[$clave]);
            $motivo = self::validarAjuste($clave, $nuevo);
            if ($motivo !== null) {
                $avisos[] = self::h($etiqueta) . ': valor omitido (' . self::h($motivo) . ').';
                continue;
            }
            $antes = array_key_exists($clave, $plugin['params']) ? (string) $plugin['params'][$clave] : null;
            $plugin['params'][$clave] = $nuevo;
            $cambios[] = [$etiqueta, $antes, $nuevo];
        }

        if ($cambios) {
            $json = json_encode($plugin['params'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
            $db->setQuery(
                $db->getQuery(true)
                    ->update($db->quoteName('#__extensions'))
                    ->set($db->quoteName('params') . ' = ' . $db->quote($json))
                    ->where($db->quoteName('extension_id') . ' = ' . (int) $plugin['id'])
            )->execute();
        }

        return ['cambios' => $cambios, 'avisos' => $avisos];
    }

    private static function aplicarAjustes($app): void
    {
        $session = $app->getSession();
        $ruta = (string) $session->get(self::SESION, '');
        $remap = (bool) $session->get(self::SESION_REMAP, 0);

        if ($ruta === '' || !is_file($ruta) || strpos(basename($ruta), 'vfb_import_') !== 0) {
            echo self::alerta('warning', 'No hay ninguna copia subida. Sube el fichero y pulsa «Comprobar copia».');
            self::pantalla();
            return;
        }

        $data = self::leerCopia($ruta);
        $efectivos = self::ajustesDeNumeracion($data, false);
        $copia = $efectivos['valores'];
        unset($data);

        foreach ($efectivos['avisos'] as $aviso) {
            echo self::alerta('warning', self::h($aviso));
        }

        if (!$copia) {
            echo self::alerta('warning', 'La copia no incluye ajustes de numeración.');
            self::mostrarInforme($ruta, $remap);
            return;
        }

        $res = self::guardarAjustes(self::db(), $copia);

        foreach ($res['avisos'] as $aviso) {
            echo self::alerta('warning', $aviso);
        }

        if (!$res['cambios']) {
            echo self::alerta('warning', 'No hay ajustes válidos que aplicar.');
            self::mostrarInforme($ruta, $remap);
            return;
        }

        self::limpiarCachePlugins();

        echo self::alerta('success', '<strong>Ajustes de numeración aplicados</strong> en el plugin VeriFactu de esta web.');
        echo self::tablaCambios($res['cambios']);
        echo self::alerta('info', 'Abre la configuración del plugin VeriFactu para comprobarlos. Si ves valores antiguos, vacía la caché desde Sistema > Borrar caché.');
        self::avisarPadre();

        self::mostrarInforme($ruta, $remap);
    }

    // ------------------------------------------------------------------ pantalla

    private static function pantalla(): void
    {
        $db = self::db();

        foreach (self::TABLAS as $t) {
            if (!self::existeTabla($db, self::PREFIJO . $t)) {
                echo self::alerta(
                    'warning',
                    'Faltan tablas del plugin VeriFactu en esta web. Reinstala o actualiza el plugin (Extensiones > Gestionar > Instalar) para que se creen y vuelve aquí.'
                );
                return;
            }
        }

        $nRegistros = self::contar($db, self::PREFIJO . 'registro');
        $ultima = '';
        if ($nRegistros > 0) {
            $db->setQuery(
                $db->getQuery(true)
                    ->select([$db->quoteName('invoice_number'), $db->quoteName('invoice_date')])
                    ->from($db->quoteName(self::PREFIJO . 'registro'))
                    ->order($db->quoteName('id') . ' DESC'),
                0,
                1
            );
            $fila = $db->loadObject();
            if ($fila) {
                $ultima = ' (última factura: <strong>' . self::h($fila->invoice_number) . '</strong>, ' . self::h($fila->invoice_date) . ')';
            }
        }

        $hayMapa = self::existeTabla($db, self::MAPA);
        $nMapa = $hayMapa ? self::contar($db, self::MAPA) : 0;
        $tokenUrl = Session::getFormToken();

        $soloRestaurar = (self::input()->getCmd('modo', '') === 'restaurar');

        echo '<div class="row">';

        // ---- Exportar
        if (!$soloRestaurar) {
        echo '<div class="col-lg-6 mb-4"><div class="card h-100"><div class="card-body">';
        echo '<h3 class="card-title">1. Descargar copia (web antigua)</h3>';
        echo '<p>Esta web tiene <strong>' . $nRegistros . '</strong> registros de facturación' . $ultima . '.</p>';
        echo '<p>Descarga un único fichero con todo el registro VeriFactu: registros, numeración, contadores y declaración responsable.</p>';
        $guardados = self::ajustesParaCopia($db);
        echo '<p class="small">También se guardan los prefijos de las series y los contadores de numeración (último número usado de cada serie): ';
        $partes = [];
        foreach (self::AJUSTES as $clave => $etiqueta) {
            if (strpos($clave, 'prefijo_') === 0) {
                $partes[] = self::h($clave) . ' = ' . self::verValor(array_key_exists($clave, $guardados) ? $guardados[$clave] : null);
            }
        }
        $db->setQuery('SELECT familia, anio, ultimo_numero FROM ' . $db->quoteName(self::PREFIJO . 'contador') . ' ORDER BY anio, familia');
        foreach ($db->loadObjectList() as $c) {
            $partes[] = self::h($c->familia . ' ' . $c->anio) . ': ' . (int) $c->ultimo_numero;
        }
        echo implode(' · ', $partes) . '.</p>';
        echo '<a class="btn btn-primary" href="' . self::enlace('export') . '&amp;' . $tokenUrl . '=1">Descargar copia</a>';
        echo '</div></div></div>';
        }

        // ---- Importar
        echo '<div class="' . ($soloRestaurar ? 'col-12' : 'col-lg-6') . ' mb-4"><div class="card h-100"><div class="card-body">';
        echo '<h3 class="card-title">2. Restaurar copia (web nueva)</h3>';
        echo '<p>Sube el fichero descargado en la web antigua. Primero se <strong>comprueba</strong> y se te muestra un resumen; no se cambia nada hasta que confirmes.</p>';
        echo '<form action="' . self::enlace('check') . '" method="post" enctype="multipart/form-data">';
        echo '<div class="mb-3"><input class="form-control" type="file" name="copia" accept=".gz,.json" required></div>';

        if ($hayMapa && $nMapa > 0) {
            echo '<div class="form-check mb-3"><input class="form-check-input" type="checkbox" id="vfb-remap" name="remap" value="1" checked>';
            echo '<label class="form-check-label" for="vfb-remap">Los pedidos de esta web se han traído con el importador de HikaShop: <strong>reasignar los números de pedido</strong> (recomendado; se han encontrado ' . $nMapa . ' equivalencias).</label></div>';
        } else {
            echo '<div class="alert alert-secondary">No hay equivalencias de pedidos del importador de HikaShop en esta web. Los registros se restaurarán con los mismos números de pedido que tenían.</div>';
        }

        echo self::token();
        echo '<button class="btn btn-secondary" type="submit">Comprobar copia</button>';
        echo '</form>';
        echo '<p class="small text-muted mt-3">Tamaño máximo de subida en este servidor: ' . self::h(ini_get('upload_max_filesize')) . ' (el formulario completo, ' . self::h(ini_get('post_max_size')) . ').</p>';
        echo '</div></div></div>';

        echo '</div>';
    }

    // ------------------------------------------------------------------ exportar

    private static function exportar($app): void
    {
        $db = self::db();

        foreach (self::TABLAS as $t) {
            if (!self::existeTabla($db, self::PREFIJO . $t)) {
                echo self::alerta('warning', 'Falta la tabla ' . self::h(self::PREFIJO . $t) . '. Reinstala o actualiza el plugin VeriFactu para que se cree.');
                return;
            }
        }

        $ruta = self::tmpDir() . '/vfb_export_' . bin2hex(random_bytes(6)) . '.gz';
        $gz = gzopen($ruta, 'wb6');
        if (!$gz) {
            throw new \RuntimeException('No se puede crear el fichero temporal en ' . self::tmpDir());
        }

        $flags = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR;

        try {
            gzwrite($gz, '{"formato":' . json_encode(self::FORMATO) . ',"creado":' . json_encode(date('c'))
                . ',"origen":' . json_encode(Uri::root())
                . ',"ajustes":' . json_encode((object) self::ajustesParaCopia($db), $flags)
                . ',"tablas":{');

            $primeraTabla = true;
            foreach (self::TABLAS as $t) {
                $nombre = self::PREFIJO . $t;
                $cols = array_keys($db->getTableColumns($nombre));
                $orden = in_array('id', $cols, true) ? 'id' : $cols[0];

                gzwrite($gz, ($primeraTabla ? '' : ',') . json_encode($t) . ':{"columnas":' . json_encode($cols, $flags) . ',"filas":[');
                $primeraTabla = false;

                $offset = 0;
                $primeraFila = true;
                do {
                    $q = $db->getQuery(true)
                        ->select('*')
                        ->from($db->quoteName($nombre))
                        ->order($db->quoteName($orden));
                    $db->setQuery($q, $offset, 200);
                    $filas = $db->loadAssocList();
                    foreach ($filas as $fila) {
                        gzwrite($gz, ($primeraFila ? '' : ',') . json_encode($fila, $flags));
                        $primeraFila = false;
                    }
                    $offset += 200;
                } while (count($filas) === 200);

                gzwrite($gz, ']}');
            }

            gzwrite($gz, '}}');
        } catch (\Throwable $e) {
            gzclose($gz);
            @unlink($ruta);
            throw $e;
        }

        gzclose($gz);

        while (ob_get_level() > 0) {
            ob_end_clean();
        }

        header('Content-Type: application/gzip');
        header('Content-Disposition: attachment; filename="verifactu-registro-' . date('Ymd-His') . '.json.gz"');
        header('Content-Length: ' . filesize($ruta));
        header('Cache-Control: no-store');
        readfile($ruta);
        @unlink($ruta);
        $app->close();
    }

    // ------------------------------------------------------------------ importar: comprobar

    private static function borrarArchivoSesion($app): void
    {
        $session = $app->getSession();
        $ruta = (string) $session->get(self::SESION, '');
        if ($ruta !== '' && is_file($ruta) && strpos(basename($ruta), 'vfb_import_') === 0) {
            @unlink($ruta);
        }
        $session->clear(self::SESION);
        $session->clear(self::SESION_REMAP);
    }

    private static function comprobar($app): void
    {
        $input = self::input();
        // 'raw': no se aplica el filtro de "fichero seguro" de Joomla, que podría rechazar una copia
        // que contenga texto como <?xml; el fichero nunca se ejecuta ni se publica (se guarda como .bin).
        $archivo = $input->files->get('copia', [], 'raw');

        if (empty($archivo['name'])) {
            echo self::alerta('warning', 'Selecciona el fichero de la copia.');
            self::pantalla();
            return;
        }

        if ((int) $archivo['error'] !== UPLOAD_ERR_OK) {
            $motivo = in_array((int) $archivo['error'], [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true)
                ? 'El fichero es más grande que el límite del servidor (' . self::h(ini_get('upload_max_filesize')) . '). Pide a tu hosting que suba <em>upload_max_filesize</em> y <em>post_max_size</em>.'
                : 'No se ha podido subir el fichero (código ' . (int) $archivo['error'] . ').';
            echo self::alerta('danger', $motivo);
            self::pantalla();
            return;
        }

        self::borrarArchivoSesion($app);

        $destino = self::tmpDir() . '/vfb_import_' . bin2hex(random_bytes(8)) . '.bin';
        if (!move_uploaded_file($archivo['tmp_name'], $destino)) {
            throw new \RuntimeException('No se puede guardar el fichero subido en ' . self::tmpDir());
        }

        $remap = (bool) $input->getInt('remap', 0);
        $session = $app->getSession();
        $session->set(self::SESION, $destino);
        $session->set(self::SESION_REMAP, $remap ? 1 : 0);

        self::mostrarInforme($destino, $remap);
    }

    private static function leerCopia(string $ruta): array
    {
        $raw = file_get_contents($ruta);
        if ($raw === false) {
            throw new \RuntimeException('No se puede leer el fichero subido.');
        }

        if (substr($raw, 0, 2) === "\x1f\x8b") {
            $raw = gzdecode($raw);
            if ($raw === false) {
                throw new \RuntimeException('El fichero comprimido está dañado.');
            }
        }

        $data = json_decode($raw, true);
        if (!is_array($data) || ($data['formato'] ?? '') !== self::FORMATO || !isset($data['tablas']) || !is_array($data['tablas'])) {
            throw new \RuntimeException('El fichero no es una copia válida de VeriFactu (o es de otra versión de esta herramienta).');
        }

        return $data;
    }

    /**
     * Analiza la copia contra esta web sin escribir nada.
     * Devuelve errores (bloquean), avisos, resumen y las filas ya preparadas.
     */
    private static function analizar(array $data, bool $remap): array
    {
        $db = self::db();
        $errores = [];
        $avisos = [];
        $resumen = [];
        $plan = [];
        $columnas = [];

        foreach (self::TABLAS as $t) {
            if (!self::existeTabla($db, self::PREFIJO . $t)) {
                $errores[] = 'Falta la tabla ' . self::h(self::PREFIJO . $t) . ': reinstala o actualiza el plugin VeriFactu para que se cree.';
            }
        }
        if ($errores) {
            return compact('errores', 'avisos', 'resumen', 'plan', 'columnas');
        }

        foreach (self::TABLAS as $t) {
            $nombre = self::PREFIJO . $t;
            $bloque = $data['tablas'][$t] ?? null;
            if (!is_array($bloque)) {
                $plan[$t] = [];
                $columnas[$t] = [];
                $avisos[] = 'La copia no incluye la tabla «' . self::h($t) . '».';
                continue;
            }

            $colsCopia = array_values(array_map('strval', $bloque['columnas'] ?? []));
            $colsDestino = array_keys($db->getTableColumns($nombre));
            $faltan = array_diff($colsCopia, $colsDestino);
            if ($faltan) {
                $errores[] = 'La tabla «' . self::h($t) . '» de la copia tiene columnas que esta web no tiene (' . self::h(implode(', ', $faltan))
                    . '). Instala en esta web la misma versión del plugin VeriFactu que en la web antigua, o una más reciente.';
            }

            $plan[$t] = is_array($bloque['filas'] ?? null) ? $bloque['filas'] : [];
            $columnas[$t] = $colsCopia;
        }

        foreach (['registro', 'numeracion_asignada'] as $t) {
            $existentes = self::contar($db, self::PREFIJO . $t);
            if ($existentes > 0) {
                $errores[] = 'La tabla «' . self::h($t) . '» de esta web ya tiene ' . $existentes
                    . ' filas. Solo se puede restaurar en una web sin registros VeriFactu, para no mezclar dos historiales.';
            }
        }

        // ---- Reasignación de números de pedido (importador de HikaShop)
        if ($remap && !$errores) {
            if (!self::existeTabla($db, self::MAPA)) {
                $errores[] = 'No existe la tabla de equivalencias del importador de HikaShop. Desmarca la reasignación o importa antes los pedidos.';
            } else {
                $db->setQuery('SELECT source_id, hk_id FROM ' . $db->quoteName(self::MAPA));
                $mapa = [];
                foreach ($db->loadObjectList() as $r) {
                    $mapa[(int) $r->source_id] = (int) $r->hk_id;
                }
                if (!$mapa) {
                    $errores[] = 'La tabla de equivalencias del importador está vacía. Importa antes los pedidos con el importador de HikaShop.';
                } else {
                    $sinPedido = 0;
                    $ejemplos = [];
                    foreach ($plan['registro'] as $i => $fila) {
                        $viejo = (int) ($fila['order_id'] ?? 0);
                        if (isset($mapa[$viejo])) {
                            $plan['registro'][$i]['order_id'] = (string) $mapa[$viejo];
                        } else {
                            $plan['registro'][$i]['order_id'] = '0';
                            $sinPedido++;
                            if (count($ejemplos) < 5) {
                                $ejemplos[] = (string) ($fila['invoice_number'] ?? ('pedido ' . $viejo));
                            }
                        }
                    }

                    $nuevas = [];
                    $omitidas = 0;
                    foreach ($plan['numeracion_asignada'] as $fila) {
                        $viejo = (int) ($fila['order_id'] ?? 0);
                        if (isset($mapa[$viejo])) {
                            $fila['order_id'] = (string) $mapa[$viejo];
                            $nuevas[] = $fila;
                        } else {
                            $omitidas++;
                        }
                    }
                    $plan['numeracion_asignada'] = $nuevas;

                    $resumen[] = 'Reasignación de pedidos: ' . (count($plan['registro']) - $sinPedido) . ' de ' . count($plan['registro'])
                        . ' registros enlazados con su pedido nuevo.';
                    if ($sinPedido > 0) {
                        $avisos[] = $sinPedido . ' registros no tienen pedido equivalente en esta web (por ejemplo: ' . self::h(implode(', ', $ejemplos))
                            . '). Se restauran igualmente como histórico, sin pedido asociado (número de pedido 0).';
                    }
                    if ($omitidas > 0) {
                        $avisos[] = $omitidas . ' números asignados a pedidos que no existen en esta web se omiten (no afectan a la numeración, que va en los contadores).';
                    }
                }
            }
        } elseif (!$remap && !$errores) {
            $avisos[] = 'Los registros se restaurarán con los <strong>mismos números de pedido</strong> que tenían. Comprueba que los pedidos de esta web conservan esos números; si no, vuelve atrás y marca la reasignación.';
        }

        // ---- Comprobación de la cadena de hashes dentro de la copia
        $hashPorId = [];
        foreach ($plan['registro'] as $fila) {
            $hashPorId[(int) ($fila['id'] ?? 0)] = (string) ($fila['hash_actual'] ?? '');
        }
        $roturas = 0;
        foreach ($plan['registro'] as $fila) {
            $ant = (int) ($fila['registro_anterior_id'] ?? 0);
            if ($ant > 0 && (!isset($hashPorId[$ant]) || $hashPorId[$ant] !== (string) ($fila['hash_anterior'] ?? ''))) {
                $roturas++;
            }
        }
        if ($roturas > 0) {
            $avisos[] = 'La cadena de hashes de la copia tiene ' . $roturas . ' enlaces que no cuadran. Ya venían así de la web antigua: la restauración no los cambia.';
        }

        // ---- Resumen
        $nReg = count($plan['registro']);
        $ultimo = null;
        foreach ($plan['registro'] as $fila) {
            if ($ultimo === null || (int) ($fila['id'] ?? 0) > (int) ($ultimo['id'] ?? 0)) {
                $ultimo = $fila;
            }
        }
        $linea = 'Registros de facturación: <strong>' . $nReg . '</strong>';
        if ($ultimo) {
            $linea .= ' (último: ' . self::h($ultimo['invoice_number'] ?? '') . ', ' . self::h($ultimo['invoice_date'] ?? '') . ')';
        }
        array_unshift($resumen, $linea);

        $contadores = [];
        foreach ($plan['contador'] as $fila) {
            $contadores[] = self::h(($fila['familia'] ?? '') . ' ' . ($fila['anio'] ?? '') . ': último nº ' . ($fila['ultimo_numero'] ?? ''));
        }
        $resumen[] = 'Contadores de numeración: ' . ($contadores ? implode(' · ', $contadores) : 'ninguno');
        $resumen[] = 'Números asignados a pedidos: ' . count($plan['numeracion_asignada']);
        $resumen[] = 'Declaraciones responsables: ' . count($plan['declaracion']);

        if (!empty($data['origen'])) {
            $resumen[] = 'Copia creada en: ' . self::h($data['origen']) . ' el ' . self::h($data['creado'] ?? '');
        }

        return compact('errores', 'avisos', 'resumen', 'plan', 'columnas');
    }

    private static function mostrarInforme(string $ruta, bool $remap): void
    {
        $data = self::leerCopia($ruta);
        $an = self::analizar($data, $remap);
        $ajustesCopia = (isset($data['ajustes']) && is_array($data['ajustes'])) ? $data['ajustes'] : [];
        $ajNum = self::ajustesDeNumeracion($data, false);
        unset($data);

        echo '<h3>Resultado de la comprobación</h3>';

        if ($an['resumen']) {
            echo '<ul>';
            foreach ($an['resumen'] as $linea) {
                echo '<li>' . $linea . '</li>';
            }
            echo '</ul>';
        }
        foreach ($an['avisos'] as $aviso) {
            echo self::alerta('warning', $aviso);
        }
        foreach ($an['errores'] as $error) {
            echo self::alerta('danger', $error);
        }

        self::bloqueAjustes($ajustesCopia, $ajNum);

        if ($an['errores']) {
            echo self::alerta('info', 'No se ha cambiado nada en el registro. Corrige lo indicado y vuelve a subir la copia.');
            echo '<form action="' . self::enlace('cancel') . '" method="post">' . self::token()
                . '<button class="btn btn-secondary" type="submit">Volver y borrar el fichero subido</button></form>';
            return;
        }

        echo '<h3 class="mt-4">Restaurar el registro</h3>';
        echo self::alerta('success', 'La copia del registro es válida y se puede restaurar. <strong>Todavía no se ha cambiado nada en el registro.</strong>');
        echo '<form action="' . self::enlace('restore') . '" method="post" class="mb-3">';
        echo '<div class="form-check mb-3"><input class="form-check-input" type="checkbox" id="vfb-confirmo" name="confirmo" value="1" required>';
        echo '<label class="form-check-label" for="vfb-confirmo">Entiendo que esto solo se hace una vez, en una web sin facturas VeriFactu emitidas, y que antes he hecho una copia de seguridad de la base de datos.</label></div>';
        if ($ajNum['valores']) {
            echo '<div class="form-check mb-3"><input class="form-check-input" type="checkbox" id="vfb-conajustes" name="con_ajustes" value="1" checked>';
            echo '<label class="form-check-label" for="vfb-conajustes">Aplicar también los prefijos de las series (F1, F2, F3 y R) de la copia en el plugin VeriFactu de esta web. Los números iniciales manuales se dejarán vacíos, porque los contadores se restauran con el registro.</label></div>';
        }
        echo self::token();
        echo '<button class="btn btn-danger" type="submit">Restaurar ahora</button> ';
        echo '</form>';
        echo '<form action="' . self::enlace('cancel') . '" method="post">' . self::token()
            . '<button class="btn btn-secondary" type="submit">Cancelar y borrar el fichero subido</button></form>';
    }

    // ------------------------------------------------------------------ importar: restaurar

    private static function restaurar($app): void
    {
        $session = $app->getSession();
        $ruta = (string) $session->get(self::SESION, '');
        $remap = (bool) $session->get(self::SESION_REMAP, 0);

        if ($ruta === '' || !is_file($ruta) || strpos(basename($ruta), 'vfb_import_') !== 0) {
            echo self::alerta('warning', 'No hay ninguna copia subida. Sube el fichero y pulsa «Comprobar copia».');
            self::pantalla();
            return;
        }

        if (!self::input()->getInt('confirmo', 0)) {
            echo self::alerta('warning', 'Marca la casilla de confirmación para restaurar.');
            self::mostrarInforme($ruta, $remap);
            return;
        }

        $data = self::leerCopia($ruta);
        $an = self::analizar($data, $remap);
        // Al restaurar también los contadores, los números iniciales manuales se dejan vacíos.
        $ajustesCopia = self::ajustesDeNumeracion($data, true)['valores'];
        unset($data);
        $conAjustes = (bool) self::input()->getInt('con_ajustes', 0);

        if ($an['errores']) {
            foreach ($an['errores'] as $error) {
                echo self::alerta('danger', $error);
            }
            echo self::alerta('info', 'No se ha cambiado nada.');
            return;
        }

        $db = self::db();
        $resAjustes = null;
        $db->transactionStart();

        try {
            // Declaración y contadores se sustituyen por los de la copia.
            $db->setQuery('DELETE FROM ' . $db->quoteName(self::PREFIJO . 'declaracion'))->execute();
            $db->setQuery('DELETE FROM ' . $db->quoteName(self::PREFIJO . 'contador'))->execute();

            foreach (self::TABLAS as $t) {
                self::insertar($db, self::PREFIJO . $t, $an['columnas'][$t], $an['plan'][$t]);
            }

            // Los ajustes de numeración van en la misma transacción: si algo falla, no se aplica nada.
            if ($conAjustes && $ajustesCopia) {
                $resAjustes = self::guardarAjustes($db, $ajustesCopia);
            }

            $db->transactionCommit();
        } catch (\Throwable $e) {
            $db->transactionRollback();
            echo self::alerta(
                'danger',
                '<strong>No se ha podido restaurar y se han deshecho los cambios.</strong> Detalle: ' . self::h($e->getMessage())
            );
            return;
        }

        self::borrarArchivoSesion($app);

        echo self::alerta('success', '<strong>Restauración completada.</strong>');
        echo '<ul>';
        foreach ($an['resumen'] as $linea) {
            echo '<li>' . $linea . '</li>';
        }
        echo '</ul>';
        foreach ($an['avisos'] as $aviso) {
            echo self::alerta('warning', $aviso);
        }
        if ($resAjustes !== null) {
            self::limpiarCachePlugins();
            foreach ($resAjustes['avisos'] as $aviso) {
                echo self::alerta('warning', $aviso);
            }
            if ($resAjustes['cambios']) {
                echo '<h4>Ajustes de numeración aplicados</h4>' . self::tablaCambios($resAjustes['cambios']);
            }
        }
        self::avisarPadre();
        echo self::alerta('info', 'Ahora revisa el listado de pedidos (columna VeriFactu) y la numeración antes de emitir facturas nuevas. El fichero subido se ha borrado del servidor.');
    }

    private static function insertar($db, string $nombre, array $cols, array $filas): void
    {
        if (!$cols || !$filas) {
            return;
        }

        $colsQ = array_map(function ($c) use ($db) {
            return $db->quoteName($c);
        }, $cols);

        foreach (array_chunk($filas, 50) as $bloque) {
            $q = $db->getQuery(true)->insert($db->quoteName($nombre))->columns($colsQ);
            foreach ($bloque as $fila) {
                $valores = [];
                foreach ($cols as $c) {
                    $v = $fila[$c] ?? null;
                    $valores[] = ($v === null) ? 'NULL' : $db->quote((string) $v);
                }
                $q->values(implode(',', $valores));
            }
            $db->setQuery($q)->execute();
        }
    }
}
