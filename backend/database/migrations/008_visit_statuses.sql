CREATE TABLE visit_statuses (id CHAR(32) PRIMARY KEY,`key` VARCHAR(60) NOT NULL UNIQUE,label VARCHAR(100) NOT NULL,color VARCHAR(20) NOT NULL DEFAULT '#2563eb',sort_order INT NOT NULL DEFAULT 0,blocks_schedule TINYINT(1) NOT NULL DEFAULT 0,requires_reason TINYINT(1) NOT NULL DEFAULT 0,created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP);
INSERT INTO visit_statuses(id,`key`,label,color,sort_order,blocks_schedule,requires_reason) VALUES
('60000000000000000000000000000001','scheduled','Agendada','#2563eb',0,1,0),
('60000000000000000000000000000002','completed','Concluída','#12b76a',1,0,0),
('60000000000000000000000000000003','cancelled','Cancelada','#dc3545',2,0,1),
('60000000000000000000000000000004','no_show','Não compareceu','#f59e0b',3,0,0);
ALTER TABLE visits MODIFY status VARCHAR(60) NOT NULL DEFAULT 'scheduled';
ALTER TABLE visits ADD FOREIGN KEY(status) REFERENCES visit_statuses(`key`);
