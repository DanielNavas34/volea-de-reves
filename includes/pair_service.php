<?php

/**
 * Servicio de dominio — parejas de pádel (Fase 4).
 * Dos titulares, invitaciones, estados borrador/activa/disuelta.
 */

require_once __DIR__ . '/parejas_helpers.php';
require_once __DIR__ . '/padel_guardrails.php';
require_once __DIR__ . '/usuario_padel_helpers.php';

final class PairService
{
    public function __construct(private PDO $db)
    {
    }

    /** Creación directa admin (pareja activa con 2 titulares). */
    public function crearParejaAdmin(int $usuario1Id, int $usuario2Id, ?string $nombreDisplay, int $creadorId): int
    {
        return adminCrearPareja($this->db, $usuario1Id, $usuario2Id, $nombreDisplay, $creadorId);
    }

    public function actualizarParejaAdmin(int $parejaId, string $estado, ?string $nombreDisplay, ?string $notaAdmin = null): void
    {
        if (!in_array($estado, ['borrador', 'activa', 'inactiva', 'disuelta'], true)) {
            $estado = 'activa';
        }
        $hasNota = dbColumnExists($this->db, 'parejas', 'nota_admin');
        if (parejasTieneNombreDisplay($this->db)) {
            $sql = 'UPDATE parejas SET nombre_display = :n, estado = :e';
            if ($hasNota) {
                $sql .= ', nota_admin = :nota';
            }
            $sql .= ' WHERE id = :id';
            $params = [
                'n' => $nombreDisplay !== null && $nombreDisplay !== '' ? $nombreDisplay : null,
                'e' => $estado,
                'id' => $parejaId,
            ];
            if ($hasNota) {
                $params['nota'] = $notaAdmin !== null && trim($notaAdmin) !== '' ? trim($notaAdmin) : null;
            }
            $this->db->prepare($sql)->execute($params);
        } else {
            $sql = 'UPDATE parejas SET estado = :e';
            if ($hasNota) {
                $sql .= ', nota_admin = :nota';
            }
            $sql .= ' WHERE id = :id';
            $params = ['e' => $estado, 'id' => $parejaId];
            if ($hasNota) {
                $params['nota'] = $notaAdmin !== null && trim($notaAdmin) !== '' ? trim($notaAdmin) : null;
            }
            $this->db->prepare($sql)->execute($params);
        }
        if ($estado === 'disuelta' || $estado === 'inactiva') {
            $this->db->prepare(
                'UPDATE pareja_miembros SET activo = 0, hasta = NOW() WHERE pareja_id = :id AND activo = 1'
            )->execute(['id' => $parejaId]);
        }
    }

    /** @return array{ok: bool, message: string, invitado_nombre?: string} */
    public function enviarInvitacionAUsuario(int $invitadorId, int $invitadoId): array
    {
        if ($invitadoId <= 0) {
            return ['ok' => false, 'message' => 'Jugador no válido.'];
        }
        $stmt = $this->db->prepare(
            "SELECT id, nombre, apellidos FROM usuarios WHERE id = :id AND activo = 1 AND rol = 'usuario' LIMIT 1"
        );
        $stmt->execute(['id' => $invitadoId]);
        $invitado = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$invitado) {
            return ['ok' => false, 'message' => 'Jugador no encontrado.'];
        }
        $bloqueo = parejaMotivoBloqueoEnviarInvitacion($this->db, $invitadorId, (int) $invitado['id']);
        if ($bloqueo) {
            return ['ok' => false, 'message' => $bloqueo];
        }

