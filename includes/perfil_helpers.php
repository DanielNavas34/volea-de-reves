<?php

const TIPO_PERFIL_LIGA = 'liga';
const TIPO_PERFIL_PULL = 'pull';

/** Perfil orientado a Pull (inscripción individual). Acepta valor legacy solo_torneo. */
function tipoPerfilEsPull(?string $tipo): bool
{
    return in_array($tipo, [TIPO_PERFIL_PULL, 'solo_torneo'], true);
}

function tipoPerfilDesdeRegistro(array $post): string
{
    if (!empty($post['modalidad_pull']) || !empty($post['solo_torneo'])) {
        return TIPO_PERFIL_PULL;
    }

    return TIPO_PERFIL_LIGA;
}

/** Tipos de competición en esquema pádel. */
function competicionTipoPullSqlIn(): string
{
    return "'pull','torneo'";
}
