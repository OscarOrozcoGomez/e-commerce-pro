<?php
require_once __DIR__ . '/../core/config.php';
require_once __DIR__ . '/../core/auth.php';

$pageTitle = 'Mi Carrito de Compras';
$usuarioLogueado = $_SESSION['usuario'] ?? null;
$isUserAuthenticated = isAuthenticated(); // Obtener el estado de autenticación de PHP

$direcciones = [];
if ($isUserAuthenticated && isCliente()) {
    $pdo = getPDO();
    $stmtDir = $pdo->prepare("SELECT * FROM cliente_direcciones WHERE id_cliente = ? ORDER BY es_default DESC");
    $stmtDir->execute([$usuarioLogueado['id_cliente']]);
    $direcciones = $stmtDir->fetchAll();
}

$telefonoGuardado = $usuarioLogueado['telefono_cliente'] ?? '';
$hasSavedAddresses = !empty($direcciones);

include __DIR__ . '/includes/header.php';
?>

<div class="container" style="margin-top: 30px;">
    <div class="row">
        <div class="col s12 m8">
            <div class="card">
                <div class="card-content">
                    <span class="card-title">Resumen de Compra</span>
                    <table class="highlight">
                        <thead>
                            <tr>
                                <th>Producto</th>
                                <th>Precio</th>
                                <th>Cant.</th>
                                <th>Subtotal</th>
                                <th></th>
                            </tr>
                        </thead>
                        <tbody id="cart-table-body">
                            <!-- Se llena con JS -->
                        </tbody>
                    </table>
                    <div style="margin-top: 20px; display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap;">
                        <button type="button" onclick="clearCart()" class="btn-flat red-text waves-effect" id="btn-empty-cart" style="display: none; font-weight: bold;">
                            <i class="material-icons left">delete_sweep</i> VACIAR CARRITO
                        </button>
                        <h5 style="margin: 0; font-weight: bold;">Total: $<span id="cart-total-display">0.00</span></h5>
                    </div>
                </div>
            </div>
        </div>

        <div class="col s12 m4">
            <div class="card">
                <div class="card-content">
                    <span class="card-title">Confirmar Reserva</span>
                    <div class="card-panel blue lighten-5">
                        <p class="blue-text text-darken-4" style="margin-bottom: 10px;">
                            <i class="material-icons left">account_balance</i> <strong>Datos de Transferencia:</strong><br>
                            Banco: [Nombre del Banco]<br>
                            Cuenta: [Tu Cuenta]<br>
                            CLABE: [Tu CLABE]
                        </p>
                        <p class="orange-text text-darken-4" style="font-weight: bold;">
                            <i class="material-icons left">report_problem</i> Importante: Envía tu comprobante de anticipo de $50 vía WhatsApp para confirmar tu lugar en la entrega.
                        </p>
                    </div>
                    <form id="form-checkout">
                        <!-- PASO 1: Tipo de entrega -->
                        <div style="margin-bottom: 20px;">
                            <label style="display: block; margin-bottom: 8px; font-weight: bold; color: #333; font-size: 0.9rem;">¿Cómo deseas recibir tu pedido?</label>
                            <select id="tipo_entrega" name="tipo_entrega" required class="browser-default" style="border: 1px solid #9e9e9e; border-radius: 4px; padding: 10px; height: auto; width: 100%;">
                                <option value="" disabled selected>Selecciona método de entrega</option>
                                <option value="Sucursal">Recoger en Sucursal (Gratis)</option>
                                <option value="Domicilio">Entrega a Domicilio (Miércoles y Sábados)</option>
                            </select>
                        </div>

                        <!-- Info sucursal (visible solo cuando se elige Sucursal) -->
                        <div id="info-sucursal" style="display:none; margin-bottom: 20px;">
                            <div class="card-panel blue lighten-5" style="margin:0; border-radius:8px;">
                                <p style="margin:0 0 8px 0; font-weight:bold; color:#0d47a1;">
                                    <i class="material-icons tiny">store</i> Punto de Venta
                                </p>
                                <p style="margin:0 0 4px 0; color:#333;">
                                    Tabachín 248, Bosques de Tonalá,<br>45400 Tonalá, Jal.
                                </p>
                                <a href="https://maps.app.goo.gl/gasKXxJgcHsvG3qM6" target="_blank"
                                   class="btn-small blue darken-4 waves-effect waves-light"
                                   style="margin-top:10px; margin-bottom:14px; width:100%; text-align:center;">
                                    <i class="material-icons left">navigation</i> Cómo Llegar
                                </a>
                                <p style="margin:0 0 4px 0; font-weight:bold; color:#333; font-size:0.85rem;">
                                    <i class="material-icons tiny">schedule</i> Horarios:
                                </p>
                                <table style="width:100%; font-size:0.82rem; border-collapse:collapse;">
                                    <tr><td style="padding:2px 6px;">Lunes – Miércoles</td><td style="padding:2px 6px; color:#2e7d32;">7:30 AM – 8:00 PM</td></tr>
                                    <tr><td style="padding:2px 6px;">Jueves</td><td style="padding:2px 6px; color:#2e7d32;">7:30 AM – 1:50 PM</td></tr>
                                    <tr><td style="padding:2px 6px;">Viernes</td><td style="padding:2px 6px; color:#2e7d32;">7:30 AM – 8:00 PM</td></tr>
                                    <tr><td style="padding:2px 6px;">Sábado</td><td style="padding:2px 6px; color:#2e7d32;">9:00 AM – 3:00 PM</td></tr>
                                    <tr><td style="padding:2px 6px;">Domingo</td><td style="padding:2px 6px; color:#c62828;">Cerrado</td></tr>
                                </table>
                            </div>
                        </div>

                        <!-- PASO 2: Mis Direcciones (solo domicilio) -->
                        <?php if (!empty($direcciones)): ?>
                        <div id="wrapper-select-direccion" style="display:none; margin-bottom: 20px;">
                            <label style="display: block; margin-bottom: 8px; font-weight: bold; color: #333; font-size: 0.9rem;">Mis Direcciones</label>
                            <select id="select_direccion" class="browser-default" style="border: 1px solid #9e9e9e; border-radius: 4px; padding: 10px; height: auto; width: 100%;">
                                <option value="">-- Seleccionar dirección guardada --</option>
                                <?php foreach ($direcciones as $d): ?>
                                    <option value="<?php echo esc($d['direccion']); ?>" data-maps-link="<?php echo esc($d['maps_link'] ?? ''); ?>" <?php echo $d['es_default'] ? 'selected' : ''; ?>>
                                        <?php echo esc($d['alias']); ?>: <?php echo esc($d['direccion']); ?>
                                    </option>
                                <?php endforeach; ?>
                                <option value="__other__" data-maps-link="">+ Agregar otra dirección para este pedido</option>
                            </select>
                            <span class="helper-text">También puedes guardar esta nueva dirección después del pedido.</span>
                        </div>
                        <?php endif; ?>

                        <!-- Buscador de Google Maps (solo domicilio) -->
                        <div id="wrapper-maps-search" style="display:none; margin-bottom: 20px;">
                            <label style="display: block; margin-bottom: 8px; font-weight: bold; color: #333; font-size: 0.9rem;">Buscar dirección con Google</label>
                            <div class="input-field" style="margin: 0;">
                                <i class="material-icons prefix blue-text">search</i>
                                <input type="text" id="autocomplete_search_cart" placeholder="Escribe tu calle y número...">
                                <span class="helper-text">Selecciona una opción sugerida para mayor precisión</span>
                            </div>
                        </div>

                        <!-- Vista previa del mapa -->
                        <div id="map-preview-cart" class="z-depth-1" style="height: 200px; width: 100%; margin-bottom: 20px; border-radius: 4px; display: none; border: 1px solid #ddd;"></div>

                        <input type="hidden" id="maps_link" name="maps_link" value="">

                        <div class="input-field">
                            <input type="text" id="nombre" name="nombre" required value="<?php echo esc($usuarioLogueado['nombre'] ?? ''); ?>">
                            <label for="nombre">Nombre Completo</label>
                        </div>
                        <div class="input-field">
                            <input type="tel" id="telefono" name="telefono" required
                                   value="<?php echo esc($telefonoGuardado); ?>" placeholder="Ej: (331) - 863 - 5185" maxlength="19" inputmode="numeric" autocomplete="tel-national">
                            <label for="telefono" class="<?php echo $telefonoGuardado ? 'active' : ''; ?>">Teléfono de contacto</label>
                            <?php if (empty($telefonoGuardado) && $isUserAuthenticated): ?>
                                <span class="helper-text orange-text">
                                    <i class="material-icons tiny">info</i> Lo guardaremos para no pedírtelo otra vez.
                                </span>
                            <?php endif; ?>
                        </div>
                        <div id="direccion-container">
                            <div class="input-field">
                                <textarea id="direccion" name="direccion" class="materialize-textarea" required><?php echo esc($direcciones[0]['direccion'] ?? ''); ?></textarea>
                                <label for="direccion">Dirección exacta de domicilio</label>
                            </div>
                        </div>
                        <button type="submit" class="btn-large green waves-effect waves-light btn-block" style="width: 100%;">
                            Confirmar Pedido <i class="material-icons right">check</i>
                        </button>
                    </form>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- SweetAlert2 para mensajes emergentes -->
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
<script src="https://maps.googleapis.com/maps/api/js?key=<?php echo GOOGLE_MAPS_API_KEY; ?>&libraries=places&callback=initAutocompleteCart" async defer></script>

