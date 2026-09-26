ALTER TABLE opportunities
  ADD triage_status ENUM('pending','contacted','qualified','nurturing','no_interest','invalid','duplicate') NOT NULL DEFAULT 'pending' AFTER stage,
  ADD property_type VARCHAR(100) NULL,
  ADD decision_timing VARCHAR(100) NULL,
  ADD needs TEXT NULL,
  ADD qualification_notes TEXT NULL,
  ADD nurture_reason VARCHAR(255) NULL,
  ADD next_contact_at DATETIME NULL,
  ADD first_contact_due_at DATETIME NULL,
  ADD qualified_at DATETIME NULL,
  ADD distributed_at DATETIME NULL,
  ADD received_at DATETIME NULL,
  ADD service_started_at DATETIME NULL,
  ADD INDEX idx_sdr_queue(sdr_id,triage_status,first_service_at),
  ADD INDEX idx_nurture(stage,next_contact_at),
  ADD INDEX idx_broker_queue(broker_id,distributed_at);

CREATE TABLE distribution_settings (
  id TINYINT PRIMARY KEY,
  automatic_enabled TINYINT(1) NOT NULL DEFAULT 0,
  last_broker_id CHAR(32) NULL,
  updated_by CHAR(32) NULL,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  FOREIGN KEY(last_broker_id) REFERENCES users(id), FOREIGN KEY(updated_by) REFERENCES users(id)
);
INSERT INTO distribution_settings(id,automatic_enabled) VALUES(1,0);

CREATE TABLE distributions (
  id CHAR(32) PRIMARY KEY, opportunity_id CHAR(32) NOT NULL,
  previous_broker_id CHAR(32) NULL, new_broker_id CHAR(32) NOT NULL,
  distributed_by CHAR(32) NOT NULL, method ENUM('manual','round_robin','redistribution') NOT NULL,
  reason VARCHAR(255) NULL, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY(opportunity_id) REFERENCES opportunities(id) ON DELETE CASCADE,
  FOREIGN KEY(previous_broker_id) REFERENCES users(id), FOREIGN KEY(new_broker_id) REFERENCES users(id),
  FOREIGN KEY(distributed_by) REFERENCES users(id), INDEX(opportunity_id,created_at)
);

UPDATE opportunities SET first_contact_due_at=DATE_ADD(created_at, INTERVAL 30 MINUTE) WHERE first_contact_due_at IS NULL;
