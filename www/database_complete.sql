-- Base de datos completa para Habbo Agency
-- Ejecutar en phpMyAdmin o cliente MySQL

CREATE DATABASE IF NOT EXISTS habbo_agency;
USE habbo_agency;

-- Tabla de usuarios
CREATE TABLE IF NOT EXISTS users (
    id INT AUTO_INCREMENT PRIMARY KEY,
    username VARCHAR(50) NOT NULL UNIQUE,
    password VARCHAR(255) NOT NULL,
    role ENUM('super_admin', 'administrador', 'operador', 'usuario') DEFAULT 'usuario',
    habbo_username VARCHAR(50) NOT NULL UNIQUE,
    profile_image VARCHAR(255),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);

-- Tabla de configuración del sistema
CREATE TABLE IF NOT EXISTS system_config (
    id INT AUTO_INCREMENT PRIMARY KEY,
    config_key VARCHAR(100) NOT NULL UNIQUE,
    config_value TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);

-- Tabla de sesiones de tiempo
CREATE TABLE IF NOT EXISTS time_sessions (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    username VARCHAR(50) NOT NULL,
    description TEXT,
    start_time TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    end_time TIMESTAMP NULL,
    total_time INT DEFAULT 0,
    status ENUM('active', 'paused', 'completed', 'cancelled') DEFAULT 'active',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_user_id (user_id),
    INDEX idx_status (status)
);

-- Insertar configuración inicial del sistema
INSERT INTO system_config (config_key, config_value) VALUES 
('site_title', 'Habbo Agency'),
('company_logo', '') 
ON DUPLICATE KEY UPDATE config_value = VALUES(config_value);

-- Crear usuario administrador por defecto (contraseña: admin123)
INSERT INTO users (username, password, role, habbo_username) VALUES 
('admin', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'super_admin', 'AdminHabbo')
ON DUPLICATE KEY UPDATE role = VALUES(role);

-- Tabla de rangos de usuario
CREATE TABLE IF NOT EXISTS user_ranks (
    id INT AUTO_INCREMENT PRIMARY KEY,
    rank_name VARCHAR(50) NOT NULL UNIQUE,
    display_name VARCHAR(100) NOT NULL,
    description TEXT,
    level INT NOT NULL DEFAULT 1,
    permissions TEXT,
    rank_image VARCHAR(255) DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);

-- Agregar columna rank_image para instalaciones existentes (ejecutar solo si no existe)
-- ALTER TABLE user_ranks ADD COLUMN rank_image VARCHAR(255) DEFAULT NULL;

-- Tabla de sesiones web
CREATE TABLE IF NOT EXISTS sessions (
    sid VARCHAR(128) NOT NULL PRIMARY KEY,
    sess JSON NOT NULL,
    expire TIMESTAMP NOT NULL,
    INDEX idx_expire (expire)
);

-- Tabla de horarios de negocio
CREATE TABLE IF NOT EXISTS business_hours (
    id INT AUTO_INCREMENT PRIMARY KEY,
    day_of_week TINYINT NOT NULL, -- 0=Domingo, 1=Lunes, 2=Martes, etc.
    opening_time TIME DEFAULT '09:00:00',
    closing_time TIME DEFAULT '18:00:00',
    is_open TINYINT(1) DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY unique_day (day_of_week)
);

-- Insertar configuración básica del sistema
INSERT IGNORE INTO system_config (config_key, config_value) VALUES
('site_title', 'Habbo Agency'),
('company_logo', ''),
('credits_per_hour', '1'),
('credits_per_minute', '1'),
('credits_calculation_type', 'minute');

-- Actualizar valores existentes para asegurar que ambos créditos sean 1 por defecto
-- (Esto corrige cualquier valor previo y establece 1 como valor estándar)
UPDATE system_config SET config_value = '1' WHERE config_key = 'credits_per_minute';
UPDATE system_config SET config_value = '1' WHERE config_key = 'credits_per_hour';
UPDATE system_config SET config_value = 'minute' WHERE config_key = 'credits_calculation_type';

-- Insertar rangos básicos con jerarquía correcta
INSERT IGNORE INTO user_ranks (rank_name, display_name, level, permissions) VALUES
('super_admin', 'Creador', 4, '["manage_users","manage_time","view_reports","manage_system","manage_roles","view_logs","manage_config","admin_panel"]'),
('administrador', 'Administrador', 3, '["manage_users","manage_time","view_reports","manage_system","manage_roles","view_logs","manage_config","admin_panel"]'),
('operador', 'Operador', 2, '["manage_time"]'),
('usuario', 'Usuario', 1, '[]');

-- Insertar usuarios por defecto (password: admin123)
INSERT IGNORE INTO users (username, password, role, habbo_username) VALUES
('creator', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'super_admin', 'Creator'),
('admin', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'administrador', 'AdminHabbo'),
('operador1', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'operador', 'OperadorHabbo'),
('usuario1', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'usuario', 'UsuarioHabbo');

-- Tabla de días especiales (para la página de horarios)
CREATE TABLE IF NOT EXISTS special_days (
    id INT AUTO_INCREMENT PRIMARY KEY,
    date DATE NOT NULL UNIQUE,
    opening_time TIME,
    closing_time TIME,
    is_open TINYINT(1) DEFAULT 1,
    description TEXT,
    color VARCHAR(7) DEFAULT '#9333ea',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);

-- Tabla de historial de pagos
CREATE TABLE IF NOT EXISTS payment_history (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    status ENUM('PAGADO', 'PENDIENTE', 'CANCELADO') NOT NULL DEFAULT 'PENDIENTE',
    amount DECIMAL(10,2) DEFAULT 0.00,
    description TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_user_id (user_id),
    INDEX idx_status (status),
    INDEX idx_updated (updated_at)
);

-- Insertar horarios de negocio por defecto (Lunes a Viernes 9:00-18:00, Sábado 10:00-14:00, Domingo cerrado)
INSERT IGNORE INTO business_hours (day_of_week, opening_time, closing_time, is_open) VALUES
(0, '00:00:00', '00:00:00', 0), -- Domingo cerrado
(1, '09:00:00', '18:00:00', 1), -- Lunes
(2, '09:00:00', '18:00:00', 1), -- Martes
(3, '09:00:00', '18:00:00', 1), -- Miércoles
(4, '09:00:00', '18:00:00', 1), -- Jueves
(5, '09:00:00', '18:00:00', 1), -- Viernes
(6, '10:00:00', '14:00:00', 1); -- Sábado