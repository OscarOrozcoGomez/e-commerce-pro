-- Migracion: el negocio solo acepta Efectivo o Transferencia Bancaria (contra entrega,
-- confirmado por el usuario) -- Tarjeta y Cheque nunca se aceptaron en realidad, pero
-- estaban en metodos_pago con estado='activo' y en el selector de views/sales.php.
--
-- Se desactivan (estado='inactivo'), NO se borran: fk_pedidos_metodo (pedidos.id_metodo_pago)
-- puede tener pedidos historicos que ya usaron alguno de estos dos, y borrar la fila
-- rompería ese reporte (ON DELETE SET NULL perderia el dato de que metodo se uso).
UPDATE `metodos_pago`
SET `estado` = 'inactivo'
WHERE `nombre` IN ('Tarjeta', 'Cheque');
