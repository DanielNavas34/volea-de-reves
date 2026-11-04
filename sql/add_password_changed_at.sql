ALTER TABLE usuarios
  ADD COLUMN password_changed_at DATETIME DEFAULT NULL AFTER expiracion_token;
