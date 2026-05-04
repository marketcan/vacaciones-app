<?php
/**
 * API para Vacaciones App
 * Gestiona registros en MySQL para reemplazar Google Sheets.
 * Optimizado para PHP 8.x
 */

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] == 'OPTIONS') {
    exit;
}

// 1. CONFIGURACIÓN
define('DB_HOST', 'localhost');
define('DB_NAME', 'marketca_split_bill');
define('DB_USER', 'marketca_split_bill');
define('DB_PASS', 'aTBlg1vcpf!');

// 2. CONEXIÓN
try {
    $pdo = new PDO("mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=utf8", DB_USER, DB_PASS);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => 'DB Connection failed']);
    exit;
}

// 3. RUTAS
$method = $_SERVER['REQUEST_METHOD'];

// A. OBTENER DATOS (GET)
if ($method === 'GET') {
    $type = $_GET['type'] ?? 'history';

    if ($type === 'config') {
        $stmt = $pdo->query("SELECT code, rate, is_base FROM config_monedas");
        $results = [];
        while($row = $stmt->fetch()){
            $results[] = [
                'code' => $row['code'],
                'rate' => (float)$row['rate'],
                'isBase' => (bool)$row['is_base']
            ];
        }
        echo json_encode($results);
    } else if ($type === 'log') {
        $stmt = $pdo->query("SELECT uuid as ID, fecha as Fecha, comercio as Comercio, importe as Importe, categoria as Categoria, moneda as Moneda, usuario as Usuario, apellido as Apellido, importe_base as ImporteBase, created_at, fecha_backup FROM gastos_log ORDER BY fecha_backup DESC, fecha DESC");
        echo json_encode($stmt->fetchAll());
    } else {
        $stmt = $pdo->query("SELECT uuid as ID, fecha as Fecha, comercio as Comercio, importe as Importe, categoria as Categoria, moneda as Moneda, usuario as Usuario, apellido as Apellido, importe_base as ImporteBase FROM gastos ORDER BY fecha DESC, created_at DESC");
        echo json_encode($stmt->fetchAll());
    }
}

// B. GUARDAR/ACTUALIZAR DATOS (POST)
else if ($method === 'POST') {
    $json = file_get_contents('php://input');
    $payload = json_decode($json, true);
    
    if (!$payload) {
        http_response_code(400);
        echo json_encode(['status' => 'error', 'message' => 'Invalid JSON payload']);
        exit;
    }

    $action = $payload['action'] ?? '';

    try {
        if ($action === 'create') {
            $uuid = bin2hex(random_bytes(16));
            $stmt = $pdo->prepare("INSERT INTO gastos (uuid, fecha, comercio, importe, categoria, moneda, usuario, apellido, importe_base) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
            $stmt->execute([
                $uuid,
                $payload['fecha'] ?? date('Y-m-d'),
                $payload['comercio'],
                $payload['importe'],
                $payload['categoria'],
                $payload['moneda'],
                $payload['usuario'],
                $payload['apellido'],
                $payload['importeBase']
            ]);
            echo json_encode(['status' => 'success', 'data' => ['id' => $uuid]]);
        }
        else if ($action === 'update') {
            $stmt = $pdo->prepare("UPDATE gastos SET fecha=?, comercio=?, importe=?, categoria=?, moneda=?, usuario=?, apellido=?, importe_base=? WHERE uuid=?");
            $stmt->execute([
                $payload['fecha'],
                $payload['comercio'],
                $payload['importe'],
                $payload['categoria'],
                $payload['moneda'],
                $payload['usuario'],
                $payload['apellido'],
                $payload['importeBase'],
                $payload['id']
            ]);
            echo json_encode(['status' => 'success', 'message' => 'Actualizado']);
        }
        else if ($action === 'delete') {
            $stmt = $pdo->prepare("DELETE FROM gastos WHERE uuid = ?");
            $stmt->execute([$payload['id']]);
            echo json_encode(['status' => 'success', 'message' => 'Eliminado']);
        }
        else if ($action === 'updateCurrencies') {
            if (!empty($payload['currencies'])) {
                $pdo->query("DELETE FROM config_monedas");
                $stmt = $pdo->prepare("INSERT INTO config_monedas (code, rate, is_base) VALUES (?, ?, ?)");
                foreach ($payload['currencies'] as $c) {
                    $stmt->execute([$c['code'], $c['rate'], (int)$c['isBase']]);
                }
                echo json_encode(['status' => 'success', 'message' => 'Divisas actualizadas']);
            }
        }
        else if ($action === 'reset') {
            $pdo->query("DELETE FROM gastos");
            echo json_encode(['status' => 'success', 'message' => 'Reinicio completo']);
        }
        else if ($action === 'archive_and_reset') {
            try {
                $pdo->beginTransaction();
                
                // 1. Obtener datos actuales para el archivo CSV individual
                $stmt = $pdo->query("SELECT * FROM gastos");
                $rows = $stmt->fetchAll();
                $backupFile = "";

                if (!empty($rows)) {
                    // Crear carpeta de backups si no existe
                    if (!is_dir('backups')) {
                        mkdir('backups', 0755, true);
                    }
                    
                    $backupFile = 'backups/backup_' . date('Y-m-d_H-i-s') . '.csv';
                    $fp = fopen($backupFile, 'w');
                    
                    // BOM para Excel (UTF-8)
                    fprintf($fp, chr(0xEF).chr(0xBB).chr(0xBF));
                    
                    // Encabezados
                    fputcsv($fp, array_keys($rows[0]));
                    
                    // Datos
                    foreach ($rows as $row) {
                        fputcsv($fp, $row);
                    }
                    fclose($fp);
                }

                // 2. Copiar a la tabla de log (Base de datos)
                $copySql = "INSERT INTO gastos_log (uuid, fecha, comercio, importe, categoria, moneda, usuario, apellido, importe_base, created_at)
                            SELECT uuid, fecha, comercio, importe, categoria, moneda, usuario, apellido, importe_base, created_at FROM gastos";
                $pdo->exec($copySql);
                
                // 3. Limpiar tabla principal
                $pdo->exec("DELETE FROM gastos");
                
                $pdo->commit();
                echo json_encode([
                    'status' => 'success', 
                    'message' => 'Gastos archivados y tabla reiniciada',
                    'file' => $backupFile
                ]);
            } catch (Exception $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                http_response_code(500);
                echo json_encode(['status' => 'error', 'message' => 'archive_and_reset failed: ' . $e->getMessage()]);
                exit;
            }
        }
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
    }
}
?>
