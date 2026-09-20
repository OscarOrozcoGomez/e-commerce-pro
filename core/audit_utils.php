<?php
declare(strict_types=1);

/**
 * Utilidades puras de auditoria (sin BD): comparar antes/despues, enmascarar datos
 * sensibles, clasificar severidad y poner nombre legible a las acciones.
 *
 * La escritura real vive en core/auth.php (logAudit / logAuditCambios); aqui solo esta
 * la logica que se puede probar sin base de datos (tests/Unit/AuditUtilsTest.php).
 *
 * Reglas de privacidad (importante -- el log lo lee mucha gente):
 *   - Contrasenas, tokens, codigos OTP, llaves: NUNCA se guardan; se reemplazan por
 *     '[oculto]'.
 *   - Telefono, correo y direccion de clientes se guardan enmascarados: alcanza para
 *     saber QUE campo cambio y, en telefono, reconocer los ultimos 4 digitos, sin
 *     reproducir en el log el dato que en la tabla clientes esta cifrado (ENCv1:).
 */

const AUDIT_SEVERIDADES = ['info', 'aviso', 'alerta'];

/** Limites de columnas de logs_auditoria (VARCHAR) para no perder el log por "Data too long". */
const AUDIT_MAX_ACCION = 50;
const AUDIT_MAX_TABLA = 50;
const AUDIT_MAX_DETALLES = 4000;

/** Tamano maximo (bytes) del JSON de datos_antes / datos_despues. */
const AUDIT_MAX_JSON_BYTES = 8000;

/** Acciones que solo LEEN aunque viajen por POST (no cuentan como "movimiento"). */
const AUDIT_PREFIJOS_ACCION_LECTURA = [
    'list', 'listar', 'get', 'obtener', 'consultar', 'buscar', 'search', 'fetch',
    'preview', 'quote', 'check', 'load', 'cargar', 'poll', 'status', 'blife_search',
    'data', 'read', 'find', 'validar', 'validate', 'export_preview', 'suggest', 'sugerir',
];

/**
 * Endpoints (basename) que no aportan a la trazabilidad: telemetria propia, sondeos y
 * webhooks. Se excluyen del registro generico de peticiones para no inundar la tabla.
 */
const AUDIT_ENDPOINTS_SIN_REGISTRO = [
    'log_activity.php',
    'whatsapp_webhook.php',
    'run_migrations.php',
    'clear_secrets_cache.php',
    'favorites.php',
    // Chat de soporte: cada mensaje ya queda en mensajes_soporte (con id_staff); el registro
    // generico volcaria el texto de las conversaciones. Inicio/cierre/transferencia se registran a mano.
    'chat_handler.php',
    'pickup_stock_check.php',
    'delivery_zone_quote.php',
    'catalog_products.php',
    'product_detail.php',
    'product_feed.php',
    'dashboard_data.php',
    'analytics_data.php',
    'catalog_performance_report.php',
    'purchase_orders_data.php',
    'postponed_items_data.php',
    'purchase_orders_open.php',
    'purchase_order_import_preview.php',
    'purchase_order_mayoreo_preview.php',
    'lote_ocr.php',
    // Login y recuperacion: ya tienen eventos propios y su payload lleva contrasenas.
    'login.php',
    'forgot_password.php',
    'register.php',
    'complete_account.php',
];

/**
 * ¿La clave de un campo es un secreto que jamas debe quedar en el log?
 */
function auditClaveEsSecreta(string $clave): bool
{
    return (bool) preg_match(
        '/(contrasena|password|passwd|^pass$|token|secret|otp|api_?key|authorization|cvv|tarjeta|card_?number|clabe|nip$)/i',
        $clave
    );
}

/**
 * Tipo de dato personal de un campo ('telefono' | 'email' | 'direccion') o null.
 */
function auditClaveTipoPii(string $clave): ?string
{
    if (preg_match('/(telefono|celular|whatsapp|movil|phone|^wa_id$)/i', $clave)) {
        return 'telefono';
    }
    if (preg_match('/(email|correo|e_mail|mail$)/i', $clave)) {
        return 'email';
    }
    if (preg_match('/(direccion|domicilio|address|calle|maps_link|latitud|longitud)/i', $clave)) {
        return 'direccion';
    }
    return null;
}

/**
 * Valor de un campo listo para guardarse en el log: oculta secretos y enmascara PII.
 * Los valores no escalares se resumen (el log no es un volcado de objetos).
 *
 * @param mixed $valor
 * @return mixed
 */
