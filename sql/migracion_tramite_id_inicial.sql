-- ID de catálogo al crear la solicitud (nombre de trámite estable en historial).
-- tramite_id puede cambiar al redirigir (FK a la copia local en coordinación destino).

ALTER TABLE `solicitudes`
ADD COLUMN `tramite_id_inicial` int DEFAULT NULL COMMENT 'Catálogo tramite al alta; no se actualiza en redirección' AFTER `tramite_id`;

-- Desde la primera auditoría de creación (corrige filas ya redirigidas antes de esta migración).
UPDATE solicitudes s
INNER JOIN (
    SELECT a.solicitud_id,
           NULLIF(CAST(JSON_UNQUOTE(JSON_EXTRACT(a.detalles, '$.tramite_id')) AS UNSIGNED), 0) AS tid
    FROM auditoria a
    INNER JOIN (
        SELECT solicitud_id, MIN(auditoria_id) AS min_aid
        FROM auditoria
        WHERE accion_codigo IN ('SOLICITUD_CREADA', 'SOLICITUD_COMPLETADA')
        GROUP BY solicitud_id
    ) fc ON fc.min_aid = a.auditoria_id
) cre ON cre.solicitud_id = s.solicitud_id
SET s.tramite_id_inicial = cre.tid
WHERE cre.tid IS NOT NULL;

UPDATE solicitudes SET tramite_id_inicial = tramite_id WHERE tramite_id_inicial IS NULL;
