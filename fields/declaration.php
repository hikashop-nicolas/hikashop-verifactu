<?php
defined('_JEXEC') or die;

class JFormFieldDeclaration extends JFormField
{
    protected $type = 'Declaration';

    protected function getInput()
    {
        $base = 'index.php?option=com_ajax&group=hikashop&plugin=verifactu&format=json';
        $ajax = $base . '&task=';

        $html = '<div class="verifactu-declaration-tools" style="padding:12px 0;">';
        $html .= '<div class="alert alert-info">Los datos de la Declaración Responsable se pueden cargar desde los datos de facturación de HikaShop. Una vez cargados, los campos son editables y deben guardarse con <strong>Guardar</strong> o <strong>Aplicar</strong>.</div>';
        $html .= '<button type="button" class="btn btn-secondary" id="verifactu-load-billing">Cargar datos de facturación de HikaShop</button> ';
        $html .= '<button type="button" class="btn btn-primary" id="verifactu-open-declaration">Ver declaración responsable</button> ';
        $html .= '<button type="button" class="btn btn-outline-secondary" id="verifactu-open-conditions">Ver condiciones de uso</button>';
        $html .= '<div id="verifactu-load-result" style="margin-top:10px;"></div>';
        $html .= '</div>';
        $html .= '<div class="verifactu-donation-box" style="margin:18px 0;padding:16px;border:1px solid #ddd;border-radius:6px;">';
        $html .= '<h3 style="margin-top:0;">❤️ Apoyar el desarrollo</h3>';
        $html .= '<p>PLG_HIKASHOP_VERIFACTU es software freeware desarrollado por Locker25.com. Si te resulta útil, puedes apoyar voluntariamente su desarrollo.</p>';
        $html .= '<p><strong>Selecciona una cantidad:</strong></p>';
        $html .= '<div style="display:flex;gap:8px;flex-wrap:wrap;">
        <a class="btn btn-primary" href="https://donate.stripe.com/5kQ7sDaEf7Rt7638S7aZi05" target="_blank" rel="noopener noreferrer">❤️ 1 €</a>';
        $html .= '<a class="btn btn-primary" href="https://donate.stripe.com/3cI6oz27J2x99ebc4jaZi00" target="_blank" rel="noopener noreferrer">❤️ 5 €</a>';
        $html .= '<a class="btn btn-primary" href="https://donate.stripe.com/eVq7sD5jV5Jl763c4jaZi01" target="_blank" rel="noopener noreferrer">❤️ 10 €</a>';
        $html .= '<a class="btn btn-primary" href="https://donate.stripe.com/fZubIT8w71t5cqn7O3aZi02" target="_blank" rel="noopener noreferrer">❤️ 20 €</a>';
        $html .= '<a class="btn btn-primary" href="https://donate.stripe.com/eVq5kvbIjefR763b0faZi03" target="_blank" rel="noopener noreferrer">❤️ 50 €</a>';
        $html .= '<a class="btn btn-primary" href="https://donate.stripe.com/dRmbIT13F4FhdurfgvaZi04" target="_blank" rel="noopener noreferrer">❤️ 100 €</a>';
        $html .= '</div>';
        $html .= '</div>';

        $html .= '<script>
(function(){
    function initVerifactuDeclaration(){
        const loadUrl = ' . json_encode($ajax . 'cargar_datos') . ';
        const declarationUrl = ' . json_encode($ajax . 'declaracion') . ';
        const conditionsUrl = ' . json_encode($ajax . 'condiciones') . ';
        const button = document.getElementById("verifactu-load-billing");
        const result = document.getElementById("verifactu-load-result");
        const declarationButton = document.getElementById("verifactu-open-declaration");
        const conditionsButton = document.getElementById("verifactu-open-conditions");
        if (!button || button.dataset.vfReady === "1") return;
        button.dataset.vfReady = "1";

        function setValue(id, value) {
            const el = document.getElementById(id);
            if (!el || value === undefined || value === null) return;
            el.value = value;
            el.dispatchEvent(new Event("input", {bubbles:true}));
            el.dispatchEvent(new Event("change", {bubbles:true}));
        }

        function unwrap(payload){
            // com_ajax envuelve la devolución del plugin en data[0].
            if (payload && Array.isArray(payload.data)) return payload.data[0] || {};
            if (payload && payload.data && typeof payload.data === "object") return payload.data;
            return payload || {};
        }

        button.addEventListener("click", function(){
            button.disabled = true;
            result.textContent = "Cargando datos de facturación de HikaShop...";
            fetch(loadUrl, {credentials:"same-origin", headers:{"X-Requested-With":"XMLHttpRequest"}})
                .then(function(r){ return r.json().then(function(j){ if(!r.ok) throw new Error((j && j.message) || "Error HTTP " + r.status); return j; }); })
                .then(function(payload){
                    const data = unwrap(payload);
                    if (data.success === false) throw new Error(data.message || "No se pudieron cargar los datos.");
                    setValue("jform_params_declaracion_nombre", data.nombre);
                    setValue("jform_params_declaracion_nif", data.nif);
                    setValue("jform_params_declaracion_direccion", data.direccion);
                    setValue("jform_params_declaracion_cp", data.cp);
                    setValue("jform_params_declaracion_localidad", data.localidad);
                    setValue("jform_params_declaracion_provincia", data.provincia);
                    setValue("jform_params_declaracion_pais", data.pais);
                    setValue("jform_params_declaracion_lugar", data.localidad);
                    result.className = "alert alert-success";
                    result.textContent = "Datos cargados desde HikaShop. Revisa los campos y pulsa Guardar/Aplicar para conservarlos.";
                })
                .catch(function(e){ result.className = "alert alert-danger"; result.textContent = "Error al cargar los datos: " + e.message; })
                .finally(function(){ button.disabled = false; });
        });

        function openDocument(url, title){
            // Abrimos la ventana inmediatamente, dentro del click, para evitar
            // que el navegador bloquee el popup después del fetch AJAX.
            const win = window.open("about:blank", "_blank");
            if (!win) { alert("El navegador ha bloqueado la ventana emergente. Permite ventanas emergentes para este sitio."); return; }
            win.document.write("<p style=\\"font-family:Arial;padding:30px\\">Cargando...</p>");
            win.document.close();
            fetch(url, {credentials:"same-origin", headers:{"X-Requested-With":"XMLHttpRequest"}})
                .then(function(r){ return r.json().then(function(j){ if(!r.ok) throw new Error((j && j.message) || "Error HTTP " + r.status); return j; }); })
                .then(function(payload){
                    const data = unwrap(payload);
                    const html = typeof data === "string" ? data : (data.html || data.content || "");
                    if (!html) throw new Error("La respuesta del plugin no contiene el documento.");
                    win.document.open();
                    win.document.write(html);
                    win.document.close();
                    win.document.title = title;
                })
                .catch(function(e){ win.document.body.innerHTML = "<p style=\\"font-family:Arial;padding:30px;color:#b00020\\">Error: " + String(e.message).replace(/[&<>]/g, function(c){return {"&":"&amp;","<":"&lt;",">":"&gt;"}[c];}) + "</p>"; });
        }

        declarationButton.addEventListener("click", function(){ openDocument(declarationUrl, "Declaración Responsable - PLG_HIKASHOP_VERIFACTU"); });
        conditionsButton.addEventListener("click", function(){ openDocument(conditionsUrl, "Condiciones de uso - PLG_HIKASHOP_VERIFACTU"); });

    }

    if (document.readyState === "loading") document.addEventListener("DOMContentLoaded", initVerifactuDeclaration);
    else initVerifactuDeclaration();
})();
</script>';

        return $html;
    }
}
