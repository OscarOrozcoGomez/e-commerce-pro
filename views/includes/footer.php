    <!-- Botón Volver Arriba -->
    <div id="scroll-to-top" style="display: none; position: fixed; bottom: 20px; right: 20px; z-index: 999;">
        <a class="btn-floating btn-large blue darken-4 waves-effect waves-light z-depth-3">
            <i class="material-icons">arrow_upward</i>
        </a>
    </div>

    <script src="https://cdnjs.cloudflare.com/ajax/libs/materialize/1.0.0/js/materialize.min.js"></script>
    <script>
        // Función global para actualizar el contador del carrito
        function updateCartBadge() {
            try {
                // Asumimos que el carrito se guarda en localStorage como un array
                let cart = JSON.parse(localStorage.getItem('cart') || '[]');
                
                // Filtrar items corruptos (sin nombre o con errores previos)
                const validItems = cart.filter(item => item.nombre && !isNaN(item.precio));
                
                // Si había basura, limpiamos el localStorage para que no estorbe
                if (validItems.length !== cart.length) {
                    localStorage.setItem('cart', JSON.stringify(validItems));
                }

                const totalItems = validItems.reduce((sum, item) => sum + (parseInt(item.quantity) || 0), 0);
                
                const badge = document.getElementById('cart-count');
                if (badge) {
                    if (totalItems > 0) {
                        badge.textContent = totalItems;
                        badge.style.display = 'inline-block';
                    } else {
                        badge.style.display = 'none';
                    }
                }
            } catch (e) {
                console.error("Error actualizando el badge del carrito:", e);
            }
        }

        document.addEventListener('DOMContentLoaded', function() {
            const selects = document.querySelectorAll('select');
            M.FormSelect.init(selects);
            const modals = document.querySelectorAll('.modal');
            M.Modal.init(modals);
            const dropdowns = document.querySelectorAll('.dropdown-trigger');
            M.Dropdown.init(dropdowns);
            
            // Lógica del botón Volver Arriba
            const scrollTopBtn = document.getElementById('scroll-to-top');
            
            window.addEventListener('scroll', function() {
                if (window.pageYOffset > 400) {
                    scrollTopBtn.style.display = "block";
                } else {
                    scrollTopBtn.style.display = "none";
                }
            });

            scrollTopBtn.addEventListener('click', function() {
                window.scrollTo({top: 0, behavior: 'smooth'});
            });

            // Inicializar el contador al cargar la página
            updateCartBadge();
        });
        
        // Escuchar cambios en otros tabs (opcional pero recomendado)
        window.addEventListener('storage', (e) => {
            if (e.key === 'cart') updateCartBadge();
        });
    </script>
</body>
</html>