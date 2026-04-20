<?php
/**
 * Envío de respaldos .sql comprimidos en .zip por correo (PHPMailer + SMTP).
 */
declare(strict_types=1);

use PHPMailer\PHPMailer\PHPMailer;

function email_obtener_config(): array
{
    $path = dirname(__DIR__) . '/config/email_config.php';
    if (!is_file($path)) {
        return [];
    }
    $cfg = require $path;
    return is_array($cfg) ? $cfg : [];
}

function email_log_respaldo(string $mensaje): void
{
    error_log('RespaldoEmail: ' . $mensaje);
}

/**
 * Gmail: la contraseña de aplicación son 16 caracteres; los espacios al pegar deben ignorarse.
 */
function email_normalizar_pass_smtp(string $pass): string
{
    return preg_replace('/\s+/', '', trim($pass));
}

function email_esta_configurado(): bool
{
    $c = email_obtener_config();
    $host = trim((string) ($c['SMTP_HOST'] ?? ''));
    $user = trim((string) ($c['SMTP_USER'] ?? ''));
    $pass = email_normalizar_pass_smtp((string) ($c['SMTP_PASS'] ?? ''));
    $to = trim((string) ($c['SMTP_TO_EMAIL'] ?? ''));
    return $host !== '' && $user !== '' && $pass !== '' && $to !== '';
}

/**
 * Comprime el .sql en .zip, envía por correo y elimina ambos archivos si el envío es correcto.
 *
 * @return array{success: bool, message: string}
 */
function enviarRespaldoPorEmail(string $ruta_sql, ?string $nombre_base_datos = null): array
{
    if (!is_readable($ruta_sql)) {
        $msg = 'Archivo SQL no legible: ' . $ruta_sql;
        email_log_respaldo($msg);
        return ['success' => false, 'message' => $msg];
    }

    if (!email_esta_configurado()) {
        $msg = 'Correo no configurado: complete config/email_config.php (SMTP_* y SMTP_TO_EMAIL).';
        email_log_respaldo($msg);
        return ['success' => false, 'message' => $msg];
    }

    $autoload = dirname(__DIR__) . '/vendor/autoload.php';
    if (!is_file($autoload)) {
        $msg = 'Composer autoload no encontrado. Ejecute: composer require phpmailer/phpmailer';
        email_log_respaldo($msg);
        return ['success' => false, 'message' => $msg];
    }
    require_once $autoload;

    $cfg = email_obtener_config();
    $dbName = $nombre_base_datos !== null && $nombre_base_datos !== ''
        ? $nombre_base_datos
        : trim((string) ($cfg['BACKUP_DB_NAME'] ?? 'cne_sistema'));

    $dir = dirname($ruta_sql);
    $base = pathinfo($ruta_sql, PATHINFO_FILENAME);
    $zipPath = $dir . DIRECTORY_SEPARATOR . $base . '.zip';

    $adjuntoPath = $ruta_sql;
    $adjuntoNombre = basename($ruta_sql);

    if (class_exists('ZipArchive')) {
        $zip = new ZipArchive();
        if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            $msg = 'No se pudo crear el archivo ZIP en: ' . $zipPath;
            email_log_respaldo($msg);
            return ['success' => false, 'message' => $msg];
        }
        $zip->addFile($ruta_sql, basename($ruta_sql));
        $zip->close();
        if (!is_readable($zipPath)) {
            $msg = 'ZIP generado pero no legible: ' . $zipPath;
            email_log_respaldo($msg);
            return ['success' => false, 'message' => $msg];
        }
        $adjuntoPath = $zipPath;
        $adjuntoNombre = basename($zipPath);
    } else {
        email_log_respaldo('Extensión ZipArchive no disponible; se adjunta el .sql sin comprimir.');
    }

    date_default_timezone_set('America/Caracas');
    $fechaHora = date('Y-m-d H:i:s');

    $host = trim((string) $cfg['SMTP_HOST']);
    $port = (int) ($cfg['SMTP_PORT'] ?? 587);
    $user = trim((string) $cfg['SMTP_USER']);
    $pass = email_normalizar_pass_smtp((string) ($cfg['SMTP_PASS'] ?? ''));
    $secure = strtolower(trim((string) ($cfg['SMTP_SECURE'] ?? 'tls')));
    $to = trim((string) ($cfg['SMTP_TO_EMAIL'] ?? ''));
    $fromEmail = trim((string) ($cfg['SMTP_FROM_EMAIL'] ?? $user));
    if ($fromEmail === '') {
        $fromEmail = $user;
    }
    $fromName = trim((string) ($cfg['SMTP_FROM_NAME'] ?? 'Sistema CNE'));

    $subject = 'Respaldo BD — ' . $dbName . ' — ' . $fechaHora;

    $body = "Respaldo de base de datos (Sistema CNE)\r\n\r\n";
    $body .= "Fecha y hora (America/Caracas): {$fechaHora}\r\n";
    $body .= "Base de datos: {$dbName}\r\n";
    $body .= "Archivo adjunto: {$adjuntoNombre}\r\n\r\n";
    $body .= "Este mensaje se generó de forma automática.\r\n";

    $altBody = $body;

    $mail = null;
    try {
        $mail = new PHPMailer(true);
        $mail->CharSet = PHPMailer::CHARSET_UTF8;
        $mail->isSMTP();
        $mail->Host = $host;
        $mail->Port = $port > 0 ? $port : 587;
        $mail->SMTPAuth = true;
        $mail->Username = $user;
        $mail->Password = $pass;
        if ($secure === 'ssl') {
            $mail->SMTPSecure = PHPMailer::ENCRYPTION_SMTPS;
        } elseif ($secure === 'tls' || $secure === 'starttls') {
            $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
        } else {
            $mail->SMTPAutoTLS = false;
        }
        $mail->setFrom($fromEmail, $fromName);
        $mail->addAddress($to);
        $mail->Subject = $subject;
        $mail->Body = $body;
        $mail->AltBody = $altBody;
        $mail->addAttachment($adjuntoPath, $adjuntoNombre);

        $mail->send();
    } catch (\Throwable $e) {
        $detalle = isset($mail) && $mail instanceof PHPMailer && $mail->ErrorInfo !== ''
            ? $mail->ErrorInfo
            : $e->getMessage();
        email_log_respaldo('Error envío correo: ' . $detalle);
        if (isset($zipPath) && $adjuntoPath !== $ruta_sql && is_file($zipPath)) {
            @unlink($zipPath);
        }
        return ['success' => false, 'message' => $detalle];
    }

    @unlink($ruta_sql);
    if ($adjuntoPath !== $ruta_sql && is_file($zipPath)) {
        @unlink($zipPath);
    }

    email_log_respaldo('Envío correcto a ' . $to . ' — ' . $adjuntoNombre);
    return ['success' => true, 'message' => 'Enviado por correo electrónico'];
}
