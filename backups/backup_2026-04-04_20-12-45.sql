-- MySQL dump 10.13  Distrib 8.4.3, for Win64 (x86_64)
--
-- Host: localhost    Database: cne_sistema
-- ------------------------------------------------------
-- Server version	8.4.3

/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!50503 SET NAMES utf8mb4 */;
/*!40103 SET @OLD_TIME_ZONE=@@TIME_ZONE */;
/*!40103 SET TIME_ZONE='+00:00' */;
/*!40014 SET @OLD_UNIQUE_CHECKS=@@UNIQUE_CHECKS, UNIQUE_CHECKS=0 */;
/*!40014 SET @OLD_FOREIGN_KEY_CHECKS=@@FOREIGN_KEY_CHECKS, FOREIGN_KEY_CHECKS=0 */;
/*!40101 SET @OLD_SQL_MODE=@@SQL_MODE, SQL_MODE='NO_AUTO_VALUE_ON_ZERO' */;
/*!40111 SET @OLD_SQL_NOTES=@@SQL_NOTES, SQL_NOTES=0 */;

--
-- Table structure for table `auditoria`
--

DROP TABLE IF EXISTS `auditoria`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `auditoria` (
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
) ENGINE=InnoDB AUTO_INCREMENT=4 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `auditoria`
--

