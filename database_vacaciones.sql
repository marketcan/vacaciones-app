-- Tablas para Vacaciones App
CREATE TABLE IF NOT EXISTS gastos (
    id INT AUTO_INCREMENT PRIMARY KEY,
    uuid VARCHAR(50) UNIQUE NOT NULL,
    fecha DATE NOT NULL,
    comercio VARCHAR(255) NOT NULL,
    importe DECIMAL(10, 2) NOT NULL,
    categoria VARCHAR(100),
    moneda VARCHAR(10),
    usuario VARCHAR(100),
    apellido VARCHAR(100),
    importe_base DECIMAL(10, 2),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS config_monedas (
    code VARCHAR(10) PRIMARY KEY,
    rate DECIMAL(10, 4) NOT NULL,
    is_base BOOLEAN DEFAULT FALSE
);

-- Valores iniciales por defecto
INSERT INTO config_monedas (code, rate, is_base) VALUES 
('BRL', 1.0000, 1),
('ARS', 210.0000, 0),
('USD', 0.1800, 0)
ON DUPLICATE KEY UPDATE rate=VALUES(rate), is_base=VALUES(is_base);