function auditEnmascararValor(string $clave, $valor)
{
    if ($valor === null || $valor === '') {
        return $valor;
    }

    if (auditClaveEsSecreta($clave)) {
        return '[oculto]';
    }

    if (is_array($valor)) {
        return '[' . count($valor) . ' elemento(s)]';
    }
    if (is_object($valor)) {
        return '[objeto]';
    }
    if (is_bool($valor)) {
        return $valor;
    }
    if (is_int($valor) || is_float($valor)) {
        // Un campo numerico llamado "telefono" tambien es PII.
        return auditClaveTipoPii($clave) !== null ? auditEnmascararPii((string) auditClaveTipoPii($clave), (string) $valor) : $valor;
    }

    $texto = (string) $valor;
    $tipo = auditClaveTipoPii($clave);
    if ($tipo !== null) {
        return auditEnmascararPii($tipo, $texto);
    }

    return auditRecortar($texto, 300);
}

/**
 * Enmascara un dato personal. Un valor cifrado en reposo (ENCv1:) nunca se muestra.
 */
function auditEnmascararPii(string $tipo, string $valor): string
{
    $valor = trim($valor);
    if ($valor === '') {
        return '';
    }
    if (strncmp($valor, 'ENCv1:', 6) === 0) {
        return '[cifrado]';
    }

    if ($tipo === 'telefono') {
        $digitos = preg_replace('/\D+/', '', $valor) ?? '';
        if (strlen($digitos) <= 4) {
            return '****';
        }
        return '******' . substr($digitos, -4);
    }

    if ($tipo === 'email') {
        $partes = explode('@', $valor, 2);
        if (count($partes) !== 2 || $partes[0] === '') {
            return '***';
        }
        return mb_substr($partes[0], 0, 1) . '***@' . $partes[1];
    }

    // direccion / ubicacion: se conserva el inicio para reconocerla, no la direccion completa.
    return mb_strlen($valor) <= 14 ? mb_substr($valor, 0, 4) . '...' : mb_substr($valor, 0, 14) . '...';
}

function auditRecortar(string $texto, int $max): string
{
    $texto = trim($texto);
    if (mb_strlen($texto) <= $max) {
        return $texto;
    }
    return mb_substr($texto, 0, $max) . '...';
}

/**
 * Normaliza un valor para compararlo ("10" == "10.00" == 10.0; null == ''; true == '1').
 *
 * @param mixed $valor
 */
function auditNormalizarParaComparar($valor): string
{
    if ($valor === null) {
        return '';
    }
    if (is_bool($valor)) {
        return $valor ? '1' : '0';
    }
    if (is_int($valor)) {
        return (string) $valor;
    }
    if (is_float($valor)) {
        if (!is_finite($valor)) {
            // number_format(-INF) devuelve "inf": se distingue el signo a mano.
            return is_nan($valor) ? 'nan' : ($valor > 0 ? 'inf' : '-inf');
        }
        return rtrim(rtrim(number_format($valor, 4, '.', ''), '0'), '.');
    }
    if (is_array($valor) || is_object($valor)) {
        return (string) json_encode($valor, JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR);
    }

    $texto = trim((string) $valor);
    // "10" == "10.00" (precios). Un valor con cero a la izquierda ("0012345": SKU, codigo de
    // barras) es un identificador, no un numero: se compara como texto para no perder un cambio real.
    // Tampoco se convierte a float si tiene mas de 15 digitos: perderia precision y dos identificadores
    // distintos (17 digitos) parecerian iguales.
    if ($texto !== '' && is_numeric($texto) && !preg_match('/^-?0\d/', $texto) && strlen(preg_replace('/\D/', '', $texto)) <= 15) {
        return rtrim(rtrim(number_format((float) $texto, 4, '.', ''), '0'), '.');
    }
    return $texto;
}

/**
 * Diferencias entre dos versiones de un registro. Devuelve SOLO los campos que
 * cambiaron, ya enmascarados, listos para datos_antes / datos_despues.
 *
 * @param array<string,mixed> $antes
 * @param array<string,mixed> $despues
 * @param string[]            $campos Lista blanca de campos a comparar (vacio = todos).
 * @return array{antes: array<string,mixed>, despues: array<string,mixed>}
 */
function auditDiff(array $antes, array $despues, array $campos = []): array
{
    $claves = $campos !== [] ? $campos : array_values(array_unique(array_merge(array_keys($antes), array_keys($despues))));

    $outAntes = [];
    $outDespues = [];
    foreach ($claves as $clave) {
        $clave = (string) $clave;
        $a = $antes[$clave] ?? null;
        $d = $despues[$clave] ?? null;

        // Un campo que solo existe en un lado (ej. el formulario no lo mando) no es un cambio.
        if ($campos === [] && (!array_key_exists($clave, $antes) || !array_key_exists($clave, $despues))) {
            continue;
        }

        if (auditNormalizarParaComparar($a) === auditNormalizarParaComparar($d)) {
            continue;
        }

        $outAntes[$clave] = auditEnmascararValor($clave, $a);
        $outDespues[$clave] = auditEnmascararValor($clave, $d);
    }

    return ['antes' => $outAntes, 'despues' => $outDespues];
}

