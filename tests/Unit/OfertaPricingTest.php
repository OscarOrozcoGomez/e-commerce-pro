<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class OfertaPricingTest extends TestCase
{
    // ------------------------------------------------------------------
    // Regla del negocio: precio de oferta = costo de compra + $50
    // ------------------------------------------------------------------

    public function testPrecioSugeridoEsCostoMasCincuenta(): void
    {
        $this->assertSame(150.0, ofertaPrecioSugerido(100.0));
        $this->assertSame(199.9, ofertaPrecioSugerido(149.9));
        $this->assertSame(50.0, ofertaPrecioSugerido(0.0));
    }

    public function testPrecioSugeridoNuncaBajaDeCincuentaConCostoNegativo(): void
    {
        $this->assertSame(50.0, ofertaPrecioSugerido(-30.0));
    }

    // ------------------------------------------------------------------
    // Precio efectivo segun si el producto esta en la categoria "Ofertas"
    // ------------------------------------------------------------------

    public function testProductoFueraDeOfertaConservaPrecioVenta(): void
    {
        $this->assertSame(
            499.0,
            ofertaPrecioEfectivo(499.0, 200.0, null, false)
        );
        // Aunque tenga precio_oferta capturado, si no esta en la categoria no aplica.
        $this->assertSame(
            499.0,
            ofertaPrecioEfectivo(499.0, 200.0, 250.0, false)
        );
    }

    public function testEnOfertaSinOverrideUsaCostoMasCincuenta(): void
    {
        $this->assertSame(250.0, ofertaPrecioEfectivo(499.0, 200.0, null, true));
        $this->assertSame(250.0, ofertaPrecioEfectivo(499.0, 200.0, '', true));
        $this->assertSame(250.0, ofertaPrecioEfectivo(499.0, 200.0, 0, true));
        $this->assertSame(250.0, ofertaPrecioEfectivo(499.0, 200.0, '0', true));
    }

    public function testEnOfertaConOverrideManualUsaEseValor(): void
    {
        $this->assertSame(180.0, ofertaPrecioEfectivo(499.0, 200.0, 180.0, true));
        // Aceptado como string (viene de $_POST / columna DECIMAL).
        $this->assertSame(85.5, ofertaPrecioEfectivo(499.0, 200.0, '85.5', true));
    }

    // ------------------------------------------------------------------
    // Expresiones SQL: no deben introducir placeholders
    // ------------------------------------------------------------------

    public function testEnOfertaSqlExprReconoceSingularYPlural(): void
    {
        $expr = ofertaSqlEnOfertaExpr('p');

        $this->assertStringContainsString("'oferta'", $expr);
        $this->assertStringContainsString("'ofertas'", $expr);
        $this->assertStringContainsString('LOWER(c_of.nombre)', $expr);
        $this->assertStringContainsString('pc_of.id_producto = p.id_producto', $expr);
        $this->assertStringNotContainsString('?', $expr);
        $this->assertStringNotContainsString(':', $expr);
    }

    public function testPrecioEfectivoSqlExprAplicaLaRegla(): void
    {
        $expr = ofertaSqlPrecioEfectivoExpr('p.precio_venta', 'p.precio_costo', 'p.precio_oferta', ofertaSqlEnOfertaExpr('p'));

        $this->assertStringContainsString('CASE WHEN', $expr);
        $this->assertStringContainsString('NULLIF(p.precio_oferta, 0)', $expr);
        $this->assertStringContainsString('GREATEST(p.precio_costo, 0) + 50', $expr);
        $this->assertStringContainsString('ELSE p.precio_venta END', $expr);
        $this->assertStringNotContainsString('?', $expr);
    }

    // ------------------------------------------------------------------
    // Resolucion de la categoria / pertenencia (usadas por el 1-clic de Caducidades)
    // ------------------------------------------------------------------

    private function pdoConCategorias(): PDO
    {
        $pdo = new PDO('sqlite::memory:');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->exec('CREATE TABLE categorias (id_categoria INTEGER PRIMARY KEY AUTOINCREMENT, nombre TEXT, estado TEXT DEFAULT "activo")');
        $pdo->exec('CREATE TABLE producto_categorias (id_producto INTEGER, id_categoria INTEGER, PRIMARY KEY (id_producto, id_categoria))');
        return $pdo;
    }

    public function testResolverCategoriaEncuentraNombrePluralExistente(): void
    {
        $pdo = $this->pdoConCategorias();
        $pdo->exec("INSERT INTO categorias (nombre, estado) VALUES ('Ofertas', 'activo')");

        $id = ofertaResolverCategoriaId($pdo, false);
        $this->assertSame(1, $id);
    }

    public function testResolverCategoriaCreaOfertaSiNoExiste(): void
    {
        $pdo = $this->pdoConCategorias();

        $this->assertNull(ofertaResolverCategoriaId($pdo, false));

        $id = ofertaResolverCategoriaId($pdo, true);
        $this->assertIsInt($id);
        $nombre = $pdo->query('SELECT nombre FROM categorias WHERE id_categoria = ' . (int) $id)->fetchColumn();
        $this->assertSame('Oferta', $nombre);
    }

    public function testProductoEnOfertaYFiltrado(): void
    {
        $pdo = $this->pdoConCategorias();
        $pdo->exec("INSERT INTO categorias (nombre) VALUES ('Ofertas')");        // id 1
        $pdo->exec("INSERT INTO categorias (nombre) VALUES ('Omega')");          // id 2
        $pdo->exec('INSERT INTO producto_categorias VALUES (10, 1)');            // producto 10 en Ofertas
        $pdo->exec('INSERT INTO producto_categorias VALUES (11, 2)');            // producto 11 solo en Omega

        $this->assertTrue(ofertaProductoEnOferta($pdo, 10));
        $this->assertFalse(ofertaProductoEnOferta($pdo, 11));
        $this->assertFalse(ofertaProductoEnOferta($pdo, 999));

        $mapa = ofertaFiltrarEnOferta($pdo, [10, 11, 999]);
        $this->assertSame([10 => true], $mapa);
    }
}
