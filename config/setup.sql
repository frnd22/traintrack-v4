-- ============================================================
-- TrainTrack SVF System — Clean Database Setup
-- Database: svf_training_db
--NOT SURE
-- ============================================================

CREATE DATABASE IF NOT EXISTS svf_training_db
  CHARACTER SET utf8mb4
  COLLATE utf8mb4_unicode_ci;

USE svf_training_db;

-- ============================================================
-- USERS TABLE (Admin / Managers)
-- ============================================================
CREATE TABLE IF NOT EXISTS users (
    id         INT AUTO_INCREMENT PRIMARY KEY,
    username   VARCHAR(50) UNIQUE NOT NULL,
    password   VARCHAR(255) NOT NULL,
    role       ENUM('general_manager','training_manager','manager_on_duty') NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- ============================================================
-- CREW TABLE
-- ============================================================
CREATE TABLE IF NOT EXISTS crew (
    id         INT AUTO_INCREMENT PRIMARY KEY,
    name       VARCHAR(100) NOT NULL,
    birthday   DATE NOT NULL,
    date_hired DATE NOT NULL,
    username   VARCHAR(50) UNIQUE NOT NULL,
    password   VARCHAR(255) NOT NULL,
    is_active  TINYINT(1) DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- ============================================================
-- STATIONS TABLE
-- ============================================================
CREATE TABLE IF NOT EXISTS stations (
    id           INT AUTO_INCREMENT PRIMARY KEY,
    station_name VARCHAR(100) UNIQUE NOT NULL,
    description  VARCHAR(255),
    created_at   TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at   TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);

-- ============================================================
-- TRAINING RECORDS TABLE
-- ============================================================
CREATE TABLE IF NOT EXISTS training_records (
    id          INT AUTO_INCREMENT PRIMARY KEY,
    crew_id     INT NOT NULL,
    station_id  INT NOT NULL,

    initial_training_date DATE,
    initial_status ENUM('Pass','Fail','Pending') DEFAULT 'Pending',

    followup1 ENUM('Pass','Fail','Pending','Not Due') DEFAULT 'Not Due',
    followup1_date DATE,

    followup2 ENUM('Pass','Fail','Pending','Not Due') DEFAULT 'Not Due',
    followup2_date DATE,

    followup3 ENUM('Pass','Fail','Pending','Not Due') DEFAULT 'Not Due',
    followup3_date DATE,

    followup4 ENUM('Pass','Fail','Pending','Not Due') DEFAULT 'Not Due',
    followup4_date DATE,

    followup5 ENUM('Pass','Fail','Pending','Not Due') DEFAULT 'Not Due',
    followup5_date DATE,

    notes TEXT,

    -- Verification tracking
    initial_verified_by   INT NULL,
    followup1_verified_by INT NULL,
    followup2_verified_by INT NULL,
    followup3_verified_by INT NULL,
    followup4_verified_by INT NULL,
    followup5_verified_by INT NULL,

    -- Soft delete fields
    is_deleted TINYINT(1) DEFAULT 0,
    deleted_at TIMESTAMP NULL,
    deleted_by INT NULL,

    last_updated TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,

    UNIQUE KEY uq_crew_station (crew_id, station_id),

    FOREIGN KEY (crew_id) REFERENCES crew(id) ON DELETE CASCADE,
    FOREIGN KEY (station_id) REFERENCES stations(id) ON DELETE RESTRICT
);

-- ============================================================
-- ARCHIVE TABLE (Full record backup)
-- ============================================================
CREATE TABLE IF NOT EXISTS training_records_archive (
    LIKE training_records
);

ALTER TABLE training_records_archive
    ADD COLUMN archived_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    ADD COLUMN archived_by INT NULL;

-- ============================================================
-- SEED USERS (default password: "password")
-- ============================================================
INSERT INTO users (username, password, role) VALUES
('gm_admin',
 '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi',
 'general_manager'),

('training_manager',
 '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi',
 'training_manager'),

('mod_manager',
 '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi',
 'manager_on_duty')
ON DUPLICATE KEY UPDATE username = username;



-- ============================================================
-- NOTE
-- Stations are managed dynamically via UI (no seed data)

-- ============================================================