/**
 * Resumen de una linea de un diff: "precio_oferta: 180 -> 120; estado: activo -> inactivo".
 *
 * @param array<string,mixed> $antes   Salida de auditDiff()['antes'].
 * @param array<string,mixed> $despues Salida de auditDiff()['despues'].
 */
function auditResumenCambios(array $antes, array $despues, int $max = 900): string
{
    $partes = [];
    foreach ($despues as $campo => $nuevo) {
        $viejo = $antes[$campo] ?? null;
        $partes[] = $campo . ': ' . auditValorLegible($viejo) . ' -> ' . auditValorLegible($nuevo);
    }
    foreach ($antes as $campo => $viejo) {
        if (!array_key_exists($campo, $despues)) {
            $partes[] = $campo . ': ' . auditValorLegible($viejo) . ' -> (vacio)';
        }
    }

    return auditRecortar(implode('; ', $partes), $max);
}

/**
 * @param mixed $valor
 */
function auditValorLegible($valor): string
{
    if ($valor === null || $valor === '') {
        return '(vacio)';
    }
    if (is_bool($valor)) {
        return $valor ? 'si' : 'no';
    }
    return (string) $valor;
}

/**
 * Payload de una peticion (POST/JSON) sanitizado para el log: sin secretos, sin PII en
 * claro, con limites de campos y de longitud.
 *
 * @param array<string,mixed> $datos
 * @return array<string,mixed>
 */
function auditSanitizarPayload(array $datos, int $maxCampos = 40, int $profundidad = 0): array
{
    $salida = [];
    $n = 0;
    foreach ($datos as $clave => $valor) {
        if (++$n > $maxCampos) {
            $salida['_omitidos'] = count($datos) - $maxCampos;
            break;
        }
        $clave = (string) $clave;

        if (is_array($valor)) {
            if ($profundidad >= 1 || auditClaveEsSecreta($clave)) {
                $salida[$clave] = auditClaveEsSecreta($clave) ? '[oculto]' : '[' . count($valor) . ' elemento(s)]';
            } else {
                $salida[$clave] = auditSanitizarPayload($valor, 15, $profundidad + 1);
            }
            continue;
        }

        $salida[$clave] = auditEnmascararValor($clave, $valor);
    }
    return $salida;
}

/**
 * JSON compacto para las columnas MEDIUMTEXT. null si no hay nada que guardar; si excede el
 * tope se guarda solo la lista de claves (mejor eso que perder el evento).
 *
 * @param array<string,mixed>|null $datos
 */
function auditJsonCompacto(?array $datos, int $maxBytes = AUDIT_MAX_JSON_BYTES): ?string
{
    if ($datos === null || $datos === []) {
        return null;
    }

    $json = json_encode($datos, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE | JSON_PARTIAL_OUTPUT_ON_ERROR);
    if (!is_string($json)) {
        return null;
    }
    if (strlen($json) <= $maxBytes) {
        return $json;
    }

    return (string) json_encode(
        ['_truncado' => true, 'campos' => array_slice(array_map('strval', array_keys($datos)), 0, 60)],
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
    );
}

/**
 * ¿Esta accion de una peticion solo consulta? (no debe contar como movimiento)
 */
function auditAccionEsLectura(string $accion): bool
{
    $accion = strtolower(trim($accion));
    if ($accion === '') {
        return false;
    }
    foreach (AUDIT_PREFIJOS_ACCION_LECTURA as $prefijo) {
        if ($accion === $prefijo || strncmp($accion, $prefijo . '_', strlen($prefijo) + 1) === 0) {
            return true;
        }
    }
    return false;
}

/**
 * ¿Este endpoint queda fuera del registro generico de peticiones?
 */
function auditEndpointSinRegistro(string $rutaOScript): bool
{
    $ruta = (string) parse_url($rutaOScript, PHP_URL_PATH);
    // El script real es el PRIMER segmento .php de la ruta. Lo que venga despues es PATH_INFO: con
    // '/api/products_manager.php/log_activity.php' el ultimo segmento NO es el script que corre, y no
    // debe servir para escapar del registro.
    if (!preg_match('#([^/]+\.php)(?=/|$)#i', $ruta, $m)) {
        return false;
    }
    return in_array(strtolower($m[1]), AUDIT_ENDPOINTS_SIN_REGISTRO, true);
}

