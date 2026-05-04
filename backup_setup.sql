-- Crear tabla de backup para gastos
CREATE TABLE IF NOT EXISTS gastos_log (
    id INT AUTO_INCREMENT PRIMARY KEY,
    uuid VARCHAR(50) NOT NULL,
    fecha DATE NOT NULL,
    comercio VARCHAR(255) NOT NULL,
    importe DECIMAL(10, 2) NOT NULL,
    categoria VARCHAR(100),
    moneda VARCHAR(10),
    usuario VARCHAR(100),
    apellido VARCHAR(100),
    importe_base DECIMAL(10, 2),
    created_at TIMESTAMP,
    fecha_backup TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);
