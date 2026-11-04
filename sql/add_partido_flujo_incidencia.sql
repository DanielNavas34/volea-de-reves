USE liga_padel;

ALTER TABLE partidos
  MODIFY COLUMN estado ENUM('pendiente','validado','rechazado','anulado','incidencia') NOT NULL DEFAULT 'pendiente';

ALTER TABLE partidos
  ADD COLUMN disputa_confirmada TINYINT(1) NOT NULL DEFAULT 0 AFTER estado,
  ADD COLUMN incidencia_en TIMESTAMP NULL DEFAULT NULL AFTER motivo_rechazo;

UPDATE partidos SET disputa_confirmada = 1
WHERE estado = 'pendiente' AND ganador_pareja_id IS NOT NULL;