/**
 * Severidad por defecto segun el nombre de la accion. Se puede forzar desde el llamador.
 */
function auditSeveridadPorDefecto(string $accion): string
{
    $a = strtoupper($accion);

    if (preg_match('/(ELIMIN|BORRAD|BORRAR|DENEGAD|BLOQUEO|FALLID|DESACTIV|CANCELA|SUPERADMIN|EXPORT|PASSWORD|CONTRASENA|PERMISO|ROL_|RETIRAD|DESCARTAD|NO_ENTREGADO|SIN_EVIDENCIA|SIN_AFECTAR)/', $a)) {
        return 'alerta';
    }
    if (preg_match('/(PRODUCTO|PRECIO|OFERTA|DESCUENTO|AJUST|STOCK|LOTE|INVENTARIO|CATEGORIA|CLIENTE|USUARIO|SUCURSAL|ALMACEN|TRANSFER|ORDEN|LIBERAD|REASIGN|FECHA|CARGO)/', $a)) {
        return 'aviso';
    }
    return 'info';
}

function auditNormalizarSeveridad(?string $severidad, string $accion): string
{
    $severidad = strtolower(trim((string) $severidad));
    return in_array($severidad, AUDIT_SEVERIDADES, true) ? $severidad : auditSeveridadPorDefecto($accion);
}

/**
 * Mapa accion -> nombre legible. Toda accion nueva que se pase a logAudit() debe agregarse aqui
 * (tests/Unit/AuditUtilsTest.php lo verifica).
 *
 * @return array<string,string>
 */
