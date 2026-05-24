<?php
require_once __DIR__ . '/../core/config.php';
require_once __DIR__ . '/../core/auth.php';

$pageTitle = 'Términos y Condiciones';
include __DIR__ . '/includes/header.php';
?>

<div class="container" style="margin-top: 40px; margin-bottom: 60px;">
    <div class="row">
        <div class="col s12 m10 offset-m1">
            <div class="card-panel z-depth-1" style="border-radius: 8px; padding: 40px;">
                <h4 class="indigo-text text-darken-4 center-align" style="font-weight: bold; margin-bottom: 40px;">
                    TÉRMINOS Y CONDICIONES ESTÁNDAR DE VENTA
                </h4>
                
                <p class="flow-text grey-text text-darken-3">
                    Estos términos y condiciones rigen el uso del sitio web y las compras realizadas en <strong>Belleza Y Bienestar</strong>. Al hacer una compra, aceptas estar sujeto a estos términos.
                </p>

                <div class="section" style="margin-top: 30px;">
                    <h5 class="indigo-text"><i class="material-icons left">local_shipping</i> 1. Política de Entregas</h5>
                    <p><strong>Cobertura y costos:</strong> Realizamos entregas contra entrega en la <strong>Zona Metropolitana de Guadalajara</strong>. El costo de envío es de <strong>$40 MXN</strong>, excepto si tu pedido incluye dos o más productos, en cuyo caso el envío es <strong>gratis</strong>.</p>
                    <p><strong>Días de entrega programados:</strong> Las entregas se realizan los días <strong>miércoles y sábados</strong> después de mediodía.</p>
                    <p><strong>Entregas especiales:</strong> Si requieres una entrega en un día diferente, por favor contáctanos para revisar la disponibilidad.</p>
                    <p><strong>Método de pago:</strong> El pago se realiza en <strong>efectivo</strong> al momento de la entrega de tu pedido.</p>
                </div>

                <div class="divider"></div>

                <div class="section">
                    <h5 class="indigo-text"><i class="material-icons left">security</i> 2. Política de Privacidad y Derechos ARCO</h5>
                    <p>En Belleza y Bienestar, valoramos la confianza que depositas en nosotros. La información que nos proporcionas, como nombre y dirección, se utiliza únicamente para procesar y entregar tu pedido. No compartimos tus datos con terceros.</p>
                    <p><strong>Derechos ARCO:</strong> De acuerdo con la Ley de Protección de Datos Personales, tienes derecho a <strong>Acceder, Rectificar, Cancelar u Oponerte</strong> al tratamiento de tus datos personales (Derechos ARCO).</p>
                    <p><strong>Para ejercer tus derechos:</strong> Envía tu solicitud formal por correo electrónico a <a href="mailto:bellezaybienestar80@gmail.com" class="blue-text text-darken-4">bellezaybienestar80@gmail.com</a>.</p>
                </div>

                <div class="divider"></div>

                <div class="section">
                    <h5 class="indigo-text"><i class="material-icons left">contact_support</i> 3. Contacto</h5>
                    <p>Para cualquier duda, comentario o consulta, puedes contactarnos a través de:</p>
                    <ul class="collection" style="border: none;">
                        <li class="collection-item" style="border: none; padding-left: 0;">
                            <i class="material-icons left tiny indigo-text">phone</i> <strong>Teléfono:</strong> 334420747
                        </li>
                        <li class="collection-item" style="border: none; padding-left: 0;">
                            <i class="material-icons left tiny indigo-text">email</i> <strong>Correo electrónico:</strong> <a href="mailto:bellezaybienestar80@gmail.com" class="blue-text text-darken-4">bellezaybienestar80@gmail.com</a>
                        </li>
                    </ul>
                </div>

                <div class="center-align" style="margin-top: 50px;">
                    <a href="<?php echo BASE_URL; ?>" class="btn-large indigo darken-4 waves-effect waves-light" style="border-radius: 30px; text-transform: uppercase; font-weight: bold;">
                        VOLVER AL CATÁLOGO <i class="material-icons right">shopping_basket</i>
                    </a>
                </div>
            </div>
        </div>
    </div>
</div>

<?php include __DIR__ . '/includes/footer.php'; ?>