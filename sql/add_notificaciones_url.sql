USE liga_padel;

ALTER TABLE notificaciones_usuarios
  ADD COLUMN url_destino VARCHAR(255) DEFAULT NULL AFTER mensaje;
