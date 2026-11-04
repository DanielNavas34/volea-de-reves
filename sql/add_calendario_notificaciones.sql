-- Calendario inteligente (jornadas + fecha programada) y notificaciones internas.
USE liga_padel;

ALTER TABLE partidos
  ADD COLUMN jornada SMALLINT UNSIGNED DEFAULT NULL AFTER ronda,
  ADD COLUMN fecha_programada DATE DEFAULT NULL AFTER fecha;

CREATE TABLE IF NOT EXISTS notificaciones_usuarios (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  usuario_id INT UNSIGNED NOT NULL,
  partido_id INT UNSIGNED DEFAULT NULL,
  tipo VARCHAR(32) NOT NULL,
  titulo VARCHAR(200) NOT NULL,
  mensaje TEXT NOT NULL,
  leida TINYINT(1) NOT NULL DEFAULT 0,
  creado_en TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uk_notif_usuario_partido_tipo (usuario_id, partido_id, tipo),
  KEY idx_notif_usuario_leida (usuario_id, leida),
  CONSTRAINT fk_notif_usuario FOREIGN KEY (usuario_id) REFERENCES usuarios (id) ON DELETE CASCADE,
  CONSTRAINT fk_notif_partido FOREIGN KEY (partido_id) REFERENCES partidos (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
