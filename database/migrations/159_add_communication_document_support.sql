ALTER TABLE documents
    MODIFY COLUMN entity_type ENUM('contact','task','event','email','activity','deal','communication') NOT NULL;
