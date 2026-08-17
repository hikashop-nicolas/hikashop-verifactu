<?php
/**
 * Applies the fixes the plugin needs on top of eseperio/verifactu-php.
 *
 * The library is required as dev-master, so a Composer install brings back the
 * unpatched files every time. Composer runs this script after install and after
 * update (see composer.json). Run it by hand with:
 *
 *   php scripts/apply-vendor-patches.php
 *
 * Applying twice is harmless. If the library ever changes so that a patch no
 * longer matches, the script stops with an error instead of shipping a build
 * which looks fine and is rejected by the AEAT.
 */

$root = dirname(__DIR__);
$services = $root . '/vendor/eseperio/verifactu-php/src/services';

if (!is_dir($services)) {
    fwrite(STDERR, "vendor/eseperio/verifactu-php is missing, run composer install first\n");
    exit(1);
}

/**
 * Each patch says what it fixes, the exact text to replace and how many times
 * that text is expected in the file.
 */
$patches = [
    [
        'file' => $services . '/HashGeneratorService.php',
        'why' => 'the fingerprint was computed with the raw date while the XML carries it as DD-MM-YYYY, which the AEAT rejects with error 2000',
        'from' => "'issueDate'         => \$invoiceId->issueDate,",
        'to' => "'issueDate'         => InvoiceSerializer::formatDate((string) \$invoiceId->issueDate),",
        'count' => 2,
    ],
    [
        'file' => $services . '/QrGeneratorService.php',
        'why' => 'the QR date had the same format problem',
        'from' => "\$date = \$invoiceId->issueDate;",
        'to' => "\$date = InvoiceSerializer::formatDate((string) \$invoiceId->issueDate);",
        'count' => 1,
    ],
    [
        'file' => $services . '/QrGeneratorService.php',
        'why' => 'the QR address used the parameter name num instead of numserie and carried no importe at all',
        'from' => "        \$params = [\n            'nif' => \$nif,\n            'num' => \$series,\n            'fecha' => \$date,\n        ];\n\n        if (!empty(\$hash)) {\n            \$params['huella'] = \$hash;\n        }\n",
        'to' => "        \$importe = number_format((float) (\$record->totalAmount ?? 0), 2, '.', '');\n\n        \$params = [\n            'nif' => \$nif,\n            'numserie' => \$series,\n            'fecha' => \$date,\n            'importe' => \$importe,\n        ];\n",
        'count' => 1,
    ],
];

$applied = 0;
$already = 0;

foreach ($patches as $patch) {
    $file = $patch['file'];
    if (!is_file($file)) {
        fwrite(STDERR, 'missing file: ' . $file . "\n");
        exit(1);
    }

    $content = file_get_contents($file);

    if (substr_count($content, $patch['to']) >= $patch['count']) {
        $already++;
        continue;
    }

    $found = substr_count($content, $patch['from']);
    if ($found !== $patch['count']) {
        fwrite(STDERR, sprintf(
            "patch no longer matches in %s (expected %d occurrence(s), found %d).\nIt fixes: %s\nCheck whether the library fixed it upstream, then update this script.\n",
            basename($file),
            $patch['count'],
            $found,
            $patch['why']
        ));
        exit(1);
    }

    file_put_contents($file, str_replace($patch['from'], $patch['to'], $content));
    $applied++;
}

echo sprintf("vendor patches: %d applied, %d already in place\n", $applied, $already);