function auditMapaEtiquetasAccion(): array
{
    return [
        'LOGIN_EXITOSO' => 'Inicio de sesión',
        'LOGIN_FALLIDO' => 'Intento de inicio de sesión fallido',
        'LOGIN_OTP_ENVIADO' => 'Código de verificación enviado',
        'LOGIN_OTP_FALLIDO' => 'Código de verificación incorrecto',
        'LOGOUT' => 'Cierre de sesión',
        'BLOQUEO_CUENTA' => 'Cuenta bloqueada por intentos fallidos',
        'PASSWORD_RESET_SOLICITADO' => 'Recuperación de contraseña solicitada',
        'PASSWORD_RESET_COMPLETADO' => 'Contraseña restablecida',
        'PASSWORD_RESET_FALLIDO' => 'Código de recuperación incorrecto',
        'ACCESO_DENEGADO' => 'Acceso denegado (sin permiso)',
        'PETICION_ESCRITURA' => 'Operación sin registro detallado',
        'EXPORTACION_DATOS' => 'Exportación / descarga de datos',
        'PRODUCTO_CREADO' => 'Producto creado',
        'PRODUCTO_EDITADO' => 'Producto editado',
        'PRODUCTO_ELIMINADO' => 'Producto desactivado',
        'PRODUCTO_EN_OFERTA' => 'Producto puesto en oferta (1 clic)',
        'PRODUCTO_STOCK_AJUSTADO' => 'Stock ajustado a mano',
        'PRODUCTO_IMAGENES_CAMBIADAS' => 'Imágenes de producto cambiadas',
        'PRODUCTO_LIBERADO' => 'Producto liberado del pedido',
        'CATEGORIA_CREADA' => 'Categoría creada',
        'CATEGORIA_ASIGNADA_MASIVA' => 'Categoría asignada en lote',
        'CATEGORIAS_PRODUCTO_CAMBIADAS' => 'Categorías de un producto cambiadas',
        'UMBRALES_STOCK_CAMBIADOS' => 'Stock mínimo/máximo cambiado',
        'LOTE_GUARDADO' => 'Lote guardado',
        'LOTE_AJUSTADO' => 'Cantidad de lote ajustada',
        'LOTE_ESTADO_CAMBIADO' => 'Estado de lote cambiado',
        'LOTE_ATENDIDO' => 'Alerta de caducidad marcada como atendida',
        'LOTE_ELIMINADO' => 'Lote eliminado',
        'TRANSFERENCIA_STOCK' => 'Transferencia de stock entre almacenes',
        'ENTRADA_INVENTARIO' => 'Entrada de inventario',
        'PEDIDO_ASIGNADO' => 'Pedido asignado a repartidor',
        'PEDIDO_FECHA_ENTREGA_CAMBIADA' => 'Fecha de entrega cambiada',
        'PEDIDO_EN_CAMINO' => 'Pedido en camino',
        'PEDIDO_ENTREGADO' => 'Pedido entregado',
        'PEDIDO_ENTREGADO_SIN_EVIDENCIA' => 'Pedido entregado SIN foto de evidencia',
        'PEDIDO_CANCELADO' => 'Pedido cancelado',
        'PEDIDO_CANCELADO_CLIENTE' => 'Pedido cancelado por el cliente',
        'PEDIDO_LIBERADO' => 'Pedido liberado (stock devuelto)',
        'PEDIDO_SIN_AFECTAR_INVENTARIO' => 'Pedido creado sin afectar inventario',
        'PEDIDO_CONVERTIDO_A_SUCURSAL' => 'Pedido convertido a recoger en sucursal',
        'PEDIDO_CONVERTIDO_A_DOMICILIO' => 'Pedido convertido a entrega a domicilio',
        'PEDIDO_PRODUCTO_AGREGADO' => 'Producto agregado a un pedido',
        'PRODUCTO_AGREGADO_A_PEDIDO' => 'Producto agregado a un pedido',
        'PRODUCTO_NO_ENTREGADO' => 'Producto marcado como no entregado',
        'VENTA_SUCURSAL_REGISTRADA' => 'Venta en sucursal registrada',
        'PEDIDO_DOMICILIO_AGENDADO' => 'Pedido a domicilio agendado',
        'VENTA_DESCUENTO_MANUAL' => 'Descuento manual en venta',
        'VENTA_PRECIO_DISTINTO_CATALOGO' => 'Venta con precio distinto al catálogo',
        'CLIENTE_CREADO' => 'Cliente creado',
        'CLIENTE_EDITADO' => 'Cliente editado',
        'CLIENTE_ESTADO_CAMBIADO' => 'Cliente activado / desactivado',
        'CLIENTE_ELIMINADO' => 'Cliente ELIMINADO',
        'CLIENTE_DIRECCION_CREADA' => 'Dirección de cliente agregada',
        'CLIENTE_DIRECCION_EDITADA' => 'Dirección de cliente editada',
        'CLIENTE_DIRECCION_ELIMINADA' => 'Dirección de cliente eliminada',
        'CLIENTE_DIRECCION_PREDETERMINADA' => 'Dirección predeterminada cambiada',
        'CLIENTE_HORARIO_CAMBIADO' => 'Horario de entrega de cliente cambiado',
        'CLIENTE_TELEFONO_CAMBIADO' => 'Teléfono de cliente cambiado',
        'PEDIDO_TELEFONO_SINCRONIZADO' => 'Teléfono de entrega del pedido actualizado al del cliente',
        'CLIENTE_PERFIL_EDITADO' => 'El cliente editó su perfil',
        'CLIENTE_DIRECCION_PROPIA' => 'El cliente cambió sus direcciones',
        'CUENTA_CLIENTE_REGISTRADA' => 'Cuenta de cliente registrada',
        'CUENTA_ACTIVADA' => 'Cuenta activada por el cliente',
        'SUCURSAL_CREADA' => 'Sucursal creada',
        'SUCURSAL_EDITADA' => 'Sucursal editada',
        'SUCURSAL_ESTADO_CAMBIADO' => 'Sucursal activada / desactivada',
        'SUCURSAL_INCENTIVO_CAMBIADO' => 'Incentivo de sucursal cambiado',
        'USUARIO_CREADO' => 'Usuario creado',
        'USUARIO_REACTIVADO' => 'Usuario reactivado',
        'USUARIO_EDITADO' => 'Usuario editado',
        'USUARIO_ESTADO_CAMBIADO' => 'Usuario activado / desactivado',
        'USUARIO_DESBLOQUEADO' => 'Usuario desbloqueado',
        'USUARIO_ELIMINADO' => 'Usuario eliminado',
        'USUARIO_PASSWORD_RESETEADA' => 'Contraseña de usuario reseteada',
        'USUARIO_PERMISOS_ACTUALIZADOS' => 'Permisos individuales de usuario cambiados',
        'USUARIO_ELEVADO_SUPERADMIN' => 'Usuario elevado a superadmin',
        'ROL_CREADO' => 'Rol creado',
        'ROL_ACTUALIZADO' => 'Rol actualizado',
        'ROL_ELIMINADO' => 'Rol eliminado',
        'PERMISO_CREADO' => 'Permiso creado',
        'PERMISO_ACTUALIZADO' => 'Permiso actualizado',
        'PERMISO_DESACTIVADO' => 'Permiso desactivado',
        'PERMISO_EXPIRADO' => 'Permiso temporal expirado',
        'ORDEN_COMPRA_CREADA' => 'Orden de compra creada',
        'ORDEN_COMPRA_EDITADA' => 'Orden de compra editada',
        'ORDEN_COMPRA_POSPUESTOS' => 'Productos pospuestos en compras',
        'ORDEN_COMPRA_RECIBIDA' => 'Orden de compra recibida',
        'BLOG_ELIMINADO' => 'Entrada de blog eliminada',
        'CAMPANA_GUARDADA' => 'Campaña guardada en el calendario',
        'CAMPANA_ELIMINADA' => 'Campaña eliminada del calendario',
        'VENTAS_FEATURES_CONFIG' => 'Configuración de iniciativas de ventas cambiada',
        'SOPORTE_MENSAJE_ENVIADO' => 'Mensaje de soporte enviado',
        'SOPORTE_RESPUESTA_RAPIDA_CAMBIADA' => 'Respuesta rápida de soporte cambiada',
        'SOPORTE_CHAT_ASIGNADO' => 'Chat de soporte asignado / cerrado',
        'AI_ASISTENTE_CONFIG_CAMBIADA' => 'Configuración de Alex cambiada',
        'AI_ASISTENTE_GLOBAL_TOGGLE' => 'Alex activado / desactivado globalmente',
        'AI_ASISTENTE_PLAYGROUND_LIMPIADO' => 'Playground de Alex limpiado',
        'AI_ASISTENTE_BOT_REACTIVADO' => 'Alex reactivado en una conversación',
        'AI_ASISTENTE_DIAGNOSTICO_RESUELTO' => 'Diagnóstico de Alex marcado como resuelto',
        'AI_ASISTENTE_ETIQUETA_ASIGNADA' => 'Etiqueta asignada a una conversación',
        'AI_ASISTENTE_ETIQUETA_QUITADA' => 'Etiqueta quitada de una conversación',
        'AI_ASISTENTE_REGLA_APRENDIZAJE_CREADA' => 'Regla de aprendizaje de Alex creada',
        'AI_ASISTENTE_REGLA_APRENDIZAJE_TOGGLE' => 'Regla de aprendizaje activada / desactivada',
        'BLOG_GUARDADO' => 'Artículo de blog guardado',
        'CLIENTE_DIRECCION_CONFIRMADA' => 'Dirección de cliente confirmada a mano',
        'PEDIDO_WEB_CREADO' => 'Pedido creado desde el catálogo web',
        'PEDIDO_ENTREGA_CANCELADA' => 'Entrega cancelada por el repartidor',
        'PEDIDO_CARGO_ENVIO_QUITADO' => 'Cargo de envío quitado por el repartidor',
        'PEDIDO_LOTES_VERIFICADOS' => 'Lotes verificados antes de entregar',
        'PEDIDO_PUBLICACION_FACEBOOK' => 'Entrega publicada en Facebook',
        'PEDIDO_PUBLICACION_FOTO_SUBIDA' => 'Foto de entrega subida',
        'PEDIDO_PUBLICACION_OMITIDA' => 'Entrega terminada sin publicar en redes',
        'PICKUP_NOTIFICACION_ACTUALIZADA' => 'Aviso de recoger en sucursal actualizado',
        'PICKUP_NOTIFICACION_CANCELADA' => 'Aviso de recoger en sucursal cancelado',
        'RUTA_OPTIMIZADA' => 'Ruta de entregas optimizada',
        'LIQUIDACION_VENDEDOR_DECLARADA' => 'Liquidación de vendedor declarada',
        'importar' => 'Importación de pedido de proveedor',
        'recibir' => 'Recepción de orden de compra',
        'crear' => 'Creación',
        'cancelar' => 'Cancelación',
        'actualizar' => 'Actualización',
    ];
}

