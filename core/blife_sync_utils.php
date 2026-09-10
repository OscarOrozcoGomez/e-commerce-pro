<?php
declare(strict_types=1);

/**
 * Utilidades de sincronización con B-Life (tienda Shopify pública).
 *
 * B-Life apagó su microservicio backend.blife-mx.com/nutritional-information (hoy responde
 * 409 a todo). Su catálogo vive en Shopify, así que la sincronización de la ficha de
 * producto lee de ahí:
 *   - products/<handle>.json  -> nombre, variantes, precios, SKU, código de barras, imágenes
 *   - products/<handle>       -> pestañas del tema (ingredientes, modo de uso) + JSON-LD
 *
 * Estas funciones se extrajeron de api/products_manager.php para poder cubrirlas con
 * pruebas unitarias sin arrastrar el manejador HTTP ni la sesión.
 */

if (!defined('BLIFE_SHOP')) {
    define('BLIFE_SHOP', 'https://blifemx.myshopify.com');
}

if (!function_exists('blifeHttpGet')) {
    /** GET simple con cURL. Devuelve [body, http_code, curl_error]. */
    function blifeHttpGet(string $url, int $timeout = 15): array
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_SSL_VERIFYPEER => false, // XAMPP local no siempre tiene el bundle de CAs
            CURLOPT_TIMEOUT        => $timeout,
            CURLOPT_HTTPHEADER     => [
                'Accept: */*',
                'User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/149.0.0.0 Safari/537.36',
            ],
        ]);
        $body = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err  = curl_error($ch);
        curl_close($ch);
        return [$body === false ? '' : (string) $body, $code, $err];
    }
}

if (!function_exists('blifeCatalog')) {
    /**
     * Catálogo completo de la tienda Shopify de B-Life (products.json paginado), cacheado en
     * disco 6 h para no descargar ~1.5 MB en cada clic de "SINC".
     * @return array<int,array<string,mixed>>
     */
    function blifeCatalog(bool $force = false): array
    {
        $cacheFile = sys_get_temp_dir() . '/blife_catalog.json';
        if (!$force && is_file($cacheFile) && (time() - (int) filemtime($cacheFile) < 6 * 3600)) {
            $cached = json_decode((string) file_get_contents($cacheFile), true);
            if (is_array($cached) && $cached) return $cached;
        }

        $all = [];
        for ($page = 1; $page <= 6; $page++) {
            [$body, $code] = blifeHttpGet(BLIFE_SHOP . "/products.json?limit=250&page=$page", 20);
            if ($code !== 200) break;
            $chunk = json_decode($body, true)['products'] ?? null;
            if (!is_array($chunk) || !$chunk) break;
            $all = array_merge($all, $chunk);
            if (count($chunk) < 250) break;
        }
        if ($all) @file_put_contents($cacheFile, json_encode($all));
        return $all;
    }
}

if (!function_exists('blifeNormalizeText')) {
    /** minúsculas + sin diacríticos del español, para buscar "multivitaminico" == "multivitamínico". */
    function blifeNormalizeText(string $s): string
    {
        $s = mb_strtolower(trim($s), 'UTF-8');
        return strtr($s, [
            'á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u', 'ü' => 'u', 'ñ' => 'n',
            'à' => 'a', 'è' => 'e', 'ì' => 'i', 'ò' => 'o', 'ù' => 'u',
        ]);
    }
}

if (!function_exists('blifeHandleFromSku')) {
    /**
     * Busca un SKU exacto (case-insensitive) en el catálogo. B-Life los escribe en
     * MAYÚSCULAS y sin guiones (BLIFEWOMENSMULTMATUR3180), a diferencia de los handles.
     * @return array{0:string,1:int|null}|null  [handle, variantId] o null si no está.
     */
    function blifeHandleFromSku(string $sku, ?array $catalogo = null): ?array
    {
        $sku = strtoupper(trim($sku));
        if ($sku === '') return null;

        $pasadas = $catalogo !== null ? [$catalogo] : [null, null];
        foreach ($pasadas as $i => $listaFija) {
            $lista = $catalogo !== null ? $listaFija : blifeCatalog($i === 1);
            foreach ($lista as $p) {
                foreach ($p['variants'] ?? [] as $v) {
                    if (strtoupper(trim((string) ($v['sku'] ?? ''))) === $sku) {
                        $vid = (int) ($v['id'] ?? 0);
                        return [(string) ($p['handle'] ?? ''), $vid ?: null];
                    }
                }
            }
        }
        return null;
    }
}

