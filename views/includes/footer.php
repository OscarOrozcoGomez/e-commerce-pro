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
                    <!-- Facebook Link -->
                    <a href="https://www.facebook.com/bellezaybienestar80" target="_blank" class="white-text" title="Visítanos en Facebook">
                        <i class="fab fa-facebook fa-3x"></i>
                    </a>
                    <!-- WhatsApp Link -->
                    <a href="https://wa.me/52334420747" target="_blank" class="white-text" title="Contáctanos por WhatsApp">
                        <i class="fab fa-whatsapp fa-3x"></i>
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
    .page-footer .fab {
        transition: transform 0.3s ease, color 0.3s ease;
    }
    .page-footer .fab:hover {
        transform: scale(1.2);
        color: #f8bbd0; /* Rosa suave del tema al pasar el mouse */
    }
</style>
</body>
</html>