</main> <!-- Cierre de main definido en header.php -->

<!-- Botón Ir Arriba (Scroll to Top) -->
<button id="scroll-to-top" class="btn-floating btn-large blue darken-4 waves-effect waves-light" style="display: none; position: fixed; bottom: 30px; right: 30px; z-index: 1000; box-shadow: 0 4px 10px rgba(0,0,0,0.3);">
    <i class="material-icons">arrow_upward</i>
</button>

<footer class="page-footer blue darken-4" style="margin-top: 50px;">
    <div class="container">
        <div class="row">
            <div class="col l6 s12">
                <h5 class="white-text">Belleza y Bienestar</h5>
                <p class="grey-text text-lighten-4">Tu tienda de confianza para el cuidado personal y la salud en la Zona Metropolitana de Guadalajara.</p>
            </div>
            <div class="col l4 offset-l2 s12">
                <h5 class="white-text">Conecta con nosotros</h5>
                <div style="display: flex; gap: 25px; margin-top: 15px;">
                    <!-- Facebook -->
                    <a href="https://www.facebook.com/bellezamasbienestar/" target="_blank" aria-label="Facebook" class="s_share_facebook white-text">
                        <i class="fa-brands fa-facebook o_editable_media rounded-empty-circle shadow-sm fa-2x"></i>
                    </a>
                    <!-- WhatsApp -->
                    <a href="https://api.whatsapp.com/send?phone=5213344420747" target="_blank" aria-label="WhatsApp" class="s_share_whatsapp white-text">
                        <i class="fa-brands fa-whatsapp o_editable_media rounded-empty-circle shadow-sm fa-2x"></i>
                    </a>
                </div>
            </div>
        </div>
    </div>
    <div class="footer-copyright">
        <div class="container">
            <div style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap;">
                <span>© <?php echo date('Y'); ?> Belleza y Bienestar.</span>
                <a class="grey-text text-lighten-4" href="<?php echo BASE_URL; ?>views/terminos.php">Términos y Condiciones</a>
            </div>
        </div>
    </div>
</footer>

<style>
    /* Adaptación del botón Ir Arriba para móviles */
    @media only screen and (max-width: 600px) {
        #scroll-to-top {
            bottom: 20px !important;
            right: 20px !important;
            width: 45px !important;
            height: 45px !important;
        }
        #scroll-to-top i {
            line-height: 45px !important;
            font-size: 1.5rem !important;
        }
    }

    /* Estilos estilo Odoo para iconos sociales */
    .rounded-empty-circle {
        display: inline-flex !important;
        align-items: center;
        justify-content: center;
        width: 50px;
        height: 50px;
        border: 2px solid rgba(255,255,255,0.7);
        border-radius: 50%;
        transition: all 0.3s ease;
    }
    .shadow-sm {
        box-shadow: 0 .125rem .25rem rgba(0,0,0,.075)!important;
    }
    .page-footer .fa-brands:hover {
        transform: translateY(-3px);
        background-color: white;
        color: #0d47a1 !important; /* Azul oscuro al hover */
        border-color: white;
    }
</style>
<!-- Scripts JS -->
<script src="https://cdnjs.cloudflare.com/ajax/libs/materialize/1.0.0/js/materialize.min.js"></script>
<script>
    // Los links con clase "whatsapp-business-link" (data-wa-phone = numero con lada, solo digitos)
    // se reescriben en Android para forzar la apertura de WhatsApp Business (com.whatsapp.w4b) en
    // lugar de WhatsApp personal, cuando ambas apps estan instaladas. En iOS/escritorio no hay forma
    // confiable de elegir la app, asi que se deja el link normal de wa.me como esta.
    document.addEventListener('DOMContentLoaded', () => {
        const isAndroid = /Android/i.test(navigator.userAgent || '');
        if (isAndroid) {
            document.querySelectorAll('a.whatsapp-business-link[data-wa-phone]').forEach((link) => {
                const phone = (link.getAttribute('data-wa-phone') || '').replace(/\D/g, '');
                if (!phone) {
                    return;
                }
                const fallback = encodeURIComponent('https://wa.me/' + phone);
                link.setAttribute('href', 'intent://send/?phone=' + encodeURIComponent(phone) + '#Intent;scheme=smsto;package=com.whatsapp.w4b;S.browser_fallback_url=' + fallback + ';end');
            });
        }

        // Inicializar componentes de Materialize de forma global y segura
        if (typeof M !== 'undefined') {
            try {
                M.AutoInit();
            } catch (err) {
                console.warn('Materialize AutoInit falló en el footer:', err);
                // Fallback por si AutoInit no está disponible o falla
                const sidenavElems = document.querySelectorAll('.sidenav');
                if (M.Sidenav && sidenavElems.length) M.Sidenav.init(sidenavElems);

                const dropdownElems = document.querySelectorAll('.dropdown-trigger');
                if (M.Dropdown && dropdownElems.length) M.Dropdown.init(dropdownElems, {
                    alignment: 'right', constrainWidth: false, coverTrigger: false, closeOnClick: true
                });
            }
        }
    });
</script>
</body>
</html>