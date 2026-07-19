ALTER TABLE invoice_line_items
    ADD COLUMN workspace_id INT NOT NULL DEFAULT 1 AFTER id,
    ADD KEY idx_invoice_line_workspace_invoice (workspace_id, invoice_id);

UPDATE invoice_line_items li
JOIN invoices i ON i.id = li.invoice_id
SET li.workspace_id = COALESCE(i.workspace_id, 1)
WHERE li.workspace_id IS NULL OR li.workspace_id <> COALESCE(i.workspace_id, 1);

ALTER TABLE invoice_activity_log
    ADD COLUMN workspace_id INT NOT NULL DEFAULT 1 AFTER id,
    ADD KEY idx_invoice_activity_workspace_invoice (workspace_id, invoice_id);

UPDATE invoice_activity_log l
JOIN invoices i ON i.id = l.invoice_id
SET l.workspace_id = COALESCE(i.workspace_id, 1)
WHERE l.workspace_id IS NULL OR l.workspace_id <> COALESCE(i.workspace_id, 1);

ALTER TABLE invoice_status_history
    ADD COLUMN workspace_id INT NOT NULL DEFAULT 1 AFTER id,
    ADD KEY idx_invoice_status_workspace_invoice (workspace_id, invoice_id);

UPDATE invoice_status_history h
JOIN invoices i ON i.id = h.invoice_id
SET h.workspace_id = COALESCE(i.workspace_id, 1)
WHERE h.workspace_id IS NULL OR h.workspace_id <> COALESCE(i.workspace_id, 1);

ALTER TABLE invoice_delivery_log
    ADD COLUMN workspace_id INT NOT NULL DEFAULT 1 AFTER id,
    ADD KEY idx_invoice_delivery_workspace_invoice (workspace_id, invoice_id);

UPDATE invoice_delivery_log d
JOIN invoices i ON i.id = d.invoice_id
SET d.workspace_id = COALESCE(i.workspace_id, 1)
WHERE d.workspace_id IS NULL OR d.workspace_id <> COALESCE(i.workspace_id, 1);
