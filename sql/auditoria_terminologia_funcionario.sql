-- =============================================================================
-- CNE: auditoría — terminología "empleado" → "funcionario"
-- Ejecutar una vez en MySQL/MariaDB tras copia de seguridad.
-- =============================================================================

START TRANSACTION;

UPDATE auditoria
SET accion_codigo = 'FUNCIONARIO_COMPLETA_TRAMITE'
WHERE accion_codigo = 'EMPLEADO_COMPLETA_TRAMITE';

UPDATE auditoria
SET accion_codigo = 'FUNCIONARIO_REDIRIGE_TRAMITE'
WHERE accion_codigo = 'EMPLEADO_REDIRIGE_TRAMITE';

UPDATE auditoria
SET accion_codigo = 'FUNCIONARIO_MARCA_VENCIDA'
WHERE accion_codigo = 'EMPLEADO_MARCA_VENCIDA';

UPDATE auditoria
SET accion_descripcion = REPLACE(accion_descripcion, 'El empleado completa el trámite', 'El funcionario completa el trámite')
WHERE accion_descripcion LIKE '%El empleado completa el trámite%';

UPDATE auditoria
SET accion_descripcion = REPLACE(accion_descripcion, 'Trámite iniciado por el empleado', 'Trámite iniciado por el funcionario')
WHERE accion_descripcion LIKE '%Trámite iniciado por el empleado%';

UPDATE auditoria
SET accion_descripcion = REPLACE(accion_descripcion, 'Requisitos actualizados por el empleado', 'Requisitos actualizados por el funcionario')
WHERE accion_descripcion LIKE '%Requisitos actualizados por el empleado%';

UPDATE auditoria
SET accion_descripcion = REPLACE(accion_descripcion, 'Por el empleado', 'Por el funcionario')
WHERE accion_descripcion LIKE '%Por el empleado%';

UPDATE auditoria
SET accion_descripcion = REPLACE(accion_descripcion, 'por el empleado', 'por el funcionario')
WHERE accion_descripcion LIKE '%por el empleado%';

COMMIT;
