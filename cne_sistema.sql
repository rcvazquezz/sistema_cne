-- --------------------------------------------------------
-- Sistema CNE — Esquema final consolidado (cne_sistema)
-- MySQL 8.x · utf8mb4_unicode_ci (BD) / utf8mb4_0900_ai_ci (tablas)
--
-- Incluye frente a volcados anteriores:
--   · auditoria: índice (solicitud_id, fecha_creacion) para timelines
--   · detalles_solicitud: índice (solicitud_id, detalle_created_at)
--   · solicitudes: coordinacion_actual_id + CHECK con estado vencida
--   · notificaciones: usuario_id VARCHAR alineado con usuarios.user_identificacion + FK
-- --------------------------------------------------------

/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET NAMES utf8 */;
/*!50503 SET NAMES utf8mb4 */;
/*!40103 SET @OLD_TIME_ZONE=@@TIME_ZONE */;
/*!40103 SET TIME_ZONE='+00:00' */;
/*!40014 SET @OLD_FOREIGN_KEY_CHECKS=@@FOREIGN_KEY_CHECKS, FOREIGN_KEY_CHECKS=0 */;
/*!40101 SET @OLD_SQL_MODE=@@SQL_MODE, SQL_MODE='NO_AUTO_VALUE_ON_ZERO' */;
/*!40111 SET @OLD_SQL_NOTES=@@SQL_NOTES, SQL_NOTES=0 */;


-- Volcando estructura de base de datos para cne_sistema
CREATE DATABASE IF NOT EXISTS `cne_sistema` /*!40100 DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci */ /*!80016 DEFAULT ENCRYPTION='N' */;
USE `cne_sistema`;

