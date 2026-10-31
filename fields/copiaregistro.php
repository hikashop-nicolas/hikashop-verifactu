<?php
defined('_JEXEC') or die;

use Joomla\CMS\Session\Session;


/**
 * Botones de copia de seguridad del registro VeriFactu, dentro de la configuración
 * del plugin (v0.34.88).
 *
 *  - "Descargar copia": descarga directa del fichero (.json.gz) con las tablas del
 *    plugin y los ajustes de numeración.
 *  - "Subir copia y restaurar": abre la pantalla de restauración en una ventana
 *    emergente (subir fichero, comprobar, confirmar). Al cerrarla, si se ha restaurado
 *    algo, esta página se recarga para que un "Guardar" posterior no escriba de nuevo
 *    valores antiguos del formulario.
 *
 * La lógica está en src/VerifactuBackup.php, servida por onAjaxVerifactu() (tareas
 * backup_*), solo para superadministradores.
 */
class JFormFieldCopiaregistro extends \Joomla\CMS\Form\FormField
{
    protected $type = 'Copiaregistro';

    protected function getInput()
    {
        $base = 'index.php?option=com_ajax&amp;group=hikashop&amp;plugin=verifactu&amp;format=raw&amp;task=backup_';
        $token = htmlspecialchars(Session::getFormToken(), ENT_QUOTES, 'UTF-8');

        $exportar = $base . 'export&amp;' . $token . '=1';
        $marco = $base . 'pantalla&amp;modo=restaurar';
        $pestana = $base . 'pantalla';

        $caja = 'border:1px solid #ffe08a;background:#fff8e1;border-left:5px solid #e0a800;padding:14px 16px;border-radius:4px;color:#212529';

        $anio = date('Y');
        $siguiente = (string) ((int) $anio + 1);
        $a2 = substr($anio, 2);
        $s2 = substr($siguiente, 2);

        // Últimos números del año en curso, para que se vea qué dejará el segundo botón.
        require_once dirname(__DIR__) . '/src/VerifactuFinAnio.php';
        $resumen = '';
        try {
            $db = \Joomla\CMS\Factory::getContainer()->get('DatabaseDriver');
            $ultimos = VerifactuFinAnio::ultimosDelAnio($db, $a2);
            $partes = [];
            foreach (array_keys(VerifactuFinAnio::SEMILLAS) as $familia) {
                $partes[] = $familia . ' ' . (isset($ultimos[$familia]) ? str_pad((string) $ultimos[$familia], 4, '0', STR_PAD_LEFT) : '—');
            }
            $resumen = implode(' · ', $partes);
        } catch (\Throwable $e) {
            $resumen = '';
        }

        $ajax = 'index.php?option=com_ajax&amp;group=hikashop&amp;plugin=verifactu&amp;format=raw&amp;task=finanio_';
        $btn = 'class="btn btn-secondary btn-sm" style="white-space:nowrap" ';
        $fila = 'display:flex;gap:14px;align-items:flex-start;justify-content:space-between';

        $html = '<div style="' . $caja . '">';
        $html .= '<p style="margin:0 0 10px">Descarga un único fichero con todo el registro de facturación (registros, numeración, contadores y declaración responsable) '
            . 'y los prefijos de las series (F1, F2, F3 y R) junto con el último número usado de cada una. '
            . 'Está pensado para una <strong>instalación nueva en la que no se han importado las facturas antiguas</strong>: en esa web nueva, sube ese fichero para restaurar '
            . 'el registro, los contadores y los prefijos de las series. '
            . '(Si los pedidos antiguos sí se han traído con el importador de HikaShop, la pantalla de restauración permite reasignar los números de pedido.) '
            . 'Antes de restaurar se muestra un resumen y no se cambia nada hasta que lo confirmes.</p>';
        $html .= '<p style="margin:0">';
        $html .= '<a href="' . $exportar . '" class="btn btn-primary">Descargar copia</a> ';
        $html .= '<button type="button" id="vfb-abrir" class="btn btn-danger">Subir copia y restaurar…</button>';
        $html .= '</p>';
        $html .= '<details style="margin-top:12px;font-size:.92em"><summary style="cursor:pointer;font-weight:600">Fin de año: qué hacer con los «Número inicial manual»</summary>';
        $html .= '<div id="vff-caja" data-token="' . $token . '" data-vaciar="' . $ajax . 'vaciar" data-continuar="' . $ajax . 'continuar" data-anio="' . $anio . '" style="margin-top:8px">';
        $html .= '<p style="margin:0 0 6px">El plugin lleva un contador por serie y año. Los campos «Número inicial manual» (F1, F2, F3 y R) los usa para cualquier serie y año que todavía no tenga contador, así que en enero influyen en la primera factura de cada serie de ' . $siguiente . '.</p>';
        $html .= '<p style="margin:0 0 6px"><strong>Estas acciones se hacen después de la última factura de ' . $anio . '.</strong> Del ' . VerifactuFinAnio::DIA_INICIO_AVISO . ' al 31 de diciembre el plugin te lo recuerda por email (al «Email para avisos de caducidad») y con un mensaje en el panel de inicio de Joomla, hasta que pulses uno de los dos botones.</p>';
        $html .= '<ul style="margin:0 0 6px 18px;padding:0">';
        $html .= '<li style="margin-bottom:6px"><div style="' . $fila . '"><div><strong>Para que ' . $siguiente . ' empiece en ' . $s2 . '-0001</strong> (o ' . $s2 . 'FS-0001, ' . $s2 . 'F3-0001, ' . $s2 . 'R-0001): vacía los cuatro campos antes del 1 de enero.</div>'
            . '<button type="button" id="vff-vaciar" ' . $btn . '>Vaciar los campos</button></div></li>';
        $html .= '<li style="margin-bottom:6px"><div style="' . $fila . '"><div><strong>Para continuar la numeración</strong> (por ejemplo, tras la ' . $a2 . '-0320, que la primera de ' . $siguiente . ' sea la ' . $s2 . '-0321): deja en cada campo el último número de ' . $anio . ', <em>sin sumar 1</em>. '
            . 'El botón lo hace por ti: lee el último número de cada serie en la tabla de contadores de ' . $anio . ' y lo deja en su campo (la serie que no haya emitido facturas este año queda vacía). '
            . 'Vacía los campos cuando cada serie haya emitido su primera factura de ' . $siguiente . '; si no, en ' . ((int) $siguiente + 1) . ' volverían a empezar desde ese número.'
            . ($resumen !== '' ? '<br><span style="color:#495057">Ahora, en los contadores de ' . $anio . ': ' . htmlspecialchars($resumen, ENT_QUOTES, 'UTF-8') . '</span>' : '')
            . '</div>'
            . '<button type="button" id="vff-continuar" ' . $btn . '>Dejar el último número de ' . $anio . '</button></div></li>';
        $html .= '<li><strong>Si los dejas con otro valor</strong>, ' . $siguiente . ' empezará en ese número más uno (por ejemplo, ' . $s2 . '-0285 con 0284).</li>';
        $html .= '</ul>';
        $html .= '<p id="vff-estado" role="status" style="margin:6px 0;font-weight:600"></p>';
        $html .= '<p style="margin:0">Elige el criterio que corresponda a tu numeración de facturas; si tienes dudas, consúltalo con tu asesor.</p>';
        $html .= '</div></details>';
        $html .= '</div>';

        // Ventana emergente propia (no depende de Bootstrap); se mueve al <body> al iniciar.
        $html .= '<div id="vfb-capa" style="display:none;position:fixed;top:0;left:0;right:0;bottom:0;z-index:100000;background:rgba(0,0,0,.55);align-items:center;justify-content:center">';
        $html .= '<div style="background:#fff;color:#212529;width:min(960px,94vw);height:min(720px,90vh);border-radius:8px;display:flex;flex-direction:column;overflow:hidden;box-shadow:0 10px 40px rgba(0,0,0,.4)">';
        $html .= '<div style="display:flex;align-items:center;gap:12px;padding:10px 16px;border-bottom:1px solid #dee2e6">';
        $html .= '<strong style="flex:1">Restaurar copia del registro VeriFactu</strong>';
        $html .= '<a href="' . $pestana . '" target="_blank" rel="noopener noreferrer" style="font-size:.85em">Abrir en una pestaña nueva</a>';
        $html .= '<button type="button" id="vfb-cerrar" class="btn btn-secondary btn-sm">Cerrar</button>';
        $html .= '</div>';
        $html .= '<iframe id="vfb-marco" src="about:blank" data-src="' . $marco . '" title="Restaurar copia del registro VeriFactu" style="flex:1;border:0;width:100%"></iframe>';
        $html .= '</div></div>';

        $html .= <<<'HTML'
<script>
(function () {
  try {
    var abrir = document.getElementById('vfb-abrir');
    var capa = document.getElementById('vfb-capa');
    var marco = document.getElementById('vfb-marco');
    var cerrar = document.getElementById('vfb-cerrar');
    if (!abrir || !capa || !marco || !cerrar) { return; }

    document.body.appendChild(capa);
    var recargar = false;

    function mostrar() {
      marco.src = marco.getAttribute('data-src');
      capa.style.display = 'flex';
    }
    function ocultar() {
      capa.style.display = 'none';
      marco.src = 'about:blank';
      if (recargar) { window.location.reload(); }
    }

    abrir.addEventListener('click', mostrar);
    cerrar.addEventListener('click', ocultar);
    capa.addEventListener('click', function (e) { if (e.target === capa) { ocultar(); } });
    document.addEventListener('keydown', function (e) {
      if (e.key === 'Escape' && capa.style.display !== 'none') { ocultar(); }
    });
    window.addEventListener('message', function (e) {
      if (e.origin === window.location.origin && e.data === 'vfb-restaurado') { recargar = true; }
    });
  } catch (err) {
    // solo interfaz: nunca debe romper la configuración del plugin
  }
})();
</script>
<script>
(function () {
  try {
    var caja = document.getElementById('vff-caja');
    var estado = document.getElementById('vff-estado');
    if (!caja || !estado) { return; }
    var campos = ['semilla_f1', 'semilla_f2', 'semilla_f3', 'semilla_r'];

    function mostrar(texto, ok) {
      estado.textContent = texto;
      estado.style.color = ok ? '#146c43' : '#b02a37';
    }

    function enviar(tarea, boton, confirmacion) {
      if (!window.confirm(confirmacion)) { return; }
      var datos = new FormData();
      datos.append(caja.getAttribute('data-token'), '1');
      boton.disabled = true;
      mostrar('Guardando…', true);
      fetch(caja.getAttribute(tarea).replace(/&amp;/g, '&'), { method: 'POST', body: datos, credentials: 'same-origin' })
        .then(function (r) { return r.text(); })
        .then(function (texto) {
          var r = JSON.parse(texto);
          if (r.ok && r.valores) {
            // se actualizan también los campos abiertos: un "Guardar" posterior no debe volver a escribir los valores viejos
            campos.forEach(function (c) {
              var el = document.querySelector('[name="jform[params][' + c + ']"]');
              if (el && Object.prototype.hasOwnProperty.call(r.valores, c)) { el.value = r.valores[c]; }
            });
          }
          mostrar(r.mensaje || 'Respuesta inesperada.', !!r.ok);
        })
        .catch(function () { mostrar('No se pudo completar la acción. Recarga la configuración del plugin e inténtalo otra vez.', false); })
        .then(function () { boton.disabled = false; });
    }

    var anio = caja.getAttribute('data-anio');
    var vaciar = document.getElementById('vff-vaciar');
    var continuar = document.getElementById('vff-continuar');
    if (vaciar) {
      vaciar.addEventListener('click', function () {
        enviar('data-vaciar', vaciar, 'Se vaciarán los cuatro «Número inicial manual» (F1, F2, F3 y R) y se guardará en el plugin. El año nuevo empezará en 0001. ¿Continuar?');
      });
    }
    if (continuar) {
      continuar.addEventListener('click', function () {
        enviar('data-continuar', continuar, 'Hazlo solo después de emitir la última factura de ' + anio + '. Cada «Número inicial manual» se rellenará con el último número de ' + anio + ' de su serie (sin sumar 1) y se guardará en el plugin. ¿Continuar?');
      });
    }
  } catch (err) {
    // solo interfaz: nunca debe romper la configuración del plugin
  }
})();
</script>
HTML;

        return $html;
    }
}
