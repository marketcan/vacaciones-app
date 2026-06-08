<?php
/**
 * API publica para Vacaciones App.
 * Requiere login por sesion y aplica permisos por usuario.
 */

declare(strict_types=1);

session_start([
    'cookie_httponly' => true,
    'cookie_samesite' => 'Lax',
    'cookie_secure' => !empty($_SERVER['HTTPS'])
]);

header('Content-Type: application/json; charset=utf-8');
$allowedOrigin = getenv('VACACIONES_ALLOWED_ORIGIN') ?: '';
if ($allowedOrigin && ($_SERVER['HTTP_ORIGIN'] ?? '') === $allowedOrigin) {
    header('Access-Control-Allow-Origin: ' . $allowedOrigin);
}
header('Access-Control-Allow-Credentials: true');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit;
}

define('DB_HOST', getenv('VACACIONES_DB_HOST') ?: 'localhost');
define('DB_NAME', getenv('VACACIONES_DB_NAME') ?: 'marketca_multigasto');
define('DB_USER', getenv('VACACIONES_DB_USER') ?: 'marketca_multigasto');
define('DB_PASS', getenv('VACACIONES_DB_PASS') ?: 'aTBlg1vcpf!');

function json_out($data, int $status = 200): void {
    http_response_code($status);
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

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
    
    // Auto-migration: check if categories column exists in usuarios, if not, create it
    try {
        $pdo->query("SELECT categories FROM usuarios LIMIT 1");
    } catch (PDOException $ex) {
        $pdo->exec("ALTER TABLE usuarios ADD COLUMN categories TEXT NULL");
    }

    // Auto-migration: check if viaje column exists in usuarios, if not, create it
    try {
        $pdo->query("SELECT viaje FROM usuarios LIMIT 1");
    } catch (PDOException $ex) {
        $pdo->exec("ALTER TABLE usuarios ADD COLUMN viaje VARCHAR(100) DEFAULT 'Viaje Principal'");
    }

    // Auto-migration: check if viaje column exists in gastos, if not, create it
    try {
        $pdo->query("SELECT viaje FROM gastos LIMIT 1");
    } catch (PDOException $ex) {
        $pdo->exec("ALTER TABLE gastos ADD COLUMN viaje VARCHAR(100) DEFAULT 'Viaje Principal'");
    }

    // Auto-migration: check if viaje column exists in gastos_log, if not, create it
    try {
        $pdo->query("SELECT viaje FROM gastos_log LIMIT 1");
    } catch (PDOException $ex) {
        try {
            $pdo->exec("ALTER TABLE gastos_log ADD COLUMN viaje VARCHAR(100) DEFAULT 'Viaje Principal'");
        } catch (PDOException $e_log) {}
    }

    // Auto-migration: check if eventos table exists, if not, create it
    try {
        $pdo->query("SELECT 1 FROM eventos LIMIT 1");
    } catch (PDOException $ex) {
        $pdo->exec("
            CREATE TABLE `eventos` (
              `id` int(11) NOT NULL AUTO_INCREMENT,
              `nombre` varchar(150) NOT NULL,
              `tipo` enum('shared','single') NOT NULL DEFAULT 'shared',
              `categorias` text DEFAULT NULL,
              `familias` text DEFAULT NULL,
              `multi_moneda` tinyint(1) DEFAULT 1,
              `moneda_defecto` varchar(3) DEFAULT 'USD',
              PRIMARY KEY (`id`),
              UNIQUE KEY `nombre` (`nombre`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
        ");
    }

    // Auto-migration: add currency columns to eventos
    try {
        $pdo->query("SELECT multi_moneda FROM eventos LIMIT 1");
    } catch (PDOException $ex) {
        try {
            $pdo->exec("ALTER TABLE eventos ADD COLUMN multi_moneda TINYINT(1) DEFAULT 1, ADD COLUMN moneda_defecto VARCHAR(3) DEFAULT 'USD'");
        } catch (PDOException $e) {}
    }
} catch (PDOException $e) {
    json_out(['status' => 'error', 'message' => 'DB Connection failed'], 500);
}

function current_user(PDO $pdo): ?array {
    if (empty($_SESSION['user_id'])) {
        return null;
    }

    $stmt = $pdo->prepare("SELECT id, username, display_name, family_name, role, can_view_data, can_edit_data, can_manage_users, app_mode, is_active, categories, viaje FROM usuarios WHERE id = ?");
    $stmt->execute([$_SESSION['user_id']]);
    $user = $stmt->fetch();

    if (!$user || !(int)$user['is_active']) {
        $_SESSION = [];
        session_destroy();
        return null;
    }

    // Preserve full list before overriding active
    $all_viajes = array_values(array_filter(array_map('trim', explode(',', $user['viaje']))));
    $user['all_viajes'] = $all_viajes;

    if (!empty($_SESSION['active_evento'])) {
        $user['viaje'] = $_SESSION['active_evento'];
    } else {
        $user['viaje'] = $all_viajes[0] ?? 'Viaje Principal';
        $_SESSION['active_evento'] = $user['viaje'];
    }

    $stmt_ev = $pdo->prepare("SELECT categorias, tipo FROM eventos WHERE nombre = ?");
    $stmt_ev->execute([$user['viaje']]);
    $ev = $stmt_ev->fetch();
    if ($ev) {
        if (!empty($ev['categorias'])) {
            $user['categories'] = $ev['categorias'];
        }
        $user['app_mode'] = $ev['tipo'];
    }

    $user['can_view_data'] = (bool)$user['can_view_data'];
    $user['can_edit_data'] = (bool)$user['can_edit_data'];
    $user['can_manage_users'] = (bool)$user['can_manage_users'];
    $user['isAdmin'] = $user['role'] === 'admin';
    return $user;
}

function require_user(PDO $pdo): array {
    $user = current_user($pdo);
    if (!$user) {
        json_out(['status' => 'error', 'message' => 'No autenticado'], 401);
    }
    return $user;
}

function require_view(array $user): void {
    if (!$user['can_view_data'] && !$user['isAdmin']) {
        json_out(['status' => 'error', 'message' => 'Sin permiso de visualización'], 403);
    }
}

function require_edit(array $user): void {
    if (!$user['can_edit_data'] && !$user['isAdmin']) {
        json_out(['status' => 'error', 'message' => 'Sin permiso de modificación'], 403);
    }
}

function require_admin(array $user): void {
    if (!$user['isAdmin'] && !$user['can_manage_users']) {
        json_out(['status' => 'error', 'message' => 'Sin permiso de administración'], 403);
    }
}

function app_settings(PDO $pdo): array {
    $rows = $pdo->query("SELECT setting_key, setting_value FROM app_settings")->fetchAll();
    $settings = [
        'multiCurrencyEnabled' => true,
        'defaultCurrency' => 'BRL',
        'defaultAppMode' => 'shared',
    ];

    foreach ($rows as $row) {
        if ($row['setting_key'] === 'multi_currency_enabled') {
            $settings['multiCurrencyEnabled'] = $row['setting_value'] === '1';
        }
        if ($row['setting_key'] === 'default_currency') {
            $settings['defaultCurrency'] = $row['setting_value'];
        }
        if ($row['setting_key'] === 'default_app_mode') {
            $settings['defaultAppMode'] = $row['setting_value'];
        }
    }

    if (!empty($_SESSION['active_evento'])) {
        $stmt = $pdo->prepare("SELECT multi_moneda, moneda_defecto, tipo FROM eventos WHERE nombre = ?");
        $stmt->execute([$_SESSION['active_evento']]);
        $ev = $stmt->fetch();
        if ($ev) {
            $settings['multiCurrencyEnabled'] = (bool)$ev['multi_moneda'];
            $settings['defaultCurrency'] = $ev['moneda_defecto'] ?: $settings['defaultCurrency'];
            $settings['defaultAppMode'] = $ev['tipo'] ?: $settings['defaultAppMode'];
        }
    }

    return $settings;
}

$method = $_SERVER['REQUEST_METHOD'];
$payload = [];
if ($method === 'POST') {
    $payload = json_decode(file_get_contents('php://input'), true) ?: [];
}

$action = $payload['action'] ?? '';

if ($method === 'POST' && $action === 'login') {
    $username = trim((string)($payload['username'] ?? ''));
    $password = (string)($payload['password'] ?? '');

    $stmt = $pdo->prepare("SELECT * FROM usuarios WHERE username = ? AND is_active = 1");
    $stmt->execute([$username]);
    $user = $stmt->fetch();

    if (!$user || !password_verify($password, $user['password_hash'])) {
        json_out(['status' => 'error', 'message' => 'Credenciales incorrectas'], 401);
    }

    $assigned_events = array_map('trim', explode(',', $user['viaje']));
    $selected_event = trim((string)($payload['evento'] ?? ''));
    
    if ($selected_event && in_array($selected_event, $assigned_events)) {
        $_SESSION['active_evento'] = $selected_event;
    } else {
        $_SESSION['active_evento'] = $assigned_events[0] ?? 'Viaje Principal';
    }

    session_regenerate_id(true);
    $_SESSION['user_id'] = (int)$user['id'];
    json_out(['status' => 'success', 'user' => current_user($pdo), 'settings' => app_settings($pdo)]);
}

if ($method === 'POST' && $action === 'check_user') {
    $username = trim((string)($payload['username'] ?? ''));
    $stmt = $pdo->prepare("SELECT viaje FROM usuarios WHERE username = ? AND is_active = 1");
    $stmt->execute([$username]);
    $user = $stmt->fetch();
    if ($user) {
        $eventos = array_filter(array_map('trim', explode(',', $user['viaje'])));
        json_out(['status' => 'success', 'eventos' => array_values($eventos)]);
    }
    json_out(['status' => 'error']);
}

if ($method === 'POST' && $action === 'switch_event') {
    $user = require_user($pdo);
    $nombre = trim((string)($payload['nombre'] ?? ''));
    $stmt = $pdo->prepare("SELECT viaje FROM usuarios WHERE id = ?");
    $stmt->execute([$user['id']]);
    $row = $stmt->fetch();
    $allowed = array_values(array_filter(array_map('trim', explode(',', $row['viaje'] ?? ''))));
    if (!$nombre || !in_array($nombre, $allowed)) {
        json_out(['status' => 'error', 'message' => 'Evento no permitido'], 403);
    }
    $_SESSION['active_evento'] = $nombre;
    json_out(['status' => 'success', 'user' => current_user($pdo), 'settings' => app_settings($pdo)]);
}

if ($method === 'POST' && $action === 'logout') {
    $_SESSION = [];
    session_destroy();
    json_out(['status' => 'success']);
}

if ($method === 'GET' && ($_GET['type'] ?? '') === 'session') {
    $user = current_user($pdo);
    json_out(['status' => $user ? 'success' : 'anonymous', 'user' => $user, 'settings' => app_settings($pdo)]);
}

$user = require_user($pdo);

if ($method === 'GET') {
    $type = $_GET['type'] ?? 'history';

    if ($type === 'config') {
        require_view($user);
        $currencies = $pdo->query("SELECT code, rate, is_base FROM config_monedas")->fetchAll();
        $results = array_map(fn($row) => [
            'code' => $row['code'],
            'rate' => (float)$row['rate'],
            'isBase' => (bool)$row['is_base']
        ], $currencies);
        json_out(['settings' => app_settings($pdo), 'currencies' => $results]);
    }

    if ($type === 'users') {
        require_admin($user);
        $stmt = $pdo->query("SELECT id, username, display_name, family_name, role, can_view_data, can_edit_data, can_manage_users, app_mode, is_active, categories, viaje FROM usuarios ORDER BY username");
        json_out($stmt->fetchAll());
    }

    if ($type === 'log') {
        require_admin($user);
        $stmt = $pdo->prepare("SELECT uuid as ID, fecha as Fecha, comercio as Comercio, importe as Importe, categoria as Categoria, moneda as Moneda, usuario as Usuario, apellido as Apellido, importe_base as ImporteBase, created_at, fecha_backup FROM gastos_log WHERE viaje = ? ORDER BY fecha_backup DESC, fecha DESC");
        $stmt->execute([$user['viaje']]);
        json_out($stmt->fetchAll());
    }

    if ($type === 'eventos') {
        require_admin($user);
        $stmt = $pdo->query("SELECT id, nombre, tipo, categorias, familias, multi_moneda, moneda_defecto FROM eventos ORDER BY nombre");
        json_out($stmt->fetchAll());
    }

    require_view($user);
    $sql = "SELECT uuid as ID, fecha as Fecha, comercio as Comercio, importe as Importe, categoria as Categoria, moneda as Moneda, usuario as Usuario, apellido as Apellido, importe_base as ImporteBase FROM gastos WHERE viaje = ?";
    $params = [$user['viaje']];
    if (!$user['isAdmin'] && $user['app_mode'] === 'single') {
        $sql .= " AND usuario = ?";
        $params[] = $user['username'];
    }
    $sql .= " ORDER BY fecha DESC, created_at DESC";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    json_out($stmt->fetchAll());
}

if ($method !== 'POST' || !$payload) {
    json_out(['status' => 'error', 'message' => 'Invalid request'], 400);
}

try {
    if ($action === 'create') {
        require_edit($user);
        $settings = app_settings($pdo);
        $currency = $settings['multiCurrencyEnabled'] ? ($payload['moneda'] ?? $settings['defaultCurrency']) : $settings['defaultCurrency'];
        $rate = 1.0;

        if ($settings['multiCurrencyEnabled']) {
            $stmt = $pdo->prepare("SELECT rate FROM config_monedas WHERE code = ?");
            $stmt->execute([$currency]);
            $rate = (float)($stmt->fetchColumn() ?: 1);
        }

        $uuid = bin2hex(random_bytes(16));
        $stmt = $pdo->prepare("INSERT INTO gastos (uuid, fecha, comercio, importe, categoria, moneda, usuario, apellido, importe_base, viaje) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
        $stmt->execute([
            $uuid,
            $payload['fecha'] ?? date('Y-m-d'),
            trim((string)$payload['comercio']),
            (float)$payload['importe'],
            $payload['categoria'],
            $currency,
            $user['username'],
            $user['family_name'],
            (float)$payload['importe'] / $rate,
            $user['viaje']
        ]);
        json_out(['status' => 'success', 'data' => ['id' => $uuid]]);
    }

    if ($action === 'update') {
        require_edit($user);
        $stmt = $pdo->prepare("UPDATE gastos SET fecha=?, comercio=?, importe=?, categoria=?, moneda=?, usuario=?, apellido=?, importe_base=? WHERE uuid=?");
        $stmt->execute([
            $payload['fecha'],
            $payload['comercio'],
            (float)$payload['importe'],
            $payload['categoria'],
            $payload['moneda'],
            $payload['usuario'],
            $payload['apellido'],
            (float)$payload['importeBase'],
            $payload['id']
        ]);
        json_out(['status' => 'success', 'message' => 'Actualizado']);
    }

    if ($action === 'delete') {
        require_edit($user);
        $stmt = $pdo->prepare("DELETE FROM gastos WHERE uuid = ?");
        $stmt->execute([$payload['id']]);
        json_out(['status' => 'success', 'message' => 'Eliminado']);
    }

    if ($action === 'updateCurrencies') {
        require_admin($user);
        if (empty($payload['currencies']) || !is_array($payload['currencies'])) {
            json_out(['status' => 'error', 'message' => 'Divisas inválidas'], 400);
        }
        $pdo->beginTransaction();
        $pdo->query("DELETE FROM config_monedas");
        $stmt = $pdo->prepare("INSERT INTO config_monedas (code, rate, is_base) VALUES (?, ?, ?)");
        foreach ($payload['currencies'] as $c) {
            $stmt->execute([$c['code'], (float)$c['rate'], (int)!empty($c['isBase'])]);
        }
        $pdo->commit();
        json_out(['status' => 'success', 'message' => 'Divisas actualizadas']);
    }

    if ($action === 'updateSettings') {
        require_admin($user);
        $settings = [
            'multi_currency_enabled' => !empty($payload['multiCurrencyEnabled']) ? '1' : '0',
            'default_currency' => $payload['defaultCurrency'] ?? 'BRL',
            'default_app_mode' => $payload['defaultAppMode'] ?? 'shared',
        ];
        $stmt = $pdo->prepare("INSERT INTO app_settings (setting_key, setting_value) VALUES (?, ?) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)");
        foreach ($settings as $key => $value) {
            $stmt->execute([$key, $value]);
        }
        json_out(['status' => 'success', 'settings' => app_settings($pdo)]);
    }

    if ($action === 'saveUser') {
        require_admin($user);
        $id = $payload['id'] ?? null;
        $username = trim($payload['username'] ?? '');
        $password = $payload['password'] ?? '';
        $displayName = trim($payload['displayName'] ?? '');
        $familyName = trim($payload['familyName'] ?? '');
        $role = $payload['role'] ?? 'user';
        $appMode = $payload['appMode'] ?? 'shared';
        $canView = (int)($payload['canViewData'] ?? 0);
        $canEdit = (int)($payload['canEditData'] ?? 0);
        $canManage = (int)($payload['canManageUsers'] ?? 0);
        $isActive = (int)($payload['isActive'] ?? 1);
        $categories = trim($payload['categories'] ?? '');
        $viaje = trim($payload['viaje'] ?? 'Viaje Principal');

        // familyName is optional for single-type events
        $firstEvento = trim(explode(',', $viaje)[0]);
        $evStmt = $pdo->prepare("SELECT tipo FROM eventos WHERE nombre = ?");
        $evStmt->execute([$firstEvento]);
        $evTipo = $evStmt->fetchColumn();
        $isSingle = ($evTipo === 'single');

        if (!$username || !$displayName || (!$isSingle && !$familyName)) {
            json_out(['status' => 'error', 'message' => 'Faltan campos obligatorios'], 400);
        }

        if ($id) {
            if ($password) {
                $hash = password_hash($password, PASSWORD_DEFAULT);
                $stmt = $pdo->prepare("UPDATE usuarios SET username=?, password_hash=?, display_name=?, family_name=?, role=?, can_view_data=?, can_edit_data=?, can_manage_users=?, app_mode=?, is_active=?, categories=?, viaje=? WHERE id=?");
                $stmt->execute([$username, $hash, $displayName, $familyName, $role, $canView, $canEdit, $canManage, $appMode, $isActive, $categories, $viaje, $id]);
            } else {
                $stmt = $pdo->prepare("UPDATE usuarios SET username=?, display_name=?, family_name=?, role=?, can_view_data=?, can_edit_data=?, can_manage_users=?, app_mode=?, is_active=?, categories=?, viaje=? WHERE id=?");
                $stmt->execute([$username, $displayName, $familyName, $role, $canView, $canEdit, $canManage, $appMode, $isActive, $categories, $viaje, $id]);
            }
        } else {
            if (!$password) {
                json_out(['status' => 'error', 'message' => 'La clave es obligatoria para usuarios nuevos'], 400);
            }
            $hash = password_hash($password, PASSWORD_DEFAULT);
            $stmt = $pdo->prepare("INSERT INTO usuarios (username, password_hash, display_name, family_name, role, can_view_data, can_edit_data, can_manage_users, app_mode, is_active, categories, viaje) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
            $stmt->execute([$username, $hash, $displayName, $familyName, $role, $canView, $canEdit, $canManage, $appMode, $isActive, $categories, $viaje]);
        }
        json_out(['status' => 'success']);
    }

    if ($action === 'save_event') {
        require_admin($user);
        $id = $payload['id'] ?? null;
        $nombre = trim($payload['nombre'] ?? '');
        $tipo = $payload['tipo'] ?? 'shared';
        $categorias = trim($payload['categorias'] ?? '');
        $familias = trim($payload['familias'] ?? '');
        $multi_moneda = !empty($payload['multi_moneda']) ? 1 : 0;
        $moneda_defecto = trim($payload['moneda_defecto'] ?? 'USD');

        if (!$nombre) {
            json_out(['status' => 'error', 'message' => 'El nombre del evento es obligatorio'], 400);
        }

        if ($id) {
            $stmt = $pdo->prepare("UPDATE eventos SET nombre=?, tipo=?, categorias=?, familias=?, multi_moneda=?, moneda_defecto=? WHERE id=?");
            $stmt->execute([$nombre, $tipo, $categorias, $familias, $multi_moneda, $moneda_defecto, $id]);
        } else {
            $stmt = $pdo->prepare("INSERT INTO eventos (nombre, tipo, categorias, familias, multi_moneda, moneda_defecto) VALUES (?, ?, ?, ?, ?, ?)");
            $stmt->execute([$nombre, $tipo, $categorias, $familias, $multi_moneda, $moneda_defecto]);
        }
        json_out(['status' => 'success']);
    }

    if ($action === 'delete_event') {
        require_admin($user);
        $id = $payload['id'] ?? null;
        if ($id) {
            $stmt = $pdo->prepare("DELETE FROM eventos WHERE id=?");
            $stmt->execute([$id]);
            json_out(['status' => 'success']);
        }
        json_out(['status' => 'error', 'message' => 'ID no proporcionado'], 400);
    }

    if ($action === 'deleteUser') {
        require_admin($user);
        if ((int)$payload['id'] === (int)$user['id']) {
            json_out(['status' => 'error', 'message' => 'No puedes eliminar tu propio usuario'], 400);
        }
        $stmt = $pdo->prepare("DELETE FROM usuarios WHERE id = ?");
        $stmt->execute([(int)$payload['id']]);
        json_out(['status' => 'success']);
    }

    if ($action === 'archive_and_reset') {
        require_admin($user);
        $pdo->beginTransaction();
        $stmtRows = $pdo->prepare("SELECT * FROM gastos WHERE viaje = ?");
        $stmtRows->execute([$user['viaje']]);
        $rows = $stmtRows->fetchAll();
        $backupFile = "";

        if (!empty($rows)) {
            if (!is_dir('backups')) {
                mkdir('backups', 0755, true);
            }
            $backupFile = 'backups/backup_' . str_replace(' ', '_', $user['viaje']) . '_' . date('Y-m-d_H-i-s') . '.csv';
            $fp = fopen($backupFile, 'w');
            fprintf($fp, chr(0xEF).chr(0xBB).chr(0xBF));
            fputcsv($fp, array_keys($rows[0]));
            foreach ($rows as $row) {
                fputcsv($fp, $row);
            }
            fclose($fp);
        }

        $stmtInsertLog = $pdo->prepare("INSERT INTO gastos_log (uuid, fecha, comercio, importe, categoria, moneda, usuario, apellido, importe_base, viaje) SELECT uuid, fecha, comercio, importe, categoria, moneda, usuario, apellido, importe_base, viaje FROM gastos WHERE viaje = ?");
        $stmtInsertLog->execute([$user['viaje']]);

        $stmtDelete = $pdo->prepare("DELETE FROM gastos WHERE viaje = ?");
        $stmtDelete->execute([$user['viaje']]);
        
        $pdo->commit();
        json_out(['status' => 'success', 'message' => 'Gastos del evento actual archivados y reiniciados', 'file' => $backupFile]);
    }

    json_out(['status' => 'error', 'message' => 'Acción inválida'], 400);
} catch (Exception $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    json_out(['status' => 'error', 'message' => $e->getMessage()], 500);
}
