-- Transición Torneos → Pull (Volea de revés)
-- Ejecutar una vez en la BD liga_padel (MySQL 8+).

-- 1) Perfil de usuario: solo_torneo → pull
ALTER TABLE usuarios
  MODIFY COLUMN tipo_perfil ENUM('liga', 'solo_torneo', 'pull') NOT NULL DEFAULT 'liga';

UPDATE usuarios SET tipo_perfil = 'pull' WHERE tipo_perfil = 'solo_torneo';

ALTER TABLE usuarios
  MODIFY COLUMN tipo_perfil ENUM('liga', 'pull') NOT NULL DEFAULT 'liga';

-- 2) Competiciones: torneo → pull
ALTER TABLE competiciones
  MODIFY COLUMN tipo ENUM('liga', 'torneo', 'pull') NOT NULL;

UPDATE competiciones SET tipo = 'pull' WHERE tipo = 'torneo';

ALTER TABLE competiciones
  MODIFY COLUMN tipo ENUM('liga', 'pull') NOT NULL;

-- 3) Inscripciones individuales a Pull (sin pareja fija)
CREATE TABLE IF NOT EXISTS inscripciones_pull (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  competicion_id INT UNSIGNED NOT NULL,
  usuario_id INT UNSIGNED NOT NULL,
  estado ENUM('pendiente','confirmada','rechazada','baja') NOT NULL DEFAULT 'pendiente',
  fecha_inscripcion TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uk_inscripcion_pull (competicion_id, usuario_id),
  KEY idx_pull_usuario (usuario_id),
  CONSTRAINT fk_pull_comp FOREIGN KEY (competicion_id) REFERENCES competiciones (id) ON DELETE RESTRICT,
  CONSTRAINT fk_pull_usuario FOREIGN KEY (usuario_id) REFERENCES usuarios (id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
