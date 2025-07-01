-- Base de datos completa para Habbo Agency
-- Ejecutar en phpMyAdmin o cliente MySQL

CREATE DATABASE IF NOT EXISTS habbo_agency;
USE habbo_agency;

-- Tabla de usuarios
CREATE TABLE IF NOT EXISTS users (
    id INT AUTO_INCREMENT PRIMARY KEY,
    username VARCHAR(50) NOT NULL UNIQUE, -- Mantener por compatibilidad
    password VARCHAR(255) NOT NULL,
    role ENUM('super_admin', 'administrador', 'operador', 'usuario') DEFAULT 'usuario',
    habbo_username VARCHAR(50) NOT NULL UNIQUE,
    profile_image VARCHAR(255),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_habbo_username (habbo_username),
    INDEX idx_role (role)
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
    credits_time_hours INT DEFAULT 1,
    credits_time_minutes INT DEFAULT 0,
    credits_per_interval INT DEFAULT 1,
    role_color VARCHAR(7) DEFAULT NULL,
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

-- Agregar columnas de configuración de créditos a user_ranks (MySQL compatible)
-- Note: These will fail silently if columns already exist, which is expected behavior
SET @sql1 = IF((SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE table_name = 'user_ranks' AND column_name = 'credits_time_hours' AND table_schema = DATABASE()) = 0, 'ALTER TABLE user_ranks ADD COLUMN credits_time_hours INT DEFAULT 1', 'SELECT 1');
PREPARE stmt1 FROM @sql1;
EXECUTE stmt1;
DEALLOCATE PREPARE stmt1;

SET @sql2 = IF((SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE table_name = 'user_ranks' AND column_name = 'credits_time_minutes' AND table_schema = DATABASE()) = 0, 'ALTER TABLE user_ranks ADD COLUMN credits_time_minutes INT DEFAULT 0', 'SELECT 1');
PREPARE stmt2 FROM @sql2;
EXECUTE stmt2;
DEALLOCATE PREPARE stmt2;

SET @sql3 = IF((SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE table_name = 'user_ranks' AND column_name = 'credits_per_interval' AND table_schema = DATABASE()) = 0, 'ALTER TABLE user_ranks ADD COLUMN credits_per_interval INT DEFAULT 1', 'SELECT 1');
PREPARE stmt3 FROM @sql3;
EXECUTE stmt3;
DEALLOCATE PREPARE stmt3;

-- Insertar configuración básica del sistema (sin configuración de créditos)
INSERT IGNORE INTO system_config (config_key, config_value) VALUES
('site_title', 'Habbo Agency'),
('company_logo', '');

-- Configurar créditos por defecto para rangos existentes que no tengan configuración
UPDATE user_ranks SET 
    credits_time_hours = 1,
    credits_time_minutes = 0, 
    credits_per_interval = 1
WHERE credits_time_hours IS NULL OR credits_time_minutes IS NULL OR credits_per_interval IS NULL;

-- Remover configuración de créditos del sistema (ahora se maneja por rango)
DELETE FROM system_config WHERE config_key IN (
    'credits_calculation_type', 
    'time_hours', 
    'time_minutes', 
    'credits_per_interval', 
    'credits_per_minute', 
    'credits_per_hour'
);

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

-- Tabla de historial de ascensos/promociones
CREATE TABLE IF NOT EXISTS promotion_log (
    id INT AUTO_INCREMENT PRIMARY KEY,
    promoted_user_id INT NOT NULL,
    promoted_by_user_id INT NOT NULL,
    old_role VARCHAR(50) NOT NULL,
    new_role VARCHAR(50) NOT NULL,
    reason TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (promoted_user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (promoted_by_user_id) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_promoted_user (promoted_user_id),
    INDEX idx_promoted_by (promoted_by_user_id),
    INDEX idx_created_at (created_at)
);

-- Tabla para invalidar sesiones de usuarios (para actualizaciones de rol en tiempo real)
CREATE TABLE IF NOT EXISTS session_invalidations (
    user_id INT PRIMARY KEY,
    invalidated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
);