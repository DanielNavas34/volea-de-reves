-- Cuadrante y rondas Pull (Volea de revés)

ALTER TABLE competiciones
  ADD COLUMN IF NOT EXISTS pull_num_pistas TINYINT UNSIGNED NULL DEFAULT 3,
  ADD COLUMN IF NOT EXISTS pull_capacidad_jugadores SMALLINT UNSIGNED NULL,
  ADD COLUMN IF NOT EXISTS pull_ronda_actual INT UNSIGNED NOT NULL DEFAULT 0;

CREATE TABLE IF NOT EXISTS pull_rondas (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  competicion_id INT UNSIGNED NOT NULL,
  numero INT UNSIGNED NOT NULL,
  estado ENUM('programada','en_juego','cerrada') NOT NULL DEFAULT 'programada',
  creado_en TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uk_pull_ronda (competicion_id, numero),
  CONSTRAINT fk_pull_ronda_comp FOREIGN KEY (competicion_id) REFERENCES competiciones (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS pull_pista_slots (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  ronda_id INT UNSIGNED NOT NULL,
  pista_numero TINYINT UNSIGNED NOT NULL COMMENT '1 = nivel más alto',
  slot TINYINT UNSIGNED NOT NULL COMMENT '1-4: 1-2 equipo A, 3-4 equipo B',
  usuario_id INT UNSIGNED NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uk_pull_slot (ronda_id, pista_numero, slot),
  UNIQUE KEY uk_pull_jugador_ronda (ronda_id, usuario_id),
  KEY idx_pull_pista (ronda_id, pista_numero),
  CONSTRAINT fk_pull_slot_ronda FOREIGN KEY (ronda_id) REFERENCES pull_rondas (id) ON DELETE CASCADE,
  CONSTRAINT fk_pull_slot_user FOREIGN KEY (usuario_id) REFERENCES usuarios (id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS pull_pista_resultados (
  ronda_id INT UNSIGNED NOT NULL,
  pista_numero TINYINT UNSIGNED NOT NULL,
  ganador_equipo ENUM('A','B') NOT NULL COMMENT 'A = slots 1-2, B = slots 3-4',
  PRIMARY KEY (ronda_id, pista_numero),
  CONSTRAINT fk_pull_res_ronda FOREIGN KEY (ronda_id) REFERENCES pull_rondas (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
