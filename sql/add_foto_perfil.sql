-- Foto de perfil opcional (ruta relativa en uploads/avatars/).
ALTER TABLE usuarios
  ADD COLUMN foto_perfil VARCHAR(120) NULL DEFAULT NULL AFTER telefono;
