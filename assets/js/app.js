// /assets/js/app.js
// Manejo del DOM, Validaciones, Menú, Cookies y Sistema de Publicidad

document.addEventListener("DOMContentLoaded", () => {
    
    // --- 1. MENÚ HAMBURGUESA GLOBAL ---
    const hamburgerBtn = document.getElementById('hamburger-btn');
    const navLinks = document.getElementById('nav-links');

    if (hamburgerBtn && navLinks) {
        // Funciona tanto pulsando el icono como la palabra "Menú" (si la pones)
        hamburgerBtn.addEventListener('click', () => {
            navLinks.classList.toggle('active');
            hamburgerBtn.classList.toggle('toggle');
        });
    }

    // --- 2. BANNER DE COOKIES (RGPD / LSSI-CE) ---
    const cookieBanner = document.getElementById('cookie-banner');
    const acceptBtn = document.getElementById('btn-accept-cookies');
    const rejectBtn = document.getElementById('btn-reject-cookies');
    const COOKIE_PREF_KEY = 'preferencia_cookies';
    const COOKIE_PREF_DAYS = 365;

    function getCookiePreference() {
        return localStorage.getItem(COOKIE_PREF_KEY);
    }

    function setCookiePreference(value) {
        localStorage.setItem(COOKIE_PREF_KEY, value);
        const maxAge = COOKIE_PREF_DAYS * 24 * 60 * 60;
        document.cookie = `${COOKIE_PREF_KEY}=${encodeURIComponent(value)}; path=/; max-age=${maxAge}; SameSite=Lax`;
        if (cookieBanner) {
            cookieBanner.classList.add('hidden');
        }
    }

    if (cookieBanner && !getCookiePreference()) {
        cookieBanner.classList.remove('hidden');
    }

    if (acceptBtn) {
        acceptBtn.addEventListener('click', () => setCookiePreference('aceptadas_todas'));
    }

    if (rejectBtn) {
        rejectBtn.addEventListener('click', () => setCookiePreference('solo_esenciales'));
    }

    // --- 3. GESTIÓN DE PUBLICIDAD ---
    
    // Publicidad TIPO 1: Pantalla Completa (La Malagueña)
    const adFullscreen = document.getElementById('ad-fullscreen');
    if (adFullscreen) {
        // Aparece automáticamente a los 0.5 segundos de cargar la página
        setTimeout(() => {
            adFullscreen.classList.remove('hidden');
        }, 50);

        // Desaparece si el usuario hace clic en cualquier parte de la pantalla
        adFullscreen.addEventListener('click', () => {
            adFullscreen.classList.add('hidden');
        });

        // Auto-cierre de seguridad a los 6.5 segundos
        setTimeout(() => {
            if (!adFullscreen.classList.contains('hidden')) {
                adFullscreen.classList.add('hidden');
            }
        }, 2500);
    }

    // Publicidad TIPO 2: Rotación de Banner Superior
    const adTopBanner = document.getElementById("ad-top-banner");
    if (adTopBanner) {
        const ads = [
            "<div style='display: flex; justify-content: center; align-items: center; gap: 10px;'><span>Aplicación Web en construcción (Publi 1)</span></div>",
            "<div style='display: flex; justify-content: center; align-items: center; gap: 10px;'><span>Aplicación Web en construcción (Publi 2)</span></div>",
            "<div style='display: flex; justify-content: center; align-items: center; gap: 10px;'><span>Aplicación Web en construcción (Publi 3).</span></div>"
        ];
        let currentAd = 0;
        
        setInterval(() => {
            currentAd = (currentAd + 1) % ads.length;
            adTopBanner.innerHTML = ads[currentAd];
        }, 5000);
    }

    // --- 4. VALIDACIONES DE FORMULARIO (Frontend) ---
    const registerForm = document.getElementById("registroForm");
    if (registerForm) {
        registerForm.addEventListener("submit", (e) => {
            const password = document.getElementById("password").value;
            const confirm = document.getElementById("confirm_password").value;
            
            if (password !== confirm) {
                e.preventDefault();
                alert("Las contraseñas no coinciden. Por favor, verifica.");
            } else if (password.length < 8) {
                e.preventDefault();
                alert("La contraseña debe tener al menos 8 caracteres por seguridad.");
            }
        });
    }

    // --- 5. UX: CONFIRMACIONES SEGURAS (Borrado) ---
    const deleteButtons = document.querySelectorAll(".btn-delete");
    deleteButtons.forEach(btn => {
        btn.addEventListener("click", (e) => {
            if (!confirm("¿Estás absolutamente seguro de que deseas eliminar este registro? Esta acción no se puede deshacer.")) {
                e.preventDefault(); 
            }
        });
    });
  // --- 6. UX: EXPANDIR/CONTRAER TABLAS EN MÓVILES (Con cierre inteligente) ---
    const standingsCols = document.querySelectorAll('.standings-col');
    
    standingsCols.forEach(col => {
        col.addEventListener('click', function(e) {
            // Evitamos que el evento se propague hacia arriba de golpe
            e.stopPropagation();
            
            // Si ya está abierta, no hacemos nada (dejamos hacer scroll dentro) 
            // a menos que toquen la cabecera (h3) para cerrarla opcionalmente.
            const isAlreadyOpen = this.classList.contains('is-expanded');
            
            // Cerramos todas las demás columnas primero (efecto acordeón limpio)
            standingsCols.forEach(c => c.classList.remove('is-expanded'));
            
            // Si no estaba abierta, la abrimos
            if (!isAlreadyOpen) {
                this.classList.add('is-expanded');
            }
        });
    });

    // Si el usuario hace clic en cualquier otra parte de la página, se cierra todo
    document.addEventListener('click', (e) => {
        if (!e.target.closest('.standings-col')) {
            standingsCols.forEach(col => col.classList.remove('is-expanded'));
        }
    });
});