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

CREATE TABLE IF NOT EXISTS app_settings (
    setting_key VARCHAR(80) PRIMARY KEY,
    setting_value VARCHAR(255) NOT NULL
);

CREATE TABLE IF NOT EXISTS usuarios (
    id INT AUTO_INCREMENT PRIMARY KEY,
    username VARCHAR(80) UNIQUE NOT NULL,
    password_hash VARCHAR(255) NOT NULL,
    display_name VARCHAR(120) NOT NULL,
    family_name VARCHAR(120) NOT NULL,
    role ENUM('admin', 'user') NOT NULL DEFAULT 'user',
    can_view_data BOOLEAN NOT NULL DEFAULT TRUE,
    can_edit_data BOOLEAN NOT NULL DEFAULT FALSE,
    can_manage_users BOOLEAN NOT NULL DEFAULT FALSE,
    app_mode ENUM('shared', 'single') NOT NULL DEFAULT 'shared',
    is_active BOOLEAN NOT NULL DEFAULT TRUE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- Valores iniciales por defecto
INSERT INTO config_monedas (code, rate, is_base) VALUES 
('BRL', 1.0000, 1),
('ARS', 210.0000, 0),
('USD', 0.1800, 0)
ON DUPLICATE KEY UPDATE rate=VALUES(rate), is_base=VALUES(is_base);

INSERT INTO app_settings (setting_key, setting_value) VALUES
('multi_currency_enabled', '1'),
('default_currency', 'BRL'),
('default_app_mode', 'shared')
ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value);

-- Crear el primer administrador con setup_admin.php usando variables de entorno:
-- VACACIONES_SETUP_TOKEN, VACACIONES_ADMIN_USER y VACACIONES_ADMIN_PASS.
