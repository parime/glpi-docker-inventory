<?php

/**
 * -------------------------------------------------------------------------
 * Docker Inventory plugin for GLPI
 * Copyright (C) 2026 Vincent GUILLOTTE
 * https://github.com/parime/glpi-docker-inventory
 * -------------------------------------------------------------------------
 * LICENSE
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version. See LICENSE for the full text.
 * -------------------------------------------------------------------------
 */

declare(strict_types=1);

// Rapport en lecture seule pour préparer une éventuelle migration des msgid vers l'anglais
// (charte commune, section 2 ; issue #37). Ne modifie aucun fichier.
//
// Usage : php tools/msgid-report.php [dossier locales]   (défaut : locales)
//
// Pour chaque msgid du .pot, la traduction en_GB existante deviendrait le nouveau msgid. Le
// rapport compte les chaînes, liste celles qui bloqueraient une migration automatique (sans
// traduction en_GB) et les collisions (deux msgid français, une même traduction anglaise : ils
// fusionneraient). Les chaînes à contexte (msgctxt) et au pluriel sont comptées à part : elles se
// migrent avec leur contexte et leurs deux formes.

/**
 * Entrées d'un fichier .po ou .pot (en-tête exclu), sous forme de blocs séparés par une ligne vide.
 *
 * @return list<array{ctxt: string, id: string, plural: bool, str: string}>
 */
$parse = static function (string $file): array {
    $entries = [];
    $blocks = preg_split('/\n\s*\n/', str_replace("\r\n", "\n", (string) file_get_contents($file))) ?: [];
    foreach ($blocks as $block) {
        $fields = [];
        $field = null;
        foreach (explode("\n", $block) as $line) {
            if (preg_match('/^(msgctxt|msgid|msgid_plural|msgstr|msgstr\[0\]) "(.*)"$/', $line, $m) === 1) {
                $field = $m[1] === 'msgstr[0]' ? 'msgstr' : $m[1];
                $fields[$field] = stripcslashes($m[2]);
            } elseif (preg_match('/^msgstr\[\d+\] /', $line) === 1) {
                $field = null;
            } elseif ($field !== null && preg_match('/^"(.*)"$/', $line, $m) === 1) {
                $fields[$field] .= stripcslashes($m[1]);
            }
        }
        if (($fields['msgid'] ?? '') === '') {
            continue;
        }
        $entries[] = [
            'ctxt'   => $fields['msgctxt'] ?? '',
            'id'     => $fields['msgid'],
            'plural' => isset($fields['msgid_plural']),
            'str'    => $fields['msgstr'] ?? '',
        ];
    }

    return $entries;
};

$dir = rtrim($argv[1] ?? 'locales', '/');
$pots = glob($dir . '/*.pot') ?: [];
if ($pots === [] || !is_file($dir . '/en_GB.po')) {
    fwrite(STDERR, "Fichier .pot ou en_GB.po introuvable dans $dir\n");
    exit(1);
}

$english = [];
foreach ($parse($dir . '/en_GB.po') as $entry) {
    $english[$entry['ctxt'] . "\x04" . $entry['id']] = $entry['str'];
}

$total = $withContext = $plural = $unchanged = 0;
$blocking = [];
$byEnglish = [];
foreach ($parse($pots[0]) as $entry) {
    $total++;
    $withContext += $entry['ctxt'] !== '' ? 1 : 0;
    $plural += $entry['plural'] ? 1 : 0;
    $en = $english[$entry['ctxt'] . "\x04" . $entry['id']] ?? '';
    if ($en === '') {
        $blocking[] = $entry['id'];
        continue;
    }
    $unchanged += $en === $entry['id'] ? 1 : 0;
    $byEnglish[$entry['ctxt'] . "\x04" . $en][] = $entry['id'];
}
$collisions = array_filter($byEnglish, fn (array $ids): bool => count($ids) > 1);

printf("Chaînes du .pot : %d (dont %d à contexte, %d au pluriel)\n", $total, $withContext, $plural);
printf("Identiques en français et en anglais (msgid inchangé) : %d\n", $unchanged);
printf("Sans traduction en_GB (bloquant) : %d\n", count($blocking));
foreach ($blocking as $id) {
    printf("  - %s\n", $id);
}
printf("Collisions (même traduction anglaise) : %d\n", count($collisions));
foreach ($collisions as $key => $ids) {
    printf("  - « %s » <= %s\n", substr((string) $key, (int) strpos((string) $key, "\x04") + 1), implode(' | ', $ids));
}