LOCK TABLES `auditoria` WRITE;
/*!40000 ALTER TABLE `auditoria` DISABLE KEYS */;
INSERT INTO `auditoria` VALUES (1,'V-12312313',1,'SOLICITUD_CREADA','Solicitud creada por la Oficina de Atención al Ciudadano','2026-04-03 20:49:59','{\"ciudadano\": \"V-31584677\", \"tramite_id\": \"21\", \"estado_inicial\": \"pendiente\", \"tipo_solicitud\": \"normal\", \"usuario_creador\": \"V-12312313\", \"numero_seguimiento\": \"CNE-0001\"}'),(2,'V-19187197',1,'REDIRECCION','Trámite redirigido desde Registro Civil hacia Registro Electoral. Motivo: Redirigir a Registro Electoral','2026-04-03 20:50:43','{\"observaciones\": \"Redirigir a Registro Electoral\", \"codigo_interno\": \"\", \"tramite_nombre\": \"Nacimientos ocurridos fuera de establecimientos de salud (Extra hospitalarios)\", \"solicitud_numero\": \"CNE-0001\", \"coordinacion_origen\": \"Registro Civil\", \"coordinacion_destino\": \"Registro Electoral\"}'),(3,'V-16644767',1,'REDIRECCION','Trámite redirigido desde Registro Civil hacia Registro Civil. Motivo: adadsadasdasd','2026-04-03 21:03:26','{\"observaciones\": \"adadsadasdasd\", \"codigo_interno\": \"\", \"tramite_nombre\": \"Nacimientos ocurridos fuera de establecimientos de salud (Extra hospitalarios)\", \"solicitud_numero\": \"CNE-0001\", \"coordinacion_origen\": \"Registro Civil\", \"coordinacion_destino\": \"Registro Civil\"}');
/*!40000 ALTER TABLE `auditoria` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `ciudadanos`
--

DROP TABLE IF EXISTS `ciudadanos`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `ciudadanos` (
  `ciudadano_identificacion` varchar(20) NOT NULL,
  `ciudadano_nombres` varchar(100) NOT NULL,
  `ciudadano_apellidos` varchar(100) NOT NULL,
  `ciudadano_tipo_identificacion` varchar(20) DEFAULT 'cedula',
  `ciudadano_nacionalidad` varchar(1) DEFAULT 'V' COMMENT 'V=venezolano, E=extranjero',
  `ciudadano_fecha_nacimiento` date DEFAULT NULL,
  `ciudadano_genero` varchar(10) DEFAULT NULL,
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
  CONSTRAINT `ciudadanos_chk_genero` CHECK ((`ciudadano_genero` in (_utf8mb4'masculino',_utf8mb4'femenino',_utf8mb4'otro'))),
  CONSTRAINT `ciudadanos_chk_nacionalidad` CHECK ((`ciudadano_nacionalidad` in (_utf8mb4'V',_utf8mb4'E'))),
  CONSTRAINT `ciudadanos_chk_tipo_identificacion` CHECK ((`ciudadano_tipo_identificacion` in (_utf8mb4'cedula',_utf8mb4'pasaporte')))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `ciudadanos`
--

LOCK TABLES `ciudadanos` WRITE;
/*!40000 ALTER TABLE `ciudadanos` DISABLE KEYS */;
INSERT INTO `ciudadanos` VALUES ('V-31584677','Roberto Carlos','Roberto Vázquez','cedula','V','2026-02-26','masculino','0412-4353363','rcvazquezantelo2006@gmail.com','Fundaguanare',18,225,1,'2026-04-03 20:49:59','2026-04-03 20:49:59');
/*!40000 ALTER TABLE `ciudadanos` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `configuracion_sistema`
--

DROP TABLE IF EXISTS `configuracion_sistema`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `configuracion_sistema` (
  `configuracion_id` int NOT NULL AUTO_INCREMENT,
  `configuracion_clave` varchar(50) NOT NULL,
  `configuracion_valor` json NOT NULL,
  `configuracion_descripcion` text,
  `configuracion_created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`configuracion_id`),
  UNIQUE KEY `configuracion_clave` (`configuracion_clave`)
) ENGINE=InnoDB AUTO_INCREMENT=3 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `configuracion_sistema`
--

LOCK TABLES `configuracion_sistema` WRITE;
/*!40000 ALTER TABLE `configuracion_sistema` DISABLE KEYS */;
INSERT INTO `configuracion_sistema` VALUES (1,'backup_historial','[{\"fecha\": \"2026-04-04 19:55:28\", \"estado\": \"Completado\", \"archivo\": \"backup_2026-04-04_19-55-27.sql\", \"tamanio\": \"78.5 KB\"}]','Configuración de backup_historial','2026-04-04 23:54:07'),(2,'respaldo_automatico','{\"hora\": \"20:12\", \"activado\": true, \"ultimo_auto_slot\": null, \"config_actualizado_en\": \"2026-04-04 20:11:05\", \"ultimo_respaldo_fecha\": null}','Configuración de respaldo_automatico','2026-04-04 23:54:20');
/*!40000 ALTER TABLE `configuracion_sistema` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `coordinacion`
--

DROP TABLE IF EXISTS `coordinacion`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `coordinacion` (
  `coordinacion_id` int NOT NULL AUTO_INCREMENT,
  `coordinacion_nombre` varchar(100) NOT NULL,
  `coordinacion_descripcion` text,
  `coordinacion_estado` varchar(20) DEFAULT 'activo',
  `coordinacion_created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`coordinacion_id`),
  UNIQUE KEY `coordinacion_nombre` (`coordinacion_nombre`)
) ENGINE=InnoDB AUTO_INCREMENT=6 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `coordinacion`
--

LOCK TABLES `coordinacion` WRITE;
/*!40000 ALTER TABLE `coordinacion` DISABLE KEYS */;
INSERT INTO `coordinacion` VALUES (1,'Oficina de Atención al Ciudadano','Oficina de Atención al Ciudadano','activo','2026-01-25 20:18:02'),(2,'Registro Electoral','Registro Electoral','activo','2026-01-25 20:18:02'),(3,'Registro Civil','Registro Civil','activo','2026-01-25 20:18:02'),(4,'Secretaría','Secretaría','activo','2026-01-25 20:18:02'),(5,'COPAFI','COPAFI','activo','2026-01-25 20:18:02');
/*!40000 ALTER TABLE `coordinacion` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `detalles_solicitud`
--

DROP TABLE IF EXISTS `detalles_solicitud`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `detalles_solicitud` (
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
) ENGINE=InnoDB AUTO_INCREMENT=3 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `detalles_solicitud`
--

LOCK TABLES `detalles_solicitud` WRITE;
/*!40000 ALTER TABLE `detalles_solicitud` DISABLE KEYS */;
INSERT INTO `detalles_solicitud` VALUES (1,1,'Redirigir a Registro Electoral','V-19187197','2026-04-03 20:50:43'),(2,1,'adadsadasdasd','V-16644767','2026-04-03 21:03:26');
/*!40000 ALTER TABLE `detalles_solicitud` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `estados`
--

DROP TABLE IF EXISTS `estados`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `estados` (
  `estado_id` int NOT NULL AUTO_INCREMENT,
  `estado_nombre` varchar(100) NOT NULL,
  `estado_created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`estado_id`),
  UNIQUE KEY `estado_nombre` (`estado_nombre`)
) ENGINE=InnoDB AUTO_INCREMENT=25 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `estados`
--

LOCK TABLES `estados` WRITE;
/*!40000 ALTER TABLE `estados` DISABLE KEYS */;
INSERT INTO `estados` VALUES (1,'Amazonas','2026-01-27 04:00:00'),(2,'Anzoátegui','2026-01-27 04:00:00'),(3,'Apure','2026-01-27 04:00:00'),(4,'Aragua','2026-01-27 04:00:00'),(5,'Barinas','2026-01-27 04:00:00'),(6,'Bolívar','2026-01-27 04:00:00'),(7,'Carabobo','2026-01-27 04:00:00'),(8,'Cojedes','2026-01-27 04:00:00'),(9,'Delta Amacuro','2026-01-27 04:00:00'),(10,'Distrito Capital','2026-01-27 04:00:00'),(11,'Falcón','2026-01-27 04:00:00'),(12,'Guárico','2026-01-27 04:00:00'),(13,'Lara','2026-01-27 04:00:00'),(14,'Mérida','2026-01-27 04:00:00'),(15,'Miranda','2026-01-27 04:00:00'),(16,'Monagas','2026-01-27 04:00:00'),(17,'Nueva Esparta','2026-01-27 04:00:00'),(18,'Portuguesa','2026-01-27 04:00:00'),(19,'Sucre','2026-01-27 04:00:00'),(20,'Táchira','2026-01-27 04:00:00'),(21,'Trujillo','2026-01-27 04:00:00'),(22,'Vargas','2026-01-27 04:00:00'),(23,'Yaracuy','2026-01-27 04:00:00'),(24,'Zulia','2026-01-27 04:00:00');
/*!40000 ALTER TABLE `estados` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `institucion`
--

DROP TABLE IF EXISTS `institucion`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `institucion` (
  `institucion_id` int NOT NULL AUTO_INCREMENT,
  `institucion_nombre` varchar(255) NOT NULL,
  `institucion_created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`institucion_id`),
  UNIQUE KEY `institucion_nombre` (`institucion_nombre`)
) ENGINE=InnoDB AUTO_INCREMENT=2 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `institucion`
--

LOCK TABLES `institucion` WRITE;
/*!40000 ALTER TABLE `institucion` DISABLE KEYS */;
INSERT INTO `institucion` VALUES (1,'Personal','2026-02-06 06:44:29');
/*!40000 ALTER TABLE `institucion` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `municipios`
--

DROP TABLE IF EXISTS `municipios`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `municipios` (
  `municipio_id` int NOT NULL AUTO_INCREMENT,
  `municipio_nombre` varchar(100) NOT NULL,
  `estado_id` int NOT NULL,
  `municipio_created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`municipio_id`),
  UNIQUE KEY `municipio_estado` (`municipio_nombre`,`estado_id`),
  KEY `fk_municipios_estado` (`estado_id`),
  CONSTRAINT `fk_municipios_estado` FOREIGN KEY (`estado_id`) REFERENCES `estados` (`estado_id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=336 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `municipios`
--

LOCK TABLES `municipios` WRITE;
/*!40000 ALTER TABLE `municipios` DISABLE KEYS */;
INSERT INTO `municipios` VALUES (1,'Alto Orinoco',1,'2026-02-27 16:20:55'),(2,'Atabapo',1,'2026-02-27 16:21:17'),(3,'Atures',1,'2026-02-27 16:21:17'),(4,'Autana',1,'2026-02-27 16:21:17'),(5,'Manapiare',1,'2026-02-27 16:21:17'),(6,'Maroa',1,'2026-02-27 16:21:17'),(7,'Río Negro',1,'2026-02-27 16:21:17'),(8,'Anaco',2,'2026-02-27 16:22:00'),(9,'Aragua',2,'2026-02-27 16:22:00'),(10,'Bolívar',2,'2026-02-27 16:22:00'),(11,'Bruzual',2,'2026-02-27 16:22:00'),(12,'Cajigal',2,'2026-02-27 16:22:00'),(13,'Carvajal',2,'2026-02-27 16:22:00'),(14,'Diego Bautista Urbaneja',2,'2026-02-27 16:22:00'),(15,'Freites',2,'2026-02-27 16:22:00'),(16,'Guanipa',2,'2026-02-27 16:22:00'),(17,'Guanta',2,'2026-02-27 16:22:00'),(18,'Independencia',2,'2026-02-27 16:22:00'),(19,'Libertad',2,'2026-02-27 16:22:00'),(20,'McGregor',2,'2026-02-27 16:22:00'),(21,'Miranda',2,'2026-02-27 16:22:00'),(22,'Monagas',2,'2026-02-27 16:22:00'),(23,'Peñalver',2,'2026-02-27 16:22:00'),(24,'Píritu',2,'2026-02-27 16:22:00'),(25,'San Juan de Capistrano',2,'2026-02-27 16:22:00'),(26,'Santa Ana',2,'2026-02-27 16:22:00'),(27,'Simón Rodríguez',2,'2026-02-27 16:22:00'),(28,'Sotillo',2,'2026-02-27 16:22:00'),(29,'Achaguas',3,'2026-02-27 16:23:30'),(30,'Biruaca',3,'2026-02-27 16:23:30'),(31,'Muñoz',3,'2026-02-27 16:23:30'),(32,'Páez',3,'2026-02-27 16:23:30'),(33,'Pedro Camejo',3,'2026-02-27 16:23:30'),(34,'Rómulo Gallegos',3,'2026-02-27 16:23:30'),(35,'San Fernando',3,'2026-02-27 16:23:30'),(36,'Bolívar',4,'2026-02-27 16:24:07'),(37,'Camatagua',4,'2026-02-27 16:24:07'),(38,'Francisco Linares Alcántara',4,'2026-02-27 16:24:07'),(39,'Girardot',4,'2026-02-27 16:24:07'),(40,'José Ángel Lamas',4,'2026-02-27 16:24:07'),(41,'José Félix Ribas',4,'2026-02-27 16:24:07'),(42,'José Rafael Revenga',4,'2026-02-27 16:24:07'),(43,'Libertador',4,'2026-02-27 16:24:07'),(44,'Mario Briceño Iragorry',4,'2026-02-27 16:24:07'),(45,'Ocumare de la Costa de Oro',4,'2026-02-27 16:24:07'),(46,'San Casimiro',4,'2026-02-27 16:24:07'),(47,'San Sebastián',4,'2026-02-27 16:24:07'),(48,'Santiago Mariño',4,'2026-02-27 16:24:07'),(49,'Santos Michelena',4,'2026-02-27 16:24:07'),(50,'Sucre',4,'2026-02-27 16:24:07'),(51,'Tovar',4,'2026-02-27 16:24:07'),(52,'Urdaneta',4,'2026-02-27 16:24:07'),(53,'Zamora',4,'2026-02-27 16:24:07'),(54,'Alberto Arvelo Torrealba',5,'2026-02-27 16:27:13'),(55,'Andrés Eloy Blanco',5,'2026-02-27 16:27:13'),(56,'Antonio José de Sucre',5,'2026-02-27 16:27:13'),(57,'Arismendi',5,'2026-02-27 16:27:13'),(58,'Barinas',5,'2026-02-27 16:27:13'),(59,'Bolívar',5,'2026-02-27 16:27:13'),(60,'Cruz Paredes',5,'2026-02-27 16:27:13'),(61,'Ezequiel Zamora',5,'2026-02-27 16:27:13'),(62,'Obispos',5,'2026-02-27 16:27:13'),(63,'Pedraza',5,'2026-02-27 16:27:13'),(64,'Rojas',5,'2026-02-27 16:27:13'),(65,'Sosa',5,'2026-02-27 16:27:13'),(66,'Angostura',6,'2026-02-27 16:27:54'),(67,'Angostura del Orinoco',6,'2026-02-27 16:27:54'),(68,'Caroní',6,'2026-02-27 16:27:54'),(69,'Cedeño',6,'2026-02-27 16:27:54'),(70,'El Callao',6,'2026-02-27 16:27:54'),(71,'Gran Sabana',6,'2026-02-27 16:27:54'),(72,'Padre Pedro Chien',6,'2026-02-27 16:27:54'),(73,'Piar',6,'2026-02-27 16:27:54'),(74,'Roscio',6,'2026-02-27 16:27:54'),(75,'Sifontes',6,'2026-02-27 16:27:54'),(76,'Sucre',6,'2026-02-27 16:27:54'),(77,'Bejuma',7,'2026-02-27 16:28:43'),(78,'Carlos Arvelo',7,'2026-02-27 16:28:43'),(79,'Diego Ibarra',7,'2026-02-27 16:28:43'),(80,'Guacara',7,'2026-02-27 16:28:43'),(81,'Juan José Mora',7,'2026-02-27 16:28:43'),(82,'Libertador',7,'2026-02-27 16:28:43'),(83,'Los Guayos',7,'2026-02-27 16:28:43'),(84,'Miranda',7,'2026-02-27 16:28:43'),(85,'Montalbán',7,'2026-02-27 16:28:43'),(86,'Naguanagua',7,'2026-02-27 16:28:43'),(87,'Puerto Cabello',7,'2026-02-27 16:28:43'),(88,'San Diego',7,'2026-02-27 16:28:43'),(89,'San Joaquín',7,'2026-02-27 16:28:43'),(90,'Valencia',7,'2026-02-27 16:28:43'),(91,'Anzoátegui',8,'2026-02-27 16:29:10'),(92,'Falcón',8,'2026-02-27 16:29:10'),(93,'Girardot',8,'2026-02-27 16:29:10'),(94,'Lima Blanco',8,'2026-02-27 16:29:10'),(95,'Pao de San Juan Bautista',8,'2026-02-27 16:29:10'),(96,'Ricaurte',8,'2026-02-27 16:29:10'),(97,'Rómulo Gallegos',8,'2026-02-27 16:29:10'),(98,'San Carlos',8,'2026-02-27 16:29:10'),(99,'Tinaco',8,'2026-02-27 16:29:10'),(100,'Antonio Díaz',9,'2026-02-27 16:29:31'),(101,'Casacoima',9,'2026-02-27 16:29:31'),(102,'Pedernales',9,'2026-02-27 16:29:31'),(103,'Tucupita',9,'2026-02-27 16:29:31'),(104,'Libertador',10,'2026-02-27 16:29:56'),(105,'Acosta',11,'2026-02-27 16:30:18'),(106,'Bolívar',11,'2026-02-27 16:30:18'),(107,'Buchivacoa',11,'2026-02-27 16:30:18'),(108,'Cacique Manaure',11,'2026-02-27 16:30:18'),(109,'Carirubana',11,'2026-02-27 16:30:18'),(110,'Colina',11,'2026-02-27 16:30:18'),(111,'Dabajuro',11,'2026-02-27 16:30:18'),(112,'Democracia',11,'2026-02-27 16:30:18'),(113,'Falcón',11,'2026-02-27 16:30:18'),(114,'Federación',11,'2026-02-27 16:30:18'),(115,'Jacura',11,'2026-02-27 16:30:18'),(116,'Los Taques',11,'2026-02-27 16:30:18'),(117,'Mauroa',11,'2026-02-27 16:30:18'),(118,'Miranda',11,'2026-02-27 16:30:18'),(119,'Monseñor Iturriza',11,'2026-02-27 16:30:18'),(120,'Palmasola',11,'2026-02-27 16:30:18'),(121,'Petit',11,'2026-02-27 16:30:18'),(122,'Píritu',11,'2026-02-27 16:30:18'),(123,'San Francisco',11,'2026-02-27 16:30:18'),(124,'Silva',11,'2026-02-27 16:30:18'),(125,'Sucre',11,'2026-02-27 16:30:18'),(126,'Tocópero',11,'2026-02-27 16:30:18'),(127,'Unión',11,'2026-02-27 16:30:18'),(128,'Urumaco',11,'2026-02-27 16:30:18'),(129,'Zamora',11,'2026-02-27 16:30:18'),(130,'Camaguán',12,'2026-02-27 16:30:42'),(131,'Chaguaramas',12,'2026-02-27 16:30:42'),(132,'El Socorro',12,'2026-02-27 16:30:42'),(133,'Francisco de Miranda',12,'2026-02-27 16:30:42'),(134,'José Félix Ribas',12,'2026-02-27 16:30:42'),(135,'José Tadeo Monagas',12,'2026-02-27 16:30:42'),(136,'Juan Germán Roscio',12,'2026-02-27 16:30:42'),(137,'Julián Mellado',12,'2026-02-27 16:30:42'),(138,'Las Mercedes',12,'2026-02-27 16:30:42'),(139,'Leonardo Infante',12,'2026-02-27 16:30:42'),(140,'Ortiz',12,'2026-02-27 16:30:42'),(141,'Pedro Zaraza',12,'2026-02-27 16:30:42'),(142,'San Gerónimo de Guayabal',12,'2026-02-27 16:30:42'),(143,'San José de Guaribe',12,'2026-02-27 16:30:42'),(144,'Santa María de Ipire',12,'2026-02-27 16:30:42'),(145,'Andrés Eloy Blanco',13,'2026-02-27 16:31:33'),(146,'Crespo',13,'2026-02-27 16:31:33'),(147,'Iribarren',13,'2026-02-27 16:31:33'),(148,'Jiménez',13,'2026-02-27 16:31:33'),(149,'Morán',13,'2026-02-27 16:31:33'),(150,'Palavecino',13,'2026-02-27 16:31:33'),(151,'Simón Planas',13,'2026-02-27 16:31:33'),(152,'Torres',13,'2026-02-27 16:31:33'),(153,'Urdaneta',13,'2026-02-27 16:31:33'),(154,'Alberto Adriani',14,'2026-02-27 16:32:00'),(155,'Andrés Bello',14,'2026-02-27 16:32:00'),(156,'Antonio Pinto Salinas',14,'2026-02-27 16:32:00'),(157,'Aricagua',14,'2026-02-27 16:32:00'),(158,'Arzobispo Chacón',14,'2026-02-27 16:32:00'),(159,'Campo Elías',14,'2026-02-27 16:32:00'),(160,'Caracciolo Parra Olmedo',14,'2026-02-27 16:32:00'),(161,'Cardenal Quintero',14,'2026-02-27 16:32:00'),(162,'Guaraque',14,'2026-02-27 16:32:00'),(163,'Julio César Salas',14,'2026-02-27 16:32:00'),(164,'Justo Briceño',14,'2026-02-27 16:32:00'),(165,'Libertador',14,'2026-02-27 16:32:00'),(166,'Miranda',14,'2026-02-27 16:32:00'),(167,'Obispo Ramos de Lora',14,'2026-02-27 16:32:00'),(168,'Padre Noguera',14,'2026-02-27 16:32:00'),(169,'Pueblo Llano',14,'2026-02-27 16:32:00'),(170,'Rangel',14,'2026-02-27 16:32:00'),(171,'Rivas Dávila',14,'2026-02-27 16:32:00'),(172,'Santos Marquina',14,'2026-02-27 16:32:00'),(173,'Sucre',14,'2026-02-27 16:32:00'),(174,'Tovar',14,'2026-02-27 16:32:00'),(175,'Tulio Febres Cordero',14,'2026-02-27 16:32:00'),(176,'Zea',14,'2026-02-27 16:32:00'),(177,'Acevedo',15,'2026-02-27 16:32:35'),(178,'Andrés Bello',15,'2026-02-27 16:32:35'),(179,'Baruta',15,'2026-02-27 16:32:35'),(180,'Brión',15,'2026-02-27 16:32:35'),(181,'Buroz',15,'2026-02-27 16:32:35'),(182,'Carrizal',15,'2026-02-27 16:32:35'),(183,'Chacao',15,'2026-02-27 16:32:35'),(184,'Cristóbal Rojas',15,'2026-02-27 16:32:35'),(185,'El Hatillo',15,'2026-02-27 16:32:35'),(186,'Guaicaipuro',15,'2026-02-27 16:32:35'),(187,'Independencia',15,'2026-02-27 16:32:35'),(188,'Lander',15,'2026-02-27 16:32:35'),(189,'Los Salias',15,'2026-02-27 16:32:35'),(190,'Páez',15,'2026-02-27 16:32:35'),(191,'Paz Castillo',15,'2026-02-27 16:32:35'),(192,'Pedro Gual',15,'2026-02-27 16:32:35'),(193,'Plaza',15,'2026-02-27 16:32:35'),(194,'Simón Bolívar',15,'2026-02-27 16:32:35'),(195,'Sucre',15,'2026-02-27 16:32:35'),(196,'Urdaneta',15,'2026-02-27 16:32:35'),(197,'Zamora',15,'2026-02-27 16:32:35'),(198,'Acosta',16,'2026-02-27 16:33:00'),(199,'Aguasay',16,'2026-02-27 16:33:00'),(200,'Bolívar',16,'2026-02-27 16:33:00'),(201,'Caripe',16,'2026-02-27 16:33:00'),(202,'Cedeño',16,'2026-02-27 16:33:00'),(203,'Ezequiel Zamora',16,'2026-02-27 16:33:00'),(204,'Libertador',16,'2026-02-27 16:33:00'),(205,'Maturín',16,'2026-02-27 16:33:00'),(206,'Piar',16,'2026-02-27 16:33:00'),(207,'Punceres',16,'2026-02-27 16:33:00'),(208,'Santa Bárbara',16,'2026-02-27 16:33:00'),(209,'Sotillo',16,'2026-02-27 16:33:00'),(210,'Uracoa',16,'2026-02-27 16:33:00'),(211,'Antolín del Campo',17,'2026-02-27 16:33:21'),(212,'Arismendi',17,'2026-02-27 16:33:21'),(213,'Díaz',17,'2026-02-27 16:33:21'),(214,'García',17,'2026-02-27 16:33:21'),(215,'Gómez',17,'2026-02-27 16:33:21'),(216,'Maneiro',17,'2026-02-27 16:33:21'),(217,'Marcano',17,'2026-02-27 16:33:21'),(218,'Mariño',17,'2026-02-27 16:33:21'),(219,'Península de Macanao',17,'2026-02-27 16:33:21'),(220,'Tubores',17,'2026-02-27 16:33:21'),(221,'Villalba',17,'2026-02-27 16:33:21'),(222,'Agua Blanca',18,'2026-02-27 16:33:46'),(223,'Araure',18,'2026-02-27 16:33:46'),(224,'Esteller',18,'2026-02-27 16:33:46'),(225,'Guanare',18,'2026-02-27 16:33:46'),(226,'Guanarito',18,'2026-02-27 16:33:46'),(227,'Monseñor José Vicente de Unda',18,'2026-02-27 16:33:46'),(228,'Ospino',18,'2026-02-27 16:33:46'),(229,'Páez',18,'2026-02-27 16:33:46'),(230,'Papelón',18,'2026-02-27 16:33:46'),(231,'San Genaro de Boconoíto',18,'2026-02-27 16:33:46'),(232,'San Rafael de Onoto',18,'2026-02-27 16:33:46'),(233,'Santa Rosalía',18,'2026-02-27 16:33:46'),(234,'Sucre',18,'2026-02-27 16:33:46'),(235,'Turén',18,'2026-02-27 16:33:46'),(236,'Andrés Eloy Blanco',19,'2026-02-27 16:34:07'),(237,'Andrés Mata',19,'2026-02-27 16:34:07'),(238,'Arismendi',19,'2026-02-27 16:34:07'),(239,'Benítez',19,'2026-02-27 16:34:07'),(240,'Bermúdez',19,'2026-02-27 16:34:07'),(241,'Bolívar',19,'2026-02-27 16:34:07'),(242,'Cajigal',19,'2026-02-27 16:34:07'),(243,'Cruz Salmerón Acosta',19,'2026-02-27 16:34:07'),(244,'Libertador',19,'2026-02-27 16:34:07'),(245,'Mariño',19,'2026-02-27 16:34:07'),(246,'Mejía',19,'2026-02-27 16:34:07'),(247,'Montes',19,'2026-02-27 16:34:07'),(248,'Ribero',19,'2026-02-27 16:34:07'),(249,'Sucre',19,'2026-02-27 16:34:07'),(250,'Valdez',19,'2026-02-27 16:34:07'),(251,'Andrés Bello',20,'2026-02-27 16:34:35'),(252,'Antonio Rómulo Costa',20,'2026-02-27 16:34:35'),(253,'Ayacucho',20,'2026-02-27 16:34:35'),(254,'Bolívar',20,'2026-02-27 16:34:35'),(255,'Cárdenas',20,'2026-02-27 16:34:35'),(256,'Córdoba',20,'2026-02-27 16:34:35'),(257,'Fernández Feo',20,'2026-02-27 16:34:35'),(258,'Francisco de Miranda',20,'2026-02-27 16:34:35'),(259,'García de Hevia',20,'2026-02-27 16:34:35'),(260,'Guasimos',20,'2026-02-27 16:34:35'),(261,'Independencia',20,'2026-02-27 16:34:35'),(262,'Jáuregui',20,'2026-02-27 16:34:35'),(263,'José María Vargas',20,'2026-02-27 16:34:35'),(264,'Junín',20,'2026-02-27 16:34:35'),(265,'Libertad',20,'2026-02-27 16:34:35'),(266,'Libertador',20,'2026-02-27 16:34:35'),(267,'Lobatera',20,'2026-02-27 16:34:35'),(268,'Michelena',20,'2026-02-27 16:34:35'),(269,'Panamericano',20,'2026-02-27 16:34:35'),(270,'Pedro María Ureña',20,'2026-02-27 16:34:35'),(271,'Rafael Urdaneta',20,'2026-02-27 16:34:35'),(272,'Samuel Darío Maldonado',20,'2026-02-27 16:34:35'),(273,'San Cristóbal',20,'2026-02-27 16:34:35'),(274,'San Judas Tadeo',20,'2026-02-27 16:34:35'),(275,'Seboruco',20,'2026-02-27 16:34:35'),(276,'Simón Rodríguez',20,'2026-02-27 16:34:35'),(277,'Sucre',20,'2026-02-27 16:34:35'),(278,'Torbes',20,'2026-02-27 16:34:35'),(279,'Uribante',20,'2026-02-27 16:34:35'),(280,'Andrés Bello',21,'2026-02-27 16:35:12'),(281,'Boconó',21,'2026-02-27 16:35:12'),(282,'Bolívar',21,'2026-02-27 16:35:12'),(283,'Candelaria',21,'2026-02-27 16:35:12'),(284,'Carache',21,'2026-02-27 16:35:12'),(285,'Escuque',21,'2026-02-27 16:35:12'),(286,'José Felipe Márquez Cañizalez',21,'2026-02-27 16:35:12'),(287,'Juan Vicente Campo Elías',21,'2026-02-27 16:35:12'),(288,'La Ceiba',21,'2026-02-27 16:35:12'),(289,'Miranda',21,'2026-02-27 16:35:12'),(290,'Monte Carmelo',21,'2026-02-27 16:35:12'),(291,'Motatán',21,'2026-02-27 16:35:12'),(292,'Pampán',21,'2026-02-27 16:35:12'),(293,'Pampanito',21,'2026-02-27 16:35:12'),(294,'Rafael Rangel',21,'2026-02-27 16:35:12'),(295,'San Rafael de Carvajal',21,'2026-02-27 16:35:12'),(296,'Sucre',21,'2026-02-27 16:35:12'),(297,'Trujillo',21,'2026-02-27 16:35:12'),(298,'Urdaneta',21,'2026-02-27 16:35:12'),(299,'Valera',21,'2026-02-27 16:35:12'),(300,'Vargas',22,'2026-02-27 16:36:18'),(301,'Arístides Bastidas',23,'2026-02-27 16:37:07'),(302,'Bolívar',23,'2026-02-27 16:37:07'),(303,'Bruzual',23,'2026-02-27 16:37:07'),(304,'Cocorote',23,'2026-02-27 16:37:07'),(305,'Independencia',23,'2026-02-27 16:37:07'),(306,'José Antonio Páez',23,'2026-02-27 16:37:07'),(307,'La Trinidad',23,'2026-02-27 16:37:07'),(308,'Manuel Monge',23,'2026-02-27 16:37:07'),(309,'Nirgua',23,'2026-02-27 16:37:07'),(310,'Peña',23,'2026-02-27 16:37:07'),(311,'San Felipe',23,'2026-02-27 16:37:07'),(312,'Sucre',23,'2026-02-27 16:37:07'),(313,'Urachiche',23,'2026-02-27 16:37:07'),(314,'Veroes',23,'2026-02-27 16:37:07'),(315,'Almirante Padilla',24,'2026-02-27 16:37:34'),(316,'Baralt',24,'2026-02-27 16:37:34'),(317,'Cabimas',24,'2026-02-27 16:37:34'),(318,'Catatumbo',24,'2026-02-27 16:37:34'),(319,'Colón',24,'2026-02-27 16:37:34'),(320,'Francisco Javier Pulgar',24,'2026-02-27 16:37:34'),(321,'Indígena Bolivariano Guajira',24,'2026-02-27 16:37:34'),(322,'Jesús Enrique Lossada',24,'2026-02-27 16:37:34'),(323,'Jesús María Semprún',24,'2026-02-27 16:37:34'),(324,'La Cañada de Urdaneta',24,'2026-02-27 16:37:34'),(325,'Lagunillas',24,'2026-02-27 16:37:34'),(326,'Machiques de Perijá',24,'2026-02-27 16:37:34'),(327,'Mara',24,'2026-02-27 16:37:34'),(328,'Maracaibo',24,'2026-02-27 16:37:34'),(329,'Miranda',24,'2026-02-27 16:37:34'),(330,'Rosario de Perijá',24,'2026-02-27 16:37:34'),(331,'San Francisco',24,'2026-02-27 16:37:34'),(332,'Santa Rita',24,'2026-02-27 16:37:34'),(333,'Simón Bolívar',24,'2026-02-27 16:37:34'),(334,'Sucre',24,'2026-02-27 16:37:34'),(335,'Valmore Rodríguez',24,'2026-02-27 16:37:34');
/*!40000 ALTER TABLE `municipios` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `notificaciones`
--

DROP TABLE IF EXISTS `notificaciones`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `notificaciones` (
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
  CONSTRAINT `fk_notificaciones_coordinacion` FOREIGN KEY (`coordinacion_id`) REFERENCES `coordinacion` (`coordinacion_id`) ON DELETE CASCADE,
  CONSTRAINT `fk_notificaciones_destinatario_rol` FOREIGN KEY (`destinatario_rol_id`) REFERENCES `roles` (`rol_id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_notificaciones_solicitud` FOREIGN KEY (`solicitud_id`) REFERENCES `solicitudes` (`solicitud_id`) ON DELETE CASCADE,
  CONSTRAINT `fk_notificaciones_usuario` FOREIGN KEY (`usuario_id`) REFERENCES `usuarios` (`user_identificacion`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=4 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `notificaciones`
--

LOCK TABLES `notificaciones` WRITE;
/*!40000 ALTER TABLE `notificaciones` DISABLE KEYS */;
INSERT INTO `notificaciones` VALUES (1,NULL,3,NULL,1,'Nueva solicitud pendiente','Nueva solicitud pendiente: CNE-0001 - Nacimientos ocurridos fuera de establecimientos de salud (Extra hospitalarios)','leida','2026-04-03 20:49:59'),(2,NULL,2,NULL,1,'Trámite redirigido','Trámite redirigido: CNE-0001 - Nacimientos ocurridos fuera de establecimientos de salud (Extra hospitalarios). Proveniente de: Registro Civil','leida','2026-04-03 20:50:43'),(3,NULL,3,NULL,1,'Trámite redirigido','Trámite redirigido: CNE-0001 - Nacimientos ocurridos fuera de establecimientos de salud (Extra hospitalarios). Proveniente de: Registro Civil','leida','2026-04-03 21:03:26');
/*!40000 ALTER TABLE `notificaciones` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `permisos`
--

DROP TABLE IF EXISTS `permisos`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `permisos` (
  `permiso_id` int NOT NULL AUTO_INCREMENT,
  `permiso_codigo` varchar(50) NOT NULL,
  `permiso_nombre` varchar(100) NOT NULL,
  `permiso_descripcion` text,
  `permiso_modulo` varchar(50) DEFAULT NULL,
  `permiso_created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`permiso_id`),
  UNIQUE KEY `permiso_codigo` (`permiso_codigo`)
) ENGINE=InnoDB AUTO_INCREMENT=12 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `permisos`
--

LOCK TABLES `permisos` WRITE;
/*!40000 ALTER TABLE `permisos` DISABLE KEYS */;
INSERT INTO `permisos` VALUES (1,'crear_tramites','Crear Trámites','Permiso para crear nuevos trámites','tramites','2026-01-25 20:18:02'),(2,'ver_tramites','Ver Trámites','Permiso para visualizar trámites','tramites','2026-01-25 20:18:02'),(3,'editar_tramites','Editar Trámites','Permiso para editar trámites','tramites','2026-01-25 20:18:02'),(4,'eliminar_tramites','Eliminar Trámites','Permiso para eliminar trámites','tramites','2026-01-25 20:18:02'),(5,'asignar_tramites','Asignar Trámites','Permiso para asignar trámites a empleados','tramites','2026-01-25 20:18:02'),(6,'cambiar_estado_tramites','Cambiar Estado','Permiso para cambiar estado de trámites','tramites','2026-01-25 20:18:02'),(7,'gestionar_requisitos','Gestionar Requisitos','Permiso para crear, editar y eliminar requisitos','configuracion','2026-02-03 04:00:00'),(8,'gestionar_solicitudes','Gestionar Solicitudes','Permiso para gestionar solicitudes de trámites','solicitudes','2026-02-03 04:00:00'),(9,'gestionar_usuarios','Gestionar Usuarios','Permiso para crear, editar y eliminar usuarios del sistema','administracion','2026-02-03 04:00:00'),(10,'gestionar_roles','Gestionar Roles','Permiso para gestionar roles y permisos del sistema','administracion','2026-02-03 04:00:00'),(11,'gestionar_sistema','Gestionar Sistema','Permiso para configuraciones avanzadas del sistema','administracion','2026-02-03 04:00:00');
/*!40000 ALTER TABLE `permisos` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `requisitos`
--

DROP TABLE IF EXISTS `requisitos`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `requisitos` (
  `requisito_id` int NOT NULL AUTO_INCREMENT,
  `tramite_id` int NOT NULL,
  `requisito_nombre` varchar(600) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci NOT NULL,
  `requisito_activo` tinyint(1) DEFAULT '1',
  `requisito_created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`requisito_id`),
  KEY `fk_requisitos_tramite` (`tramite_id`),
  CONSTRAINT `fk_requisitos_tramite` FOREIGN KEY (`tramite_id`) REFERENCES `tramite` (`tramite_id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=283 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `requisitos`
--

LOCK TABLES `requisitos` WRITE;
/*!40000 ALTER TABLE `requisitos` DISABLE KEYS */;
INSERT INTO `requisitos` VALUES (1,1,'Copia de CI',1,'2026-02-04 18:07:52'),(2,1,'Formato de Declaración Jurada C.N.E',1,'2026-02-04 18:07:52'),(3,1,'Copia de CI de los Testigos',1,'2026-02-04 18:23:16'),(4,1,'Fé de vida ',1,'2026-02-04 18:23:16'),(5,2,'Copia de CI (Venezolana)',1,'2026-02-04 18:25:34'),(6,2,'Copia de CI (Extranjera)',1,'2026-02-04 18:25:34'),(7,3,'Copia de CI',1,'2026-02-04 18:25:34'),(8,3,'Auto mediante el cual el organo juridiccional levanta o suspende la pena dirigida al C.N.E',1,'2026-02-04 18:25:34'),(9,4,'Copia de CI',1,'2026-02-04 18:31:23'),(10,4,'Copia certificada del oficio emanado de la contraloria de la República el cual se deja constancia del cumplimiento del lapso de la Inhabilitación para ejercer Funciones públicas',1,'2026-02-04 18:31:24'),(11,5,'Copia de CI',1,'2026-02-04 18:31:24'),(12,5,'Copia certificada del oficio del Tribunal civil que deja sin efecto la Interdicción y en consecuencia restituye al elector su derecho al sufragio ',1,'2026-02-04 18:33:32'),(13,6,'Copia de CI',1,'2026-02-04 18:33:32'),(14,6,'Acta de nacimiento original',1,'2026-02-04 18:33:32'),(15,7,'Copia de CI',1,'2026-02-04 18:34:29'),(16,8,'Copia de CI',1,'2026-02-04 18:34:29'),(17,9,'Copia de CI',1,'2026-02-04 18:34:29'),(18,10,'Copia de CI',1,'2026-02-04 18:34:49'),(19,10,'Copia de CI',1,'2026-02-04 18:37:03'),(20,10,'Copia certificada del acta de nacimiento por Registro Civil',1,'2026-02-04 18:37:04'),(21,10,'Constancia de nacimiento (si nació en centro de salud)',1,'2026-02-04 18:37:04'),(22,11,'Copia de CI',1,'2026-02-04 18:39:07'),(23,11,'Copia de CI de los padres',1,'2026-02-04 18:39:07'),(24,11,'Copia certificada del acta de nacimiento por Registro Civil y Principal',1,'2026-02-04 18:39:07'),(25,11,'Constancia de nacimiento (si nació en centro de salud)',1,'2026-02-04 18:39:07'),(26,12,'Copia de CI',1,'2026-02-04 18:40:21'),(27,12,'Copia del libro certificado del acta de nacimiento',1,'2026-02-04 18:40:21'),(28,13,'Copia de CI',1,'2026-02-04 18:40:21'),(29,13,'Copia del libro certificado del acta de nacimiento',1,'2026-02-04 18:40:21'),(30,14,'Copia de CI',1,'2026-02-04 18:42:07'),(31,14,'Copia del libro certificado del acta de nacimiento',1,'2026-02-04 18:42:07'),(32,15,'Copia de CI',1,'2026-02-04 18:42:07'),(33,15,'Copia de CI de los padres (si están naturalizados Gaceta Oficial certificada por la imprenta nacional)',1,'2026-02-04 18:42:07'),(34,15,'Copia del libro certificado del acta de nacimiento',1,'2026-02-04 18:47:49'),(35,15,'Constancia de nacimiento (si nació en centro de salud)',1,'2026-02-04 18:47:49'),(36,15,'Presentar justificativo de partera y testigos por un Tribunal Civil del municipio donde nació (Si el padre fue extrahospitalario y madre extranjera)',1,'2026-02-04 18:47:49'),(37,16,'Copia de CI',1,'2026-02-04 18:49:34'),(38,16,'Copia del libro certificado del acta de nacimiento por Registro Civil',1,'2026-02-04 18:49:34'),(39,16,'Constancia de nacimiento',1,'2026-02-04 18:49:34'),(40,16,'Copia de CI de los padres',1,'2026-02-04 18:49:34'),(41,17,'Copia de CI del familiar directo',1,'2026-02-04 18:51:53'),(42,17,'Copia de CI del difunto',1,'2026-02-04 18:51:54'),(43,17,'Acta de  defunción original',1,'2026-02-04 18:51:54'),(44,18,'Copia del libro certificado del acta de nacimiento por Registro Civil',1,'2026-02-04 18:51:54'),(45,18,'Constancia de nacimiento',1,'2026-02-04 18:51:54'),(46,18,'Copia de CI de los padres',1,'2026-02-04 18:51:54'),(47,20,'Oficio emitido por el Registro Civil del Municipio.',1,'2026-02-11 07:55:46'),(48,20,'Planilla de solicitud de Inscripción extemporánea de nacimiento de personas mayor de edad.',1,'2026-02-11 07:55:46'),(49,20,'Original y copia fotostática del documento emanado por el establecimiento de salud que acredite la ocurrencia del nacimiento (consignado por el solicitante).',1,'2026-02-11 07:55:46'),(50,20,'Oficio de certificación de nacimiento emanado del establecimiento de salud, en los casos que no haya sido consignado por el solicitante.',1,'2026-02-11 07:55:46'),(51,20,'Declaración Jurada de Filiación de uno o ambos padres del solicitante (en caso de estar vivos), las cuales deberán ser rendidas ante el Registrador o Registradora Civil, quien la suscribirá junto con el declarante. ',1,'2026-02-11 07:55:46'),(52,20,'Copia fotostática de la cédula de identidad del padre, la madre y/o de ambos, en caso de poseerla.',1,'2026-02-11 07:55:46'),(53,20,'Copia fotostática del Acta de Defunción, en caso de fallecimiento de alguno de los padres.',1,'2026-02-11 07:55:46'),(54,20,'Constancia inexistencia de acta en el registro civil. ',1,'2026-02-11 07:55:46'),(55,20,'Acta de verificación de autenticidad de registro de nacimiento en centros hospitalarios.',1,'2026-02-11 07:55:46'),(56,20,'Informe de cierre de expediente.',1,'2026-02-11 07:55:46'),(57,21,'Oficio emitido por el Registro Civil del Municipio.',1,'2026-02-11 07:55:46'),(58,21,'Planilla de Solicitud de Inscripción extemporánea de nacimiento de  persona  mayor de edad. ',1,'2026-02-11 07:55:46'),(59,21,'Declaración Jurada de Filiación de uno o ambos padres del solicitante (en caso de estar vivos), las cuales deberán ser rendidas ante el Registrador o Registradora Civil, quien la suscribirá junto con el declarante. ',1,'2026-02-11 07:55:46'),(60,21,'Copia fotostática de la cédula de identidad del padre, la madre y/o de ambos, en caso de poseerla.',1,'2026-02-11 07:55:46'),(61,21,'Copia fotostática del Acta de Defunción, en caso de fallecimiento de alguno de los padres.',1,'2026-02-11 07:55:46'),(62,21,'Declaración jurada de la persona que asistió el parto, la cual deberá ser rendida por ante el Registrador o Registradora Civil, quien la suscribirá junto con el declarante.',1,'2026-02-11 07:55:46'),(63,21,'Copia fotostática de la cédula de identidad de la  persona que asistió el parto. ',1,'2026-02-11 07:55:46'),(64,21,'Declaraciones juradas de dos testigos venezolanos que den fe de la ocurrencia del nacimiento, las cuales deberán ser rendidas ante el Registrador o Registradora Civil, quien la suscribirá junto con el declarante.',1,'2026-02-11 07:55:46'),(65,21,'Copia fotostática de la cédula de identidad de los testigos.',1,'2026-02-11 07:55:46'),(66,21,'Constancia del Consejo Comunal Nacimiento Extra hospitalario.',1,'2026-02-11 07:55:46'),(67,21,'Copia de la cédula de  identidad de la miembro del consejo comunal.',1,'2026-02-11 07:55:46'),(68,21,'Constancia  inexistencia de acta en la Unidad de Registro Civil.',1,'2026-02-11 07:55:46'),(69,21,'Informe de cierre de expediente.',1,'2026-02-11 07:55:46'),(70,21,'Otros documentos probatorios consignados por el solicitante de ser el caso.',1,'2026-02-11 07:55:46'),(71,23,'Planilla de Solicitud, debidamente llenada y suscrita por el solicitante.',1,'2026-02-13 16:33:36'),(72,23,'Copia fotostática de la cédula de identidad del solicitante.',1,'2026-02-13 16:33:36'),(73,23,'Copia certificada y/o copia fotostática del acta que solicita su reconstrucción consignada por el solicitante.',1,'2026-02-13 16:33:36'),(74,23,'Informe descriptivo Formato ONRC-RA-002 ',1,'2026-02-13 16:34:56'),(75,23,'Formulario de Reconstrucción-OURC.',1,'2026-02-13 16:34:56'),(76,23,'Copia certificada de los restos del acta a reconstruir, de ser el caso.',1,'2026-02-13 16:34:57'),(77,23,'Copia certificada del acta que reposa en el duplicado, de encontrarse el mismo en la Oficina o Unidad de Registro Civil.',1,'2026-02-13 16:34:57'),(78,23,'Oficio de solicitud al Registro Principal del estado.',1,'2026-02-13 16:34:57'),(79,23,'Oficio de respuesta por parte del Registro Principal del estado.',1,'2026-02-13 16:34:57'),(80,23,'Copia Certificada del Duplicado que reposan en el Registro Principal o Formulario de Reconstrucción del Registro Principal.',1,'2026-02-13 16:35:37'),(81,23,'Impresión del correo electrónico de solicitud de tarjeta alfabética. ',1,'2026-02-13 16:35:37'),(82,23,'Informe de Cierre del Expediente.',1,'2026-02-13 16:35:37'),(83,24,'Planilla de Solicitud, debidamente llenada y suscrita por el solicitante.',1,'2026-02-13 16:38:09'),(84,24,'Copia fotostática de la cédula de identidad del solicitante.',1,'2026-02-13 16:38:09'),(85,24,'Copia certificada y/o copia fotostática del acta que solicita su reconstrucción consignada por el solicitante.',1,'2026-02-13 16:38:09'),(86,24,'Informe descriptivo Formato ONRC-RA-002 ',1,'2026-02-13 16:38:09'),(87,24,'Formulario de Reconstrucción-OURC.',1,'2026-02-13 16:39:38'),(88,24,'Copia certificada de los restos del acta a reconstruir, de ser el caso.',1,'2026-02-13 16:39:38'),(89,24,'Copia certificada del acta que reposa en el duplicado, de encontrarse el mismo en la Oficina o Unidad de Registro Civil.',1,'2026-02-13 16:39:38'),(90,24,'Oficio de solicitud al Registro Principal del estado.',1,'2026-02-13 16:39:38'),(91,24,'Oficio de respuesta por parte del Registro Principal del estado.',1,'2026-02-13 16:39:38'),(92,24,'Copia Certificada del Duplicado que reposan en el Registro Principal o Formulario de Reconstrucción del Registro Principal.',1,'2026-02-13 16:40:54'),(93,24,'Impresión del correo electrónico de solicitud de tarjeta alfabética. ',1,'2026-02-13 16:40:54'),(94,25,'Planilla de Solicitud, debidamente llenada y suscrita por el solicitante.',1,'2026-02-13 16:43:33'),(95,25,'Copia fotostática de la cédula de identidad del solicitante.',1,'2026-02-13 16:43:33'),(96,25,'Copia certificada y/o copia fotostática del acta que solicita su reconstrucción consignada por el solicitante.',1,'2026-02-13 16:43:33'),(97,25,'Informe descriptivo Formato ONRC-RA-002 ',1,'2026-02-13 16:43:33'),(98,25,'Formulario de Reconstrucción-OURC.',1,'2026-02-13 16:43:33'),(99,25,'Copia certificada de los restos del acta a reconstruir, de ser el caso.',1,'2026-02-13 16:43:33'),(100,25,'Copia certificada del acta que reposa en el duplicado, de encontrarse el mismo en la Oficina o Unidad de Registro Civil.',1,'2026-02-13 16:44:32'),(101,25,'Oficio de solicitud al Registro Principal del estado.',1,'2026-02-13 16:44:33'),(102,25,'Oficio de respuesta por parte del Registro Principal del estado.',1,'2026-02-13 16:44:33'),(103,25,'Copia Certificada del Duplicado que reposan en el Registro Principal o Formulario de Reconstrucción del Registro Principal.',1,'2026-02-13 16:44:33'),(104,25,'Impresión del correo electrónico de solicitud de tarjeta alfabética. ',1,'2026-02-13 16:44:33'),(105,25,'Informe de Cierre del Expediente.',1,'2026-02-13 16:44:33'),(106,26,'Planilla de Solicitud, debidamente llenada y suscrita por el solicitante.',1,'2026-02-13 16:47:40'),(107,26,'Copia fotostática de la cédula de identidad del solicitante.',1,'2026-02-13 16:47:40'),(108,26,'Copia certificada y/o copia fotostática del acta que solicita su reconstrucción consignada por el solicitante.',1,'2026-02-13 16:47:40'),(109,26,'Informe descriptivo Formato ONRC-RA-002.',1,'2026-02-13 16:47:40'),(110,26,'Formulario de Reconstrucción-OURC.',1,'2026-02-13 16:47:40'),(111,26,'Copia certificada de los restos del acta a reconstruir, de ser el caso.',1,'2026-02-13 16:47:40'),(112,26,'Copia certificada del acta que reposa en el duplicado, de encontrarse el mismo en la Oficina o Unidad de Registro Civil.',1,'2026-02-13 16:47:40'),(113,26,'Oficio de solicitud al Registro Principal del estado.',1,'2026-02-13 16:47:40'),(114,26,'Oficio de respuesta por parte del Registro Principal del estado.',1,'2026-02-13 16:47:40'),(115,26,'Copia Certificada del Duplicado que reposan en el Registro Principal o Formulario de Reconstrucción del Registro Principal.',1,'2026-02-13 16:47:40'),(116,26,'Impresión del correo electrónico de solicitud de tarjeta alfabética. ',1,'2026-02-13 16:47:40'),(117,26,'Informe de Cierre del Expediente.',1,'2026-02-13 16:47:40'),(118,27,'Planilla de Solicitud, debidamente llenada y suscrita por el solicitante.',1,'2026-02-13 16:52:43'),(119,27,'Copia fotostática de la cédula de identidad del solicitante.',1,'2026-02-13 16:52:43'),(120,27,'Copia certificada y/o copia fotostática del acta que solicita su reconstrucción consignada por el solicitante.',1,'2026-02-13 16:52:43'),(121,27,'Informe descriptivo Formato ONRC-RA-002 .',1,'2026-02-13 16:52:43'),(122,27,'Formulario de Reconstrucción-OURC.',1,'2026-02-13 16:52:43'),(123,27,'Copia certificada de los restos del acta a reconstruir, de ser el caso.',1,'2026-02-13 16:52:43'),(124,27,'Copia certificada del acta que reposa en el duplicado, de encontrarse el mismo en la Oficina o Unidad de Registro Civil.',1,'2026-02-13 16:52:43'),(125,27,'Oficio de solicitud al Registro Principal del estado.',1,'2026-02-13 16:52:43'),(126,27,'Oficio de respuesta por parte del Registro Principal del estado.',1,'2026-02-13 16:52:43'),(127,27,'Copia Certificada del Duplicado que reposan en el Registro Principal o Formulario de Reconstrucción del Registro Principal.',1,'2026-02-13 16:52:43'),(128,27,'Impresión del correo electrónico de solicitud de tarjeta alfabética. ',1,'2026-02-13 16:52:43'),(129,27,'Informe de Cierre del Expediente.',1,'2026-02-13 16:52:43'),(130,29,'Formato de solicitud de nulidad de acta, debidamente llenada y suscrita por el solicitante.',1,'2026-02-13 17:39:30'),(131,29,'Copia fotostática de la cédula de identidad del solicitante.',1,'2026-02-13 17:39:30'),(132,29,'Certificación de copia fotostática del acta que se solicita su nulidad.',1,'2026-02-13 17:39:30'),(133,29,'Copia certificada del expediente del acta o Informe que acredite su inexistencia.',1,'2026-02-13 17:39:30'),(134,29,'Oficio de solicitud de certificación o inexistencia del hecho vital, en caso de nulidad fundamentada en el artículo 150 numeral 1.',1,'2026-02-13 17:39:30'),(135,29,'Certificación de inexistencia del hecho vital, emitida por la autoridad sanitaria o forense, en caso de nulidad fundamentada en el artículo 150 numeral 1.',1,'2026-02-13 17:39:30'),(136,29,'Oficio de Solicitud de acta duplicada o multiplicada, en caso de nulidad fundamentada en el artículo 150 numeral 3.',1,'2026-02-13 17:39:30'),(137,29,'Copia Certificada del Acta de la doble o múltiple inscripción, procedente de la ORE correspondiente.',1,'2026-02-13 17:39:30'),(138,29,'Informe de Cierre del Expediente.',1,'2026-02-13 17:39:30'),(139,30,'Formato de solicitud de nulidad de acta, debidamente llenada y suscrita por el solicitante.',1,'2026-02-13 17:41:46'),(140,30,'Copia fotostática de la cédula de identidad del solicitante.',1,'2026-02-13 17:41:46'),(141,30,'Certificación de copia fotostática del acta que se solicita su nulidad.',1,'2026-02-13 17:41:46'),(142,30,'Copia certificada del expediente del acta o Informe que acredite su inexistencia.',1,'2026-02-13 17:41:46'),(143,30,'Oficio de solicitud de certificación o inexistencia del hecho vital, en caso de nulidad fundamentada en el artículo 150 numeral 1.',1,'2026-02-13 17:42:43'),(144,30,'Certificación de inexistencia del hecho vital, emitida por la autoridad sanitaria o forense, en caso de nulidad fundamentada en el artículo 150 numeral 1.',1,'2026-02-13 17:42:43'),(145,30,'Oficio de Solicitud de acta duplicada o multiplicada, en caso de nulidad fundamentada en el artículo 150 numeral 3.',1,'2026-02-13 17:42:43'),(146,30,'Copia Certificada del Acta de la doble o múltiple inscripción, procedente de la ORE correspondiente.',1,'2026-02-13 17:42:43'),(147,30,'Informe de Cierre del Expediente.',1,'2026-02-13 17:42:43'),(148,31,'Formato de solicitud de nulidad de acta, debidamente llenada y suscrita por el solicitante.',1,'2026-02-13 17:42:43'),(149,31,'Copia fotostática de la cédula de identidad del solicitante.',1,'2026-02-13 17:42:43'),(150,31,'Certificación de copia fotostática del acta que se solicita su nulidad.',1,'2026-02-13 17:42:43'),(151,31,'Copia certificada del expediente del acta o Informe que acredite su inexistencia.',1,'2026-02-13 17:42:43'),(152,31,'Oficio de solicitud de certificación o inexistencia del hecho vital, en caso de nulidad fundamentada en el artículo 150 numeral 1.',1,'2026-02-13 17:42:43'),(153,31,'Certificación de inexistencia del hecho vital, emitida por la autoridad sanitaria o forense, en caso de nulidad fundamentada en el artículo 150 numeral 1.',1,'2026-02-13 17:42:43'),(154,31,'Oficio de Solicitud de acta duplicada o multiplicada, en caso de nulidad fundamentada en el artículo 150 numeral 3.',1,'2026-02-13 17:42:43'),(155,31,'Copia Certificada del Acta de la doble o múltiple inscripción, procedente de la ORE correspondiente.',1,'2026-02-13 18:14:13'),(156,31,'Informe de Cierre del Expediente.',1,'2026-02-13 18:14:13'),(157,32,'Formato de solicitud de nulidad de acta, debidamente llenada y suscrita por el solicitante.',1,'2026-02-13 18:14:13'),(158,32,'Copia fotostática de la cédula de identidad del solicitante.',1,'2026-02-13 18:14:13'),(159,32,'Certificación de copia fotostática del acta que se solicita su nulidad.',1,'2026-02-13 18:14:13'),(160,32,'Copia certificada del expediente del acta o Informe que acredite su inexistencia.',1,'2026-02-13 18:14:13'),(161,32,'Oficio de solicitud de certificación o inexistencia del hecho vital, en caso de nulidad fundamentada en el artículo 150 numeral 1.',1,'2026-02-13 18:14:13'),(162,32,'Certificación de inexistencia del hecho vital, emitida por la autoridad sanitaria o forense, en caso de nulidad fundamentada en el artículo 150 numeral 1.',1,'2026-02-13 18:14:13'),(163,32,'Oficio de Solicitud de acta duplicada o multiplicada, en caso de nulidad fundamentada en el artículo 150 numeral 3.',1,'2026-02-13 18:14:13'),(164,32,'Copia Certificada del Acta de la doble o múltiple inscripción, procedente de la ORE correspondiente.',1,'2026-02-13 18:14:13'),(165,32,'Informe de Cierre del Expediente.',1,'2026-02-13 18:14:13'),(166,33,'Formato de solicitud de nulidad de acta, debidamente llenada y suscrita por el solicitante.',1,'2026-02-13 18:23:40'),(167,33,'Copia fotostática de la cédula de identidad del solicitante.',1,'2026-02-13 18:23:40'),(168,33,'Certificación de copia fotostática del acta que se solicita su nulidad.',1,'2026-02-13 18:23:40'),(169,33,'Copia certificada del expediente del acta o Informe que acredite su inexistencia.',1,'2026-02-13 18:23:40'),(170,33,'Oficio de solicitud de certificación o inexistencia del hecho vital, en caso de nulidad fundamentada en el artículo 150 numeral 1.',1,'2026-02-13 18:23:40'),(171,33,'Certificación de inexistencia del hecho vital, emitida por la autoridad sanitaria o forense, en caso de nulidad fundamentada en el artículo 150 numeral 1',1,'2026-02-13 18:23:40'),(172,33,'Oficio de Solicitud de acta duplicada o multiplicada, en caso de nulidad fundamentada en el artículo 150 numeral 3.',1,'2026-02-13 18:24:20'),(173,33,'Copia Certificada del Acta de la doble o múltiple inscripción, procedente de la ORE correspondiente.',1,'2026-02-13 18:24:20'),(174,33,'Informe de Cierre del Expediente.',1,'2026-02-13 18:24:20'),(175,35,'Planilla de Solicitud, debidamente llenada y suscrita por el solicitante.',1,'2026-02-13 18:24:20'),(176,35,'Copia fotostática de la cédula de identidad del solicitante.',1,'2026-02-13 18:24:20'),(177,35,'Copia certificada y/o copia fotostática del acta omitida.',1,'2026-02-13 18:24:20'),(178,35,'Constancia de inexistencia por la OURC.',1,'2026-02-13 18:24:20'),(179,35,'Formulario de omisión OURC.',1,'2026-02-13 18:24:20'),(180,35,'Informe descriptivo ONRC.',1,'2026-02-13 18:24:20'),(181,35,'Copia del libro diario de la fecha que corresponda a la emisión del acta.',1,'2026-02-13 18:24:20'),(182,35,'Copia certificada de las actas anterior y posterior del acta omitida.',1,'2026-02-13 18:24:20'),(183,35,'Oficio de solicitud al Registro Principal del estado.',1,'2026-02-13 18:24:20'),(184,35,'Copia fotostática de datos filiatorio.',1,'2026-02-13 18:24:20'),(185,35,'Copia de pasaporte.',1,'2026-02-13 18:24:20'),(186,35,'Informe de Cierre del Expediente.',1,'2026-02-13 18:24:20'),(187,36,'Planilla de Solicitud, debidamente llenada y suscrita por el solicitante.',1,'2026-02-13 18:24:20'),(188,36,'Copia fotostática de la cédula de identidad del solicitante.',1,'2026-02-13 18:24:20'),(189,36,'Copia certificada y/o copia fotostática del acta omitida.',1,'2026-02-13 18:24:20'),(190,36,'Constancia de inexistencia por la OURC.',1,'2026-02-13 18:24:20'),(191,36,'Formulario de omisión OURC.',1,'2026-02-13 18:24:20'),(192,36,'Informe descriptivo ONRC.',1,'2026-02-13 18:24:20'),(193,36,'Copia del libro diario de la fecha que corresponda a la emisión del acta.',1,'2026-02-13 18:24:20'),(194,36,'Copia certificada de las actas anterior y posterior del acta omitida.',1,'2026-02-13 18:24:20'),(195,36,'Oficio de solicitud al Registro Principal del estado.',1,'2026-02-13 18:24:20'),(196,36,'Copia fotostática de datos filiatorio.',1,'2026-02-13 18:24:20'),(197,36,'Copia de pasaporte.',1,'2026-02-13 18:24:20'),(198,36,'Informe de Cierre del Expediente.',1,'2026-02-13 18:24:20'),(199,37,'Planilla de Solicitud, debidamente llenada y suscrita por el solicitante.',1,'2026-02-13 18:24:20'),(200,37,'Copia fotostática de la cédula de identidad del solicitante.',1,'2026-02-13 18:24:20'),(201,37,'Copia certificada y/o copia fotostática del acta omitida.',1,'2026-02-13 18:24:20'),(202,37,'Constancia de inexistencia por la OURC.',1,'2026-02-13 18:24:20'),(203,37,'Formulario de omisión OURC.',1,'2026-02-13 18:24:20'),(204,37,'Informe descriptivo ONRC.',1,'2026-02-13 18:24:20'),(205,37,'Copia del libro diario de la fecha que corresponda a la emisión del acta.',1,'2026-02-13 18:24:20'),(206,37,'Copia certificada de las actas anterior y posterior del acta omitida.',1,'2026-02-13 18:24:20'),(207,37,'Oficio de solicitud al Registro Principal del estado.',1,'2026-02-13 18:24:20'),(208,37,'Copia fotostática de datos filiatorio.',1,'2026-02-13 18:24:20'),(209,37,'Copia de pasaporte.',1,'2026-02-13 18:24:20'),(210,37,'Informe de Cierre del Expediente.',1,'2026-02-13 18:24:20'),(211,38,'Planilla de Solicitud, debidamente llenada y suscrita por el solicitante.',1,'2026-02-13 18:24:20'),(212,38,'Copia fotostática de la cédula de identidad del solicitante.',1,'2026-02-13 18:24:20'),(213,38,'Copia certificada y/o copia fotostática del acta omitida.',1,'2026-02-13 18:24:20'),(214,38,'Constancia de inexistencia por la OURC.',1,'2026-02-13 18:24:20'),(215,38,'Formulario de omisión OURC.',1,'2026-02-13 18:24:20'),(216,38,'Informe descriptivo ONRC.',1,'2026-02-13 18:24:20'),(217,38,'Copia del libro diario de la fecha que corresponda a la emisión del acta.',1,'2026-02-13 18:24:20'),(218,38,'Copia certificada de las actas anterior y posterior del acta omitida.',1,'2026-02-13 18:24:20'),(219,38,'Oficio de solicitud al Registro Principal del estado.',1,'2026-02-13 18:24:20'),(220,38,'Copia fotostática de datos filiatorio.',1,'2026-02-13 18:24:20'),(221,38,'Copia de pasaporte.',1,'2026-02-13 18:24:20'),(222,38,'Informe de Cierre del Expediente.',1,'2026-02-13 18:24:20'),(223,39,'Planilla de Solicitud, debidamente llenada y suscrita por el solicitante.',1,'2026-02-13 18:24:20'),(224,39,'Copia fotostática de la cédula de identidad del solicitante.',1,'2026-02-13 18:24:20'),(225,39,'Copia certificada y/o copia fotostática del acta omitida. ',1,'2026-02-13 18:24:20'),(226,39,'Constancia de inexistencia por la OURC.',1,'2026-02-13 18:24:20'),(227,39,'Formulario de omisión OURC.',1,'2026-02-13 18:24:20'),(228,39,'Informe descriptivo ONRC.',1,'2026-02-13 18:24:20'),(229,39,'Copia del libro diario de la fecha que corresponda a la emisión del acta.',1,'2026-02-13 18:24:20'),(230,39,'Copia certificada de las actas anterior y posterior del acta omitida.',1,'2026-02-13 18:24:20'),(231,39,'Oficio de solicitud al Registro Principal del estado.',1,'2026-02-13 18:24:20'),(232,39,'Copia fotostática de datos filiatorio.',1,'2026-02-13 18:24:20'),(233,39,'Copia de pasaporte.',1,'2026-02-13 18:24:20'),(234,39,'Informe de Cierre del Expediente.',1,'2026-02-13 18:24:20'),(235,1,'Asesoría',1,'2026-02-24 07:58:36'),(236,2,'Asesoría',1,'2026-02-24 07:58:36'),(237,3,'Asesoría',1,'2026-02-24 07:58:36'),(238,4,'Asesoría',1,'2026-02-24 07:58:36'),(239,5,'Asesoría',1,'2026-02-24 07:58:36'),(240,6,'Asesoría',1,'2026-02-24 07:58:36'),(241,7,'Asesoría',1,'2026-02-24 07:58:36'),(242,8,'Asesoría',1,'2026-02-24 07:58:36'),(243,9,'Asesoría',1,'2026-02-24 07:58:36'),(244,10,'Asesoría',1,'2026-02-24 07:58:36'),(245,11,'Asesoría',1,'2026-02-24 07:58:36'),(246,12,'Asesoría',1,'2026-02-24 07:58:36'),(247,13,'Asesoría',1,'2026-02-24 07:58:36'),(248,14,'Asesoría',1,'2026-02-24 07:58:36'),(249,15,'Asesoría',1,'2026-02-24 07:58:36'),(250,16,'Asesoría',1,'2026-02-24 07:58:36'),(251,17,'Asesoría',1,'2026-02-24 07:58:36'),(252,18,'Asesoría',1,'2026-02-24 07:58:36'),(253,19,'Asesoría',1,'2026-02-24 07:58:36'),(254,20,'Asesoría',1,'2026-02-24 07:58:36'),(255,21,'Asesoría',1,'2026-02-24 07:58:36'),(256,22,'Asesoría',1,'2026-02-24 07:58:36'),(257,23,'Asesoría',1,'2026-02-24 07:58:36'),(258,24,'Asesoría',1,'2026-02-24 07:58:36'),(259,25,'Asesoría',1,'2026-02-24 07:58:36'),(260,26,'Asesoría',1,'2026-02-24 07:58:36'),(261,27,'Asesoría',1,'2026-02-24 07:58:36'),(262,28,'Asesoría',1,'2026-02-24 07:58:36'),(263,29,'Asesoría',1,'2026-02-24 07:58:36'),(264,30,'Asesoría',1,'2026-02-24 07:58:36'),(265,31,'Asesoría',1,'2026-02-24 07:58:36'),(266,32,'Asesoría',1,'2026-02-24 07:58:36'),(267,33,'Asesoría',1,'2026-02-24 07:58:36'),(268,34,'Asesoría',1,'2026-02-24 07:58:36'),(269,35,'Asesoría',1,'2026-02-24 07:58:36'),(270,36,'Asesoría',1,'2026-02-24 07:58:36'),(271,37,'Asesoría',1,'2026-02-24 07:58:36'),(272,38,'Asesoría',1,'2026-02-24 07:58:36'),(273,39,'Asesoría',1,'2026-02-24 07:58:36'),(274,40,'Asesoría',1,'2026-02-24 07:58:36'),(275,41,'Asesoría',1,'2026-02-24 07:58:36'),(276,42,'Asesoría',1,'2026-02-24 07:58:36'),(277,43,'Asesoría',1,'2026-02-24 19:19:43'),(278,44,'Asesoría',1,'2026-02-24 19:19:43'),(279,45,'Asesoría',1,'2026-02-24 19:19:43'),(280,43,'Copia de CI',1,'2026-02-24 19:20:51'),(281,44,'Copia de CI',1,'2026-02-24 19:20:51'),(282,45,'Copia de CI',1,'2026-02-24 19:20:51');
/*!40000 ALTER TABLE `requisitos` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `requisitos_solicitud`
--

DROP TABLE IF EXISTS `requisitos_solicitud`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `requisitos_solicitud` (
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
) ENGINE=InnoDB AUTO_INCREMENT=16 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `requisitos_solicitud`
--

LOCK TABLES `requisitos_solicitud` WRITE;
/*!40000 ALTER TABLE `requisitos_solicitud` DISABLE KEYS */;
INSERT INTO `requisitos_solicitud` VALUES (1,1,57,'pendiente','2026-04-03 20:49:59','2026-04-03 20:49:59'),(2,1,58,'pendiente','2026-04-03 20:49:59','2026-04-03 20:49:59'),(3,1,59,'pendiente','2026-04-03 20:49:59','2026-04-03 20:49:59'),(4,1,60,'pendiente','2026-04-03 20:49:59','2026-04-03 20:49:59'),(5,1,61,'pendiente','2026-04-03 20:49:59','2026-04-03 20:49:59'),(6,1,62,'pendiente','2026-04-03 20:49:59','2026-04-03 20:49:59'),(7,1,63,'pendiente','2026-04-03 20:49:59','2026-04-03 20:49:59'),(8,1,64,'pendiente','2026-04-03 20:49:59','2026-04-03 20:49:59'),(9,1,65,'pendiente','2026-04-03 20:49:59','2026-04-03 20:49:59'),(10,1,66,'pendiente','2026-04-03 20:49:59','2026-04-03 20:49:59'),(11,1,67,'pendiente','2026-04-03 20:49:59','2026-04-03 20:49:59'),(12,1,68,'pendiente','2026-04-03 20:49:59','2026-04-03 20:49:59'),(13,1,69,'pendiente','2026-04-03 20:49:59','2026-04-03 20:49:59'),(14,1,70,'pendiente','2026-04-03 20:49:59','2026-04-03 20:49:59'),(15,1,255,'pendiente','2026-04-03 20:49:59','2026-04-03 20:49:59');
/*!40000 ALTER TABLE `requisitos_solicitud` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `rol_permisos`
--

DROP TABLE IF EXISTS `rol_permisos`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `rol_permisos` (
  `rol_permiso_id` int NOT NULL AUTO_INCREMENT,
  `rol_id` int NOT NULL,
  `permiso_id` int NOT NULL,
  `rol_permiso_created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`rol_permiso_id`),
  UNIQUE KEY `rol_permiso` (`rol_id`,`permiso_id`),
  KEY `fk_rol_permisos_permiso` (`permiso_id`),
  CONSTRAINT `fk_rol_permisos_permiso` FOREIGN KEY (`permiso_id`) REFERENCES `permisos` (`permiso_id`) ON DELETE CASCADE,
  CONSTRAINT `fk_rol_permisos_rol` FOREIGN KEY (`rol_id`) REFERENCES `roles` (`rol_id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=12 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `rol_permisos`
--

LOCK TABLES `rol_permisos` WRITE;
/*!40000 ALTER TABLE `rol_permisos` DISABLE KEYS */;
INSERT INTO `rol_permisos` VALUES (1,1,1,'2026-01-25 20:18:02'),(2,1,2,'2026-01-25 20:18:02'),(3,2,2,'2026-02-03 04:00:00'),(4,2,3,'2026-02-03 04:00:00'),(5,2,6,'2026-02-03 04:00:00'),(6,3,2,'2026-02-03 04:00:00'),(7,3,3,'2026-02-03 04:00:00'),(8,3,5,'2026-02-03 04:00:00'),(9,3,6,'2026-02-03 04:00:00'),(10,4,9,'2026-02-03 04:00:00'),(11,5,10,'2026-02-03 04:00:00');
/*!40000 ALTER TABLE `rol_permisos` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `roles`
--

DROP TABLE IF EXISTS `roles`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `roles` (
  `rol_id` int NOT NULL AUTO_INCREMENT,
  `rol_nombre` varchar(50) NOT NULL,
  `rol_descripcion` text,
  `rol_created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`rol_id`),
  UNIQUE KEY `rol_nombre` (`rol_nombre`)
) ENGINE=InnoDB AUTO_INCREMENT=6 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `roles`
--

LOCK TABLES `roles` WRITE;
/*!40000 ALTER TABLE `roles` DISABLE KEYS */;
INSERT INTO `roles` VALUES (1,'Atención al Ciudadano','Atiende al ciudadano y crea solicitudes de trámites','2026-01-25 20:18:02'),(2,'Funcionario','Funcionario de área, gestiona trámites','2026-01-25 20:18:02'),(3,'Coordinador','Coordinador de área, supervisa empleados y trámites','2026-01-25 20:18:02'),(4,'Director','Director de área, supervisa coordinadores y toma decisiones estratégicas','2026-01-25 20:18:02'),(5,'Admin','Administrador del sistema, acceso completo a todas las funcionalidades','2026-02-03 04:00:00');
/*!40000 ALTER TABLE `roles` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `sesiones_activas`
--

DROP TABLE IF EXISTS `sesiones_activas`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `sesiones_activas` (
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
) ENGINE=InnoDB AUTO_INCREMENT=90 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `sesiones_activas`
--

LOCK TABLES `sesiones_activas` WRITE;
/*!40000 ALTER TABLE `sesiones_activas` DISABLE KEYS */;
INSERT INTO `sesiones_activas` VALUES (82,'V-12345678','6d38e21cecce96235251b96b462d77ad','::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/146.0.0.0 Safari/537.36','2026-04-05 00:12:15','2026-04-04 23:53:59');
/*!40000 ALTER TABLE `sesiones_activas` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `solicitudes`
--

DROP TABLE IF EXISTS `solicitudes`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `solicitudes` (
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
  CONSTRAINT `fk_solicitudes_coord_actual` FOREIGN KEY (`coordinacion_actual_id`) REFERENCES `coordinacion` (`coordinacion_id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_solicitudes_created_by` FOREIGN KEY (`created_by`) REFERENCES `usuarios` (`user_identificacion`),
  CONSTRAINT `fk_solicitudes_empleado_asignado` FOREIGN KEY (`empleado_asignado_id`) REFERENCES `usuarios` (`user_identificacion`) ON DELETE SET NULL,
  CONSTRAINT `fk_solicitudes_tramite` FOREIGN KEY (`tramite_id`) REFERENCES `tramite` (`tramite_id`),
  CONSTRAINT `solicitudes_chk_estado` CHECK ((`solicitud_estado` in (_utf8mb4'pendiente',_utf8mb4'en_revision',_utf8mb4'aprobada',_utf8mb4'rechazada',_utf8mb4'completada',_utf8mb4'redirigida',_utf8mb4'vencida')))
) ENGINE=InnoDB AUTO_INCREMENT=2 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `solicitudes`
--

LOCK TABLES `solicitudes` WRITE;
/*!40000 ALTER TABLE `solicitudes` DISABLE KEYS */;
INSERT INTO `solicitudes` VALUES (1,'CNE-0001',NULL,'V-31584677',21,3,'Solicitud de trámite registrada por Roberto Carlos Roberto Vázquez para Roberto Carlos Roberto Vázquez','pendiente','2026-04-03 20:49:59',NULL,'V-12312313','V-12312313','2026-04-03 20:49:59','2026-04-03 20:49:59',NULL);
/*!40000 ALTER TABLE `solicitudes` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `tramite`
--

DROP TABLE IF EXISTS `tramite`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `tramite` (
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
) ENGINE=InnoDB AUTO_INCREMENT=50 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `tramite`
--

LOCK TABLES `tramite` WRITE;
/*!40000 ALTER TABLE `tramite` DISABLE KEYS */;
INSERT INTO `tramite` VALUES (1,'Objeción 03 (Fallecido)',2,NULL,'2026-01-25 20:18:02'),(2,'Objeción 06 (Extranjero Naturalizado)',2,NULL,'2026-01-25 20:18:02'),(3,'Objeción 07 (Inhabilitado Politico) ',2,NULL,'2026-01-25 20:18:02'),(4,'Objeción 08 (Inhabilitado para ejercer Funciones Públicas)',2,NULL,'2026-01-25 20:18:02'),(5,'Objeción 09 (Interdicto Civil) ',2,NULL,'2026-02-04 17:55:39'),(6,'Objeción 12 (Observacion y revisión onsrci)',2,NULL,'2026-02-04 18:00:44'),(7,'Objeción 13 (Posible Homónimo sin Par)',2,NULL,'2026-02-04 18:00:44'),(8,'Objeción 15 (Por Revisión de datos) ',2,NULL,'2026-02-04 18:00:44'),(9,'Objeción 16 (Incongruencia que Provoca la objeción)',2,NULL,'2026-02-04 18:00:44'),(10,'Objeción 70 (Cuando no se reciben total o parcialmente los recaudos)',2,NULL,'2026-02-04 18:00:44'),(11,'Objeción 71 (Cuando se reciben documentos con errores de fondo)',2,NULL,'2026-02-04 18:00:44'),(12,'Objeción 72 (Por no lograr establecer parentesco con los testigos)',2,NULL,'2026-02-04 18:00:44'),(13,'Objeción 73 (Fiscalia Testigo con Impedimiento)',2,NULL,'2026-02-04 18:00:44'),(14,'Objeción 74 (Falta recaudos para comprobar Filiación)',2,NULL,'2026-02-04 18:00:44'),(15,'Objeción 75 (Hijos de Padres extranjeros antes del ingreso del País)',2,NULL,'2026-02-04 18:00:44'),(16,'Objeción 76 (Pendiente por Revisión)',2,NULL,'2026-02-04 18:00:44'),(17,'Colocación de Fallecido',2,NULL,'2026-02-04 18:00:44'),(18,'Cedulación de Extemporánea (Mayor de 18 años)',2,NULL,'2026-02-04 18:05:48'),(19,'Opinión para la Inscripción de Nacimientos de Persona Mayor de Edad',3,NULL,'2026-02-11 07:48:55'),(20,'Nacimientos ocurridos en establecimientos de salud (Hospitalario)',3,19,'2026-02-11 07:48:55'),(21,'Nacimientos ocurridos fuera de establecimientos de salud (Extra hospitalarios)',3,19,'2026-02-11 07:48:55'),(22,'Reconstrucción de Actas',3,NULL,'2026-02-11 16:32:53'),(23,'Reconstrucción de Actas de Nacimiento',3,22,'2026-02-11 16:33:23'),(24,'Reconstrucción de Actas de Reconocimiento',3,22,'2026-02-11 16:33:52'),(25,'Reconstrucción de Actas de Matrimonio',3,22,'2026-02-11 16:34:51'),(26,'Reconstrucción de Actas de Unión Estable de Hechos',3,22,'2026-02-11 16:34:51'),(27,'Reconstrucción de Actas de Defunción',3,22,'2026-02-11 16:35:15'),(28,'Nulidad de Actas.',3,NULL,'2026-02-11 16:35:15'),(29,'Nulidad de Actas de Nacimiento.',3,28,'2026-02-11 16:35:15'),(30,'Nulidad de Actas de Reconocimiento.',3,28,'2026-02-11 16:35:15'),(31,'Nulidad de Actas de Matrimonio.',3,28,'2026-02-11 16:35:15'),(32,'Nulidad de Actas de Unión Estable de Hecho.',3,28,'2026-02-11 16:35:15'),(33,'Nulidad de Actas de Defunción.',3,28,'2026-02-11 16:35:15'),(34,'Omisión de Actas.',3,NULL,'2026-02-13 18:28:11'),(35,'Omisión de Actas de Nacimiento.',3,34,'2026-02-13 18:28:11'),(36,'Omisión de Actas de Reconocimiento.',3,34,'2026-02-13 18:28:11'),(37,'Omisión de Actas Matrimonio.',3,34,'2026-02-13 18:28:11'),(38,'Omisión de Actas de Unión Estable de Hechos.',3,34,'2026-02-13 18:28:11'),(39,'Omisión de Actas de Defunción.',3,34,'2026-02-13 18:28:12'),(40,'Consultas Valijas Consulares.',3,NULL,'2026-02-13 18:51:43'),(41,'Consignación de Estadísticas.',3,NULL,'2026-02-13 18:51:43'),(42,'Consulta Vía Telefónica.',3,NULL,'2026-02-13 18:51:43'),(43,'Nuevo Ingreso',2,NULL,'2026-02-24 19:19:00'),(44,'Actualización de datos',2,NULL,'2026-02-24 19:19:00'),(45,'Actualización y Reubicación ',2,NULL,'2026-02-24 19:19:00'),(46,'Oficios Recibidos',4,NULL,'2026-02-27 16:15:18'),(47,'Valijas Recibidas',4,NULL,'2026-02-27 16:15:18'),(48,'Oficios Enviados',4,NULL,'2026-02-27 16:15:18'),(49,'Valijas Enviadas',4,NULL,'2026-02-27 16:15:18');
/*!40000 ALTER TABLE `tramite` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `usuarios`
--

DROP TABLE IF EXISTS `usuarios`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `usuarios` (
  `user_identificacion` varchar(20) NOT NULL,
  `user_username` varchar(50) NOT NULL,
  `user_password_hash` varchar(255) NOT NULL,
  `user_nombres` varchar(100) NOT NULL,
  `user_apellidos` varchar(100) NOT NULL,
  `rol_id` int NOT NULL,
  `coordinacion_id` int DEFAULT NULL,
  `user_estado` varchar(20) DEFAULT 'activo',
  `user_ultima_conexion` datetime DEFAULT NULL,
  `user_created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`user_identificacion`),
  UNIQUE KEY `user_username` (`user_username`),
  KEY `fk_usuarios_rol` (`rol_id`),
  KEY `fk_usuarios_coordinacion` (`coordinacion_id`),
  CONSTRAINT `fk_usuarios_coordinacion` FOREIGN KEY (`coordinacion_id`) REFERENCES `coordinacion` (`coordinacion_id`) ON DELETE SET NULL,
  CONSTRAINT `fk_usuarios_rol` FOREIGN KEY (`rol_id`) REFERENCES `roles` (`rol_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `usuarios`
--

LOCK TABLES `usuarios` WRITE;
/*!40000 ALTER TABLE `usuarios` DISABLE KEYS */;
INSERT INTO `usuarios` VALUES ('V-10637937','copafi001','$2y$10$Wg1xj1gFdgLO6SN3VNM5ruQUUDJEYRPCEEF5qwjiZNuwHzuzc/JAC','Yuly Chiquinquira','Meza Cañizalez',2,5,'activo',NULL,'2026-04-03 20:45:50'),('V-12237659','Maylyj','$2y$10$wDm07wwUoAKBsoFpvbbSg..Pfi1.G5AkKnEvNI0mMbJjHC1oIDWOK','Mayly Carolina','Jimenez Gallardo',3,2,'activo',NULL,'2026-04-03 20:33:53'),('V-12312313','entrada','$2y$10$Qc4yhl6J7b1UQM/S5zkT1OLsyeitTgPn2chTbB.WHMlDdtUgDMC0G','Roberto Carlos','Roberto Vázquez',1,1,'activo','2026-04-03 16:50:10','2026-04-03 20:48:27'),('V-12345678','admin','$2y$10$p/Z1rFV13mkLS9Id4QBgquH6Rpw3SQgb.97.NrTe36WrU2Blmy/aC','Luis','León',5,1,'activo','2026-04-04 20:12:15','2026-01-25 20:18:02'),('V-14332808','kadelvalle','$2y$10$u3cHODm5Pnqa.raM/.wk9uYLPKPYlvwngFU27Et9YJOa.2E8c5tcC','Karelis','López',3,3,'activo',NULL,'2026-04-03 20:06:00'),('V-15349343','copafi003','$2y$10$aYRygllAOe68ZSW8wdQlDen742p8t3GGzartZhe3yvMscKhTRbaNW','Randy Antonio','Barazarte Barazarte',2,5,'activo',NULL,'2026-04-03 20:46:52'),('V-15941010','rosvelip','$2y$10$KWUByawkupm2bTY0Ss/fKu6crNTfthwdXgOBeYSsetSYHOY7/dj6i','Rosveli','Peña',2,3,'activo','2026-04-03 16:12:26','2026-04-03 20:08:56'),('V-16497339','Rosme123.','$2y$10$Y4UvdgnbvRFxrUocQVAeOO8QBejNIrSW6Hcu4UxqA5S8FRRbUowFi','Rosmelys','Duque',2,3,'activo',NULL,'2026-04-03 20:07:52'),('V-16644767','arelysr','$2y$10$Aw5llDz27fwohUJKwt9.BOJGO71fxO5o0.uSrw5xt/nXwIBYIq.V6','Arelys Carolina','Rondon Betacourt',2,2,'activo','2026-04-03 17:03:16','2026-04-03 20:34:58'),('V-17881938','copafi002','$2y$10$YPrcNehbNHdz8CxXpfZK5O8UsghmRueAEJEyrVCyI78wVy7ZHIwhK','Delia Josefina','Rivero Hernández',2,5,'activo',NULL,'2026-04-03 20:46:20'),('V-19187197','lgonzalez','$2y$10$QrwaYkixh6eeuYYVJwmVOO8jn80h8GV0mDRIFwd6VBD1.UJJCFbza','Liliana','Gonzalez',2,3,'activo','2026-04-03 17:03:05','2026-04-03 20:09:41'),('V-22095361','belma0411','$2y$10$phDcs4JsP2QXnGVspzHrXO2UP8jctTQnxj7QYaiOMM9Wmo6WKguZ2','Belmary','Graterol',2,4,'activo',NULL,'2026-04-03 20:39:22'),('V-25016337','Yonathanm','$2y$10$loP4POAq3jkUZXyKLqCyaetccSqZ3eBd/tlyeSKbscGD5uuGAV1oK','Yonathan Josue','Mendoza Delfin',2,2,'activo',NULL,'2026-04-03 20:37:39');
/*!40000 ALTER TABLE `usuarios` ENABLE KEYS */;
UNLOCK TABLES;
/*!40103 SET TIME_ZONE=@OLD_TIME_ZONE */;

/*!40101 SET SQL_MODE=@OLD_SQL_MODE */;
/*!40014 SET FOREIGN_KEY_CHECKS=@OLD_FOREIGN_KEY_CHECKS */;
/*!40014 SET UNIQUE_CHECKS=@OLD_UNIQUE_CHECKS */;
/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
/*!40111 SET SQL_NOTES=@OLD_SQL_NOTES */;

-- Dump completed on 2026-04-04 20:12:46
