-- =============================================================================
-- Estado 'invalidada' en solicitudes: alinear CHECK (y ENUM si aplica)
-- Error típico si solo se alteró ENUM: SQLSTATE[HY000] 3819 Check constraint
-- `solicitudes_chk_estado` is violated.
-- Ejecutar en la misma base que usa la aplicación (p. ej. cne_sistema).
-- Antes: SHOW CREATE TABLE solicitudes;  (confirmar nombre del CHECK)
-- =============================================================================

USE cne_sistema;

-- -----------------------------------------------------------------------------
-- 1) CHECK: quitar el anterior y recrear incluyendo 'invalidada'
--    MySQL 8.0.16+: DROP CHECK nombre
--    Si su motor devuelve error de sintaxis, probar en su versión:
--    ALTER TABLE solicitudes DROP CONSTRAINT solicitudes_chk_estado;
-- -----------------------------------------------------------------------------

ALTER TABLE solicitudes DROP CHECK solicitudes_chk_estado;

ALTER TABLE solicitudes
  ADD CONSTRAINT solicitudes_chk_estado CHECK (
    solicitud_estado IN (
      'pendiente',
      'en_revision',
      'aprobada',
      'rechazada',
      'completada',
      'redirigida',
      'vencida',
      'invalidada'
    )
  );

-- -----------------------------------------------------------------------------
-- 2) OPCIONAL: si solicitud_estado es tipo ENUM (no VARCHAR), debe incluir
--    invalidada en la lista. Descomente y adapte según SHOW FULL COLUMNS.
-- -----------------------------------------------------------------------------
-- ALTER TABLE solicitudes
--   MODIFY COLUMN solicitud_estado ENUM(
--     'pendiente','en_revision','aprobada','rechazada','completada','redirigida','vencida','invalidada'
--   ) NOT NULL DEFAULT 'pendiente';
