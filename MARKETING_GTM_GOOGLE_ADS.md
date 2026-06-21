# Setup rapido: GTM + Google Ads (Thank You Page)

Este proyecto ya envia un evento en `views/gracias.php`:
- `purchase_thank_you_view`
- `order_reference`
- `attribution` con `gclid`, `wbraid`, `gbraid`, `utm_*` (si existen)

## Estado actual (sin IDs de marketing)

No bloquea nada dejarlo asi por ahora.
La pagina de gracias funciona normal aunque no exista codigo de Google Ads/GTM.

## Cuando marketing te pase datos

Te van a compartir, minimo:
- Google Ads Conversion ID (formato `AW-XXXXXXXXX`)
- Conversion Label (formato `AbCdEfGhIjK`)
- Si usan GTM: GTM Container ID (`GTM-XXXXXXX`)

## Opcion A: usar Google Tag Manager (recomendada)

1. En GTM crea un Trigger:
- Tipo: Custom Event
- Event name: `purchase_thank_you_view`

2. Crea Variables de Data Layer:
- `order_reference`
- `attribution.gclid`
- `attribution.wbraid`
- `attribution.gbraid`
- `attribution.utm_source`
- `attribution.utm_medium`
- `attribution.utm_campaign`
- `attribution.utm_term`
- `attribution.utm_content`

3. Crea Tag de Google Ads Conversion Tracking:
- Conversion ID: (lo da marketing)
- Conversion Label: (lo da marketing)
- Transaction ID: `{{order_reference}}`
- Trigger: `purchase_thank_you_view`

4. Publica el contenedor.

## Opcion B: disparo directo por gtag (sin GTM)

Este proyecto ya soporta disparo directo en `views/gracias.php` si existe:
- `GOOGLE_ADS_SEND_TO=AW-XXXXXXXXX/AbCdEfGhIjK`

Si no esta configurado, no dispara `gtag`, pero la pagina sigue funcionando.

## Verificacion rapida

1. Completa una compra de prueba.
2. Confirma redireccion a `views/gracias.php?id=...`.
3. En navegador, verifica `dataLayer`:
- Debe existir evento `purchase_thank_you_view`.
4. Si hay GTM/Ads instalado, valida en Tag Assistant.

## Duplicados

El sistema ya evita duplicados en la misma sesion/orden para reducir doble conteo por refresh.

## Privacidad

La pagina de gracias no expone datos sensibles de la compra.
El detalle completo queda protegido en `views/detalle_compra.php`.
