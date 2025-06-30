<?php
// Initialize schedule tables for both PostgreSQL and MySQL

function initScheduleTables($pdo) {
    try {
        // Detect database type
        $driver = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
        
        if ($driver === 'pgsql') {
            // PostgreSQL syntax
            $createBusinessHours = "CREATE TABLE IF NOT EXISTS business_hours (
                id SERIAL PRIMARY KEY,
                day_of_week SMALLINT NOT NULL,
                opening_time TIME DEFAULT '09:00:00',
                closing_time TIME DEFAULT '18:00:00',
                is_open BOOLEAN DEFAULT true,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                UNIQUE(day_of_week)
            )";
            
            $createSpecialDays = "CREATE TABLE IF NOT EXISTS special_days (
                id SERIAL PRIMARY KEY,
                date DATE NOT NULL UNIQUE,
                opening_time TIME,
                closing_time TIME,
                is_open BOOLEAN DEFAULT true,
                description TEXT,
                color VARCHAR(7) DEFAULT '#9333ea',
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            )";
            
            $insertConflict = "ON CONFLICT (day_of_week) DO NOTHING";
            
        } else {
            // MySQL syntax
            $createBusinessHours = "CREATE TABLE IF NOT EXISTS business_hours (
                id INT AUTO_INCREMENT PRIMARY KEY,
                day_of_week TINYINT NOT NULL,
                opening_time TIME DEFAULT '09:00:00',
                closing_time TIME DEFAULT '18:00:00',
                is_open TINYINT(1) DEFAULT 1,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                UNIQUE KEY unique_day (day_of_week)
            )";
            
            $createSpecialDays = "CREATE TABLE IF NOT EXISTS special_days (
                id INT AUTO_INCREMENT PRIMARY KEY,
                date DATE NOT NULL UNIQUE,
                opening_time TIME,
                closing_time TIME,
                is_open TINYINT(1) DEFAULT 1,
                description TEXT,
                color VARCHAR(7) DEFAULT '#9333ea',
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
            )";
            
            $insertConflict = "ON DUPLICATE KEY UPDATE day_of_week = VALUES(day_of_week)";
        }
        
        // Create tables
        $pdo->exec($createBusinessHours);
        $pdo->exec($createSpecialDays);
        
        // Check if business_hours has data
        $stmt = $pdo->query("SELECT COUNT(*) as count FROM business_hours");
        $count = $stmt->fetch()['count'];
        
        if ($count == 0) {
            // Insert default business hours
            $default_hours = [
                [0, '00:00:00', '00:00:00', 0], // Domingo cerrado
                [1, '09:00:00', '18:00:00', 1], // Lunes
                [2, '09:00:00', '18:00:00', 1], // Martes
                [3, '09:00:00', '18:00:00', 1], // Miércoles
                [4, '09:00:00', '18:00:00', 1], // Jueves
                [5, '09:00:00', '18:00:00', 1], // Viernes
                [6, '10:00:00', '14:00:00', 1]  // Sábado
            ];
            
            $stmt = $pdo->prepare("INSERT INTO business_hours (day_of_week, opening_time, closing_time, is_open) VALUES (?, ?, ?, ?) $insertConflict");
            foreach ($default_hours as $hours) {
                $stmt->execute($hours);
            }
        }
        
        return true;
        
    } catch (PDOException $e) {
        error_log("Error initializing schedule tables: " . $e->getMessage());
        return false;
    }
}
?>