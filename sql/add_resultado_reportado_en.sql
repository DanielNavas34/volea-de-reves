ALTER TABLE partidos
  ADD COLUMN resultado_reportado_en DATETIME DEFAULT NULL AFTER reportado_por_usuario_id;

UPDATE partidos
SET resultado_reportado_en = fecha_registro
WHERE ganador_pareja_id IS NOT NULL
  AND estado = 'pendiente'
  AND resultado_reportado_en IS NULL;
