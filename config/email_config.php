<?php
/**
 * SMTP para envío de respaldos de base de datos (Gmail / Outlook u otro).
 * Copie este archivo y rellene los valores; use contraseña de aplicación en Gmail.
 * SMTP_PASS: los 16 caracteres sin espacios (aunque Google los muestre en grupos de 4).
 *
 * @return array{
 *   SMTP_HOST: string,
 *   SMTP_PORT: int,
 *   SMTP_USER: string,
 *   SMTP_PASS: string,
 *   SMTP_SECURE: string,
 *   SMTP_TO_EMAIL: string,
 *   SMTP_FROM_EMAIL: string,
 *   SMTP_FROM_NAME: string,
 *   BACKUP_DB_NAME: string
 * }
 */
return [
    'SMTP_HOST' => 'smtp.gmail.com',
    'SMTP_PORT' => 587,
    'SMTP_USER' => 'rcvazquezantelo2006@gmail.com',
    'SMTP_PASS' => 'lght nrff dtdu enti',
    'SMTP_SECURE' => 'tls',
    'SMTP_TO_EMAIL' => 'rcvazquezantelo2006@gmail.com',
    'SMTP_FROM_EMAIL' => 'rcvazquezantelo2006@gmail.com',
    'SMTP_FROM_NAME' => 'Sistema CNE — Respaldo',
    'BACKUP_DB_NAME' => 'cne_sistema',
];
