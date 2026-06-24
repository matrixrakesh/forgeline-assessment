CREATE DATABASE IF NOT EXISTS forgeline;
USE forgeline;

CREATE TABLE forgeline_events (
    id INT AUTO_INCREMENT PRIMARY KEY,
    event_id VARCHAR(255) NOT NULL UNIQUE,
    status ENUM('pending', 'completed', 'failed') NOT NULL DEFAULT 'pending',
    payload JSON NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);

CREATE TABLE forgeline_orders (
    id INT AUTO_INCREMENT PRIMARY KEY,
    order_ref VARCHAR(255) NOT NULL UNIQUE,
    status VARCHAR(50) NOT NULL DEFAULT 'pending',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);

CREATE TABLE forgeline_order_lines (
    id INT AUTO_INCREMENT PRIMARY KEY,
    order_id INT NOT NULL,
    line_ref VARCHAR(255) NOT NULL,
    status VARCHAR(50) NOT NULL DEFAULT 'pending',
    last_event_occurred_at TIMESTAMP NOT NULL,
    FOREIGN KEY (order_id) REFERENCES forgeline_orders(id) ON DELETE CASCADE,
    UNIQUE KEY order_line_unique (order_id, line_ref)
);
