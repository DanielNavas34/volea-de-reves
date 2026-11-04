<?php
/**
 * Ruta legacy de inscripción a torneos de tenis (tabla `torneos`).
 * Referencia: _legacy_deprecated/inscripcion_tenis.php
 */
require_once __DIR__ . '/includes/legacy_tenis_guard.php';

redirectLegacyTenisBlocked('pulls.php');

require_once __DIR__ . '/_legacy_deprecated/inscripcion_tenis.php';
