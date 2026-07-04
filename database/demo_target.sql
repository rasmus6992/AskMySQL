-- Optional demo reporting schema.
-- Import this file into TARGET_DB_NAME to test the application immediately.

CREATE TABLE IF NOT EXISTS cities (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    name VARCHAR(100) NOT NULL,
    state_name VARCHAR(100) NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_cities_name_state (name, state_name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS customers (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    customer_code VARCHAR(30) NOT NULL,
    customer_name VARCHAR(150) NOT NULL,
    city_id INT UNSIGNED NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_customers_code (customer_code),
    CONSTRAINT fk_customers_city
        FOREIGN KEY (city_id) REFERENCES cities (id)
        ON UPDATE CASCADE ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS sales (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    invoice_number VARCHAR(40) NOT NULL,
    customer_id BIGINT UNSIGNED NOT NULL,
    city_id INT UNSIGNED NOT NULL,
    sale_date DATE NOT NULL,
    gross_amount DECIMAL(12,2) NOT NULL,
    tax_amount DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    net_amount DECIMAL(12,2) NOT NULL,
    status ENUM('draft', 'completed', 'cancelled') NOT NULL DEFAULT 'completed',
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_sales_invoice (invoice_number),
    INDEX idx_sales_date_status (sale_date, status),
    INDEX idx_sales_city_date (city_id, sale_date),
    CONSTRAINT fk_sales_customer
        FOREIGN KEY (customer_id) REFERENCES customers (id)
        ON UPDATE CASCADE ON DELETE RESTRICT,
    CONSTRAINT fk_sales_city
        FOREIGN KEY (city_id) REFERENCES cities (id)
        ON UPDATE CASCADE ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO cities (id, name, state_name) VALUES
    (1, 'Surat', 'Gujarat'),
    (2, 'Ahmedabad', 'Gujarat'),
    (3, 'Vadodara', 'Gujarat'),
    (4, 'Mumbai', 'Maharashtra')
ON DUPLICATE KEY UPDATE state_name = VALUES(state_name);

INSERT INTO customers (id, customer_code, customer_name, city_id) VALUES
    (1, 'CUS-001', 'Sunrise Retail', 1),
    (2, 'CUS-002', 'Riverfront Foods', 2),
    (3, 'CUS-003', 'Central Mart', 3),
    (4, 'CUS-004', 'Harbour Stores', 4)
ON DUPLICATE KEY UPDATE
    customer_name = VALUES(customer_name),
    city_id = VALUES(city_id);

INSERT INTO sales
    (id, invoice_number, customer_id, city_id, sale_date, gross_amount, tax_amount, net_amount, status)
VALUES
    (1, 'INV-2026-0001', 1, 1, '2026-06-01', 11800.00, 1800.00, 10000.00, 'completed'),
    (2, 'INV-2026-0002', 2, 2, '2026-06-01', 17700.00, 2700.00, 15000.00, 'completed'),
    (3, 'INV-2026-0003', 3, 3, '2026-06-02', 9440.00, 1440.00, 8000.00, 'completed'),
    (4, 'INV-2026-0004', 1, 1, '2026-06-02', 14160.00, 2160.00, 12000.00, 'completed'),
    (5, 'INV-2026-0005', 4, 4, '2026-06-03', 23600.00, 3600.00, 20000.00, 'completed'),
    (6, 'INV-2026-0006', 2, 2, '2026-06-03', 12980.00, 1980.00, 11000.00, 'completed'),
    (7, 'INV-2026-0007', 3, 3, '2026-06-04', 10620.00, 1620.00, 9000.00, 'completed'),
    (8, 'INV-2026-0008', 1, 1, '2026-06-05', 18880.00, 2880.00, 16000.00, 'completed'),
    (9, 'INV-2026-0009', 4, 4, '2026-06-05', 8260.00, 1260.00, 7000.00, 'cancelled'),
    (10, 'INV-2026-0010', 2, 2, '2026-06-06', 15340.00, 2340.00, 13000.00, 'completed')
ON DUPLICATE KEY UPDATE
    customer_id = VALUES(customer_id),
    city_id = VALUES(city_id),
    sale_date = VALUES(sale_date),
    gross_amount = VALUES(gross_amount),
    tax_amount = VALUES(tax_amount),
    net_amount = VALUES(net_amount),
    status = VALUES(status);
