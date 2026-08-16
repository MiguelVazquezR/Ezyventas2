-- ============================================================================
-- EzyVentas 2 - Produccion: Equivalente SQL de las ultimas 41 migraciones
-- Generado a partir de las funciones up() de las migraciones entre
--   2026_06_12_000010_create_invoice_items_table
--   y
--   2026_08_15_000001_rename_pac_account_type_normal_to_shared
--
-- Ejecutar en phpMyAdmin sobre la base de datos de produccion (MySQL 8+).
-- Combina multiples migraciones aplicadas a una misma tabla en statements
-- unicos, manteniendo el estado final del esquema.
-- ============================================================================

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

-- ============================================================================
-- 1. stamp_pricing_tiers  (2026_07_18_000001)
--    Tabla nueva sin dependencias.
-- ============================================================================
CREATE TABLE stamp_pricing_tiers (
    id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    min_quantity  INT UNSIGNED NOT NULL,
    max_quantity  INT UNSIGNED NULL,
    unit_price    DECIMAL(10,4) NOT NULL,
    label         VARCHAR(255) NULL,
    is_active     TINYINT(1) NOT NULL DEFAULT 1,
    sort_order    INT UNSIGNED NOT NULL DEFAULT 0,
    created_at    TIMESTAMP NULL,
    updated_at    TIMESTAMP NULL,
    PRIMARY KEY (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================================
-- 2. pac_accounts  (2026_08_12_000001 + 2026_08_13_000005 + 2026_08_15_000001)
--    CREATE original + is_shared + subscription_id nullable
--    + rename del enum account_type 'normal' -> 'shared'
-- ============================================================================
CREATE TABLE pac_accounts (
    id                     BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    subscription_id        BIGINT UNSIGNED NOT NULL,
    provider               VARCHAR(255) NOT NULL DEFAULT 'sw_sapien',
    account_type           ENUM('subaccount', 'normal') NOT NULL,
    sw_user_id             VARCHAR(255) NULL,
    login_email            VARCHAR(255) NULL,
    password               VARCHAR(255) NULL,
    status                 ENUM('pending_request', 'pending_activation', 'active', 'inactive') NOT NULL DEFAULT 'pending_request',
    requested_by_user_id   BIGINT UNSIGNED NULL,
    activated_by_user_id   BIGINT UNSIGNED NULL,
    requested_at           TIMESTAMP NULL,
    activated_at           TIMESTAMP NULL,
    admin_notes            TEXT NULL,
    created_at             TIMESTAMP NULL,
    updated_at             TIMESTAMP NULL,
    PRIMARY KEY (id),
    KEY pac_accounts_subscription_id_index (subscription_id),
    KEY pac_accounts_status_account_type_index (status, account_type),
    CONSTRAINT pac_accounts_subscription_id_foreign FOREIGN KEY (subscription_id) REFERENCES subscriptions (id) ON DELETE CASCADE,
    CONSTRAINT pac_accounts_requested_by_user_id_foreign FOREIGN KEY (requested_by_user_id) REFERENCES users (id) ON DELETE SET NULL,
    CONSTRAINT pac_accounts_activated_by_user_id_foreign FOREIGN KEY (activated_by_user_id) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 2026_08_13_000005: is_shared + subscription_id pasa a nullable
ALTER TABLE pac_accounts
    MODIFY subscription_id BIGINT UNSIGNED NULL,
    ADD COLUMN is_shared TINYINT(1) NOT NULL DEFAULT 0 AFTER subscription_id;

-- 2026_08_15_000001: rename del enum 'normal' -> 'shared' (3 pasos, strict mode)
ALTER TABLE pac_accounts MODIFY account_type ENUM('subaccount', 'normal', 'shared') NOT NULL;
UPDATE pac_accounts SET account_type = 'shared' WHERE account_type = 'normal';
ALTER TABLE pac_accounts MODIFY account_type ENUM('subaccount', 'shared') NOT NULL;

-- ============================================================================
-- 3. fiscal_profiles
--    CREATE (2026_06_26_000001) + postal_code (2026_06_26_000003)
--    + email/password (2026_06_27_000001) + CSD (2026_06_27_000002)
--    + manifest (2026_07_18_000004, 2026_07_19_000001)
--    + pac_account_id (2026_08_12_000002)
-- ============================================================================
CREATE TABLE fiscal_profiles (
    id                          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    subscription_id             BIGINT UNSIGNED NOT NULL,
    pac_account_id              BIGINT UNSIGNED NULL,
    rfc                         VARCHAR(13) NOT NULL,
    razon_social                VARCHAR(255) NOT NULL,
    regimen_fiscal              VARCHAR(10) NOT NULL,
    postal_code                 VARCHAR(5) NULL,
    email                       VARCHAR(255) NULL,
    password                    VARCHAR(255) NULL,
    sw_user_id                  VARCHAR(255) NULL,
    certificate_number          VARCHAR(20) NULL,
    valid_from                  TIMESTAMP NULL,
    valid_to                    TIMESTAMP NULL,
    cer_file_path               VARCHAR(255) NULL,
    key_file_path               VARCHAR(255) NULL,
    manifest_signed_at          TIMESTAMP NULL,
    manifest_pdf_path           VARCHAR(255) NULL,
    manifest_sent_to_email      VARCHAR(255) NULL,
    manifest_last_attempt_error VARCHAR(255) NULL,
    manifest_text_b64           TEXT NULL,
    manifest_text_shown_at      TIMESTAMP NULL,
    manifest_text_accepted_at   TIMESTAMP NULL,
    is_active                   TINYINT(1) NOT NULL DEFAULT 1,
    created_at                  TIMESTAMP NULL,
    updated_at                  TIMESTAMP NULL,
    PRIMARY KEY (id),
    UNIQUE KEY fiscal_profiles_subscription_rfc_unique (subscription_id, rfc),
    KEY fiscal_profiles_pac_account_id_index (pac_account_id),
    CONSTRAINT fiscal_profiles_subscription_id_foreign FOREIGN KEY (subscription_id) REFERENCES subscriptions (id) ON DELETE CASCADE,
    CONSTRAINT fiscal_profiles_pac_account_id_foreign FOREIGN KEY (pac_account_id) REFERENCES pac_accounts (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================================
-- 4. stamp_global_stats_snapshots  (2026_07_22_000002)
-- ============================================================================
CREATE TABLE stamp_global_stats_snapshots (
    id                     BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    total_stamps_assigned  INT UNSIGNED NOT NULL DEFAULT 0,
    total_stamps_used      INT UNSIGNED NOT NULL DEFAULT 0,
    active_issuers_count   INT UNSIGNED NOT NULL DEFAULT 0,
    computed_at            TIMESTAMP NULL,
    created_at             TIMESTAMP NULL,
    updated_at             TIMESTAMP NULL,
    PRIMARY KEY (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================================
-- 5. stamp_purchases
--    CREATE (2026_07_18_000002) + review_reason (2026_07_22_000001)
-- ============================================================================
CREATE TABLE stamp_purchases (
    id                       BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    fiscal_profile_id        BIGINT UNSIGNED NOT NULL,
    requested_by_user_id     BIGINT UNSIGNED NOT NULL,
    stamp_quantity           INT UNSIGNED NOT NULL,
    unit_price               DECIMAL(10,4) NOT NULL,
    amount_total             DECIMAL(10,2) NOT NULL,
    pricing_tier_id          BIGINT UNSIGNED NULL,
    payment_method           VARCHAR(255) NOT NULL,
    status                   VARCHAR(255) NOT NULL,
    review_reason            VARCHAR(255) NULL,
    mp_payment_id            VARCHAR(255) NULL,
    mp_preference_id         VARCHAR(255) NULL,
    proof_file_path          VARCHAR(255) NULL,
    proof_uploaded_at        TIMESTAMP NULL,
    reviewed_by_user_id      BIGINT UNSIGNED NULL,
    reviewed_at              TIMESTAMP NULL,
    rejection_reason         VARCHAR(255) NULL,
    pac_stamps_response_raw  JSON NULL,
    stamps_applied_at        TIMESTAMP NULL,
    admin_note               VARCHAR(255) NULL,
    adjustment_type          VARCHAR(255) NULL,
    created_at               TIMESTAMP NULL,
    updated_at               TIMESTAMP NULL,
    PRIMARY KEY (id),
    KEY stamp_purchases_fiscal_profile_id_index (fiscal_profile_id),
    KEY stamp_purchases_status_index (status),
    KEY stamp_purchases_payment_method_index (payment_method),
    KEY stamp_purchases_review_reason_index (review_reason),
    CONSTRAINT stamp_purchases_fiscal_profile_id_foreign FOREIGN KEY (fiscal_profile_id) REFERENCES fiscal_profiles (id) ON DELETE CASCADE,
    CONSTRAINT stamp_purchases_requested_by_user_id_foreign FOREIGN KEY (requested_by_user_id) REFERENCES users (id),
    CONSTRAINT stamp_purchases_pricing_tier_id_foreign FOREIGN KEY (pricing_tier_id) REFERENCES stamp_pricing_tiers (id) ON DELETE SET NULL,
    CONSTRAINT stamp_purchases_reviewed_by_user_id_foreign FOREIGN KEY (reviewed_by_user_id) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================================
-- 6. stamp_movements  (2026_07_24_000001)
-- ============================================================================
CREATE TABLE stamp_movements (
    id                 BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    fiscal_profile_id  BIGINT UNSIGNED NOT NULL,
    type               VARCHAR(255) NOT NULL,
    description        VARCHAR(255) NOT NULL,
    quantity           INT NOT NULL,
    balance_after      INT NOT NULL,
    reference_type     VARCHAR(255) NULL,
    reference_id       BIGINT UNSIGNED NULL,
    metadata           JSON NULL,
    created_at         TIMESTAMP NULL,
    updated_at         TIMESTAMP NULL,
    PRIMARY KEY (id),
    KEY stamp_movements_fiscal_profile_id_index (fiscal_profile_id),
    KEY stamp_movements_created_at_index (created_at),
    KEY stamp_movements_reference_type_reference_id_index (reference_type, reference_id),
    CONSTRAINT stamp_movements_fiscal_profile_id_foreign FOREIGN KEY (fiscal_profile_id) REFERENCES fiscal_profiles (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================================
-- 7. suggestions  (2026_07_27_000001)
-- ============================================================================
CREATE TABLE suggestions (
    id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    branch_id    BIGINT UNSIGNED NOT NULL,
    user_id      BIGINT UNSIGNED NULL,
    category     VARCHAR(255) NOT NULL,
    title        VARCHAR(255) NOT NULL,
    description  TEXT NOT NULL,
    status       VARCHAR(255) NOT NULL DEFAULT 'pending',
    priority     VARCHAR(255) NOT NULL DEFAULT 'medium',
    admin_notes  TEXT NULL,
    created_at   TIMESTAMP NULL,
    updated_at   TIMESTAMP NULL,
    PRIMARY KEY (id),
    KEY suggestions_branch_id_foreign (branch_id),
    KEY suggestions_user_id_foreign (user_id),
    CONSTRAINT suggestions_branch_id_foreign FOREIGN KEY (branch_id) REFERENCES branches (id) ON DELETE CASCADE,
    CONSTRAINT suggestions_user_id_foreign FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================================
-- 8. invoice_folio_counters  (2026_08_13_000001)
-- ============================================================================
CREATE TABLE invoice_folio_counters (
    id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    branch_id   BIGINT UNSIGNED NOT NULL,
    series      VARCHAR(255) NULL,
    next_folio  INT UNSIGNED NOT NULL DEFAULT 1,
    created_at  TIMESTAMP NULL,
    updated_at  TIMESTAMP NULL,
    PRIMARY KEY (id),
    UNIQUE KEY invoice_folio_counters_branch_id_series_unique (branch_id, series),
    KEY invoice_folio_counters_branch_id_foreign (branch_id),
    CONSTRAINT invoice_folio_counters_branch_id_foreign FOREIGN KEY (branch_id) REFERENCES branches (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================================
-- 9. stamp_reservations  (2026_08_13_000002)
-- ============================================================================
CREATE TABLE stamp_reservations (
    id                   BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    fiscal_profile_id    BIGINT UNSIGNED NOT NULL,
    reference_type       VARCHAR(255) NULL,
    reference_id         BIGINT UNSIGNED NULL,
    customid             VARCHAR(100) NOT NULL,
    quantity             INT UNSIGNED NOT NULL DEFAULT 1,
    status               VARCHAR(255) NOT NULL,
    attempts             TINYINT UNSIGNED NOT NULL DEFAULT 0,
    confirmed_at         TIMESTAMP NULL,
    released_at          TIMESTAMP NULL,
    last_pac_response    JSON NULL,
    created_at           TIMESTAMP NULL,
    updated_at           TIMESTAMP NULL,
    PRIMARY KEY (id),
    UNIQUE KEY stamp_reservations_customid_unique (customid),
    KEY stamp_reservations_fiscal_profile_id_status_index (fiscal_profile_id, status),
    KEY stamp_reservations_fiscal_profile_id_foreign (fiscal_profile_id),
    KEY stamp_reservations_reference_type_reference_id_index (reference_type, reference_id),
    CONSTRAINT stamp_reservations_fiscal_profile_id_foreign FOREIGN KEY (fiscal_profile_id) REFERENCES fiscal_profiles (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================================
-- 10. pac_call_logs  (2026_08_13_000003)
-- ============================================================================
CREATE TABLE pac_call_logs (
    id                    BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    fiscal_profile_id     BIGINT UNSIGNED NULL,
    pac_account_id        BIGINT UNSIGNED NULL,
    operation             VARCHAR(255) NOT NULL,
    customid              VARCHAR(255) NULL,
    request_payload       JSON NULL,
    response_status_code  SMALLINT UNSIGNED NULL,
    response_body         JSON NULL,
    duration_ms           INT UNSIGNED NULL,
    created_at            TIMESTAMP NULL,
    updated_at            TIMESTAMP NULL,
    PRIMARY KEY (id),
    KEY pac_call_logs_fiscal_profile_id_created_at_index (fiscal_profile_id, created_at),
    KEY pac_call_logs_fiscal_profile_id_foreign (fiscal_profile_id),
    KEY pac_call_logs_pac_account_id_foreign (pac_account_id),
    CONSTRAINT pac_call_logs_fiscal_profile_id_foreign FOREIGN KEY (fiscal_profile_id) REFERENCES fiscal_profiles (id) ON DELETE SET NULL,
    CONSTRAINT pac_call_logs_pac_account_id_foreign FOREIGN KEY (pac_account_id) REFERENCES pac_accounts (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================================
-- 11. invoice_items
--     CREATE (2026_06_12_000010) + objeto_imp/retentions (2026_06_29_000001)
--     + unit_name/no_identificacion + retained_tax_rate (6,6) (2026_06_29_000002)
--     + retentions JSON (2026_07_04_000001)
-- ============================================================================
CREATE TABLE invoice_items (
    id                   BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    invoice_id           BIGINT UNSIGNED NOT NULL,
    product_id           BIGINT UNSIGNED NULL,
    description          VARCHAR(255) NOT NULL,
    quantity             DECIMAL(12,4) NOT NULL,
    sat_unit_code        VARCHAR(10) NULL,
    unit_name            VARCHAR(50) NULL,
    unit_price           DECIMAL(12,4) NOT NULL,
    subtotal             DECIMAL(12,2) NOT NULL DEFAULT 0,
    discount_amount      DECIMAL(12,2) NOT NULL DEFAULT 0,
    tax_amount           DECIMAL(12,2) NOT NULL DEFAULT 0,
    total                DECIMAL(12,2) NOT NULL DEFAULT 0,
    sat_product_code     VARCHAR(15) NULL,
    no_identificacion    VARCHAR(100) NULL,
    objeto_imp           VARCHAR(5) NULL DEFAULT '02',
    tax_type             VARCHAR(5) NULL,
    tax_rate             DECIMAL(6,4) NULL,
    retained_tax_type    VARCHAR(5) NULL,
    retained_tax_rate    DECIMAL(6,6) NULL,
    retained_tax_amount  DECIMAL(12,2) NOT NULL DEFAULT 0,
    retentions           JSON NULL,
    created_at           TIMESTAMP NULL,
    updated_at           TIMESTAMP NULL,
    PRIMARY KEY (id),
    KEY invoice_items_invoice_id_foreign (invoice_id),
    KEY invoice_items_product_id_foreign (product_id),
    CONSTRAINT invoice_items_invoice_id_foreign FOREIGN KEY (invoice_id) REFERENCES invoices (id) ON DELETE CASCADE,
    CONSTRAINT invoice_items_product_id_foreign FOREIGN KEY (product_id) REFERENCES products (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================================
-- 12. ALTERs sobre tablas existentes
-- ============================================================================

-- 12.1 customers  (2026_06_22_000000)
ALTER TABLE customers
    ADD COLUMN tax_regime VARCHAR(10) NULL,
    ADD COLUMN fiscal_address JSON NULL;

-- 12.2 billing_settings  (2026_06_22_000001)
ALTER TABLE billing_settings
    ADD COLUMN logo_path VARCHAR(255) NULL;

-- 12.3 subscription_payments  (2026_06_22_000001)
--     subscription_version_id pasa a nullable (pagos Mercado Pago sin version aun)
ALTER TABLE subscription_payments
    MODIFY subscription_version_id BIGINT UNSIGNED NULL;

-- 12.4 invoices
--     fiscal_profile_id        (2026_06_26_000002) - sin FK: la migracion
--                               2026_06_29_000001 detecta que la columna ya
--                               existe y no agrega la constraint.
--     exportacion, retained_taxes_total  (2026_06_29_000001)
--     exchange_rate                      (2026_07_04_000001)
--     timbre fiscal                      (2026_07_09_000001)
--     cancelation_*                      (2026_07_18_000003)
--     tipo_comprobante                   (2026_08_01_000001)
--     pago_*                             (2026_08_05_000001, 000003)
--     tipo_relacion, cfdi_relacionados   (2026_08_05_000002)
--     requires_manual_review + UNIQUE    (2026_08_13_000004)
--     transaction_id, prices_include_iva (2026_08_15_000001)
--
-- ADVERTENCIA: el UNIQUE (branch_id, series, folio) fallara si existen filas
-- duplicadas. La migracion original verifico que no habia duplicados antes de
-- aplicarlo.
-- ============================================================================
ALTER TABLE invoices
    ADD COLUMN fiscal_profile_id BIGINT UNSIGNED NULL,
    ADD INDEX invoices_fiscal_profile_id_index (fiscal_profile_id),
    ADD COLUMN exportacion VARCHAR(5) NULL DEFAULT '01',
    ADD COLUMN retained_taxes_total DECIMAL(12,2) NOT NULL DEFAULT 0,
    ADD COLUMN exchange_rate DECIMAL(10,6) NULL,
    ADD COLUMN fecha_timbrado TIMESTAMP NULL,
    ADD COLUMN sello_cfdi TEXT NULL,
    ADD COLUMN sello_sat TEXT NULL,
    ADD COLUMN no_certificado_sat VARCHAR(255) NULL,
    ADD COLUMN rfc_prov_certif VARCHAR(13) NULL,
    ADD COLUMN cadena_original_sat TEXT NULL,
    ADD COLUMN qr_code_base64 LONGTEXT NULL,
    ADD COLUMN cancelation_requires_acceptance TINYINT(1) NOT NULL DEFAULT 0,
    ADD COLUMN cancelation_status VARCHAR(255) NULL,
    ADD COLUMN cancelation_requested_at TIMESTAMP NULL,
    ADD COLUMN cancelation_last_checked_at TIMESTAMP NULL,
    ADD COLUMN tipo_comprobante VARCHAR(5) NULL DEFAULT 'I',
    ADD COLUMN pago_fecha TIMESTAMP NULL,
    ADD COLUMN pago_forma VARCHAR(5) NULL,
    ADD COLUMN pago_moneda VARCHAR(5) NULL DEFAULT 'MXN',
    ADD COLUMN pago_monto DECIMAL(12,2) NULL,
    ADD COLUMN pago_documentos JSON NULL,
    ADD COLUMN pago_tipo_cambio DECIMAL(8,6) NULL,
    ADD COLUMN tipo_relacion VARCHAR(5) NULL,
    ADD COLUMN cfdi_relacionados JSON NULL,
    ADD COLUMN requires_manual_review TINYINT(1) NOT NULL DEFAULT 0,
    ADD UNIQUE INDEX invoices_branch_id_series_folio_unique (branch_id, series, folio),
    ADD COLUMN transaction_id BIGINT UNSIGNED NULL,
    ADD CONSTRAINT invoices_transaction_id_foreign FOREIGN KEY (transaction_id) REFERENCES transactions (id) ON DELETE SET NULL,
    ADD COLUMN prices_include_iva TINYINT(1) NOT NULL DEFAULT 0;

-- 12.5 release_notes + release_note_user  (2026_07_08_000000)
ALTER TABLE release_notes
    ADD COLUMN is_banner TINYINT(1) NOT NULL DEFAULT 0,
    ADD COLUMN banner_title VARCHAR(255) NULL;

ALTER TABLE release_note_user
    ADD COLUMN banner_dismissed_at TIMESTAMP NULL;

-- 12.6 setting_definitions  (2026_07_13_000000)
--     default_value pasa de VARCHAR(255) a TEXT
ALTER TABLE setting_definitions
    MODIFY default_value TEXT NULL;

-- 12.7 subscriptions  (2026_07_16_000001)
ALTER TABLE subscriptions
    ADD COLUMN facturacion_habilitada TINYINT(1) NOT NULL DEFAULT 0;

-- 12.8 ai_conversations  (2026_07_24_094647)
ALTER TABLE ai_conversations
    DROP COLUMN provider,
    DROP COLUMN model;

-- 12.9 products + services  (2026_07_28_000001)
ALTER TABLE products
    ADD COLUMN sat_product_code VARCHAR(8) NULL,
    ADD COLUMN sat_unit_code VARCHAR(10) NULL;

ALTER TABLE services
    ADD COLUMN sat_product_code VARCHAR(8) NULL,
    ADD COLUMN sat_unit_code VARCHAR(10) NULL;

-- ============================================================================
-- 13. Seed: stamp_large_purchase_threshold  (2026_07_22_000003)
--     INSERT IGNORE evita duplicados si el registro ya existe.
-- ============================================================================
INSERT IGNORE INTO setting_definitions
    (`key`, `name`, `description`, `module`, `level`, `type`, `default_value`, `created_at`, `updated_at`)
VALUES
    ('stamp_large_purchase_threshold',
     'Umbral de revisión de compras grandes',
     'Cantidad de timbres a partir de la cual una compra por Mercado Pago requiere revisión manual del superadmin antes de aplicarse al PAC.',
     'billing',
     'platform',
     'integer',
     '1000',
     NOW(),
     NOW());

SET FOREIGN_KEY_CHECKS = 1;