if (!function_exists('blifeHandleFromNumericId')) {
    /**
     * Busca un id numérico (de producto o de variante Shopify) en el catálogo.
     * @param array<int,array<string,mixed>>|null $catalogo  catálogo ya cargado (para pruebas);
     *        si es null se descarga con blifeCatalog() (1ª pasada normal, 2ª forzando refresco).
     * @return array{0:string,1:int|null}  [handle, variantId|null]
     */
    function blifeHandleFromNumericId(int $n, ?int $variantHint, ?array $catalogo = null): array
    {
        $pasadas = $catalogo !== null ? [$catalogo] : [null, null];
        foreach ($pasadas as $i => $listaFija) {
            $lista = $catalogo !== null ? $listaFija : blifeCatalog($i === 1);
            foreach ($lista as $p) {
                if ((int) ($p['id'] ?? 0) === $n) return [(string) ($p['handle'] ?? ''), $variantHint];
                foreach ($p['variants'] ?? [] as $v) {
                    if ((int) ($v['id'] ?? 0) === $n) return [(string) ($p['handle'] ?? ''), $n];
                }
            }
        }
        throw new Exception("No encontré ningún producto ni variante con ID «{$n}» en la tienda de B-Life.");
    }
}

if (!function_exists('blifeResolveHandle')) {
    /**
     * Traduce lo que pega el usuario (handle, URL de blife.mx/Shopify, id de producto o id de
     * variante) al handle del producto en la tienda. Devuelve [handle, variantId|null].
     *
     * @param array<int,array<string,mixed>>|null $catalogo  catálogo ya cargado (para pruebas).
     * @return array{0:string,1:int|null}
     */
    function blifeResolveHandle(string $input, ?array $catalogo = null): array
    {
        $input = trim($input);
        if ($input === '') {
            throw new Exception('Pega el identificador o la URL del producto de B-Life.');
        }

        $variantHint = null;
        if (preg_match('~[?&]variant=(\d+)~', $input, $m)) {
            $variantHint = (int) $m[1];
        }

        // ID viejo de B-Life (Mongo ObjectId de 24 hex): ya no sirve para nada.
        if (preg_match('~^[a-f0-9]{24}$~i', $input)) {
            throw new Exception('Ese es un ID viejo de B-Life que su API ya no reconoce. Usa el handle del producto o su URL de blife.mx.');
        }

        // Si es URL, quédate con el último segmento del path (/Product/123 ó /coleccion/mi-handle).
        if (preg_match('~^https?://~i', $input)) {
            $path = (string) (parse_url($input, PHP_URL_PATH) ?: '');
            $segs = array_values(array_filter(explode('/', $path), 'strlen'));
            $input = $segs ? (string) end($segs) : '';
        }

        // Quita cualquier querystring / fragmento suelto (p. ej. "mi-handle?variant=123").
        $input = (string) preg_replace('~[?#].*$~s', '', $input);

        // gid://shopify/ProductVariant/123  ó  .../Product/123
        if (preg_match('~(Product|ProductVariant)/(\d+)~i', $input, $m)) {
            if (strcasecmp($m[1], 'ProductVariant') === 0) {
                $variantHint = $variantHint ?? (int) $m[2];
            }
            $input = $m[2];
        }

        if (ctype_digit($input)) {
            return blifeHandleFromNumericId((int) $input, $variantHint, $catalogo);
        }
        if (preg_match('~^[A-Za-z0-9][A-Za-z0-9\-]{2,}$~', $input)) {
            // Sin guiones: puede ser un SKU (los handles de B-Life siempre llevan guion).
            // Si el SKU existe en el catálogo se resuelve a su handle + esa variante.
            if (strpos($input, '-') === false) {
                $porSku = blifeHandleFromSku($input, $catalogo);
                if ($porSku !== null) {
                    return [$porSku[0], $variantHint ?? $porSku[1]];
                }
            }
            return [strtolower($input), $variantHint];
        }
        throw new Exception("No reconozco «{$input}». Pega el handle del producto, su URL de blife.mx, o el ID numérico de producto/variante.");
    }
}

