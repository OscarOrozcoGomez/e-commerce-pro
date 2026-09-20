# Alex: fotos de frascos y OCR con Google Vision

## El problema (caso real 2026-09-20)

Una clienta mando la foto de un frasco B Life ("WOMENS MULT MATUR3", 180 capsulas) con el texto
"Que precio tiene este?". El OCR del puente (Tesseract, servicio `alex-media-service` en el VPS)
solo leyo el texto chico de la etiqueta ("SUPLEMENTO ALIMENTICIO" salio como "Mento Alimentic" y los
ingredientes) y perdio el nombre grande y decorativo. Alex busco "Mento Alimentic" como si fuera el
producto, no encontro nada y ofrecio productos "con esas caracteristicas" que no eran el de la foto.

El producto si existe: su `nombre_corto` es justo `Womens Mult Matur3` (la etiqueta del pomo).

## Lo que ya hace el codigo (PHP, versionado)

`core/ai_foto_producto_utils.php`, conectado en `aiGenerarRespuestaParaConversacion()`
(`core/ai_assistant.php`). Solo agrega contexto al prompt del turno que YA se iba a generar; no manda
mensajes ni cambia la cadencia de envios (no toca las reglas anti-rafaga de WhatsApp).

- Empareja el texto de OCR (y el caption del cliente) contra los **nombres cortos** del catalogo,
  tolerando errores de lectura. Los nombres largos NO entran (chocan con los ingredientes: "Colageno
  Hidrolizado", "Vitamina C") y una sola palabra suelta no basta ("Probioticos" no es "60 Billion Probiotics").
- Segun lo que encuentre, Alex recibe una de estas lineas:
  - Coincidencia clara: "el OCR sugiere que es «Womens Mult Matur3»: verificalo con consultar_inventario".
  - Dos productos parecidos (p. ej. Mens/Womens Mult Matur3): que consulte ambos y pida al cliente confirmar.
  - Foto de etiqueta sin nombre legible (solo leyendas genericas): que NO busque las leyendas, NO ofrezca
    productos "similares" y transfiera a un asesor describiendo lo que si se leyo.
  - Comprobantes de pago u otras fotos sin rasgos de etiqueta: nada cambia.
- El prompt fijo explica que "Suplemento alimenticio", "Capsulas a base de...", "Contenido N capsulas",
  ingredientes y modo de uso no son el nombre del producto.
- Limite conocido: solo se reconocen productos con `nombre_corto` capturado (en la base local ~100 de ~216 activos).

## Mejor lectura de la imagen: `api/alex_ocr_imagen.php`

Endpoint servidor-a-servidor que lee la foto con **Google Vision `TEXT_DETECTION`** (texto de escena:
frascos, letreros, tipografias decorativas), mucho mejor que Tesseract con letras estilizadas sobre un
frasco curvo con reflejos. Usa la misma llave que la lectura de lotes (`VISION_KEY`).

- `POST /api/alex_ocr_imagen.php`
- Header `X-Webhook-Token: <WA_WEBHOOK_TOKEN>` (el mismo token que ya usa el puente en `whatsapp_webhook.php`).
  Nunca por `?token=`. Sin sesion, no escribe en la BD, no manda nada a WhatsApp.
- Body JSON: `{"imagen_base64": "<foto en base64, JPEG/PNG/WebP/GIF, hasta ~6 MB>"}` (acepta tambien data URI).
- Respuesta 200: `{"success": true, "texto": "...", "fuente": "google_vision"}`.
  Sin texto legible: `{"success": false, "message": "No se detecto texto en la foto."}` (HTTP 200).
  Errores: 400 (imagen invalida), 403 (token), 405, 413 (muy grande), 429 (mas de 60/min), 502.
- Cada llamada es 1 unidad de Google Vision; tope defensivo de 60 por minuto.

## Cambio pendiente en el puente (VPS, no versionado — NO aplicado)

En `/opt/wa-bridge/app/index.js` (o en `alex-media-service`), donde hoy se le saca OCR a la foto recibida,
probar primero este endpoint y, **si falla o no trae texto, seguir con el OCR de siempre**. Plantilla (adaptar
a los nombres reales del codigo):

```js
async function ocrConVision(imagenBuffer) {
  try {
    const res = await fetch(`${process.env.PHP_BASE_URL}/api/alex_ocr_imagen.php`, {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json',
        'X-Webhook-Token': process.env.WA_WEBHOOK_TOKEN,
      },
      body: JSON.stringify({ imagen_base64: imagenBuffer.toString('base64') }),
      signal: AbortSignal.timeout(25000),
    });
    const data = await res.json();
    return data && data.success && data.texto ? String(data.texto).trim() : '';
  } catch (e) {
    return ''; // cae al OCR de siempre
  }
}

// donde hoy se calcula el texto OCR de la foto:
let textoOcr = await ocrConVision(buffer);
if (!textoOcr) textoOcr = await ocrTesseractDeSiempre(buffer);
```

El formato del mensaje hacia PHP **no cambia** (`[El cliente envio una foto ... Texto detectado en la imagen
(puede tener errores de OCR): <texto>]`), asi que no hay que tocar nada mas.

Despliegue: aplicar en el VPS, reiniciar el puente (`systemctl restart wa-bridge`) y probar con una foto de
un frasco. Reversion: quitar la llamada a `ocrConVision` (el resto del flujo no depende de ella). Los
cambios en PHP funcionan con cualquiera de los dos OCR.

Prueba manual del endpoint:

```bash
curl -s -X POST "$BASE/api/alex_ocr_imagen.php" \
  -H "X-Webhook-Token: $WA_WEBHOOK_TOKEN" -H "Content-Type: application/json" \
  --data-binary '{"imagen_base64":"'"$(base64 -w0 frasco.jpg)"'"}'
```
