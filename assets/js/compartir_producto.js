// Partes puras del boton "Con foto" de Ventas (views/sales.php): decidir si se puede compartir la
// foto y como se llama el archivo. Sin DOM, para poder probarlas con Node desde PHPUnit
// (tests/Unit/CompartirProductoConFotoTest.php). En el navegador quedan en window.
(function (root) {
    'use strict';

    // El navegador puede abrir el menu de compartir CON archivos (celulares; casi ninguna compu).
    function compartirPuedeArchivos(nav) {
        return !!nav && typeof nav.canShare === 'function' && typeof nav.share === 'function';
    }

    // Hay una foto real que mandar (no el placeholder "sin imagen" ni un SVG en linea).
    function compartirImagenUsable(src) {
        const valor = String(src || '').trim();
        if (valor === '') return false;
        if (valor.includes('no-product') || valor.includes('no-image') || valor.startsWith('data:image/svg')) return false;
        return true;
    }

    // "Omega 3 Platinum - 180 Caps | 1000 mg" -> "Omega-3-Platinum-180-Caps-1000-mg.jpg": sin acentos
    // ni signos (WhatsApp y algunos celulares se atoran con ellos), maximo 60 caracteres.
    function compartirNombreArchivo(nombre) {
        const base = String(nombre || '')
            .normalize('NFD').replace(/[̀-ͯ]/g, '')
            .replace(/[^A-Za-z0-9]+/g, '-')
            .replace(/^-+|-+$/g, '')
            .slice(0, 60)
            .replace(/-+$/, '');
        return (base || 'producto') + '.jpg';
    }

    const api = { compartirPuedeArchivos, compartirImagenUsable, compartirNombreArchivo };
    if (typeof module !== 'undefined' && module.exports) {
        module.exports = api;
    } else {
        Object.assign(root, api);
    }
})(typeof window !== 'undefined' ? window : globalThis);
