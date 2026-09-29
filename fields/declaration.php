<?php
defined('_JEXEC') or die;

class JFormFieldDeclaration extends \Joomla\CMS\Form\FormField
{
    protected $type = 'Declaration';

    protected function getInput()
    {
        $base = 'index.php?option=com_ajax&group=hikashop&plugin=verifactu&format=json';
        $ajax = $base . '&task=';

        // El campo "Declaración responsable" conserva su título en la posición
        // normal del formulario de Joomla. El bloque de acciones se mueve por
        // JavaScript justo debajo de "Versión del sistema declarada", para que
        // el diseño sea independiente de cómo Joomla renderice los wrappers.
        $html = '<div class="verifactu-declaration-placeholder" id="verifactu-declaration-placeholder">';
        $html .= '<div class="verifactu-declaration-actions" id="verifactu-declaration-actions">';

        $html .= '<div class="verifactu-save-row">';
        $html .= '<button type="button" class="btn btn-primary" id="verifactu-save-data">Guardar mis datos</button>';
        $html .= '</div>';

        $html .= '<div class="verifactu-document-buttons">';
        $html .= '<button type="button" class="btn btn-outline-primary" id="verifactu-open-declaration">Ver declaración responsable</button>';
        $html .= '<button type="button" class="btn btn-outline-secondary" id="verifactu-open-conditions">Ver condiciones de uso</button>';
        $html .= '</div>';

        $html .= '<div class="verifactu-declaration-info alert alert-info">';
        $html .= '<div class="verifactu-info-icon" aria-hidden="true">i</div>';
        $html .= '<div>';
        $html .= '<p>Los datos de la Declaración Responsable <strong>se cargarán desde</strong> los datos de HikaShop (datos de facturación configurados en la tienda) y se completarán de forma automática cada vez que accedas a esta sección.</p>';
        $html .= '<p>Lo único que puedes modificar manualmente es la dirección fiscal y el lugar de firma.</p>';
        $html .= '<p>Una vez modificados los datos, pulsa "Guardar mis datos" para guardar una nueva copia de la Declaración Responsable.</p>';
        $html .= '</div></div>';

        $html .= '<div class="verifactu-donation-box">';
        $html .= '<h3>❤️ <span>Apoyar el desarrollo</span></h3>';
        $html .= '<p>HikaShop VeriFactu es software freeware. Si te resulta útil, puedes apoyar voluntariamente su desarrollo.</p>';
        $html .= '<p><strong>Selecciona una cantidad:</strong></p>';
        $html .= '<div class="verifactu-donation-buttons">';
        $html .= '<a class="btn btn-primary" href="https://donate.stripe.com/5kQ7sDaEf7Rt7638S7aZi05" target="_blank" rel="noopener noreferrer">↗ ❤️ 1 €</a>';
        $html .= '<a class="btn btn-primary" href="https://donate.stripe.com/3cI6oz27J2x99ebc4jaZi00" target="_blank" rel="noopener noreferrer">↗ ❤️ 5 €</a>';
        $html .= '<a class="btn btn-primary" href="https://donate.stripe.com/eVq7sD5jV5Jl763c4jaZi01" target="_blank" rel="noopener noreferrer">↗ ❤️ 10 €</a>';
        $html .= '<a class="btn btn-primary" href="https://donate.stripe.com/fZubIT8w71t5cqn7O3aZi02" target="_blank" rel="noopener noreferrer">↗ ❤️ 20 €</a>';
        $html .= '<a class="btn btn-primary" href="https://donate.stripe.com/eVq5kvbIjefR763b0faZi03" target="_blank" rel="noopener noreferrer">↗ ❤️ 50 €</a>';
        $html .= '<a class="btn btn-primary" href="https://donate.stripe.com/dRmbIT13F4FhdurfgvaZi04" target="_blank" rel="noopener noreferrer">↗ ❤️ 100 €</a>';
        $html .= '</div></div>';

        $html .= '<div id="verifactu-load-result" aria-live="polite"></div>';
        $html .= '</div></div>';

        $html .= '<style>
        .verifactu-declaration-placeholder{padding:0;margin:0}
        .verifactu-declaration-actions{width:100%;box-sizing:border-box;padding:22px 0 4px;grid-column:1 / -1;flex:0 0 100%}
        .verifactu-save-row{text-align:center;margin:0 0 28px}
        .verifactu-save-row .btn{min-width:210px}
        .verifactu-document-buttons{display:flex;justify-content:center;align-items:center;gap:14px;flex-wrap:wrap;margin:0 0 18px}
        .verifactu-document-buttons .btn{min-width:270px}
        .verifactu-declaration-info{display:flex;gap:14px;align-items:flex-start;margin:0 auto 24px;padding:18px 20px;border:1px solid #9ec5fe;border-radius:4px;box-sizing:border-box}
        .verifactu-declaration-info p{margin:0 0 9px}
        .verifactu-declaration-info p:last-child{margin-bottom:0}
        .verifactu-info-icon{flex:0 0 28px;width:28px;height:28px;border-radius:50%;background:#3f6b9d;color:#fff;font-weight:700;text-align:center;line-height:28px;margin-top:1px}
        .verifactu-donation-box{margin:0 auto;padding:20px 22px;border:1px solid #ddd;border-radius:6px;box-sizing:border-box}
        .verifactu-donation-box h3{margin:0 0 10px;font-size:18px}
        .verifactu-donation-box p{margin:0 0 12px}
        .verifactu-donation-buttons{display:flex;gap:10px;flex-wrap:wrap}
        .verifactu-donation-buttons .btn{min-width:100px}
        #verifactu-load-result{margin-top:10px}
        @media (max-width:700px){
            .verifactu-document-buttons{flex-direction:column}
            .verifactu-document-buttons .btn{width:100%;min-width:0}
            .verifactu-declaration-info{font-size:14px}
            .verifactu-donation-buttons .btn{flex:1 1 calc(50% - 10px)}
        }
        </style>';

        $html .= '<script>
(function(){
    function initVerifactuDeclaration(){
        const loadUrl = ' . json_encode($ajax . 'cargar_datos') . ';
        const saveUrl = ' . json_encode($ajax . 'guardar_datos') . ';
        const formToken = ' . json_encode(\Joomla\CMS\Session\Session::getFormToken()) . ';
        const declarationUrl = ' . json_encode($ajax . 'declaracion') . ';
        const conditionsUrl = ' . json_encode($ajax . 'condiciones') . ';
        const actionBlock = document.getElementById("verifactu-declaration-actions");
        const placeholder = document.getElementById("verifactu-declaration-placeholder");
        const button = document.getElementById("verifactu-save-data");
        const result = document.getElementById("verifactu-load-result");
        const declarationButton = document.getElementById("verifactu-open-declaration");
        const conditionsButton = document.getElementById("verifactu-open-conditions");
        if (!button || button.dataset.vfReady === "1") return;
        button.dataset.vfReady = "1";

        function getFieldWrapper(el){
            if (!el) return null;
            return el.closest(".control-group, .form-group, .field-group, .joomla-field-float, .control-group-wrapper") || el.parentElement;
        }

        function hideInternalDeclarationFields(){
            ["params][declaracion_uso_propio"].forEach(function(name){
                const field = findField(name);
                const wrapper = getFieldWrapper(field);
                if (wrapper) wrapper.style.display = "none";
            });
        }

        function placeActionBlock(){
            const versionField = findField("params][declaracion_version");
            const versionWrapper = getFieldWrapper(versionField);
            if (!actionBlock || !versionWrapper) return false;

            // Insertar el bloque completo después de la última fila del campo
            // "Versión del sistema declarada". El título del campo personalizado
            // permanece en su posición original como encabezado de la sección.
            if (versionWrapper.parentNode) {
                versionWrapper.parentNode.insertBefore(actionBlock, versionWrapper.nextSibling);
                if (placeholder) placeholder.style.display = "contents";
                return true;
            }
            return false;
        }

        function findField(name){
            const fieldName = name.split("[").pop().replace("]", "");
            const candidates = ["jform_params_" + fieldName,"jform_" + fieldName,"params_" + fieldName,fieldName];
            for (let i=0;i<candidates.length;i++){ const el=document.getElementById(candidates[i]); if(el) return el; }
            const all=document.querySelectorAll("input, textarea, select");
            for(let i=0;i<all.length;i++){
                const el=all[i], n=el.getAttribute("name")||"";
                if(n==="jform[params]["+fieldName+"]"||n==="params["+fieldName+"]"||n===fieldName) return el;
            }
            return null;
        }

        function setValue(name, value, force){
            const el=findField(name); if(!el || value===undefined || value===null) return;
            if(!force && String(el.value||"").trim()!=="") return;
            el.value=value;
            el.dispatchEvent(new Event("input",{bubbles:true}));
            el.dispatchEvent(new Event("change",{bubbles:true}));
        }
        function getValue(name){ const el=findField(name); return el?String(el.value||"").trim():""; }
        function lockField(name){
            const el=findField(name); if(!el) return;
            el.readOnly=true; el.setAttribute("readonly","readonly");
            el.style.backgroundColor="#e9ecef"; el.style.color="#6c757d"; el.style.cursor="not-allowed";
        }
        function lockAutomaticFields(){
            lockField("params][declaracion_nombre");
            lockField("params][declaracion_nif");
            lockField("params][declaracion_fecha");
            lockField("params][declaracion_version");
        }
        function unwrap(payload){
            if(payload&&Array.isArray(payload.data)) return payload.data[0]||{};
            if(payload&&payload.data&&typeof payload.data==="object") return payload.data;
            return payload||{};
        }
        function syncEmitterFields(){
            const nombre=getValue("params][nombre_emisor"), nif=getValue("params][nif_emisor");
            if(nombre!=="") setValue("params][declaracion_nombre",nombre,true);
            if(nif!=="") setValue("params][declaracion_nif",nif,true);
            lockAutomaticFields();
        }
        function loadAutomaticData(showMessage){
            lockAutomaticFields(); syncEmitterFields();
            if(showMessage){ button.disabled=true; result.className=""; result.textContent="Actualizando datos..."; }
            return fetch(loadUrl,{credentials:"same-origin",headers:{"X-Requested-With":"XMLHttpRequest"}})
                .then(function(r){return r.json().then(function(j){if(!r.ok)throw new Error((j&&j.message)||"Error HTTP "+r.status);return j;});})
                .then(function(payload){
                    const data=unwrap(payload); if(data.success===false) throw new Error(data.message||"No se pudieron cargar los datos.");
                    const currentNombre=getValue("params][nombre_emisor"), currentNif=getValue("params][nif_emisor");
                    setValue("params][declaracion_nombre",currentNombre||data.nombre,true);
                    setValue("params][declaracion_nif",currentNif||data.nif,true);
                    setValue("params][declaracion_fecha",data.fecha,true);
                    setValue("params][declaracion_version",data.version,true);
                    if(data.direccion && getValue("params][declaracion_direccion")==="") setValue("params][declaracion_direccion",data.direccion,true);
                    if(showMessage){result.className="alert alert-success";result.textContent="Datos automáticos actualizados. La dirección fiscal puede modificarse manualmente.";}
                })
                .catch(function(e){if(showMessage){result.className="alert alert-danger";result.textContent="Error al cargar los datos: "+e.message;}})
                .finally(function(){if(showMessage)button.disabled=false;lockAutomaticFields();});
        }

        const emitterNombre=findField("params][nombre_emisor"), emitterNif=findField("params][nif_emisor");
        if(emitterNombre) emitterNombre.addEventListener("input",syncEmitterFields);
        if(emitterNif) emitterNif.addEventListener("input",syncEmitterFields);

        // Joomla puede terminar de construir los wrappers después de inicializar
        // el campo personalizado. Intentamos varias veces sin alterar los datos.
        hideInternalDeclarationFields();
        placeActionBlock();
        window.setTimeout(function(){ hideInternalDeclarationFields(); placeActionBlock(); },100);
        window.setTimeout(function(){ hideInternalDeclarationFields(); placeActionBlock(); },300);
        window.setTimeout(function(){ hideInternalDeclarationFields(); placeActionBlock(); },700);
        loadAutomaticData(false);
        window.setTimeout(function(){loadAutomaticData(false);},250);

        button.addEventListener("click",function(){
            syncEmitterFields(); button.disabled=true; result.className=""; result.textContent="Guardando sus datos y la Declaración Responsable...";
            const formData=new FormData();
            formData.append("declaracion_nombre",getValue("params][declaracion_nombre"));
            formData.append("declaracion_nif",getValue("params][declaracion_nif"));
            formData.append("declaracion_direccion",getValue("params][declaracion_direccion"));
            formData.append("declaracion_lugar",getValue("params][declaracion_lugar"));
            formData.append(formToken,"1");
            fetch(saveUrl,{method:"POST",body:formData,credentials:"same-origin",headers:{"X-Requested-With":"XMLHttpRequest"}})
                .then(function(r){return r.json().then(function(j){if(!r.ok)throw new Error((j&&j.message)||"Error HTTP "+r.status);return j;});})
                .then(function(payload){const data=unwrap(payload);if(data.success===false)throw new Error(data.message||"No se pudieron guardar los datos.");result.className="alert alert-success";result.textContent=data.message||"Datos guardados correctamente.";})
                .catch(function(e){result.className="alert alert-danger";result.textContent="Error al guardar los datos: "+e.message;})
                .finally(function(){button.disabled=false;lockAutomaticFields();});
        });

        function openDocument(url,title){
            const win=window.open("about:blank","_blank");
            if(!win){alert("El navegador ha bloqueado la ventana emergente. Permite ventanas emergentes para este sitio.");return;}
            win.document.write("<p style=\\"font-family:Arial;padding:30px\\">Cargando...</p>");win.document.close();
            fetch(url,{credentials:"same-origin",headers:{"X-Requested-With":"XMLHttpRequest"}})
                .then(function(r){return r.json().then(function(j){if(!r.ok)throw new Error((j&&j.message)||"Error HTTP "+r.status);return j;});})
                .then(function(payload){const data=unwrap(payload);const html=typeof data==="string"?data:(data.html||data.content||"");if(!html)throw new Error("La respuesta del plugin no contiene el documento.");win.document.open();win.document.write(html);win.document.close();win.document.title=title;})
                .catch(function(e){win.document.body.innerHTML="<p style=\\"font-family:Arial;padding:30px;color:#b00020\\">Error: "+String(e.message).replace(/[&<>]/g,function(c){return {"&":"&amp;","<":"&lt;",">":"&gt;"}[c];})+"</p>";});
        }
        declarationButton.addEventListener("click",function(){openDocument(declarationUrl,"Declaración Responsable - HikaShop VeriFactu");});
        conditionsButton.addEventListener("click",function(){openDocument(conditionsUrl,"Condiciones de uso - HikaShop VeriFactu");});
    }
    if(document.readyState==="loading") document.addEventListener("DOMContentLoaded",initVerifactuDeclaration); else initVerifactuDeclaration();
})();
</script>';

        return $html;
    }
}
