<?php
require_once __DIR__ . '/../core/config.php';

$pdo = getPDO();
$nombre = 'Repartidor de Prueba';
$email = 'repartidor@ejemplo.com';
$password = password_hash('repartidor123', PASSWORD_BCRYPT);
$id_rol = 5; // ID del rol repartidor
$id_almacen = 1;

try {
    $stmt = $pdo->prepare("INSERT INTO usuarios (nombre, email, contrasena, id_rol, id_almacen, estado) VALUES (?, ?, ?, ?, ?, 'activo')");
    $stmt->execute([$nombre, $email, $password, $id_rol, $id_almacen]);
    echo "Usuario repartidor creado con éxito.\n";
    echo "Email: $email\n";
    echo "Password: repartidor123\n";
} catch (Exception $e) {
    echo "Error: " . $e->getMessage();
}
?>