-- Volcando estructura para tabla cne_sistema.auditoria
CREATE TABLE IF NOT EXISTS `auditoria` (
  `auditoria_id` int NOT NULL AUTO_INCREMENT,
  `empleado_id` varchar(20) NOT NULL,
  `solicitud_id` int NOT NULL,
  `accion_codigo` varchar(50) NOT NULL COMMENT 'Códigos: SOLICITUD_CREADA, SOLICITUD_ASIGNADA, SOLICITUD_MODIFICADA, SOLICITUD_COMPLETADA, SOLICITUD_CANCELADA, SOLICITUD_REDIRIGIDA, ESTADO_CAMBIADO, OBSERVACION_AGREGADA, REQUISITO_VERIFICADO, REQUISITO_RECHAZADO',
  `accion_descripcion` text,
  `fecha_creacion` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `detalles` json DEFAULT NULL,
  PRIMARY KEY (`auditoria_id`),
  KEY `fk_auditoria_empleado` (`empleado_id`),
  KEY `idx_auditoria_solicitud_fecha` (`solicitud_id`,`fecha_creacion`),
  CONSTRAINT `fk_auditoria_empleado` FOREIGN KEY (`empleado_id`) REFERENCES `usuarios` (`user_identificacion`) ON DELETE RESTRICT,
  CONSTRAINT `fk_auditoria_solicitud` FOREIGN KEY (`solicitud_id`) REFERENCES `solicitudes` (`solicitud_id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- La exportación de datos fue deseleccionada.

-- Volcando estructura para tabla cne_sistema.ciudadanos
CREATE TABLE IF NOT EXISTS `ciudadanos` (
  `ciudadano_identificacion` varchar(20) NOT NULL,
  `ciudadano_nombres` varchar(100) NOT NULL,
  `ciudadano_apellidos` varchar(100) NOT NULL,
  `ciudadano_tipo_identificacion` varchar(20) DEFAULT 'cedula',
  `ciudadano_nacionalidad` varchar(1) DEFAULT 'V' COMMENT 'V=venezolano, E=extranjero',
  `ciudadano_fecha_nacimiento` varchar(32) DEFAULT NULL,
  `ciudadano_genero` varchar(32) DEFAULT NULL,
  `ciudadano_telefono` varchar(20) DEFAULT NULL,
  `ciudadano_email` varchar(255) DEFAULT NULL,
  `ciudadano_direccion` text,
  `estado_id` int DEFAULT NULL,
  `municipio_id` int DEFAULT NULL,
  `institucion_id` int DEFAULT NULL,
  `ciudadano_created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `ciudadano_updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`ciudadano_identificacion`),
  KEY `idx_ciudadano_nombre` (`ciudadano_nombres`,`ciudadano_apellidos`),
  KEY `fk_ciudadanos_estado` (`estado_id`),
  KEY `fk_ciudadanos_municipio` (`municipio_id`),
  KEY `fk_ciudadanos_institucion` (`institucion_id`),
  CONSTRAINT `fk_ciudadanos_estado` FOREIGN KEY (`estado_id`) REFERENCES `estados` (`estado_id`) ON DELETE SET NULL,
  CONSTRAINT `fk_ciudadanos_institucion` FOREIGN KEY (`institucion_id`) REFERENCES `institucion` (`institucion_id`) ON DELETE SET NULL,
  CONSTRAINT `fk_ciudadanos_municipio` FOREIGN KEY (`municipio_id`) REFERENCES `municipios` (`municipio_id`) ON DELETE SET NULL,
  CONSTRAINT `ciudadanos_chk_genero` CHECK ((`ciudadano_genero` is null or `ciudadano_genero` in (_utf8mb4'masculino',_utf8mb4'femenino',_utf8mb4'otro',_utf8mb4'N/A'))),
  CONSTRAINT `ciudadanos_chk_nacionalidad` CHECK ((`ciudadano_nacionalidad` in (_utf8mb4'V',_utf8mb4'E'))),
  CONSTRAINT `ciudadanos_chk_tipo_identificacion` CHECK ((`ciudadano_tipo_identificacion` in (_utf8mb4'cedula',_utf8mb4'pasaporte')))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- La exportación de datos fue deseleccionada.

-- Volcando estructura para tabla cne_sistema.configuracion_sistema
CREATE TABLE IF NOT EXISTS `configuracion_sistema` (
  `configuracion_id` int NOT NULL AUTO_INCREMENT,
  `configuracion_clave` varchar(50) NOT NULL,
  `configuracion_valor` json NOT NULL,
  `configuracion_descripcion` text,
  `configuracion_created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`configuracion_id`),
  UNIQUE KEY `configuracion_clave` (`configuracion_clave`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- La exportación de datos fue deseleccionada.

-- Volcando estructura para tabla cne_sistema.coordinacion
CREATE TABLE IF NOT EXISTS `coordinacion` (
  `coordinacion_id` int NOT NULL AUTO_INCREMENT,
  `coordinacion_nombre` varchar(100) NOT NULL,
  `coordinacion_descripcion` text,
  `coordinacion_estado` varchar(20) DEFAULT 'activo',
  `coordinacion_created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`coordinacion_id`),
  UNIQUE KEY `coordinacion_nombre` (`coordinacion_nombre`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- La exportación de datos fue deseleccionada.

-- Volcando estructura para tabla cne_sistema.detalles_solicitud
CREATE TABLE IF NOT EXISTS `detalles_solicitud` (
  `detalle_id` int NOT NULL AUTO_INCREMENT,
  `solicitud_id` int NOT NULL,
  `detalle_texto` text NOT NULL,
  `creado_por` varchar(20) DEFAULT NULL,
  `detalle_created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`detalle_id`),
  KEY `idx_detalles_solicitud_fecha` (`solicitud_id`,`detalle_created_at`),
  KEY `fk_detalles_creado_por` (`creado_por`),
  CONSTRAINT `fk_detalles_creado_por` FOREIGN KEY (`creado_por`) REFERENCES `usuarios` (`user_identificacion`) ON DELETE SET NULL,
  CONSTRAINT `fk_detalles_solicitud` FOREIGN KEY (`solicitud_id`) REFERENCES `solicitudes` (`solicitud_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- La exportación de datos fue deseleccionada.

-- Volcando estructura para tabla cne_sistema.estados
CREATE TABLE IF NOT EXISTS `estados` (
  `estado_id` int NOT NULL AUTO_INCREMENT,
  `estado_nombre` varchar(100) NOT NULL,
  `estado_created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`estado_id`),
  UNIQUE KEY `estado_nombre` (`estado_nombre`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- La exportación de datos fue deseleccionada.

-- Volcando estructura para tabla cne_sistema.institucion
CREATE TABLE IF NOT EXISTS `institucion` (
  `institucion_id` int NOT NULL AUTO_INCREMENT,
  `institucion_nombre` varchar(255) NOT NULL,
  `institucion_created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`institucion_id`),
  UNIQUE KEY `institucion_nombre` (`institucion_nombre`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- La exportación de datos fue deseleccionada.

-- Volcando estructura para tabla cne_sistema.municipios
CREATE TABLE IF NOT EXISTS `municipios` (
  `municipio_id` int NOT NULL AUTO_INCREMENT,
  `municipio_nombre` varchar(100) NOT NULL,
  `estado_id` int NOT NULL,
  `municipio_created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`municipio_id`),
  UNIQUE KEY `municipio_estado` (`municipio_nombre`,`estado_id`),
  KEY `fk_municipios_estado` (`estado_id`),
  CONSTRAINT `fk_municipios_estado` FOREIGN KEY (`estado_id`) REFERENCES `estados` (`estado_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- La exportación de datos fue deseleccionada.

-- Volcando estructura para tabla cne_sistema.notificaciones
CREATE TABLE IF NOT EXISTS `notificaciones` (
  `notificacion_id` int NOT NULL AUTO_INCREMENT,
  `usuario_id` varchar(20) DEFAULT NULL COMMENT 'Cédula/ID; misma semántica que usuarios.user_identificacion',
  `coordinacion_id` int DEFAULT NULL,
  `destinatario_rol_id` int DEFAULT NULL,
  `solicitud_id` int DEFAULT NULL,
  `notificacion_titulo` varchar(100) NOT NULL,
  `mensaje` text NOT NULL,
  `notificacion_estado` varchar(20) NOT NULL DEFAULT 'no_leido',
  `notificacion_created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`notificacion_id`),
  KEY `fk_notificaciones_usuario` (`usuario_id`),
  KEY `fk_notificaciones_solicitud` (`solicitud_id`),
  KEY `fk_notificaciones_coordinacion` (`coordinacion_id`),
  KEY `fk_notificaciones_rol` (`destinatario_rol_id`),
  CONSTRAINT `fk_notificaciones_usuario` FOREIGN KEY (`usuario_id`) REFERENCES `usuarios` (`user_identificacion`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_notificaciones_coordinacion` FOREIGN KEY (`coordinacion_id`) REFERENCES `coordinacion` (`coordinacion_id`) ON DELETE CASCADE,
  CONSTRAINT `fk_notificaciones_solicitud` FOREIGN KEY (`solicitud_id`) REFERENCES `solicitudes` (`solicitud_id`) ON DELETE CASCADE,
  CONSTRAINT `fk_notificaciones_destinatario_rol` FOREIGN KEY (`destinatario_rol_id`) REFERENCES `roles` (`rol_id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- La exportación de datos fue deseleccionada.

-- Volcando estructura para tabla cne_sistema.permisos
CREATE TABLE IF NOT EXISTS `permisos` (
  `permiso_id` int NOT NULL AUTO_INCREMENT,
  `permiso_codigo` varchar(50) NOT NULL,
  `permiso_nombre` varchar(100) NOT NULL,
  `permiso_descripcion` text,
  `permiso_modulo` varchar(50) DEFAULT NULL,
  `permiso_created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`permiso_id`),
  UNIQUE KEY `permiso_codigo` (`permiso_codigo`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- La exportación de datos fue deseleccionada.

-- Volcando estructura para tabla cne_sistema.requisitos
CREATE TABLE IF NOT EXISTS `requisitos` (
  `requisito_id` int NOT NULL AUTO_INCREMENT,
  `tramite_id` int NOT NULL,
  `requisito_nombre` varchar(600) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci NOT NULL,
  `requisito_activo` tinyint(1) DEFAULT '1',
  `requisito_created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`requisito_id`),
  KEY `fk_requisitos_tramite` (`tramite_id`),
  CONSTRAINT `fk_requisitos_tramite` FOREIGN KEY (`tramite_id`) REFERENCES `tramite` (`tramite_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- La exportación de datos fue deseleccionada.

-- Volcando estructura para tabla cne_sistema.requisitos_solicitud
CREATE TABLE IF NOT EXISTS `requisitos_solicitud` (
  `requisitos_solicitud_id` int NOT NULL AUTO_INCREMENT,
  `solicitud_id` int NOT NULL,
  `requisito_id` int NOT NULL,
  `requisitos_solicitud_status` varchar(20) DEFAULT 'pendiente',
  `requisitos_solicitud_created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `requisitos_solicitud_updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`requisitos_solicitud_id`),
  UNIQUE KEY `requisitos_solicitud_unico` (`solicitud_id`,`requisito_id`),
  KEY `fk_requisitos_solicitud_solicitud` (`solicitud_id`),
  KEY `fk_requisitos_solicitud_requisito` (`requisito_id`),
  CONSTRAINT `fk_requisitos_solicitud_requisito` FOREIGN KEY (`requisito_id`) REFERENCES `requisitos` (`requisito_id`) ON DELETE CASCADE,
  CONSTRAINT `fk_requisitos_solicitud_solicitud` FOREIGN KEY (`solicitud_id`) REFERENCES `solicitudes` (`solicitud_id`) ON DELETE CASCADE,
  CONSTRAINT `requisitos_solicitud_chk_status` CHECK ((`requisitos_solicitud_status` in (_utf8mb4'pendiente',_utf8mb4'aprobado',_utf8mb4'rechazado',_utf8mb4'en_revision')))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- La exportación de datos fue deseleccionada.

-- Volcando estructura para tabla cne_sistema.roles
CREATE TABLE IF NOT EXISTS `roles` (
  `rol_id` int NOT NULL AUTO_INCREMENT,
  `rol_nombre` varchar(50) NOT NULL,
  `rol_descripcion` text,
  `rol_created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`rol_id`),
  UNIQUE KEY `rol_nombre` (`rol_nombre`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- La exportación de datos fue deseleccionada.

-- Volcando estructura para tabla cne_sistema.rol_permisos
CREATE TABLE IF NOT EXISTS `rol_permisos` (
  `rol_permiso_id` int NOT NULL AUTO_INCREMENT,
  `rol_id` int NOT NULL,
  `permiso_id` int NOT NULL,
  `rol_permiso_created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`rol_permiso_id`),
  UNIQUE KEY `rol_permiso` (`rol_id`,`permiso_id`),
  KEY `fk_rol_permisos_permiso` (`permiso_id`),
  CONSTRAINT `fk_rol_permisos_permiso` FOREIGN KEY (`permiso_id`) REFERENCES `permisos` (`permiso_id`) ON DELETE CASCADE,
  CONSTRAINT `fk_rol_permisos_rol` FOREIGN KEY (`rol_id`) REFERENCES `roles` (`rol_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- La exportación de datos fue deseleccionada.

-- Volcando estructura para tabla cne_sistema.sesiones_activas
CREATE TABLE IF NOT EXISTS `sesiones_activas` (
  `sesion_id` int NOT NULL AUTO_INCREMENT,
  `usuario_id` varchar(20) NOT NULL,
  `sesion_token` varchar(255) NOT NULL,
  `sesion_ip_address` varchar(45) DEFAULT NULL,
  `sesion_user_agent` text,
  `sesion_ultima_actividad` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `sesion_created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`sesion_id`),
  UNIQUE KEY `sesion_usuario_id` (`usuario_id`),
  KEY `idx_sesiones_actividad` (`sesion_ultima_actividad`),
  CONSTRAINT `fk_sesiones_activas_usuario` FOREIGN KEY (`usuario_id`) REFERENCES `usuarios` (`user_identificacion`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- La exportación de datos fue deseleccionada.

-- Volcando estructura para tabla cne_sistema.solicitudes
CREATE TABLE IF NOT EXISTS `solicitudes` (
  `solicitud_id` int NOT NULL AUTO_INCREMENT,
  `solicitud_numero` varchar(8) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci NOT NULL,
  `codigo_interno` varchar(50) DEFAULT NULL,
  `ciudadano_identificacion` varchar(20) NOT NULL,
  `tramite_id` int NOT NULL,
  `coordinacion_actual_id` int DEFAULT NULL COMMENT 'Coordinación actual del trámite (p. ej. tras redirección); el tramite_id sigue definiendo el tipo',
  `solicitud_descripcion` text,
  `solicitud_estado` varchar(20) DEFAULT 'pendiente' COMMENT 'Códigos: pendiente, en_revision (UI En Proceso), aprobada, rechazada, completada, redirigida, vencida',
  `solicitud_fecha_solicitud` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `solicitud_fecha_limite` date DEFAULT NULL COMMENT 'Opcional: plazo/SLA (reservado)',
  `empleado_asignado_id` varchar(20) DEFAULT NULL,
  `created_by` varchar(20) NOT NULL,
  `solicitud_created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `solicitud_updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `solicitud_fecha_completada` datetime DEFAULT NULL,
  PRIMARY KEY (`solicitud_id`),
  UNIQUE KEY `solicitud_numero` (`solicitud_numero`),
  KEY `fk_solicitudes_ciudadano` (`ciudadano_identificacion`),
  KEY `fk_solicitudes_tramite` (`tramite_id`),
  KEY `fk_solicitudes_coord_actual` (`coordinacion_actual_id`),
  KEY `fk_solicitudes_empleado_asignado` (`empleado_asignado_id`),
  KEY `fk_solicitudes_created_by` (`created_by`),
  KEY `idx_solicitudes_estado` (`solicitud_estado`),
  CONSTRAINT `fk_solicitudes_ciudadano` FOREIGN KEY (`ciudadano_identificacion`) REFERENCES `ciudadanos` (`ciudadano_identificacion`) ON DELETE CASCADE,
  CONSTRAINT `fk_solicitudes_created_by` FOREIGN KEY (`created_by`) REFERENCES `usuarios` (`user_identificacion`),
  CONSTRAINT `fk_solicitudes_empleado_asignado` FOREIGN KEY (`empleado_asignado_id`) REFERENCES `usuarios` (`user_identificacion`) ON DELETE SET NULL,
  CONSTRAINT `fk_solicitudes_tramite` FOREIGN KEY (`tramite_id`) REFERENCES `tramite` (`tramite_id`),
  CONSTRAINT `fk_solicitudes_coord_actual` FOREIGN KEY (`coordinacion_actual_id`) REFERENCES `coordinacion` (`coordinacion_id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `solicitudes_chk_estado` CHECK ((`solicitud_estado` in (_utf8mb4'pendiente',_utf8mb4'en_revision',_utf8mb4'aprobada',_utf8mb4'rechazada',_utf8mb4'completada',_utf8mb4'redirigida',_utf8mb4'vencida')))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- La exportación de datos fue deseleccionada.

-- Volcando estructura para tabla cne_sistema.tramite
CREATE TABLE IF NOT EXISTS `tramite` (
  `tramite_id` int NOT NULL AUTO_INCREMENT,
  `tramite_nombre` varchar(100) NOT NULL,
  `coordinacion_id` int NOT NULL,
  `tramite_padre_id` int DEFAULT NULL,
  `tramite_created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`tramite_id`),
  UNIQUE KEY `tramite_nombre_area` (`tramite_nombre`,`coordinacion_id`),
  KEY `fk_tramite_coordinacion` (`coordinacion_id`),
  KEY `fk_tramite_padre` (`tramite_padre_id`),
  CONSTRAINT `fk_tramite_coordinacion` FOREIGN KEY (`coordinacion_id`) REFERENCES `coordinacion` (`coordinacion_id`) ON DELETE CASCADE,
  CONSTRAINT `fk_tramite_padre` FOREIGN KEY (`tramite_padre_id`) REFERENCES `tramite` (`tramite_id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- La exportación de datos fue deseleccionada.

-- Volcando estructura para tabla cne_sistema.usuarios
CREATE TABLE IF NOT EXISTS `usuarios` (
  `user_identificacion` varchar(20) NOT NULL,
  `user_username` varchar(50) NOT NULL,
  `user_password_hash` varchar(255) NOT NULL,
  `user_nombres` varchar(100) NOT NULL,
  `user_apellidos` varchar(100) NOT NULL,
  `rol_id` int NOT NULL,
  `coordinacion_id` int DEFAULT NULL,
  `user_estado` varchar(20) DEFAULT 'activo',
  `user_ultima_conexion` datetime DEFAULT NULL,
  `ultima_actividad` datetime DEFAULT NULL,
  `user_created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`user_identificacion`),
  UNIQUE KEY `user_username` (`user_username`),
  KEY `fk_usuarios_rol` (`rol_id`),
  KEY `fk_usuarios_coordinacion` (`coordinacion_id`),
  CONSTRAINT `fk_usuarios_coordinacion` FOREIGN KEY (`coordinacion_id`) REFERENCES `coordinacion` (`coordinacion_id`) ON DELETE SET NULL,
  CONSTRAINT `fk_usuarios_rol` FOREIGN KEY (`rol_id`) REFERENCES `roles` (`rol_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- La exportación de datos fue deseleccionada.

-- =============================================================================
-- Migración: tabla ciudadanos (género y fecha de nacimiento para registro parcial)
-- Útil al actualizar una base creada con un volcado antiguo (CHECK sin N/A, género
-- varchar(10), fecha_nacimiento como DATE).
-- Si algún ALTER falla con "check constraint does not exist", omita el DROP o use
-- DROP CONSTRAINT en MariaDB según el mensaje del motor.
-- =============================================================================

ALTER TABLE `ciudadanos` MODIFY COLUMN `ciudadano_genero` VARCHAR(32) NULL DEFAULT NULL;

ALTER TABLE `ciudadanos` DROP CHECK `ciudadanos_chk_genero`;

ALTER TABLE `ciudadanos` ADD CONSTRAINT `ciudadanos_chk_genero` CHECK (`ciudadano_genero` IS NULL OR `ciudadano_genero` IN ('masculino','femenino','otro','N/A'));

ALTER TABLE `ciudadanos` MODIFY COLUMN `ciudadano_fecha_nacimiento` VARCHAR(32) NULL DEFAULT NULL;

/*!40103 SET TIME_ZONE=IFNULL(@OLD_TIME_ZONE, 'system') */;
/*!40101 SET SQL_MODE=IFNULL(@OLD_SQL_MODE, '') */;
/*!40014 SET FOREIGN_KEY_CHECKS=IFNULL(@OLD_FOREIGN_KEY_CHECKS, 1) */;
/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40111 SET SQL_NOTES=IFNULL(@OLD_SQL_NOTES, 1) */;
