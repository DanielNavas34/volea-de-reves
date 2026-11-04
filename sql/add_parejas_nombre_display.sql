-- Ejecutar si la BD se creó antes de incluir nombre_display en parejas.
USE liga_padel;

ALTER TABLE parejas
  ADD COLUMN nombre_display VARCHAR(120) DEFAULT NULL AFTER id;
