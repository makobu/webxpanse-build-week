ALTER TABLE invoice_line_items
    ADD COLUMN pricing_context TEXT NULL AFTER description;