/**
 * ¿La accion tiene un nombre legible propio (no el humanizado por defecto)?
 */
function auditAccionTieneEtiqueta(string $accion): bool
{
    $mapa = auditMapaEtiquetasAccion();
    return isset($mapa[$accion]) || isset($mapa[strtoupper($accion)]);
}

/**
 * Nombre legible de una accion de auditoria. Las acciones son constantes en MAYUSCULAS
 * (algunas antiguas van en minusculas: 'crear', 'cancelar'); las no listadas se humanizan.
 */
function auditEtiquetaAccion(string $accion): string
{
    $mapa = auditMapaEtiquetasAccion();
    if (isset($mapa[$accion])) {
        return $mapa[$accion];
    }
    if (isset($mapa[strtoupper($accion)])) {
        return $mapa[strtoupper($accion)];
    }

    $texto = strtolower(str_replace('_', ' ', trim($accion)));
    return $texto === '' ? '(sin acción)' : mb_strtoupper(mb_substr($texto, 0, 1)) . mb_substr($texto, 1);
}

/**
 * Nombre legible del "modulo" (tabla afectada) para agrupar y filtrar.
 */
function auditEtiquetaTabla(string $tabla): string
{
    static $mapa = [
        'usuarios' => 'Usuarios',
        'usuario_permisos' => 'Permisos de usuario',
        'roles' => 'Roles',
        'permisos' => 'Permisos',
        'productos' => 'Productos',
        'producto_categorias' => 'Categorías de producto',
        'categorias' => 'Categorías',
        'inventario_almacen' => 'Inventario',
        'lotes_inventario' => 'Lotes',
        'pedidos' => 'Pedidos',
        'detalle_pedidos' => 'Detalle de pedidos',
        'clientes' => 'Clientes',
        'cliente_direcciones' => 'Direcciones de clientes',
        'cliente_horarios_entrega' => 'Horarios de clientes',
        'almacenes' => 'Sucursales / almacenes',
        'ordenes_compra' => 'Órdenes de compra',
        'http' => 'Peticiones generales',
        'sesion' => 'Sesiones',
        'exportaciones' => 'Exportaciones',
        'blogs' => 'Blog',
        'calendario_campanas' => 'Campañas',
        'ai_asistente_config' => 'Alex (IA)',
        'whatsapp_conversaciones' => 'WhatsApp',
        'mensajes_soporte' => 'Soporte',
    ];

    return $mapa[$tabla] ?? $tabla;
}

