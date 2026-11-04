<?php

/**
 * Servicio de dominio — partidos de liga (Fase 6).
 * Reporte, validación, sets y puntos de clasificación (vía padelApplyValidatedMatchPoints).
 */

require_once __DIR__ . '/padel_league_points.php';
require_once __DIR__ . '/partido_sets_validate.php';
require_once __DIR__ . '/padel_guardrails.php';
require_once __DIR__ . '/usuario_padel_helpers.php';
require_once __DIR__ . '/partido_flujo_usuario.php';

final class LeagueMatchService
{
    public function __construct(private PDO $db)
    {
    }

    /** Marca validado y aplica puntos + snapshot (transacción abierta por el llamador opcional). */
    public function validarPartido(int $partidoId): void
    {
        $stmt = $this->db->prepare(
            'SELECT id, estado, ganador_pareja_id FROM partidos WHERE id = :id LIMIT 1'
        );
        $stmt->execute(['id' => $partidoId]);
        $p = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$p || empty($p['ganador_pareja_id'])) {
            throw new InvalidArgumentException('Partido sin ganador para validar.');
        }
        if ($p['estado'] === 'validado') {
            throw new InvalidArgumentException('El partido ya está validado.');
        }
        $this->db->prepare("UPDATE partidos SET estado = 'validado' WHERE id = :id")->execute(['id' => $partidoId]);
        padelApplyValidatedMatchPoints($this->db, $partidoId);
    }

    /**
     * @param list<array{numero:int,juegos_a:int,juegos_b:int}> $setsPerspectivaUsuario
     *     juegos_a = yo, juegos_b = rival (formulario usuario).
     */
    public function reportarResultadoUsuario(
        int $partidoId,
        int $usuarioId,
        int $parejaId,
        int $ganadorParejaId,
        string $fechaYmd,
        array $setsPerspectivaUsuario
    ): void {
        if (!usuarioEnPartido($this->db, $partidoId, $usuarioId)) {
            throw new InvalidArgumentException('No participas en este partido.');
        }
        $stP = $this->db->prepare('SELECT * FROM partidos WHERE id = :id LIMIT 1');
        $stP->execute(['id' => $partidoId]);
        $fixRow = $stP->fetch(PDO::FETCH_ASSOC);
        if (!$fixRow) {
            throw new InvalidArgumentException('Partido no encontrado.');
        }
        if (partidoFlujoEstadoUsuario($this->db, $fixRow, $parejaId) !== 'formulario_reporte') {
            throw new InvalidArgumentException('No puedes reportar el resultado en este momento.');
        }
        $rivalId = (int) $fixRow['pareja_a_id'] === $parejaId
            ? (int) $fixRow['pareja_b_id']
            : (int) $fixRow['pareja_a_id'];
        $contexto = fetchContextoLigaPareja($this->db, $parejaId);
        if (!$contexto) {
            throw new InvalidArgumentException('Sin contexto de liga.');
        }
        $bloqueo = padelValidarReporteResultadoUsuario(
            $this->db,
            (int) $contexto['competicion_id'],
            (int) $contexto['grupo_id'],
            $parejaId,
            $rivalId,
            $partidoId
        );
        if ($bloqueo !== null) {
            throw new InvalidArgumentException($bloqueo);
        }

        $this->db->beginTransaction();
        try {
            $stFix = $this->db->prepare(
                'SELECT pareja_a_id, pareja_b_id, estado, ganador_pareja_id FROM partidos WHERE id = :id FOR UPDATE'
            );
            $stFix->execute(['id' => $partidoId]);
            $fix = $stFix->fetch(PDO::FETCH_ASSOC);
            if (!$fix || padelCodigoErrorReportePartido($fix) !== null) {
                throw new InvalidArgumentException('error_validado');
            }
            $flip = (int) $fix['pareja_a_id'] !== $parejaId;
            $setReportadoEn = dbColumnExists($this->db, 'partidos', 'resultado_reportado_en')
                ? ', resultado_reportado_en = NOW()'
                : '';
            $this->db->prepare(
                "UPDATE partidos SET fecha = :fecha, ganador_pareja_id = :ganador, reportado_por_usuario_id = :rep{$setReportadoEn} WHERE id = :id"
            )->execute([
                'fecha' => $fechaYmd,
                'ganador' => $ganadorParejaId,
                'rep' => $usuarioId,
                'id' => $partidoId,
            ]);
            $this->reemplazarSetsFilas($partidoId, $setsPerspectivaUsuario, $flip);
            $this->db->commit();
        } catch (Throwable $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $e;
        }
    }

    public function aceptarResultadoRival(int $partidoId, int $usuarioId): void
    {
        if (!usuarioEnPartido($this->db, $partidoId, $usuarioId)) {
            throw new InvalidArgumentException('No participas en este partido.');
        }
        $stmt = $this->db->prepare(
            'SELECT id, estado, reportado_por_usuario_id, ganador_pareja_id FROM partidos
             WHERE id = :id AND estado = \'pendiente\' LIMIT 1'
        );
        $stmt->execute(['id' => $partidoId]);
        $partido = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$partido || empty($partido['ganador_pareja_id'])) {
            throw new InvalidArgumentException('No hay resultado pendiente.');
        }
        if (!padelPartidoPendienteValidacionParaUsuario($this->db, $partido, $usuarioId)) {
            throw new InvalidArgumentException('No puedes validar este resultado.');
        }

        $this->db->beginTransaction();
        try {
            $this->validarPartido($partidoId);
            $this->db->commit();
        } catch (Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }
    }

    public function rechazarResultadoRival(int $partidoId, int $usuarioId, ?string $motivo = null): void
    {
        if (!usuarioEnPartido($this->db, $partidoId, $usuarioId)) {
            throw new InvalidArgumentException('No participas en este partido.');
        }
        $stmt = $this->db->prepare(
            'SELECT id, estado, ganador_pareja_id FROM partidos WHERE id = :id AND estado = \'pendiente\' LIMIT 1'
        );
        $stmt->execute(['id' => $partidoId]);
        $partido = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$partido || empty($partido['ganador_pareja_id'])) {
            throw new InvalidArgumentException('No hay resultado pendiente.');
        }
        if (!padelPartidoPendienteValidacionParaUsuario($this->db, $partido, $usuarioId)) {
            throw new InvalidArgumentException('No puedes rechazar este resultado.');
        }
        $motivo = $motivo !== null && trim($motivo) !== '' ? trim($motivo) : 'Sin motivo especificado';

        $this->db->beginTransaction();
        try {
            $clearReportadoEn = dbColumnExists($this->db, 'partidos', 'resultado_reportado_en')
                ? ', resultado_reportado_en = NULL'
                : '';
            $this->db->prepare(
                "UPDATE partidos SET estado = 'pendiente', motivo_rechazo = :m,
                 ganador_pareja_id = NULL, reportado_por_usuario_id = NULL, disputa_confirmada = 1{$clearReportadoEn}
                 WHERE id = :id"
            )->execute(['m' => $motivo, 'id' => $partidoId]);
            padelEliminarSetsDePartidos($this->db, [$partidoId]);
            $this->db->commit();
        } catch (Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }
    }

    /** Partido ya validado creado por admin. */
    public function registrarPartidoAdminValidado(
        int $competicionId,
        ?int $grupoId,
        int $parejaAId,
        int $parejaBId,
        int $ganadorParejaId,
        string $fechaYmd,
        string $hora,
        string $resultadoTexto
    ): int {
        if ($parejaAId === $parejaBId || $ganadorParejaId <= 0 || $competicionId <= 0) {
            throw new InvalidArgumentException('Datos de partido incompletos.');
        }
        if ($ganadorParejaId !== $parejaAId && $ganadorParejaId !== $parejaBId) {
            throw new InvalidArgumentException('Ganador debe ser una de las parejas.');
        }

        $this->db->beginTransaction();
        try {
            $stmt = $this->db->prepare(
                "INSERT INTO partidos (competicion_id, grupo_id, fase, pareja_a_id, pareja_b_id, fecha, hora, estado, ganador_pareja_id)
                 VALUES (:cid, :gid, 'liga', :pa, :pb, :fecha, :hora, 'validado', :ganador)"
            );
            $stmt->execute([
                'cid' => $competicionId,
                'gid' => $grupoId,
                'pa' => $parejaAId,
                'pb' => $parejaBId,
                'fecha' => $fechaYmd,
                'hora' => $hora,
                'ganador' => $ganadorParejaId,
            ]);
            $partidoId = (int) $this->db->lastInsertId();
            padelReplacePartidoSets($this->db, $partidoId, $resultadoTexto);
            padelApplyValidatedMatchPoints($this->db, $partidoId);
            $this->db->commit();

            return $partidoId;
        } catch (Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }
    }

    public function adminValidarPartidoPendiente(int $partidoId): void
    {
        $stmt = $this->db->prepare('SELECT estado, ganador_pareja_id FROM partidos WHERE id = :id');
        $stmt->execute(['id' => $partidoId]);
        $info = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$info || !in_array($info['estado'], ['pendiente', 'rechazado'], true) || !$info['ganador_pareja_id']) {
            throw new InvalidArgumentException('Partido no validable.');
        }
        $this->db->beginTransaction();
        try {
            $this->validarPartido($partidoId);
            $this->db->commit();
        } catch (Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }
    }

    public function eliminarPartido(int $partidoId): void
    {
        $stmt = $this->db->prepare('SELECT estado FROM partidos WHERE id = :id');
        $stmt->execute(['id' => $partidoId]);
        $info = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$info) {
            throw new InvalidArgumentException('Partido no encontrado.');
        }
        $this->db->beginTransaction();
        try {
            if ($info['estado'] === 'validado') {
                padelRevertValidatedMatchPoints($this->db, $partidoId);
            }
            padelEliminarSetsDePartidos($this->db, [$partidoId]);
            padelEliminarSnapshotsDePartidos($this->db, [$partidoId]);
            require_once __DIR__ . '/admin_maintenance_service.php';
            adminEliminarNotificacionesPartido($this->db, $partidoId);
            $this->db->prepare('DELETE FROM partidos WHERE id = :id')->execute(['id' => $partidoId]);
            $this->db->commit();
        } catch (Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }
    }

    public function reemplazarSetsTexto(int $partidoId, string $resultadoTexto): void
    {
        padelReplacePartidoSets($this->db, $partidoId, $resultadoTexto);
    }

    /**
     * @param list<array{numero:int,juegos_a:int,juegos_b:int}> $sets
     *     En perspectiva pareja A del partido si $flip false; si true, intercambia A/B.
     */
    public function reemplazarSetsFilas(int $partidoId, array $sets, bool $flip = false): void
    {
        $this->db->prepare('DELETE FROM partido_sets WHERE partido_id = :id')->execute(['id' => $partidoId]);
        if ($sets === []) {
            return;
        }
        $ins = $this->db->prepare(
            'INSERT INTO partido_sets (partido_id, numero_set, juegos_pareja_a, juegos_pareja_b) VALUES (:pid, :n, :a, :b)'
        );
        foreach ($sets as $set) {
            $ja = $flip ? $set['juegos_b'] : $set['juegos_a'];
            $jb = $flip ? $set['juegos_a'] : $set['juegos_b'];
            $ins->execute([
                'pid' => $partidoId,
                'n' => $set['numero'],
                'a' => $ja,
                'b' => $jb,
            ]);
        }
    }

    /** @return list<int> IDs validados automáticamente */
    public function autoValidarPartidosCaducados(): array
    {
        if (dbColumnExists($this->db, 'partidos', 'resultado_reportado_en')) {
            $sql = "SELECT id FROM partidos
                    WHERE estado = 'pendiente'
                      AND ganador_pareja_id IS NOT NULL
                      AND resultado_reportado_en IS NOT NULL
                      AND resultado_reportado_en <= DATE_SUB(NOW(), INTERVAL 24 HOUR)";
        } else {
            $sql = "SELECT id FROM partidos
                    WHERE estado = 'pendiente'
                      AND ganador_pareja_id IS NOT NULL
                      AND reportado_por_usuario_id IS NOT NULL
                      AND fecha_registro <= DATE_SUB(NOW(), INTERVAL 24 HOUR)";
        }
        $caducados = $this->db->query($sql)->fetchAll(PDO::FETCH_ASSOC);
        if ($caducados === []) {
            return [];
        }
        $validados = [];
        $this->db->beginTransaction();
        try {
            foreach ($caducados as $p) {
                $id = (int) $p['id'];
                $this->validarPartido($id);
                $validados[] = $id;
            }
            $this->db->commit();
        } catch (Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }

        return $validados;
    }

    /**
     * Control admin: fechas, ganador, marcador, estado y nota interna.
     *
     * @param array{fecha?:string,fecha_programada?:?string,hora?:string,jornada?:?int,ganador_pareja_id?:?int,resultado_texto?:?string,estado?:?string,nota_admin?:?string} $datos
     */
    public function adminActualizarPartido(int $partidoId, array $datos): void
    {
        $stmt = $this->db->prepare('SELECT * FROM partidos WHERE id = :id LIMIT 1');
        $stmt->execute(['id' => $partidoId]);
        $before = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$before) {
            throw new InvalidArgumentException('Partido no encontrado.');
        }
        $estadoAnterior = (string) ($before['estado'] ?? 'pendiente');
        $estadoNuevo = isset($datos['estado']) && $datos['estado'] !== ''
            ? (string) $datos['estado']
            : $estadoAnterior;
        $allowedEstados = ['pendiente', 'validado', 'rechazado', 'anulado', 'incidencia'];
        if (!in_array($estadoNuevo, $allowedEstados, true)) {
            throw new InvalidArgumentException('Estado no válido.');
        }

        $this->db->beginTransaction();
        try {
            if ($estadoAnterior === 'validado' && $estadoNuevo !== 'validado') {
                padelRevertValidatedMatchPoints($this->db, $partidoId);
            }

            $sets = [];
            $params = ['id' => $partidoId];
            if (isset($datos['fecha']) && $datos['fecha'] !== '') {
                $sets[] = 'fecha = :fecha';
                $params['fecha'] = $datos['fecha'];
            }
            if (array_key_exists('fecha_programada', $datos) && dbColumnExists($this->db, 'partidos', 'fecha_programada')) {
                $sets[] = 'fecha_programada = :fp';
                $fp = $datos['fecha_programada'];
                $params['fp'] = ($fp === null || $fp === '') ? null : $fp;
            }
            if (isset($datos['hora']) && $datos['hora'] !== '') {
                $sets[] = 'hora = :hora';
                $params['hora'] = $datos['hora'];
            }
            if (array_key_exists('jornada', $datos) && dbColumnExists($this->db, 'partidos', 'jornada')) {
                $sets[] = 'jornada = :jornada';
                $j = $datos['jornada'];
                $params['jornada'] = ($j === null || $j === '') ? null : (int) $j;
            }
            if (array_key_exists('ganador_pareja_id', $datos)) {
                $g = $datos['ganador_pareja_id'];
                if ($g === null || $g === '' || (int) $g <= 0) {
                    $sets[] = 'ganador_pareja_id = NULL';
                    $sets[] = 'reportado_por_usuario_id = NULL';
                    if (dbColumnExists($this->db, 'partidos', 'resultado_reportado_en')) {
                        $sets[] = 'resultado_reportado_en = NULL';
                    }
                } else {
                    $gInt = (int) $g;
                    $pa = (int) $before['pareja_a_id'];
                    $pb = (int) $before['pareja_b_id'];
                    if ($gInt !== $pa && $gInt !== $pb) {
                        throw new InvalidArgumentException('Ganador debe ser una de las parejas del partido.');
                    }
                    $sets[] = 'ganador_pareja_id = :ganador';
                    $params['ganador'] = $gInt;
                }
            }
            if (array_key_exists('nota_admin', $datos) && dbColumnExists($this->db, 'partidos', 'nota_admin')) {
                $sets[] = 'nota_admin = :nota';
                $nota = trim((string) ($datos['nota_admin'] ?? ''));
                $params['nota'] = $nota === '' ? null : $nota;
            }
            $promoverAValidado = $estadoNuevo === 'validado' && $estadoAnterior !== 'validado';
            if (!$promoverAValidado) {
                $sets[] = 'estado = :estado';
                $params['estado'] = $estadoNuevo;
            }

            if ($sets !== []) {
                $this->db->prepare('UPDATE partidos SET ' . implode(', ', $sets) . ' WHERE id = :id')->execute($params);
            }

            if (!empty($datos['resultado_texto']) && trim((string) $datos['resultado_texto']) !== '') {
                padelReplacePartidoSets($this->db, $partidoId, trim((string) $datos['resultado_texto']));
            }

            if ($estadoNuevo === 'validado') {
                $chk = $this->db->prepare('SELECT ganador_pareja_id FROM partidos WHERE id = :id');
                $chk->execute(['id' => $partidoId]);
                if (empty($chk->fetchColumn())) {
                    throw new InvalidArgumentException('Indica ganador antes de validar.');
                }
                if ($promoverAValidado) {
                    $this->validarPartido($partidoId);
                } elseif ($estadoAnterior === 'validado') {
                    padelRevertValidatedMatchPoints($this->db, $partidoId);
                    padelApplyValidatedMatchPoints($this->db, $partidoId);
                }
            }

            $this->db->commit();
        } catch (Throwable $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $e;
        }
    }
}

function leagueMatchService(PDO $db): LeagueMatchService
{
    return new LeagueMatchService($db);
}
