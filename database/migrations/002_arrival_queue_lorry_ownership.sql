-- Recyclon migration 002
-- Queue ownership is tied to the stable lorry id, not a driver name.
-- Apply once to a legacy database where assigned_lorry_id is absent.

ALTER TABLE arrival_queue
    ADD COLUMN assigned_lorry_id INT NULL AFTER id,
    ADD INDEX idx_arrival_queue_assigned_lorry (assigned_lorry_id);

UPDATE arrival_queue q
INNER JOIN lorries l ON l.plate_number = q.plate_number
SET q.assigned_lorry_id = l.lorry_id
WHERE q.assigned_lorry_id IS NULL
  AND q.plate_number IS NOT NULL
  AND q.plate_number <> '';
