# Referencia SQL — `liga_padel_init.sql`

Copiar a `sql/liga_padel_init.sql` al implementar. Sin datos personales reales. La contraseña del admin de prueba no se documenta aquí.

```sql
SET NAMES utf8mb4;
SET time_zone = '+00:00';

CREATE DATABASE IF NOT EXISTS liga_padel
  CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE liga_padel;

CREATE TABLE usuarios (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  nombre VARCHAR(100) NOT NULL,
  apellidos VARCHAR(100) NOT NULL,
  email VARCHAR(150) NOT NULL,
  telefono VARCHAR(15) NOT NULL DEFAULT '',
  password VARCHAR(255) NOT NULL,
  rol ENUM('usuario','admin') NOT NULL DEFAULT 'usuario',
  activo TINYINT(1) NOT NULL DEFAULT 1,
  email_verificado TINYINT(1) NOT NULL DEFAULT 0,
  codigo_verificacion VARCHAR(6) DEFAULT NULL,
  intentos_pin INT NOT NULL DEFAULT 0,
  expiracion_pin DATETIME DEFAULT NULL,
  token_recuperacion VARCHAR(255) DEFAULT NULL,
  expiracion_token DATETIME DEFAULT NULL,
  fecha_registro TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uk_usuarios_email (email)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE parejas (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  nombre_display VARCHAR(120) DEFAULT NULL,
  estado ENUM('borrador','activa','inactiva','disuelta') NOT NULL DEFAULT 'borrador',
  creado_por_usuario_id INT UNSIGNED NOT NULL,
  creado_en TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_parejas_creador (creado_por_usuario_id),
  CONSTRAINT fk_parejas_creador FOREIGN KEY (creado_por_usuario_id) REFERENCES usuarios (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE pareja_miembros (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  pareja_id INT UNSIGNED NOT NULL,
  usuario_id INT UNSIGNED NOT NULL,
  rol ENUM('titular','suplente') NOT NULL DEFAULT 'titular',
  activo TINYINT(1) NOT NULL DEFAULT 1,
  desde DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  hasta DATETIME DEFAULT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uk_pareja_usuario (pareja_id, usuario_id),
  KEY idx_pm_usuario (usuario_id),
  CONSTRAINT fk_pm_pareja FOREIGN KEY (pareja_id) REFERENCES parejas (id) ON DELETE RESTRICT,
  CONSTRAINT fk_pm_usuario FOREIGN KEY (usuario_id) REFERENCES usuarios (id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE pareja_invitaciones (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  pareja_id INT UNSIGNED NOT NULL,
  invitador_usuario_id INT UNSIGNED NOT NULL,
  invitado_usuario_id INT UNSIGNED NOT NULL,
  estado ENUM('pendiente','aceptada','rechazada','expirada') NOT NULL DEFAULT 'pendiente',
  expira_en DATETIME NOT NULL,
  respondido_en DATETIME DEFAULT NULL,
  PRIMARY KEY (id),
  KEY idx_inv_pareja (pareja_id),
  CONSTRAINT fk_inv_pareja FOREIGN KEY (pareja_id) REFERENCES parejas (id) ON DELETE CASCADE,
  CONSTRAINT fk_inv_invitador FOREIGN KEY (invitador_usuario_id) REFERENCES usuarios (id),
  CONSTRAINT fk_inv_invitado FOREIGN KEY (invitado_usuario_id) REFERENCES usuarios (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE competiciones (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  tipo ENUM('liga','torneo') NOT NULL,
  nombre VARCHAR(150) NOT NULL,
  descripcion TEXT,
  estado ENUM('borrador','abierta','en_curso','finalizada','cancelada') NOT NULL DEFAULT 'borrador',
  modalidad_liga ENUM('general','grupos') DEFAULT NULL,
  formato_torneo ENUM('eliminacion','grupos','grupos_eliminatoria') DEFAULT NULL,
  fecha_inicio DATE DEFAULT NULL,
  fecha_fin DATE DEFAULT NULL,
  cartel_ruta VARCHAR(255) DEFAULT NULL,
  creado_en TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_comp_tipo (tipo)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE competicion_grupos (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  competicion_id INT UNSIGNED NOT NULL,
  nombre VARCHAR(80) NOT NULL,
  orden INT NOT NULL DEFAULT 0,
  PRIMARY KEY (id),
  UNIQUE KEY uk_comp_grupo_nombre (competicion_id, nombre),
  CONSTRAINT fk_cg_comp FOREIGN KEY (competicion_id) REFERENCES competiciones (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE inscripciones (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  competicion_id INT UNSIGNED NOT NULL,
  pareja_id INT UNSIGNED NOT NULL,
  grupo_id INT UNSIGNED DEFAULT NULL,
  estado ENUM('pendiente','confirmada','rechazada','baja') NOT NULL DEFAULT 'pendiente',
  fecha_inscripcion TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uk_inscripcion (competicion_id, pareja_id),
  KEY idx_ins_grupo (grupo_id),
  CONSTRAINT fk_ins_comp FOREIGN KEY (competicion_id) REFERENCES competiciones (id) ON DELETE RESTRICT,
  CONSTRAINT fk_ins_pareja FOREIGN KEY (pareja_id) REFERENCES parejas (id) ON DELETE RESTRICT,
  CONSTRAINT fk_ins_grupo FOREIGN KEY (grupo_id) REFERENCES competicion_grupos (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE partidos (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  competicion_id INT UNSIGNED NOT NULL,
  grupo_id INT UNSIGNED DEFAULT NULL,
  fase ENUM('liga','grupos','eliminatoria','consolacion') NOT NULL DEFAULT 'liga',
  ronda SMALLINT UNSIGNED DEFAULT NULL,
  posicion_cuadro SMALLINT UNSIGNED DEFAULT NULL,
  partido_siguiente_id INT UNSIGNED DEFAULT NULL,
  pareja_a_id INT UNSIGNED NOT NULL,
  pareja_b_id INT UNSIGNED NOT NULL,
  fecha DATE NOT NULL,
  hora TIME NOT NULL DEFAULT '12:00:00',
  estado ENUM('pendiente','validado','rechazado','anulado') NOT NULL DEFAULT 'pendiente',
  ganador_pareja_id INT UNSIGNED DEFAULT NULL,
  reportado_por_usuario_id INT UNSIGNED DEFAULT NULL,
  motivo_rechazo TEXT,
  formato_partido_codigo VARCHAR(32) NOT NULL DEFAULT 'best_of_3_standard',
  fecha_registro TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_part_comp (competicion_id),
  CONSTRAINT fk_part_comp FOREIGN KEY (competicion_id) REFERENCES competiciones (id) ON DELETE RESTRICT,
  CONSTRAINT fk_part_grupo FOREIGN KEY (grupo_id) REFERENCES competicion_grupos (id) ON DELETE SET NULL,
  CONSTRAINT fk_part_siguiente FOREIGN KEY (partido_siguiente_id) REFERENCES partidos (id) ON DELETE SET NULL,
  CONSTRAINT fk_part_a FOREIGN KEY (pareja_a_id) REFERENCES parejas (id) ON DELETE RESTRICT,
  CONSTRAINT fk_part_b FOREIGN KEY (pareja_b_id) REFERENCES parejas (id) ON DELETE RESTRICT,
  CONSTRAINT fk_part_ganador FOREIGN KEY (ganador_pareja_id) REFERENCES parejas (id) ON DELETE SET NULL,
  CONSTRAINT fk_part_reportador FOREIGN KEY (reportado_por_usuario_id) REFERENCES usuarios (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE partido_sets (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  partido_id INT UNSIGNED NOT NULL,
  numero_set TINYINT UNSIGNED NOT NULL,
  juegos_pareja_a TINYINT UNSIGNED NOT NULL,
  juegos_pareja_b TINYINT UNSIGNED NOT NULL,
  tiebreak_a TINYINT UNSIGNED DEFAULT NULL,
  tiebreak_b TINYINT UNSIGNED DEFAULT NULL,
  es_super_tiebreak TINYINT(1) NOT NULL DEFAULT 0,
  PRIMARY KEY (id),
  UNIQUE KEY uk_set (partido_id, numero_set),
  CONSTRAINT fk_ps_partido FOREIGN KEY (partido_id) REFERENCES partidos (id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE partido_integrantes_snapshot (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  partido_id INT UNSIGNED NOT NULL,
  pareja_id INT UNSIGNED NOT NULL,
  lado ENUM('A','B') NOT NULL,
  usuario_id INT UNSIGNED NOT NULL,
  orden_en_pareja TINYINT UNSIGNED NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uk_snap (partido_id, lado, orden_en_pareja),
  CONSTRAINT fk_snap_partido FOREIGN KEY (partido_id) REFERENCES partidos (id) ON DELETE RESTRICT,
  CONSTRAINT fk_snap_pareja FOREIGN KEY (pareja_id) REFERENCES parejas (id) ON DELETE RESTRICT,
  CONSTRAINT fk_snap_usuario FOREIGN KEY (usuario_id) REFERENCES usuarios (id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE clasificacion_parejas (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  competicion_id INT UNSIGNED NOT NULL,
  grupo_id INT UNSIGNED DEFAULT NULL,
  pareja_id INT UNSIGNED NOT NULL,
  puntos INT NOT NULL DEFAULT 0,
  partidos_jugados INT NOT NULL DEFAULT 0,
  partidos_ganados INT NOT NULL DEFAULT 0,
  sets_favor INT NOT NULL DEFAULT 0,
  sets_contra INT NOT NULL DEFAULT 0,
  juegos_favor INT NOT NULL DEFAULT 0,
  juegos_contra INT NOT NULL DEFAULT 0,
  PRIMARY KEY (id),
  UNIQUE KEY uk_clasif (competicion_id, grupo_id, pareja_id),
  CONSTRAINT fk_cl_comp FOREIGN KEY (competicion_id) REFERENCES competiciones (id) ON DELETE CASCADE,
  CONSTRAINT fk_cl_grupo FOREIGN KEY (grupo_id) REFERENCES competicion_grupos (id) ON DELETE CASCADE,
  CONSTRAINT fk_cl_pareja FOREIGN KEY (pareja_id) REFERENCES parejas (id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE configuracion (
  clave VARCHAR(64) NOT NULL,
  valor TEXT NOT NULL,
  actualizado_en TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  actualizado_por_usuario_id INT UNSIGNED DEFAULT NULL,
  PRIMARY KEY (clave),
  CONSTRAINT fk_cfg_user FOREIGN KEY (actualizado_por_usuario_id) REFERENCES usuarios (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE audit_log (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  usuario_id INT UNSIGNED DEFAULT NULL,
  accion VARCHAR(64) NOT NULL,
  entidad VARCHAR(64) NOT NULL,
  entidad_id INT UNSIGNED DEFAULT NULL,
  detalles JSON DEFAULT NULL,
  ip VARCHAR(45) DEFAULT NULL,
  creado_en TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_audit_entidad (entidad, entidad_id),
  CONSTRAINT fk_audit_user FOREIGN KEY (usuario_id) REFERENCES usuarios (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE login_attempts (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  ip VARCHAR(45) NOT NULL,
  email VARCHAR(255) NOT NULL,
  fecha_intento TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_login_ip_fecha (ip, fecha_intento),
  KEY idx_login_email_fecha (email, fecha_intento)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE registro_attempts (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  ip VARCHAR(45) NOT NULL,
  fecha_intento TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_reg_ip_fecha (ip, fecha_intento)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO configuracion (clave, valor) VALUES
  ('liga_puntos_victoria', '180'),
  ('liga_puntos_derrota', '50');

INSERT INTO usuarios (nombre, apellidos, email, telefono, password, rol, email_verificado) VALUES
  ('Admin', 'Padel', 'admin@liga-padel.local', '600000000', '$2y$10$6BZCTsi5XvOZ9c3IfuMZlO93UX4EH0.15KPGYuaJckTeO3beDYHgm', 'admin', 1);
```

Nota: sustituir hash por uno generado en entorno local si se prefiere otra contraseña demo.
