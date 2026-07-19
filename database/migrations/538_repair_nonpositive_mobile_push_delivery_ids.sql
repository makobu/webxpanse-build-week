-- A historical push-delivery row was inserted as id=0 before strict SQL mode
-- was enabled. Move any non-positive identities above the current maximum.

SET @mobile_push_next_id := (
    SELECT COALESCE(MAX(id), 0)
    FROM mobile_push_deliveries
);

UPDATE mobile_push_deliveries
SET id = (@mobile_push_next_id := @mobile_push_next_id + 1)
WHERE id <= 0
ORDER BY id ASC;
