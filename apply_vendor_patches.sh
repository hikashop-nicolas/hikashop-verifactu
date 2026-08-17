#!/bin/bash
# Aplica todos los parches conocidos a la librería eseperio/verifactu-php
# (versión dev-master). Es idempotente: si un parche ya está aplicado, lo
# detecta y lo salta en vez de fallar o duplicarlo.
#
# Uso:
#   cd /ruta/al/plugin/verifactu/
#   bash apply_vendor_patches.sh
#
# O indicando la ruta del plugin como argumento:
#   bash apply_vendor_patches.sh /ruta/al/plugin/verifactu/

set -e

PLUGIN_DIR="${1:-.}"
SERVICES_DIR="$PLUGIN_DIR/vendor/eseperio/verifactu-php/src/services"

if [ ! -d "$SERVICES_DIR" ]; then
    echo "ERROR: no se encuentra $SERVICES_DIR"
    echo "¿Seguro que has ejecutado 'composer install' primero, y que la ruta del plugin es correcta?"
    exit 1
fi

echo "=== Aplicando parches a eseperio/verifactu-php ==="
echo "Carpeta: $SERVICES_DIR"
echo ""

# --- Parche 1: HashGeneratorService.php ---
# Bug: calcula el hash/huella usando la fecha en formato Y-m-d (tal cual se
# le pasa), pero el XML final se envía con la fecha ya convertida a
# DD-MM-YYYY -- provocando que la AEAT rechace la huella (error 2000).
HASH_FILE="$SERVICES_DIR/HashGeneratorService.php"
if grep -q "InvoiceSerializer::formatDate((string) \$invoiceId->issueDate)" "$HASH_FILE"; then
    echo "[1/2] HashGeneratorService.php: ya parcheado, se omite."
else
    cp "$HASH_FILE" "$HASH_FILE.bak"
    sed -i "s/'issueDate'         => \$invoiceId->issueDate,/'issueDate'         => InvoiceSerializer::formatDate((string) \$invoiceId->issueDate),/g" "$HASH_FILE"
    OCURRENCIAS=$(grep -c "InvoiceSerializer::formatDate((string) \$invoiceId->issueDate)" "$HASH_FILE" || true)
    echo "[1/2] HashGeneratorService.php: parcheado ($OCURRENCIAS ocurrencias corregidas)."
fi

# --- Parche 2: QrGeneratorService.php ---
# Bug A: usa el nombre de parámetro "num" en vez de "numserie" en la URL del QR.
# Bug B: no incluye el parámetro "importe" en absoluto.
# Bug C: el mismo problema de formato de fecha que el parche 1, pero para la
# fecha que se mete en la URL del QR.
QR_FILE="$SERVICES_DIR/QrGeneratorService.php"
if grep -q "'numserie' => \$series" "$QR_FILE" && grep -q "'importe' => \$importe" "$QR_FILE"; then
    echo "[2/2] QrGeneratorService.php: ya parcheado, se omite."
else
    cp "$QR_FILE" "$QR_FILE.bak"

    python3 - "$QR_FILE" << 'PYEOF'
import sys
path = sys.argv[1]
with open(path) as f:
    content = f.read()

old_params = """        $params = [
            'nif' => $nif,
            'num' => $series,
            'fecha' => $date,
        ];

        if (!empty($hash)) {
            $params['huella'] = $hash;
        }"""

new_params = """        $importe = number_format((float) ($record->totalAmount ?? 0), 2, '.', '');

        $params = [
            'nif' => $nif,
            'numserie' => $series,
            'fecha' => $date,
            'importe' => $importe,
        ];"""

if old_params in content:
    content = content.replace(old_params, new_params)

old_date = "$date = $invoiceId->issueDate;"
new_date = "$date = InvoiceSerializer::formatDate((string) $invoiceId->issueDate);"
if old_date in content:
    content = content.replace(old_date, new_date)

with open(path, 'w') as f:
    f.write(content)
PYEOF

    echo "[2/2] QrGeneratorService.php: parcheado (numserie, importe y formato de fecha corregidos)."
fi

echo ""
echo "=== Listo ==="
echo "Verifica con:"
echo "  grep -n \"InvoiceSerializer::formatDate\" $HASH_FILE"
echo "  grep -n \"numserie\\|importe\" $QR_FILE"
