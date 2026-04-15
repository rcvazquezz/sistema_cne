<?php
date_default_timezone_set('America/Caracas');
/**
 * Lógica compartida de respaldo SQL e historial (configuracion_sistema JSON).
 */

function backup_getOrCreateConfig($db, $clave, $default) {
    $stmt = $db->prepare("SELECT configuracion_valor FROM configuracion_sistema WHERE configuracion_clave = ?");
    $stmt->execute([$clave]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($row) {
        $val = json_decode($row['configuracion_valor'], true);
        return $val !== null ? $val : $default;
    }
    $stmt = $db->prepare("INSERT INTO configuracion_sistema (configuracion_clave, configuracion_valor, configuracion_descripcion) VALUES (?, ?, ?)");
    $stmt->execute([$clave, json_encode($default), "Configuración de $clave"]);
    return $default;
}

function backup_saveConfig($db, $clave, $valor) {
    $stmt = $db->prepare("SELECT configuracion_id FROM configuracion_sistema WHERE configuracion_clave = ?");
    $stmt->execute([$clave]);
    $json = json_encode($valor);
    if ($stmt->fetch()) {
        $stmt = $db->prepare("UPDATE configuracion_sistema SET configuracion_valor = ? WHERE configuracion_clave = ?");
        $stmt->execute([$json, $clave]);
    } else {
        $stmt = $db->prepare("INSERT INTO configuracion_sistema (configuracion_clave, configuracion_valor, configuracion_descripcion) VALUES (?, ?, ?)");
        $stmt->execute([$clave, $json, "Configuración de $clave"]);
    }
}

/**
 * Convierte la hora a formato 24 h HH:MM (ej. 14:16). Acepta "14:16", "14:16:30", "2:30 pm".
 */
function backup_normalizar_hora_24h($hora) {
    $h = trim((string)$hora);
    if ($h === '') {
        return '02:00';
    }
    if (preg_match('/^(\d{1,2}):(\d{2})\s*(am|pm)\s*$/i', $h, $m)) {
        $hour = (int)$m[1];
        $min = (int)$m[2];
        $ap = strtolower($m[3]);
        if ($hour < 1 || $hour > 12) {
            return '02:00';
        }
        if ($ap === 'pm' && $hour < 12) {
            $hour += 12;
        }
        if ($ap === 'am' && $hour === 12) {
            $hour = 0;
        }
        return sprintf('%02d:%02d', $hour, $min);
    }
    if (preg_match('/^(\d{1,2}):(\d{2})(?::\d{2})?$/', $h, $m)) {
        return sprintf('%02d:%02d', (int)$m[1], (int)$m[2]);
    }
    return '02:00';
}

/**
 * Lee respaldo_automatico; migra desde backup_hora si no existe.
 */
function backup_getRespaldoAutomatico($db) {
    $stmt = $db->prepare("SELECT configuracion_valor FROM configuracion_sistema WHERE configuracion_clave = ?");
    $stmt->execute(['respaldo_automatico']);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($row) {
        $val = json_decode($row['configuracion_valor'], true);
        if (is_array($val) && (isset($val['hora']) || isset($val['activado']))) {
            return [
                'activado' => !empty($val['activado']),
                'hora' => isset($val['hora']) ? backup_normalizar_hora_24h($val['hora']) : '02:00',
                'ultimo_respaldo_fecha' => isset($val['ultimo_respaldo_fecha']) ? (string)$val['ultimo_respaldo_fecha'] : null,
                'ultimo_auto_slot' => isset($val['ultimo_auto_slot']) ? (string)$val['ultimo_auto_slot'] : null,
                'config_actualizado_en' => isset($val['config_actualizado_en']) ? (string)$val['config_actualizado_en'] : null,
            ];
        }
    }
    $stmt->execute(['backup_hora']);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($row) {
        $val = json_decode($row['configuracion_valor'], true);
        if (is_array($val) && isset($val['hora'])) {
            return [
                'activado' => !empty($val['activo']),
                'hora' => backup_normalizar_hora_24h($val['hora']),
                'ultimo_respaldo_fecha' => null,
                'ultimo_auto_slot' => null,
                'config_actualizado_en' => null,
            ];
        }
    }
    return ['activado' => false, 'hora' => '02:00', 'ultimo_respaldo_fecha' => null, 'ultimo_auto_slot' => null, 'config_actualizado_en' => null];
}

function backup_saveRespaldoAutomatico($db, array $cfg) {
    $prev = backup_getRespaldoAutomatico($db);
    $payload = [
        'activado' => array_key_exists('activado', $cfg) ? !empty($cfg['activado']) : $prev['activado'],
        'hora' => backup_normalizar_hora_24h(array_key_exists('hora', $cfg) ? $cfg['hora'] : ($prev['hora'] ?? '02:00')),
        'ultimo_respaldo_fecha' => array_key_exists('ultimo_respaldo_fecha', $cfg) ? $cfg['ultimo_respaldo_fecha'] : ($prev['ultimo_respaldo_fecha'] ?? null),
        'ultimo_auto_slot' => array_key_exists('ultimo_auto_slot', $cfg) ? $cfg['ultimo_auto_slot'] : ($prev['ultimo_auto_slot'] ?? null),
    ];
    if (array_key_exists('config_actualizado_en', $cfg)) {
        if ($cfg['config_actualizado_en'] !== null && $cfg['config_actualizado_en'] !== '') {
            $payload['config_actualizado_en'] = $cfg['config_actualizado_en'];
        }
    } elseif (!empty($prev['config_actualizado_en'])) {
        $payload['config_actualizado_en'] = $prev['config_actualizado_en'];
    }
    backup_saveConfig($db, 'respaldo_automatico', $payload);
}

function backup_addToHistorial($db, $fecha, $tamanio, $estado, $archivo = null) {
    $historial = backup_getOrCreateConfig($db, 'backup_historial', []);
    if (!is_array($historial)) {
        $historial = [];
    }
    $row = ['fecha' => $fecha, 'tamanio' => $tamanio, 'estado' => $estado];
    if ($archivo !== null && $archivo !== '') {
        $row['archivo'] = basename((string) $archivo);
    }
    array_unshift($historial, $row);
    $historial = array_slice($historial, 0, 5);
    backup_saveConfig($db, 'backup_historial', $historial);
}

function backup_formatoTamano($bytes) {
    if ($bytes < 1024) {
        return $bytes . ' B';
    }
    if ($bytes < 1048576) {
        return number_format($bytes / 1024, 1) . ' KB';
    }
    return number_format($bytes / 1048576, 1) . ' MB';
}

/**
 * Genera el archivo .sql en /backups. Devuelve claves: success, filename, filepath, tamanio, exportado.
 */
function backup_generarArchivoSql($db) {
    $backupsDir = dirname(__DIR__) . '/backups';
    if (!is_dir($backupsDir)) {
        @mkdir($backupsDir, 0755, true);
    }
    $filename = 'backup_' . date('Y-m-d_H-i-s') . '.sql';
    $filepath = $backupsDir . '/' . $filename;

    $exportado = false;
    $tamanio = 0;

    if (function_exists('exec')) {
        $host = 'localhost';
        $dbname = 'cne_sistema';
        $user = 'root';
        $pass = '';
        $mysqlPath = 'mysqldump';
        if (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN') {
            $possiblePaths = ['C:\\laragon\\bin\\mysql\\mysql-8.0.30-winx64\\bin\\mysqldump.exe', 'C:\\laragon\\bin\\mysql\\mysql-8.4.3-winx64\\bin\\mysqldump.exe', 'mysqldump'];
            foreach ($possiblePaths as $p) {
                if ($p === 'mysqldump' || file_exists($p)) {
                    $mysqlPath = $p;
                    break;
                }
            }
        }
        $cmd = sprintf('"%s" -h %s -u %s %s %s > "%s" 2>&1', $mysqlPath, $host, $user, $pass ? "-p$pass" : '', $dbname, $filepath);
        @exec($cmd, $out, $ret);
        if (file_exists($filepath) && filesize($filepath) > 0) {
            $exportado = true;
            $tamanio = filesize($filepath);
        }
    }

    if (!$exportado) {
        $output = "/* Backup CNE Sistema - " . date('Y-m-d H:i:s') . " */\n\n";
        $output .= "SET NAMES utf8mb4;\nSET FOREIGN_KEY_CHECKS=0;\n\n";
        $tables = $db->query("SHOW TABLES")->fetchAll(PDO::FETCH_COLUMN);
        foreach ($tables as $table) {
            $output .= "DROP TABLE IF EXISTS `$table`;\n";
            $create = $db->query("SHOW CREATE TABLE `$table`")->fetch(PDO::FETCH_NUM);
            $output .= $create[1] . ";\n\n";
            $rows = $db->query("SELECT * FROM `$table`")->fetchAll(PDO::FETCH_ASSOC);
            if (!empty($rows)) {
                $cols = array_keys($rows[0]);
                $output .= "INSERT INTO `$table` (`" . implode('`, `', $cols) . "`) VALUES\n";
                $vals = [];
                foreach ($rows as $r) {
                    $v = array_map(function ($x) use ($db) {
                        return $x === null ? 'NULL' : $db->quote($x);
                    }, array_values($r));
                    $vals[] = '(' . implode(', ', $v) . ')';
                }
                $output .= implode(",\n", $vals) . ";\n\n";
            }
        }
        $output .= "SET FOREIGN_KEY_CHECKS=1;\n";
        file_put_contents($filepath, $output);
        $exportado = true;
        $tamanio = strlen($output);
    }

    return [
        'success' => $exportado,
        'filename' => $filename,
        'filepath' => $filepath,
        'tamanio' => $tamanio,
        'exportado' => $exportado,
    ];
}
