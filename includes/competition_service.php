<?php

/**
 * Servicio de dominio — competiciones de liga (Fase 5).
 * Creación, publicación, inscripciones de pareja y calendario round robin.
 */

require_once __DIR__ . '/liga_service.php';

final class LeagueCompetitionService
{
    public function __construct(private PDO $db)
    {
    }

    public function crearLiga(string $nombre, string $modalidad = 'general'): int
    {
        return ligaCrearVacia($this->db, $nombre, $modalidad);
    }

    public function publicarLiga(int $competicionId, string $estado = 'abierta'): bool
    {
        return ligaPublicarCompeticion($this->db, $competicionId, $estado);
    }

    /** Solicitud usuario → estado pendiente. */
    public function solicitarInscripcion(int $parejaId, int $grupoId): void
    {
        ligaSolicitarInscripcionUsuario($this->db, $parejaId, $grupoId);
    }

    public function inscribirParejaAdmin(int $competicionId, int $grupoId, int $parejaId, string $estado = 'confirmada'): void
    {
        ligaInscribirPareja($this->db, $competicionId, $grupoId, $parejaId, $estado);
    }

    public function actualizarEstadoInscripcion(int $inscripcionId, int $grupoId, string $estado): void
    {
        if (!in_array($estado, ['pendiente', 'confirmada', 'rechazada', 'baja'], true)) {
            throw new InvalidArgumentException('Estado de inscripción no válido.');
        }
        $grupo = ligaFetchGrupo($this->db, $grupoId);
        if (!$grupo) {
            throw new InvalidArgumentException('Grupo no encontrado.');
        }
        $stmt = $this->db->prepare(
            'UPDATE inscripciones SET estado = :e WHERE id = :id AND grupo_id = :gid'
        );
        $stmt->execute(['e' => $estado, 'id' => $inscripcionId, 'gid' => $grupoId]);
        if ($stmt->rowCount() === 0) {
            throw new InvalidArgumentException('Inscripción no encontrada.');
        }
        if ($estado === 'confirmada') {
            $stPid = $this->db->prepare(
                'SELECT pareja_id FROM inscripciones WHERE id = :id AND grupo_id = :gid'
            );
            $stPid->execute(['id' => $inscripcionId, 'gid' => $grupoId]);
            $pid = (int) $stPid->fetchColumn();
            if ($pid > 0) {
                padelEnsureClasificacionRow($this->db, (int) $grupo['competicion_id'], $grupoId, $pid);
            }
        }
    }

    public function eliminarInscripcion(int $inscripcionId, int $grupoId): void
    {
        $stmt = $this->db->prepare('DELETE FROM inscripciones WHERE id = :id AND grupo_id = :gid');
        $stmt->execute(['id' => $inscripcionId, 'gid' => $grupoId]);
        if ($stmt->rowCount() === 0) {
            throw new InvalidArgumentException('No se pudo eliminar la inscripción.');
        }
    }

    /** @return array{created:int, skipped:int, jornadas:int, message:string} */
    public function generarCalendarioRoundRobin(int $grupoId, ?string $fechaInicio = null, int $diasEntreJornadas = 7): array
    {
        return ligaGenerarCalendarioRoundRobin($this->db, $grupoId, $fechaInicio, $diasEntreJornadas);
    }

    public function contarInscripcionesConfirmadasGrupo(int $grupoId): int
    {
        $grupo = ligaFetchGrupo($this->db, $grupoId);
        if (!$grupo) {
            return 0;
        }
        $stmt = $this->db->prepare(
            "SELECT COUNT(*) FROM inscripciones
             WHERE competicion_id = :cid AND grupo_id = :gid AND estado = 'confirmada'"
        );
        $stmt->execute(['cid' => (int) $grupo['competicion_id'], 'gid' => $grupoId]);

        return (int) $stmt->fetchColumn();
    }
}

function leagueCompetitionService(PDO $db): LeagueCompetitionService
{
    return new LeagueCompetitionService($db);
}
