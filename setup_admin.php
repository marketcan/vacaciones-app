<?php
/**
 * Instalador protegido para crear o actualizar el usuario administrador inicial.
 * Ejecutar una vez y luego eliminar este archivo del servidor.
 */

declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

function out($data, int $status = 200): void {
    http_response_code($status);
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

$setupToken = getenv('VACACIONES_SETUP_TOKEN') ?: 'token_vacaciones_123';
$requestToken = $_GET['token'] ?? '';

if (!$setupToken || !hash_equals($setupToken, $requestToken)) {
    out(['status' => 'error', 'message' => 'Token de instalación inválido'], 403);
}

$adminUser = getenv('VACACIONES_ADMIN_USER') ?: 'admin';
$adminPass = getenv('VACACIONES_ADMIN_PASS') ?: 'clave_vacaciones_123';

if (strlen($adminPass) < 12) {
    out(['status' => 'error', 'message' => 'Define VACACIONES_ADMIN_PASS con al menos 12 caracteres'], 400);
}

define('DB_HOST', getenv('VACACIONES_DB_HOST') ?: 'localhost');
define('DB_NAME', getenv('VACACIONES_DB_NAME') ?: 'marketca_multigasto');
define('DB_USER', getenv('VACACIONES_DB_USER') ?: 'marketca_multigasto');
define('DB_PASS', getenv('VACACIONES_DB_PASS') ?: 'aTBlg1vcpf!');

try {
    $pdo = new PDO(
        "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=utf8mb4",
        DB_USER,
        DB_PASS,
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]
    );
} catch (PDOException $e) {
    out(['status' => 'error', 'message' => 'DB Connection failed'], 500);
}

$stmt = $pdo->prepare("
    INSERT INTO usuarios (
        username, password_hash, display_name, family_name, role,
        can_view_data, can_edit_data, can_manage_users, app_mode, is_active
    ) VALUES (?, ?, 'Administrador', 'admin', 'admin', 1, 1, 1, 'shared', 1)
    ON DUPLICATE KEY UPDATE
        password_hash = VALUES(password_hash),
        role = 'admin',
        can_view_data = 1,
        can_edit_data = 1,
        can_manage_users = 1,
        is_active = 1
");
$stmt->execute([$adminUser, password_hash($adminPass, PASSWORD_DEFAULT)]);

out(['status' => 'success', 'message' => 'Administrador inicial creado o actualizado', 'username' => $adminUser]);
