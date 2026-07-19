-- Expand enrichment enum values to match runtime logging values.
-- Keeps existing values for backward compatibility.

ALTER TABLE enrichment_sources
MODIFY COLUMN source_type ENUM(
    'web',
    'email',
    'social',
    'api',
    'inference',
    'validation',
    'clearbit',
    'pdl',
    'hunter'
) NOT NULL;

ALTER TABLE enrichment_history
MODIFY COLUMN enrichment_type ENUM(
    'extract',
    'infer',
    'validate',
    'merge',
    'context_generation',
    'undo'
) NOT NULL;
