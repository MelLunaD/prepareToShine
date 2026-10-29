-- Prepare to Shine / Prepare to Die: esquema de la base de datos.
-- Se importa por phpMyAdmin sobre una base ya creada (en InfinityFree la base se crea desde el panel).
-- Orden de importación: 01-esquema.sql, 02-datos-iniciales.sql, 03-preguntas.sql.

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

DROP TABLE IF EXISTS reporte;
DROP TABLE IF EXISTS partida_pregunta;
DROP TABLE IF EXISTS partida;
DROP TABLE IF EXISTS respuesta;
DROP TABLE IF EXISTS pregunta;
DROP TABLE IF EXISTS categoria;
DROP TABLE IF EXISTS usuario;
DROP TABLE IF EXISTS pais;

SET FOREIGN_KEY_CHECKS = 1;

CREATE TABLE pais (
    id INT AUTO_INCREMENT PRIMARY KEY,
    nombre VARCHAR(60) NOT NULL,
    codigo CHAR(2) NOT NULL UNIQUE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE usuario (
    id INT AUTO_INCREMENT PRIMARY KEY,
    nombre_completo VARCHAR(100) NOT NULL,
    anio_nacimiento SMALLINT NOT NULL,
    sexo ENUM('masculino', 'femenino', 'no_informa') NOT NULL DEFAULT 'no_informa',
    pais_id INT NOT NULL,
    ciudad VARCHAR(100) NULL,
    provincia VARCHAR(100) NULL,
    latitud DECIMAL(9, 6) NULL,
    longitud DECIMAL(9, 6) NULL,
    email VARCHAR(120) NOT NULL UNIQUE,
    usuario VARCHAR(30) NOT NULL UNIQUE,
    password_hash VARCHAR(255) NOT NULL,
    foto VARCHAR(255) NULL,
    rol ENUM('jugador', 'editor', 'admin') NOT NULL DEFAULT 'jugador',
    validado BOOLEAN NOT NULL DEFAULT 0,
    codigo_validacion CHAR(6) NULL,
    codigo_vence DATETIME NULL,
    preguntas_respondidas INT NOT NULL DEFAULT 0,
    preguntas_acertadas INT NOT NULL DEFAULT 0,
    fecha_alta DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_usuario_pais FOREIGN KEY (pais_id) REFERENCES pais (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE categoria (
    id INT AUTO_INCREMENT PRIMARY KEY,
    nombre VARCHAR(50) NOT NULL,
    color CHAR(7) NOT NULL,
    fase ENUM('shine', 'die') NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE pregunta (
    id INT AUTO_INCREMENT PRIMARY KEY,
    categoria_id INT NOT NULL,
    juego VARCHAR(40) NULL,
    texto VARCHAR(255) NOT NULL,
    estado ENUM('activa', 'inactiva', 'cuarentena', 'revision') NOT NULL DEFAULT 'revision',
    dificultad ENUM('facil', 'media', 'dificil') NOT NULL DEFAULT 'media',
    veces_mostrada INT NOT NULL DEFAULT 0,
    veces_acertada INT NOT NULL DEFAULT 0,
    creada_por INT NOT NULL,
    fecha_alta DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    aprobada_por INT NULL,
    fecha_aprobacion DATETIME NULL,
    modificada_por INT NULL,
    fecha_modificacion DATETIME NULL,
    desactivada_por INT NULL,
    fecha_desactivacion DATETIME NULL,
    INDEX idx_pregunta_sorteo (estado, dificultad, categoria_id),
    CONSTRAINT fk_pregunta_categoria FOREIGN KEY (categoria_id) REFERENCES categoria (id),
    CONSTRAINT fk_pregunta_creada_por FOREIGN KEY (creada_por) REFERENCES usuario (id),
    CONSTRAINT fk_pregunta_aprobada_por FOREIGN KEY (aprobada_por) REFERENCES usuario (id),
    CONSTRAINT fk_pregunta_modificada_por FOREIGN KEY (modificada_por) REFERENCES usuario (id),
    CONSTRAINT fk_pregunta_desactivada_por FOREIGN KEY (desactivada_por) REFERENCES usuario (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE respuesta (
    id INT AUTO_INCREMENT PRIMARY KEY,
    pregunta_id INT NOT NULL,
    texto VARCHAR(150) NOT NULL,
    es_correcta BOOLEAN NOT NULL DEFAULT 0,
    CONSTRAINT fk_respuesta_pregunta FOREIGN KEY (pregunta_id) REFERENCES pregunta (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE partida (
    id INT AUTO_INCREMENT PRIMARY KEY,
    usuario_id INT NOT NULL,
    puntaje INT NOT NULL DEFAULT 0,
    estado ENUM('en_curso', 'terminada') NOT NULL DEFAULT 'en_curso',
    fecha_inicio DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    fecha_fin DATETIME NULL,
    INDEX idx_partida_ranking (fecha_inicio, usuario_id),
    CONSTRAINT fk_partida_usuario FOREIGN KEY (usuario_id) REFERENCES usuario (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE partida_pregunta (
    id INT AUTO_INCREMENT PRIMARY KEY,
    partida_id INT NOT NULL,
    pregunta_id INT NOT NULL,
    respuesta_id INT NULL,
    numero TINYINT NOT NULL,
    acerto BOOLEAN NULL,
    puntos SMALLINT NOT NULL DEFAULT 0,
    fecha_mostrada DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_partida_numero (partida_id, numero),
    CONSTRAINT fk_pp_partida FOREIGN KEY (partida_id) REFERENCES partida (id) ON DELETE CASCADE,
    CONSTRAINT fk_pp_pregunta FOREIGN KEY (pregunta_id) REFERENCES pregunta (id),
    CONSTRAINT fk_pp_respuesta FOREIGN KEY (respuesta_id) REFERENCES respuesta (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE reporte (
    id INT AUTO_INCREMENT PRIMARY KEY,
    pregunta_id INT NOT NULL,
    reportado_por INT NOT NULL,
    revisado_por INT NULL,
    fecha_revision DATETIME NULL,
    motivo VARCHAR(255) NOT NULL,
    estado ENUM('pendiente', 'aprobado', 'rechazado') NOT NULL DEFAULT 'pendiente',
    fecha DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_reporte_pregunta FOREIGN KEY (pregunta_id) REFERENCES pregunta (id) ON DELETE CASCADE,
    CONSTRAINT fk_reporte_reportado_por FOREIGN KEY (reportado_por) REFERENCES usuario (id),
    CONSTRAINT fk_reporte_revisado_por FOREIGN KEY (revisado_por) REFERENCES usuario (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
