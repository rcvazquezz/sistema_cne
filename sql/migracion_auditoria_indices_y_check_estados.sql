-- =============================================================================
-- Migración incremental (bases ya existentes antes del esquema final)
-- La referencia de estructura nueva instalación es: cne_sistema.sql (raíz del proyecto).
-- Este archivo sirve para ALTER en servidores que aún no tienen índices / columnas / CHECK.
-- IMPORTANTE: ventana de mantenimiento. Revisar sección 3 antes.
-- =============================================================================

USE cne_sistema;

-- -----------------------------------------------------------------------------
-- 1) Índices compuestos (auditoría y detalles por solicitud + fecha)
--    Mejora: ORDER BY fecha en timelines, JOINs por solicitud_id frecuentes.
--    Si el índice ya existe, MySQL devolverá error 1061: ignorar o eliminar manualmente.
-- -----------------------------------------------------------------------------

ALTER TABLE auditoria
  ADD INDEX idx_auditoria_solicitud_fecha (solicitud_id, fecha_creacion);

ALTER TABLE detalles_solicitud
  ADD INDEX idx_detalles_solicitud_fecha (solicitud_id, detalle_created_at);

-- -----------------------------------------------------------------------------
-- 2) Columna coordinacion_actual_id (ya incluida en cne_sistema.sql final)
--    Solo ejecutar en bases antiguas si INFORMATION_SCHEMA confirma que NO existe.
-- -----------------------------------------------------------------------------

-- ALTER TABLE solicitudes
--   ADD COLUMN coordinacion_actual_id INT NULL DEFAULT NULL AFTER tramite_id,
--   ADD KEY fk_solicitudes_coord_actual (coordinacion_actual_id),
--   ADD CONSTRAINT fk_solicitudes_coord_actual
--     FOREIGN KEY (coordinacion_actual_id) REFERENCES coordinacion (coordinacion_id)
--     ON DELETE SET NULL ON UPDATE CASCADE;

-- -----------------------------------------------------------------------------
-- 3) CHECK solicitudes.solicitud_estado: el volcado cne_sistema.sql NO incluye
--    'vencida', pero el código PHP (coordinador, empleado, métricas) sí la usa.
--    Si ya insertaron 'vencida' sin error, el CHECK pudo haberse eliminado o
--    alterarse en su servidor. Antes de aplicar, ejecute:
--
--    SELECT solicitud_estado, COUNT(*) FROM solicitudes GROUP BY solicitud_estado;
--
--    Luego aplique solo si necesita alinear el constraint con la aplicación.
-- -----------------------------------------------------------------------------

-- Paso A: eliminar constraint antiguo (nombre según SHOW CREATE TABLE solicitudes)
-- ALTER TABLE solicitudes DROP CHECK solicitudes_chk_1;

-- Paso B: constraint solicitudes_chk_estado (ver definición en cne_sistema.sql)
-- ALTER TABLE solicitudes
--   ADD CONSTRAINT solicitudes_chk_estado CHECK (
--     solicitud_estado IN (
--       'pendiente','en_revision','aprobada','rechazada','completada','redirigida','vencida'
--     )
--   );

-- Nota: NO se renombra 'en_revision' a 'en_proceso' aquí: implicaría cambiar PHP/JS masivamente.
-- La etiqueta "En Proceso" es solo de presentación.

-- -----------------------------------------------------------------------------
-- 4) (Opcional) notificaciones.usuario_id es INT en el esquema pero los usuarios
--    se identifican por VARCHAR (cédula). Valorar migración a VARCHAR(20) NULL
--    y backfill; requiere análisis de datos existentes. No automatizado aquí.
-- -----------------------------------------------------------------------------