/**
 * Contexto del actor y de la peticion actual para una fila de auditoria.
 *
 * @param array<string,mixed> $opciones id_usuario | usuario_nombre | usuario_rol | id_almacen
 *                                      para forzar el actor (ej. un login fallido: aun no hay sesion).
 * @return array<string,mixed>
 */
function auditContextoActual(array $opciones = []): array
{
    $usuario = (isset($_SESSION) && is_array($_SESSION['usuario'] ?? null)) ? $_SESSION['usuario'] : [];

    $idUsuario = array_key_exists('id_usuario', $opciones)
        ? ($opciones['id_usuario'] !== null ? (int) $opciones['id_usuario'] : null)
        : (isset($usuario['id_usuario']) ? (int) $usuario['id_usuario'] : null);
    if ($idUsuario !== null && $idUsuario <= 0) {
        $idUsuario = null;
    }

    $script = (string) ($_SERVER['SCRIPT_NAME'] ?? '');
    if (PHP_SAPI === 'cli') {
        $origen = 'cli';
    } elseif (strpos($script, '/api/') !== false) {
        $origen = 'api';
    } else {
        $origen = 'web';
    }
    $origen = (string) ($opciones['origen'] ?? $origen);

    $sesionHash = null;
    if (function_exists('session_id')) {
        $sid = (string) session_id();
        if ($sid !== '') {
            $sesionHash = substr(hash('sha256', $sid), 0, 12);
        }
    }

    $url = (string) ($_SERVER['REQUEST_URI'] ?? '');
    // La query string puede traer tokens/correos: se guarda solo la ruta.
    $url = (string) parse_url($url, PHP_URL_PATH);

    return [
        'id_usuario' => $idUsuario,
        'usuario_nombre' => array_key_exists('usuario_nombre', $opciones)
            ? $opciones['usuario_nombre']
            : ($usuario['nombre'] ?? null),
        'usuario_rol' => array_key_exists('usuario_rol', $opciones)
            ? $opciones['usuario_rol']
            : ($usuario['rol'] ?? null),
        'id_almacen' => isset($usuario['id_almacen']) && (int) $usuario['id_almacen'] > 0 ? (int) $usuario['id_almacen'] : null,
        'ip' => (string) ($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0'),
        'user_agent' => mb_substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255),
        'sesion_hash' => $sesionHash,
        'url' => mb_substr($url, 0, 255),
        'metodo' => PHP_SAPI === 'cli' ? 'CLI' : strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? '')),
        'origen' => $origen,
    ];
}

/**
 * Resume un user-agent en algo que un humano lee de un vistazo ("Chrome · Android").
 */
function auditResumirUserAgent(?string $ua): string
{
    $ua = (string) $ua;
    if ($ua === '') {
        return '';
    }

    $navegador = 'Otro';
    foreach (['Edg' => 'Edge', 'OPR' => 'Opera', 'Firefox' => 'Firefox', 'Chrome' => 'Chrome', 'Safari' => 'Safari'] as $marca => $nombre) {
        if (stripos($ua, $marca) !== false) {
            $navegador = $nombre;
            break;
        }
    }

    $sistema = 'Otro';
    foreach (['Android' => 'Android', 'iPhone' => 'iPhone', 'iPad' => 'iPad', 'Windows' => 'Windows', 'Mac OS' => 'Mac', 'Linux' => 'Linux'] as $marca => $nombre) {
        if (stripos($ua, $marca) !== false) {
            $sistema = $nombre;
            break;
        }
    }

    return $navegador . ' · ' . $sistema;
}

