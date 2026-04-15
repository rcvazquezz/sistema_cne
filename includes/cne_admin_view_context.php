<?php
/**
 * Ajusta el arreglo $usuario cuando un administrador supervisa otra pantalla:
 * la coordinación efectiva debe ser la elegida en la vista, no la del usuario en BD.
 */
function cneAplicarContextoAdminView(array &$usuario): void
{
    if (empty($_SESSION['is_admin_viewing'])) {
        return;
    }
    $tid = (int) ($_SESSION['admin_view_target_rol_id'] ?? 0);
    if ($tid === 4) {
        $usuario['coordinacion_id'] = null;
        $usuario['coordinacion_nombre'] = null;
        return;
    }
    $cid = $_SESSION['admin_view_coordinacion_id'] ?? null;
    if ($cid !== null && $cid !== '') {
        $usuario['coordinacion_id'] = (int) $cid;
        $usuario['coordinacion_nombre'] = $_SESSION['admin_view_coordinacion_nombre'] ?? ($usuario['coordinacion_nombre'] ?? '');
    }
}

/**
 * Misma lógica que en los dashboards: fila de usuario desde BD + coordinación de la vista supervisada.
 */
function cneObtenerUsuarioContextoSesion($usuario_id): array
{
    $usuario = obtenerUsuario($usuario_id);
    cneAplicarContextoAdminView($usuario);
    return $usuario;
}