if (!function_exists('blifeHtmlToText')) {
    /** Convierte HTML corto (body_html de Shopify) a texto plano con saltos de párrafo. */
    function blifeHtmlToText(string $html): string
    {
        $t = preg_replace('~<br\s*/?>~i', "\n", $html);
        $t = preg_replace('~</(p|div|li|h[1-6])>~i', "\n\n", (string) $t);
        $t = preg_replace('~<li[^>]*>~i', '• ', (string) $t);
        $t = html_entity_decode(strip_tags((string) $t), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $t = str_replace("\xC2\xA0", ' ', (string) $t);       // &nbsp;
        $t = preg_replace('~[ \t]+~', ' ', $t);
        $t = preg_replace('~ *\n *~', "\n", (string) $t);
        $t = preg_replace('~\n{3,}~', "\n\n", (string) $t);
        return trim((string) $t);
    }
}

if (!function_exists('blifeShortName')) {
    /**
     * Deriva el "nombre corto" / nombre de la etiqueta del pomo ("3 Mag Blend",
     * "Clarity Platinum") del body_html de Shopify, que casi siempre empieza con
     * "<Nombre> B Life(R). ...". A veces va tras una frase-gancho ("¡Descubre el
     * bienestar con Glycinate Mag B Life®!"). Si no se puede aislar con confianza
     * devuelve '' (se captura a mano en la ficha).
     */
    function blifeShortName(string $bodyHtml): string
    {
        $t = trim((string) preg_replace('~\s+~u', ' ', strip_tags(html_entity_decode($bodyHtml, ENT_QUOTES | ENT_HTML5, 'UTF-8'))));
        $t = ltrim($t, "¡¿ \t\"'");

        // Caso LIMPIO: el body arranca directo con "<Nombre> B Life(R). ...".
        // <Nombre> = 1-5 tokens; el 1º empieza con mayúscula o dígito.
        if (!preg_match('~^([\p{Lu}0-9][\p{L}\p{N}&.\+\-]*(?:\s+[\p{L}\p{N}&.\+\-]{1,20}){0,4})\s+B\s*Life\b~u', $t, $m)) {
            return '';
        }
        $cand = trim($m[1], " .·-–—|\"'");

        // Descarta si es en realidad una frase de marketing o queda colgando de una preposición.
        if (preg_match('~^(?:descubre|conoce|explora|disfruta|prueba|impulsa|eleva|experimenta|renueva|dale|potencia|mejora|transforma|incorpora|aprovecha|a[ñn]ade|suma|integra|cada|nuestr[ao]s?|este|esta|el|la|los|las|un|una|con|de)\b~ui', $cand)) {
            return '';
        }
        if (preg_match('~\b(?:de|con|y|para|del|sin)$~ui', $cand)) {
            return '';
        }

        $palabras = count(array_filter(preg_split('~\s+~u', $cand) ?: []));
        return ($palabras >= 1 && $palabras <= 4 && mb_strlen($cand) <= 40) ? $cand : '';
    }
}

if (!function_exists('blifeTabText')) {
    /** Saca el texto de una pestaña del tema (metafield renderizado) del HTML del producto. */
    function blifeTabText(string $html, string $tabPrefix, int $productId): string
    {
        $pattern = '~id="' . preg_quote($tabPrefix, '~') . '-' . $productId . '"[^>]*>(.*?)</div>~is';
        if (!preg_match($pattern, $html, $m)) {
            return '';
        }

        $txt = preg_replace('~<br\s*/?>~i', "\n", $m[1]);
        $txt = html_entity_decode(strip_tags((string) $txt), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $txt = preg_replace('~[ \t]+~', ' ', (string) $txt);
        $txt = preg_replace('~\s*\n\s*~', "\n", (string) $txt);
        return trim((string) $txt);
    }
}

if (!function_exists('blifeVariantMetaFromJsonLd')) {
    /**
     * Mapa variantId => ['sku' => ..., 'barcode' => ...] a partir del JSON-LD (ProductGroup /
     * hasVariant) que Shopify incrusta en la página del producto. El SKU también viene en
     * products.json; el código de barras (gtin) sale de aquí como respaldo del campo barcode.
     * @return array<int,array{sku:string,barcode:string}>
     */
    function blifeVariantMetaFromJsonLd(string $html): array
    {
        $out = [];
        if (!preg_match_all('~<script type="application/ld\+json"[^>]*>(.*?)</script>~is', $html, $mm)) {
            return $out;
        }
        foreach ($mm[1] as $raw) {
            $data = json_decode(trim($raw), true);
            if (!is_array($data)) continue;
            $groups = isset($data['@graph']) && is_array($data['@graph']) ? $data['@graph'] : [$data];
            foreach ($groups as $node) {
                $variants = is_array($node) ? ($node['hasVariant'] ?? null) : null;
                if (!is_array($variants)) continue;
                foreach ($variants as $v) {
                    if (!is_array($v)) continue;
                    $ref = (string) ($v['@id'] ?? $v['url'] ?? '');
                    if (!preg_match('~variant=(\d+)~', $ref, $vm)) continue;
                    $out[(int) $vm[1]] = [
                        'sku'     => trim((string) ($v['sku'] ?? '')),
                        'barcode' => trim((string) ($v['gtin'] ?? $v['gtin13'] ?? $v['gtin12'] ?? $v['gtin8'] ?? '')),
                    ];
                }
            }
        }
        return $out;
    }
}
