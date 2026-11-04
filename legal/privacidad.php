<?php
require_once __DIR__ . '/../includes/legal_layout.php';
legalLayoutStart('Política de privacidad');
?>
        <header class="legal-doc-header">
            <h1>Política de privacidad</h1>
            <p class="legal-doc-meta">Última actualización: [FECHA DE PUBLICACIÓN] · Responsable: [TU NOMBRE / EMPRESA]</p>
        </header>

        <section class="legal-doc-section">
            <h2>1. Responsable del tratamiento</h2>
            <ul>
                <li><strong>Identidad:</strong> [TU NOMBRE / RAZÓN SOCIAL]</li>
                <li><strong>NIF/CIF:</strong> [NIF/CIF]</li>
                <li><strong>Domicilio:</strong> [DIRECCIÓN COMPLETA]</li>
                <li><strong>Email de contacto:</strong> [EMAIL DE CONTACTO]</li>
                <li><strong>Delegado de Protección de Datos (si aplica):</strong> [NOMBRE Y CONTACTO DPD O «No aplica»]</li>
            </ul>
        </section>

        <section class="legal-doc-section">
            <h2>2. Ámbito de aplicación</h2>
            <p>Esta política describe cómo <strong>Volea de revés</strong> trata los datos personales de usuarios, administradores y participantes en ligas y torneos de pádel gestionados a través de la aplicación, de conformidad con el Reglamento (UE) 2016/679 (RGPD) y la Ley Orgánica 3/2018 (LOPDGDD).</p>
        </section>

        <section class="legal-doc-section">
            <h2>3. Datos que tratamos</h2>
            <p>En función del uso de la plataforma, podemos tratar las siguientes categorías de datos:</p>
            <ul>
                <li><strong>Datos identificativos y de contacto:</strong> nombre, apellidos, correo electrónico, teléfono móvil.</li>
                <li><strong>Datos de cuenta:</strong> credenciales cifradas, rol, tipo de perfil (liga/torneo), estado de verificación y actividad.</li>
                <li><strong>Datos deportivos y de competición:</strong> pareja, inscripciones, partidos, resultados, clasificación, incidencias y comunicaciones relacionadas.</li>
                <li><strong>Datos técnicos:</strong> dirección IP, identificadores de sesión, registros de acceso y cookies (ver <a href="cookies.php">Política de Cookies</a>).</li>
            </ul>
            <p>No solicitamos categorías especiales de datos (salud, ideología, etc.) salvo que la organización deportiva lo exija expresamente y con base legal adicional; en tal caso se informará aparte.</p>
        </section>

        <section class="legal-doc-section">
            <h2>4. Finalidades y bases jurídicas</h2>
            <table class="legal-doc-table">
                <thead>
                    <tr><th>Finalidad</th><th>Base legal</th></tr>
                </thead>
                <tbody>
                    <tr><td>Creación y gestión de la cuenta de usuario</td><td>Ejecución de contrato / medidas precontractuales (art. 6.1.b RGPD)</td></tr>
                    <tr><td>Gestión de ligas, torneos, partidos y clasificaciones</td><td>Ejecución de contrato e interés legítimo en organizar la competición (art. 6.1.b y 6.1.f)</td></tr>
                    <tr><td>Verificación de email y seguridad (PIN, intentos de acceso)</td><td>Interés legítimo / obligación legal en materia de seguridad (art. 6.1.f y 6.1.c)</td></tr>
                    <tr><td>Comunicaciones operativas (recordatorios de partidos, incidencias)</td><td>Ejecución de contrato e interés legítimo (art. 6.1.b y 6.1.f)</td></tr>
                    <tr><td>Atención de consultas y ejercicio de derechos</td><td>Interés legítimo / obligación legal (art. 6.1.f y 6.1.c)</td></tr>
                    <tr><td>Cumplimiento de obligaciones legales (LSSI-CE, consumo, etc.)</td><td>Obligación legal (art. 6.1.c)</td></tr>
                </tbody>
            </table>
        </section>

        <section class="legal-doc-section">
            <h2>5. Conservación</h2>
            <p>Los datos se conservarán mientras dure la relación con el usuario y, posteriormente, durante los plazos necesarios para atender responsabilidades legales o reclamaciones. Los registros de seguridad y logs se conservarán durante un periodo limitado ([PLAZO LOGS, p. ej. 12 meses]).</p>
        </section>

        <section class="legal-doc-section">
            <h2>6. Destinatarios y encargados</h2>
            <p>Los datos pueden ser accesibles por personal autorizado del responsable y por proveedores que prestan servicios necesarios (alojamiento web, correo electrónico, mantenimiento), con contratos de encargo de tratamiento cuando proceda.</p>
            <ul>
                <li><strong>Hosting / infraestructura:</strong> [PROVEEDOR HOSTING]</li>
                <li><strong>Correo electrónico transaccional:</strong> [PROVEEDOR EMAIL]</li>
            </ul>
            <p>No se cederán datos a terceros con fines comerciales sin consentimiento. Solo se producirán transferencias internacionales si existen garantías adecuadas (cláusulas tipo, decisiones de adecuación, etc.).</p>
        </section>

        <section class="legal-doc-section">
            <h2>7. Derechos de las personas usuarias</h2>
            <p>Puedes ejercer los derechos de acceso, rectificación, supresión, oposición, limitación del tratamiento y portabilidad enviando un correo a [EMAIL DE CONTACTO], adjuntando copia de un documento identificativo. También tienes derecho a reclamar ante la Agencia Española de Protección de Datos (<a href="https://www.aepd.es" target="_blank" rel="noopener noreferrer">www.aepd.es</a>).</p>
        </section>

        <section class="legal-doc-section">
            <h2>8. Medidas de seguridad</h2>
            <p>Se aplican medidas técnicas y organizativas apropiadas (cifrado de contraseñas, control de acceso, tokens CSRF, limitación de intentos de login, copias de seguridad, etc.) para proteger la confidencialidad e integridad de los datos.</p>
        </section>

        <section class="legal-doc-section">
            <h2>9. Menores de edad</h2>
            <p>El servicio no está dirigido a menores de [EDAD MÍNIMA, p. ej. 14] años. Si detectamos registros no autorizados, procederemos a su eliminación.</p>
        </section>

        <section class="legal-doc-section">
            <h2>10. Cambios en esta política</h2>
            <p>Podemos actualizar esta política para reflejar cambios legales o funcionales. Se publicará la versión vigente en esta página con la fecha de actualización.</p>
        </section>

        <p class="legal-doc-back"><a href="../index.php">&larr; Volver al inicio</a></p>
<?php legalLayoutEnd(); ?>
