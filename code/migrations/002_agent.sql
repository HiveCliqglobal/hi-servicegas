-- =====================================================
-- Migration 002 — Claude Agent watchdog
-- Adds agent_activity table for monitoring + history.
-- =====================================================

CREATE TABLE IF NOT EXISTS agent_activity (
  id INT AUTO_INCREMENT PRIMARY KEY,
  kind ENUM(
    'health_check',
    'observation',
    'recovery_action',
    'intent_fallback',
    'escalation',
    'digest'
  ) NOT NULL,
  severity ENUM('info','warn','error','critical') DEFAULT 'info',
  title VARCHAR(255) NOT NULL,
  summary TEXT,
  context_json JSON,
  action_taken VARCHAR(255),
  entity_type VARCHAR(32),
  entity_id   VARCHAR(64),
  model VARCHAR(64),
  prompt_tokens INT,
  completion_tokens INT,
  cost_usd DECIMAL(10,6),
  resolved TINYINT(1) DEFAULT 0,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_kind     (kind),
  INDEX idx_severity (severity),
  INDEX idx_created  (created_at),
  INDEX idx_resolved (resolved)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
