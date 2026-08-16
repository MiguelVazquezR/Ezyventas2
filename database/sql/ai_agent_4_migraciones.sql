-- ============================================================================
-- EzyVentas 2 - Produccion: Equivalente SQL de las 4 migraciones del paquete
-- packages/ai-agent
-- Generado a partir de las funciones up() de las migraciones:
--   0001_create_ai_conversations_table
--   0002_create_ai_messages_table
--   0003_create_ai_tool_executions_table
--   0004_create_ai_usage_monthly_table
--
-- Ejecutar en phpMyAdmin sobre la base de datos de produccion (MySQL 8+).
-- Mantiene el estado final del esquema con sus llaves foraneas e indices.
-- ============================================================================

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

-- ============================================================================
-- 1. ai_conversations  (0001_create_ai_conversations_table)
--    Tabla principal de conversaciones. Depende de subscriptions y users.
-- ============================================================================
CREATE TABLE ai_conversations (
    id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    subscription_id BIGINT UNSIGNED NOT NULL,
    user_id         BIGINT UNSIGNED NOT NULL,
    title           VARCHAR(255) NULL,
    provider        VARCHAR(255) NOT NULL,
    model           VARCHAR(255) NOT NULL,
    created_at      TIMESTAMP NULL,
    updated_at      TIMESTAMP NULL,
    PRIMARY KEY (id),
    KEY ai_conversations_subscription_id_index (subscription_id),
    KEY ai_conversations_user_id_index (user_id),
    CONSTRAINT ai_conversations_subscription_id_foreign FOREIGN KEY (subscription_id) REFERENCES subscriptions (id) ON DELETE CASCADE,
    CONSTRAINT ai_conversations_user_id_foreign FOREIGN KEY (user_id) REFERENCES users (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================================
-- 2. ai_messages  (0002_create_ai_messages_table)
--    Mensajes de cada conversacion. Depende de ai_conversations.
-- ============================================================================
CREATE TABLE ai_messages (
    id                   BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    ai_conversation_id   BIGINT UNSIGNED NOT NULL,
    role                 ENUM('user', 'assistant', 'tool') NOT NULL,
    content              LONGTEXT NULL,
    tool_calls           JSON NULL,
    created_at           TIMESTAMP NULL,
    updated_at           TIMESTAMP NULL,
    PRIMARY KEY (id),
    KEY ai_messages_ai_conversation_id_index (ai_conversation_id),
    CONSTRAINT ai_messages_ai_conversation_id_foreign FOREIGN KEY (ai_conversation_id) REFERENCES ai_conversations (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================================
-- 3. ai_tool_executions  (0003_create_ai_tool_executions_table)
--    Ejecuciones de herramientas del agente. Depende de ai_messages,
--    subscriptions y users.
-- ============================================================================
CREATE TABLE ai_tool_executions (
    id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    ai_message_id   BIGINT UNSIGNED NOT NULL,
    subscription_id BIGINT UNSIGNED NOT NULL,
    user_id         BIGINT UNSIGNED NOT NULL,
    tool_name       VARCHAR(255) NOT NULL,
    arguments       JSON NOT NULL,
    result          JSON NULL,
    duration_ms     INT UNSIGNED NULL,
    created_at      TIMESTAMP NULL,
    updated_at      TIMESTAMP NULL,
    PRIMARY KEY (id),
    KEY ai_tool_executions_ai_message_id_index (ai_message_id),
    KEY ai_tool_executions_subscription_id_index (subscription_id),
    KEY ai_tool_executions_user_id_index (user_id),
    CONSTRAINT ai_tool_executions_ai_message_id_foreign FOREIGN KEY (ai_message_id) REFERENCES ai_messages (id) ON DELETE CASCADE,
    CONSTRAINT ai_tool_executions_subscription_id_foreign FOREIGN KEY (subscription_id) REFERENCES subscriptions (id),
    CONSTRAINT ai_tool_executions_user_id_foreign FOREIGN KEY (user_id) REFERENCES users (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================================
-- 4. ai_usage_monthly  (0004_create_ai_usage_monthly_table)
--    Consumo mensual por suscripcion, con unique por mes/anio.
-- ============================================================================
CREATE TABLE ai_usage_monthly (
    id                  BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    subscription_id     BIGINT UNSIGNED NOT NULL,
    year                SMALLINT UNSIGNED NOT NULL,
    month               TINYINT UNSIGNED NOT NULL,
    credits_used        INT UNSIGNED NOT NULL DEFAULT 0,
    total_tokens        BIGINT UNSIGNED NOT NULL DEFAULT 0,
    estimated_cost_usd  DECIMAL(10,4) NOT NULL DEFAULT 0,
    created_at          TIMESTAMP NULL,
    updated_at          TIMESTAMP NULL,
    PRIMARY KEY (id),
    UNIQUE KEY ai_usage_monthly_subscription_id_year_month_unique (subscription_id, year, month),
    CONSTRAINT ai_usage_monthly_subscription_id_foreign FOREIGN KEY (subscription_id) REFERENCES subscriptions (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET FOREIGN_KEY_CHECKS = 1;