/**
 * Filtros de la pantalla de Logs de Actividad, saneados. Todo lo que llegue raro (arreglos en la
 * query string como ?accion[]=x, fechas imposibles, textos enormes, caracteres de control, numeros
 * desbordados) cae al valor por defecto en vez de tronar la consulta o filtrar de mas.
 *
 * @param array<string,mixed> $get Normalmente $_GET.
 * @return array{vista:string,usuario:int,fecha_inicio:string,fecha_fin:string,accion:string,modulo:string,severidad:string,q:string,tabla:string,registro:int,pagina:int,tipo:string,origen:string,plataforma:string}
 */
function auditFiltrosDesdeGet(array $get): array
{
    $texto = static function ($valor, int $max): string {
        if (!is_scalar($valor)) {
            return '';
        }
        // Los caracteres de control (incluido NUL y saltos de linea) no tienen lugar en un filtro.
        $limpio = preg_replace('/[\x00-\x1F\x7F]+/', ' ', (string) $valor);
        return is_string($limpio) ? mb_substr(trim($limpio), 0, $max) : '';
    };
    $identificador = static function ($valor) use ($texto): string {
        $t = $texto($valor, 60);
        return preg_match('/^[A-Za-z0-9_]{1,50}$/D', $t) ? $t : '';
    };
    $entero = static function ($valor, int $min, int $max, int $defecto): int {
        if (!is_scalar($valor) || is_bool($valor)) {
            return $defecto;
        }
        $t = trim((string) $valor);
        if (!preg_match('/^-?\d{1,18}$/D', $t)) {
            return $defecto;
        }
        $n = (int) $t;
        return ($n < $min || $n > $max) ? $defecto : $n;
    };
    $fecha = static function ($valor): string {
        if (!is_string($valor) || !preg_match('/^(\d{4})-(\d{2})-(\d{2})$/D', $valor, $m)) {
            return '';
        }
        $anio = (int) $m[1];
        return ($anio >= 2000 && $anio <= 2100 && checkdate((int) $m[2], (int) $m[3], $anio)) ? $valor : '';
    };

    $usuario = $entero($get['usuario'] ?? null, -1, 2147483647, 0);
    $usuario = $usuario < -1 ? 0 : $usuario;

    $inicio = $fecha($get['fecha_inicio'] ?? null);
    $fin = $fecha($get['fecha_fin'] ?? null);
    if ($inicio !== '' && $fin !== '' && $inicio > $fin) {
        // "Desde" posterior a "Hasta": casi seguro se capturaron al reves; no dejar la pantalla vacia sin explicacion.
        [$inicio, $fin] = [$fin, $inicio];
    }

    // Una pagina absurda se acota (el OFFSET de SQL no debe desbordar); una no numerica vuelve a la 1.
    $paginaCruda = $get['pagina'] ?? null;
    $pagina = 1;
    if (is_scalar($paginaCruda) && !is_bool($paginaCruda) && preg_match('/^\d{1,30}$/D', trim((string) $paginaCruda))) {
        $pagina = (int) min(100000, max(1, (float) trim((string) $paginaCruda)));
    }

    $tipo = $texto($get['tipo'] ?? null, 10);
    $origen = $texto($get['origen'] ?? null, 10);
    $severidad = $texto($get['severidad'] ?? null, 10);

    return [
        'vista' => (is_string($get['vista'] ?? null) && $get['vista'] === 'navegacion') ? 'navegacion' : 'movimientos',
        'usuario' => $usuario,
        'fecha_inicio' => $inicio,
        'fecha_fin' => $fin,
        'accion' => $identificador($get['accion'] ?? null),
        'modulo' => $identificador($get['modulo'] ?? null),
        'severidad' => in_array($severidad, AUDIT_SEVERIDADES, true) ? $severidad : '',
        'q' => $texto($get['q'] ?? null, 100),
        'tabla' => $identificador($get['tabla'] ?? null),
        'registro' => $entero($get['registro'] ?? null, 0, 2147483647, 0),
        'pagina' => $pagina,
        'tipo' => in_array($tipo, ['visit', 'click'], true) ? $tipo : '',
        'origen' => in_array($origen, ['interno', 'externo'], true) ? $origen : '',
        'plataforma' => $texto($get['plataforma'] ?? null, 100),
    ];
}
