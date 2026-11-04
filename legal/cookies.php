<?php
require_once __DIR__ . '/../includes/legal_layout.php';
legalLayoutStart('Política de cookies');
?>
        <header class="legal-doc-header">
            <h1>Política de cookies</h1>
            <p class="legal-doc-meta">Última actualización: [FECHA DE PUBLICACIÓN]</p>
        </header>

        <section class="legal-doc-section">
            <h2>1. ¿Qué son las cookies?</h2>
            <p>Las cookies y tecnologías similares (como el almacenamiento local del navegador) permiten recordar información sobre tu visita o sesión. En <strong>Volea de revés</strong> las utilizamos principalmente para el funcionamiento seguro de la aplicación y, opcionalmente, para recordar tus preferencias.</p>
        </section>

        <section class="legal-doc-section">
            <h2>2. Tipos de cookies que utilizamos</h2>
            <table class="legal-doc-table">
                <thead>
                    <tr><th>Nombre / clave</th><th>Tipo</th><th>Finalidad</th><th>Duración</th></tr>
                </thead>
                <tbody>
                    <tr>
                        <td>Cookie de sesión PHP (<code>PHPSESSID</code> o similar)</td>
                        <td>Técnica / esencial</td>
                        <td>Mantener la sesión iniciada de forma segura</td>
                        <td>Sesión</td>
                    </tr>
                    <tr>
                        <td>Token CSRF (si aplica en formularios)</td>
                        <td>Técnica / esencial</td>
                        <td>Proteger formularios contra ataques cross-site</td>
                        <td>Sesión o corta</td>
                    </tr>
                    <tr>
                        <td><code>preferencia_cookies</code> (localStorage y cookie técnica)</td>
                        <td>Preferencia</td>
                        <td>Recordar si aceptaste todas las cookies o solo las esenciales</td>
                        <td>Hasta [365] días</td>
                    </tr>
                </tbody>
            </table>
            <p><strong>Nota:</strong> En la versión actual no utilizamos cookies de publicidad ni de analítica de terceros. Si en el futuro se incorporaran, se solicitará consentimiento previo y se actualizará esta tabla.</p>
        </section>

        <section class="legal-doc-section">
            <h2>3. Base legal</h2>
            <p>Las cookies estrictamente necesarias para prestar el servicio solicitado por el usuario se utilizan en base al artículo 22.2 de la LSSI-CE y al interés legítimo / ejecución del contrato. Las cookies no esenciales, si las hubiera, requerirían consentimiento previo conforme al RGPD.</p>
        </section>

        <section class="legal-doc-section">
            <h2>4. Gestión del consentimiento</h2>
            <p>Al acceder por primera vez verás un banner inferior con las opciones <strong>Aceptar todas</strong> y <strong>Rechazar no esenciales</strong>. Puedes modificar tu elección eliminando la clave <code>preferencia_cookies</code> del almacenamiento local de tu navegador o borrando las cookies del sitio [DOMINIO DE TU WEB].</p>
        </section>

        <section class="legal-doc-section">
            <h2>5. Cómo desactivar cookies desde el navegador</h2>
            <p>Puedes configurar tu navegador para bloquear o eliminar cookies. Ten en cuenta que desactivar las cookies técnicas puede impedir el inicio de sesión o el uso correcto de la aplicación.</p>
            <ul>
                <li><a href="https://support.google.com/chrome/answer/95647" target="_blank" rel="noopener noreferrer">Google Chrome</a></li>
                <li><a href="https://support.mozilla.org/es/kb/habilitar-y-deshabilitar-cookies-sitios-web-rastrear-preferencias" target="_blank" rel="noopener noreferrer">Mozilla Firefox</a></li>
                <li><a href="https://support.apple.com/es-es/guide/safari/sfri11471/mac" target="_blank" rel="noopener noreferrer">Safari</a></li>
            </ul>
        </section>

        <section class="legal-doc-section">
            <h2>6. Más información</h2>
            <p>Para consultas sobre privacidad y cookies: [EMAIL DE CONTACTO]. Consulta también nuestra <a href="privacidad.php">Política de Privacidad</a>.</p>
        </section>

        <p class="legal-doc-back"><a href="../index.php">&larr; Volver al inicio</a></p>
<?php legalLayoutEnd(); ?>
