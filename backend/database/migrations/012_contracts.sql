ALTER TABLE users ADD creci VARCHAR(30) NULL;
CREATE TABLE contract_templates (template_key VARCHAR(40) PRIMARY KEY,body MEDIUMTEXT NOT NULL,updated_by CHAR(32) NULL,updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,FOREIGN KEY(updated_by) REFERENCES users(id));
CREATE TABLE contracts (
 id CHAR(32) PRIMARY KEY,
 code VARCHAR(20) NOT NULL UNIQUE,
 credit_application_id CHAR(32) NOT NULL,
 opportunity_id CHAR(32) NOT NULL,
 property_id CHAR(32) NOT NULL,
 broker_id CHAR(32) NOT NULL,
 template_key VARCHAR(40) NOT NULL,
 status ENUM('draft','sent','signed','declined','cancelled') NOT NULL DEFAULT 'draft',
 total_value DECIMAL(13,2) NULL,
 terms TEXT NULL COMMENT 'JSON cifrado: condições editadas no contrato',
 signers JSON NULL,
 pdf_storage_name VARCHAR(80) NULL,
 pdf_sha256 CHAR(64) NULL,
 signed_storage_name VARCHAR(80) NULL,
 signed_sha256 CHAR(64) NULL,
 signature_provider ENUM('documenso','manual') NULL,
 provider_envelope_id VARCHAR(120) NULL,
 sent_at DATETIME NULL,
 signed_at DATETIME NULL,
 cancelled_at DATETIME NULL,
 cancel_reason VARCHAR(500) NULL,
 created_by CHAR(32) NOT NULL,
 created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
 updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
 FOREIGN KEY(credit_application_id) REFERENCES credit_applications(id),
 FOREIGN KEY(opportunity_id) REFERENCES opportunities(id),
 FOREIGN KEY(property_id) REFERENCES properties(id),
 FOREIGN KEY(broker_id) REFERENCES users(id),
 FOREIGN KEY(created_by) REFERENCES users(id),
 UNIQUE INDEX uq_envelope(provider_envelope_id),
 INDEX(broker_id,status),
 INDEX(credit_application_id)
);
CREATE TABLE contract_events (id CHAR(32) PRIMARY KEY,contract_id CHAR(32) NOT NULL,user_id CHAR(32) NULL,action VARCHAR(40) NOT NULL,notes TEXT NULL,created_at TIMESTAMP(6) DEFAULT CURRENT_TIMESTAMP(6),FOREIGN KEY(contract_id) REFERENCES contracts(id) ON DELETE CASCADE,FOREIGN KEY(user_id) REFERENCES users(id),INDEX(contract_id,created_at));
INSERT INTO app_settings(setting_key,value) VALUES('contracts','{"foro":"","commission_percent":"6","commission_payer":"vendedor","penalty_percent":"10","deed_days":"60","signal_percent":"10","lease_months":"30","lease_due_day":"10","lease_index":"IGP-M/FGV","lease_penalty_rents":"3","deposit_months":"3"}');