<script>
    let mapCart, markerCart;

    function initAutocompleteCart() {
        if (typeof google === 'undefined') {
            console.error('Google Maps no pudo cargarse. Revisa tu API Key y Facturacion.');
            return;
        }

        const input = document.getElementById('autocomplete_search_cart');
        const mapEl = document.getElementById('map-preview-cart');
        if (!input || !mapEl) return;

        const autocomplete = new google.maps.places.Autocomplete(input, {
            types: ['address'],
            componentRestrictions: { country: 'mx' }
        });

        mapCart = new google.maps.Map(mapEl, {
            center: { lat: 23.6345, lng: -102.5528 },
            zoom: 5,
            disableDefaultUI: true,
            zoomControl: true
        });
        markerCart = new google.maps.Marker({ map: mapCart });

        autocomplete.addListener('place_changed', function() {
            const place = autocomplete.getPlace();
            if (!place.geometry) return;

            mapEl.style.display = 'block';
            const direccionEl = document.getElementById('direccion');
            const mapsLinkEl = document.getElementById('maps_link');

            if (direccionEl) {
                direccionEl.value = place.formatted_address || direccionEl.value;
                M.textareaAutoResize(direccionEl);
            }
            if (mapsLinkEl) {
                mapsLinkEl.value = `https://www.google.com/maps/search/?api=1&query=${place.geometry.location.lat()},${place.geometry.location.lng()}`;
            }

            mapCart.setCenter(place.geometry.location);
            mapCart.setZoom(17);
            markerCart.setPosition(place.geometry.location);
            M.updateTextFields();
        });
    }

    function actualizarMapaCartDesdeCoords(coords) {
        if (!mapCart || !markerCart) return;
        const [lat, lng] = String(coords || '').split(',').map(Number);
        if (!isNaN(lat) && !isNaN(lng)) {
            const pos = { lat, lng };
            const mapEl = document.getElementById('map-preview-cart');
            if (mapEl) mapEl.style.display = 'block';
            mapCart.setCenter(pos);
            mapCart.setZoom(17);
            markerCart.setPosition(pos);
            setTimeout(() => google.maps.event.trigger(mapCart, 'resize'), 100);
        }
    }

    function clearCart() {
        Swal.fire({
            title: '¿Vaciar el carrito?',
            text: "Esta acción eliminará todos los productos que has seleccionado.",
            icon: 'warning',
            showCancelButton: true,
            confirmButtonColor: '#d33',
            cancelButtonColor: '#3085d6',
            confirmButtonText: 'Sí, vaciar',
            cancelButtonText: 'Cancelar'
        }).then((result) => {
            if (result.isConfirmed) {
                localStorage.removeItem('cart');
                renderCart();
                updateCartBadge();
                M.toast({html: 'Carrito vaciado', classes: 'grey darken-3 rounded'});
            }
        });
    }

    function renderCart() {
        const cart = getCart();
        const tbody = document.getElementById('cart-table-body');
        let total = 0;
        
        tbody.innerHTML = cart.length === 0 ? '<tr><td colspan="5" class="center">El carrito está vacío</td></tr>' : '';

        cart.forEach((item, index) => {
            const price = parseFloat(item.precio) || 0;
            const subtotal = price * item.quantity;
            
            // Si el item es corrupto (undefined o NaN), ofrecer eliminarlo o saltarlo
            if (!item.nombre || isNaN(subtotal)) {
                return; // Ignorar items corruptos del error anterior
            }

            total += subtotal;
            tbody.innerHTML += `
                <tr>
                    <td>${item.nombre}</td>
                    <td>$${price.toFixed(2)}</td>
                    <td>${item.quantity}</td>
                    <td>$${subtotal.toFixed(2)}</td>
                    <td><a href="#" onclick="removeItem(${index})" class="red-text"><i class="material-icons">delete_forever</i></a></td>
                </tr>`;
        });

        document.getElementById('cart-total-display').textContent = total.toFixed(2);

        // Mostrar u ocultar el botón de vaciar según si hay items
        const btnEmpty = document.getElementById('btn-empty-cart');
        if (btnEmpty) btnEmpty.style.display = cart.length > 0 ? 'inline-block' : 'none';
    }

    function removeItem(index) {
        let cart = getCart();
        cart.splice(index, 1);
        localStorage.setItem('cart', JSON.stringify(cart));
        renderCart();
        updateCartBadge();
    }

    function formatPhoneMx(digits) {
        if (!digits) return '';
        if (digits.length <= 3) return `(${digits}`;
        if (digits.length <= 6) return `(${digits.slice(0, 3)}) - ${digits.slice(3)}`;
        return `(${digits.slice(0, 3)}) - ${digits.slice(3, 6)} - ${digits.slice(6, 10)}`;
    }

    function bindPhoneMaskValidationCart(inputId) {
        const phoneInput = document.getElementById(inputId);
        if (!phoneInput) return;

        const validatePhone = () => {
            const digits = (phoneInput.value || '').replace(/\D/g, '').slice(0, 10);
            if (digits.length !== 10) {
                phoneInput.setCustomValidity('El teléfono debe tener 10 dígitos.');
                return false;
            }
            phoneInput.setCustomValidity('');
            return true;
        };

        phoneInput.addEventListener('input', () => {
            const digits = (phoneInput.value || '').replace(/\D/g, '').slice(0, 10);
            phoneInput.value = formatPhoneMx(digits);
            validatePhone();
        });

        phoneInput.addEventListener('blur', () => {
            const digits = (phoneInput.value || '').replace(/\D/g, '').slice(0, 10);
            phoneInput.value = formatPhoneMx(digits);
            validatePhone();
        });

        const initialDigits = (phoneInput.value || '').replace(/\D/g, '').slice(0, 10);
        phoneInput.value = formatPhoneMx(initialDigits);
        validatePhone();
    }

    document.getElementById('form-checkout').addEventListener('submit', function(e) {
        e.preventDefault();
        const cart = getCart();
        if (cart.length === 0) return M.toast({html: 'Tu carrito está vacío'});

        const phoneInput = document.getElementById('telefono');
        const phoneDigits = (phoneInput?.value || '').replace(/\D/g, '').slice(0, 10);
        if (phoneDigits.length !== 10) {
            if (phoneInput) {
                phoneInput.setCustomValidity('El teléfono debe tener 10 dígitos.');
                phoneInput.reportValidity();
                phoneInput.focus();
            }
            M.toast({html: 'Completa un teléfono válido de 10 dígitos.'});
            return;
        }
        if (phoneInput) {
            phoneInput.setCustomValidity('');
            phoneInput.value = formatPhoneMx(phoneDigits);
        }

        // Bloquear confirmación si el usuario no ha iniciado sesión
        if (!<?php echo json_encode($isUserAuthenticated); ?>) {
            Swal.fire({
                title: '¡Identifícate primero!',
                text: 'Para poder registrar tu pedido y que aparezca en tu historial, necesitas iniciar sesión o crear una cuenta.',
                icon: 'info',
                showCancelButton: true,
                confirmButtonText: 'Iniciar Sesión',
                cancelButtonText: 'Crear Cuenta',
                confirmButtonColor: '#0d47a1',
                cancelButtonColor: '#2e7d32'
            }).then((result) => {
                if (result.isConfirmed) {
                    window.location.href = 'login.php?redirect=views/cart.php';
                } else if (result.dismiss === Swal.DismissReason.cancel) {
                    window.location.href = 'register.php';
                }
            });
            return;
        }

        const btn = this.querySelector('button');
        btn.disabled = true;
        btn.textContent = 'Procesando...';

        const formData = {
            tipo_entrega: document.getElementById('tipo_entrega').value,
            cliente: {
                nombre: document.getElementById('nombre').value,
                telefono: document.getElementById('telefono').value,
                direccion: document.getElementById('direccion').value
            },
            maps_link: document.getElementById('maps_link')?.value || '',
            items: cart
        };

        fetch('<?php echo BASE_URL; ?>api/public_orders.php', {
            method: 'POST',
            headers: {'Content-Type': 'application/json'},
            body: JSON.stringify(formData)
        })
        .then(res => res.json())
        .then(data => {
            if (data.success) {
                const tipoEntregaValue = document.getElementById('tipo_entrega')?.value || '';
                const direccionManual = (document.getElementById('direccion')?.value || '').trim();
                const mapsLinkActual = document.getElementById('maps_link')?.value || '';
                const selectedAddressMode = selectDireccion ? selectDireccion.value : '';
                const usedNewManualAddress = tipoEntregaValue === 'Domicilio' && (
                    !HAS_SAVED_ADDRESSES || selectedAddressMode === '__other__'
                ) && direccionManual !== '';

                const continuarFlujo = () => {
                    localStorage.removeItem('cart');
                    const idPedido = Number.parseInt(data.id_pedido, 10);
                    if (Number.isInteger(idPedido) && idPedido > 0) {
                        window.location.href = `gracias.php?id=${idPedido}`;
                    } else {
                        window.location.href = 'mis_compras.php';
                    }
                };

                Swal.fire({
                    title: '¡Pedido Confirmado!',
                    text: 'Tu pedido ha sido registrado con éxito. Puedes consultar el estado en tu sección de compras.',
                    icon: 'success',
                    confirmButtonText: 'Continuar',
                    confirmButtonColor: '#0d47a1'
                }).then(() => {
                    if (!usedNewManualAddress) {
                        continuarFlujo();
                        return;
                    }

                    Swal.fire({
                        title: '¿Deseas guardar esta dirección en Mis Direcciones?',
                        icon: 'question',
                        showCancelButton: true,
                        confirmButtonText: 'Sí, guardarla',
                        cancelButtonText: 'No, continuar',
                        confirmButtonColor: '#0d47a1'
                    }).then((saveResult) => {
                        if (saveResult.isConfirmed) {
                            Swal.fire({
                                title: 'Elige un alias para esta dirección',
                                input: 'select',
                                inputOptions: {
                                    Casa: 'Casa',
                                    Trabajo: 'Trabajo',
                                    Otro: 'Otro'
                                },
                                inputValue: 'Casa',
                                inputPlaceholder: 'Selecciona un alias',
                                showCancelButton: true,
                                confirmButtonText: 'Continuar',
                                cancelButtonText: 'Cancelar',
                                confirmButtonColor: '#0d47a1'
                            }).then((aliasChoiceResult) => {
                                if (!aliasChoiceResult.isConfirmed) {
                                    continuarFlujo();
                                    return;
                                }

                                const selectedAlias = aliasChoiceResult.value || 'Casa';
                                if (selectedAlias !== 'Otro') {
                                    const params = new URLSearchParams({
                                        prefill: '1',
                                        alias: selectedAlias,
                                        direccion: direccionManual,
                                        maps_link: mapsLinkActual
                                    });
                                    localStorage.removeItem('cart');
                                    window.location.href = `mis_direcciones.php?${params.toString()}`;
                                    return;
                                }

                                Swal.fire({
                                    title: 'Escribe el alias',
                                    input: 'text',
                                    inputLabel: 'Ejemplo: Casa de mamá, Oficina centro, etc.',
                                    inputPlaceholder: 'Alias de la dirección',
                                    showCancelButton: true,
                                    confirmButtonText: 'Guardar dirección',
                                    cancelButtonText: 'Cancelar',
                                    confirmButtonColor: '#0d47a1',
                                    inputValidator: (value) => {
                                        if (!value || !value.trim()) {
                                            return 'Ingresa un alias para continuar.';
                                        }
                                        if (value.trim().length > 50) {
                                            return 'El alias no debe exceder 50 caracteres.';
                                        }
                                        return null;
                                    }
                                }).then((customAliasResult) => {
                                    if (!customAliasResult.isConfirmed) {
                                        continuarFlujo();
                                        return;
                                    }

                                    const params = new URLSearchParams({
                                        prefill: '1',
                                        alias: customAliasResult.value.trim(),
                                        direccion: direccionManual,
                                        maps_link: mapsLinkActual
                                    });
                                    localStorage.removeItem('cart');
                                    window.location.href = `mis_direcciones.php?${params.toString()}`;
                                });
                            });
                            return;
                        }

                        continuarFlujo();
                    });
                });
            } else {
                M.toast({html: 'Error: ' + data.message});
                btn.disabled = false;
                btn.textContent = 'Confirmar Pedido';
            }
        })
        .catch(err => {
            console.error('Error en la petición:', err);
            M.toast({html: 'Error de conexión. Inténtalo de nuevo.'});
            btn.disabled = false;
            btn.textContent = 'Confirmar Pedido';
        });
    });

    // Lógica para mostrar/ocultar dirección según el tipo de entrega
    const HAS_SAVED_ADDRESSES = <?php echo $hasSavedAddresses ? 'true' : 'false'; ?>;
    const tipoEntrega = document.getElementById('tipo_entrega');
    const direccionContainer = document.getElementById('direccion-container');
    const wrapperSelect = document.getElementById('wrapper-select-direccion');
    const wrapperMapsSearch = document.getElementById('wrapper-maps-search');
    const inputDireccion = document.getElementById('direccion');
    const infoSucursal = document.getElementById('info-sucursal');
    const mapsLinkInput = document.getElementById('maps_link');
    const mapPreviewCart = document.getElementById('map-preview-cart');
    const inputSearchCart = document.getElementById('autocomplete_search_cart');
    const selectDireccion = document.getElementById('select_direccion');

    function activarModoDireccionManual() {
        if (direccionContainer) direccionContainer.style.display = 'block';
        if (wrapperMapsSearch) wrapperMapsSearch.style.display = 'block';
        if (inputDireccion) {
            inputDireccion.required = true;
            if (!inputDireccion.value || inputDireccion.value === 'Tabachín 248, Bosques de Tonalá, 45400 Tonalá, Jal.') {
                inputDireccion.value = '';
            }
        }
    }

    function activarModoDireccionGuardada() {
        if (direccionContainer) direccionContainer.style.display = 'none';
        if (wrapperMapsSearch) wrapperMapsSearch.style.display = 'none';
        if (mapPreviewCart) mapPreviewCart.style.display = 'none';
        if (inputSearchCart) inputSearchCart.value = '';
        if (inputDireccion) inputDireccion.required = false;

        if (selectDireccion) {
            const selected = selectDireccion.options[selectDireccion.selectedIndex];
            const selectedValue = selected ? selected.value : '';
            if (selectedValue && selectedValue !== '__other__') {
                if (inputDireccion) inputDireccion.value = selectedValue;
                if (mapsLinkInput) mapsLinkInput.value = selected?.dataset?.mapsLink || '';
            }
        }
    }

    function aplicarModoEntrega(valor) {
        if (valor === 'Sucursal') {
            if (infoSucursal) infoSucursal.style.display = 'block';
            if (direccionContainer) direccionContainer.style.display = 'none';
            if (wrapperSelect) wrapperSelect.style.display = 'none';
            if (wrapperMapsSearch) wrapperMapsSearch.style.display = 'none';
            if (mapPreviewCart) mapPreviewCart.style.display = 'none';
            if (inputDireccion) { inputDireccion.required = false; inputDireccion.value = 'Tabachín 248, Bosques de Tonalá, 45400 Tonalá, Jal.'; }
            if (mapsLinkInput) mapsLinkInput.value = '';
            if (inputSearchCart) inputSearchCart.value = '';
        } else if (valor === 'Domicilio') {
            if (infoSucursal) infoSucursal.style.display = 'none';
            if (wrapperSelect) wrapperSelect.style.display = HAS_SAVED_ADDRESSES ? 'block' : 'none';

            if (HAS_SAVED_ADDRESSES) {
                const selectedValue = selectDireccion ? selectDireccion.value : '';
                if (selectedValue === '__other__' || !selectedValue) {
                    activarModoDireccionManual();
                } else {
                    activarModoDireccionGuardada();
                }
            } else {
                activarModoDireccionManual();
            }
        } else {
            if (infoSucursal) infoSucursal.style.display = 'none';
            if (direccionContainer) direccionContainer.style.display = 'block';
            if (wrapperSelect) wrapperSelect.style.display = 'none';
            if (wrapperMapsSearch) wrapperMapsSearch.style.display = 'none';
            if (mapPreviewCart) mapPreviewCart.style.display = 'none';
            if (mapsLinkInput) mapsLinkInput.value = '';
        }
    }

    tipoEntrega.addEventListener('change', function() { aplicarModoEntrega(this.value); });

    document.getElementById('select_direccion')?.addEventListener('change', function() {
        if (this.value === '__other__') {
            if (mapsLinkInput) mapsLinkInput.value = '';
            activarModoDireccionManual();
            M.textareaAutoResize(document.getElementById('direccion'));
            M.updateTextFields();
            return;
        }

        if (this.value) {
            document.getElementById('direccion').value = this.value;
            const mapsLink = this.options[this.selectedIndex]?.dataset?.mapsLink || '';
            if (mapsLinkInput) mapsLinkInput.value = mapsLink;
            if (mapsLink.includes('query=')) {
                const coords = mapsLink.split('query=')[1];
                if (coords) actualizarMapaCartDesdeCoords(coords);
            } else if (mapPreviewCart) {
                mapPreviewCart.style.display = 'none';
            }
            activarModoDireccionGuardada();
            M.textareaAutoResize(document.getElementById('direccion'));
            M.updateTextFields();
        } else {
            activarModoDireccionManual();
        }
    });

    document.addEventListener('DOMContentLoaded', () => {
        renderCart();
        updateCartBadge();
        bindPhoneMaskValidationCart('telefono');
        if (typeof google !== 'undefined') initAutocompleteCart();

        const initialMapsLink = mapsLinkInput?.value || '';
        if (initialMapsLink.includes('query=')) {
            const coords = initialMapsLink.split('query=')[1];
            if (coords) actualizarMapaCartDesdeCoords(coords);
        }
    });
</script>

<style>
    .pac-container {
        z-index: 1051 !important;
        border-radius: 4px;
    }
</style>

<?php include __DIR__ . '/includes/footer.php'; ?>