        try {
            $this->db->beginTransaction();
            $this->db->prepare(
                "INSERT INTO parejas (estado, creado_por_usuario_id) VALUES ('borrador', :uid)"
            )->execute(['uid' => $invitadorId]);
            $parejaId = (int) $this->db->lastInsertId();
            $this->db->prepare(
                "INSERT INTO pareja_miembros (pareja_id, usuario_id, rol, activo) VALUES (:pid, :uid, 'titular', 1)"
            )->execute(['pid' => $parejaId, 'uid' => $invitadorId]);
            $this->db->prepare(
                "INSERT INTO pareja_invitaciones (pareja_id, invitador_usuario_id, invitado_usuario_id, estado, expira_en)
                 VALUES (:pid, :inv, :invitado, 'pendiente', DATE_ADD(NOW(), INTERVAL 7 DAY))"
            )->execute([
                'pid' => $parejaId,
                'inv' => $invitadorId,
                'invitado' => (int) $invitado['id'],
            ]);
            $this->db->commit();

            return [
                'ok' => true,
                'message' => 'Invitación enviada a ' . trim($invitado['nombre'] . ' ' . $invitado['apellidos']) . '.',
                'invitado_nombre' => (string) $invitado['nombre'],
            ];
        } catch (PDOException) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }

            return ['ok' => false, 'message' => 'No se pudo enviar la invitación (¿invitación duplicada?).'];
        }
    }

    /** @return array{ok: bool, message: string, invitado_nombre?: string} */
    public function enviarInvitacionPorEmail(int $invitadorId, string $email): array
    {
        $email = filter_var(trim($email), FILTER_SANITIZE_EMAIL);
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return ['ok' => false, 'message' => 'Email no válido.'];
        }
        $stmt = $this->db->prepare(
            "SELECT id FROM usuarios WHERE email = :e AND activo = 1 AND rol = 'usuario' LIMIT 1"
        );
        $stmt->execute(['e' => $email]);
        $invitadoId = (int) $stmt->fetchColumn();
        if ($invitadoId <= 0) {
            return ['ok' => false, 'message' => 'No hay ningún jugador activo con ese email.'];
        }

        return $this->enviarInvitacionAUsuario($invitadorId, $invitadoId);
    }

    /** @return array{ok: bool, message: string} */
    public function responderInvitacion(int $invitadoId, int $invitacionId, string $respuesta): array
    {
        $stmt = $this->db->prepare(
            "SELECT * FROM pareja_invitaciones WHERE id = :id AND invitado_usuario_id = :uid AND estado = 'pendiente' LIMIT 1"
        );
        $stmt->execute(['id' => $invitacionId, 'uid' => $invitadoId]);
        $inv = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$inv) {
            return ['ok' => false, 'message' => 'Invitación no encontrada o ya respondida.'];
        }

        if ($respuesta === 'rechazar') {
            $this->db->prepare(
                "UPDATE pareja_invitaciones SET estado = 'rechazada', respondido_en = NOW() WHERE id = :id"
            )->execute(['id' => $invitacionId]);

            return ['ok' => true, 'message' => 'Invitación rechazada.'];
        }

        if ($respuesta !== 'aceptar') {
            return ['ok' => false, 'message' => 'Respuesta no válida.'];
        }

        $bloqueo = parejaMotivoBloqueoAceptarInvitacion(
            $this->db,
            $invitadoId,
            (int) $inv['pareja_id'],
            (int) $inv['invitador_usuario_id']
        );
        if ($bloqueo) {
            return ['ok' => false, 'message' => $bloqueo];
        }

        try {
            $this->db->beginTransaction();
            $this->db->prepare(
                "INSERT INTO pareja_miembros (pareja_id, usuario_id, rol, activo) VALUES (:pid, :uid, 'titular', 1)"
            )->execute(['pid' => $inv['pareja_id'], 'uid' => $invitadoId]);
            $this->db->prepare("UPDATE parejas SET estado = 'activa' WHERE id = :id")->execute(['id' => $inv['pareja_id']]);
            $this->db->prepare(
                "UPDATE pareja_invitaciones SET estado = 'aceptada', respondido_en = NOW() WHERE id = :id"
            )->execute(['id' => $invitacionId]);
            $this->db->prepare(
                "UPDATE pareja_invitaciones SET estado = 'expirada'
                 WHERE invitado_usuario_id = :uid AND id != :id AND estado = 'pendiente'"
            )->execute(['uid' => $invitadoId, 'id' => $invitacionId]);
            $this->db->commit();

            return ['ok' => true, 'message' => '¡Pareja confirmada!'];
        } catch (PDOException) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }

            return ['ok' => false, 'message' => 'Error al aceptar la invitación.'];
        }
    }

    /** @return array{ok: bool, message: string} */
    public function disolverParejaDeUsuario(int $usuarioId): array
    {
        $parejaId = fetchParejaIdUsuario($this->db, $usuarioId);
        if (!$parejaId) {
            return ['ok' => false, 'message' => 'No tienes pareja activa.'];
        }
        $motivo = parejaMotivoBloqueoDisolucion($this->db, $parejaId);
        if ($motivo) {
            return ['ok' => false, 'message' => $motivo];
        }

        try {
            $this->db->beginTransaction();
            $this->db->prepare("UPDATE parejas SET estado = 'disuelta' WHERE id = :id")->execute(['id' => $parejaId]);
            $this->db->prepare(
                'UPDATE pareja_miembros SET activo = 0, hasta = NOW() WHERE pareja_id = :id AND activo = 1'
            )->execute(['id' => $parejaId]);
            $this->db->commit();

            return ['ok' => true, 'message' => 'Pareja disuelta.'];
        } catch (PDOException) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }

            return ['ok' => false, 'message' => 'No se pudo disolver la pareja.'];
        }
    }

    public function contarTitularesActivos(int $parejaId): int
    {
        $stmt = $this->db->prepare(
            "SELECT COUNT(*) FROM pareja_miembros WHERE pareja_id = :pid AND activo = 1 AND rol = 'titular'"
        );
        $stmt->execute(['pid' => $parejaId]);

        return (int) $stmt->fetchColumn();
    }
}

function pairService(PDO $db): PairService
{
    return new PairService($db);
}
