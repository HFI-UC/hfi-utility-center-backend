-- The Python service stored blocked time ranges in roompolicy. The Rust
-- service treats roompolicy as bookable time ranges, so convert each day's
-- blocked ranges to their complement within the public 08:00-21:30 schedule.
CREATE TABLE IF NOT EXISTS roompolicy_legacy_unavailable AS
SELECT * FROM roompolicy WITH NO DATA;

INSERT INTO roompolicy_legacy_unavailable
SELECT * FROM roompolicy
WHERE NOT EXISTS (SELECT 1 FROM roompolicy_legacy_unavailable);

DELETE FROM roompolicy;

WITH RECURSIVE
room_days AS (
  SELECT r.id AS room_id, day
  FROM room r
  CROSS JOIN generate_series(0, 6) AS day
),
raw_blocks AS (
  SELECT
    p."roomId" AS room_id,
    day,
    GREATEST(480, (p."startTime"->>0)::int * 60 + (p."startTime"->>1)::int) AS start_minute,
    LEAST(1290, (p."endTime"->>0)::int * 60 + (p."endTime"->>1)::int) AS end_minute
  FROM roompolicy_legacy_unavailable p
  CROSS JOIN LATERAL json_array_elements_text(p.days) AS day_value(day)
  WHERE p.enabled = true
),
valid_blocks AS (
  SELECT room_id, day::int AS day, start_minute, end_minute
  FROM raw_blocks
  WHERE end_minute > start_minute
),
ordered_blocks AS (
  SELECT *,
    MAX(end_minute) OVER (
      PARTITION BY room_id, day
      ORDER BY start_minute, end_minute
      ROWS BETWEEN UNBOUNDED PRECEDING AND 1 PRECEDING
    ) AS previous_end
  FROM valid_blocks
),
island_markers AS (
  SELECT *,
    SUM(CASE WHEN previous_end IS NULL OR start_minute > previous_end THEN 1 ELSE 0 END)
      OVER (PARTITION BY room_id, day ORDER BY start_minute, end_minute) AS island
  FROM ordered_blocks
),
merged_blocks AS (
  SELECT room_id, day, island, MIN(start_minute) AS start_minute, MAX(end_minute) AS end_minute
  FROM island_markers
  GROUP BY room_id, day, island
),
gaps_before_blocks AS (
  SELECT
    room_id,
    day,
    COALESCE(LAG(end_minute) OVER (PARTITION BY room_id, day ORDER BY start_minute), 480) AS start_minute,
    start_minute AS end_minute
  FROM merged_blocks
),
gaps_after_blocks AS (
  SELECT room_id, day, MAX(end_minute) AS start_minute, 1290 AS end_minute
  FROM merged_blocks
  GROUP BY room_id, day
),
fully_open_days AS (
  SELECT rd.room_id, rd.day, 480 AS start_minute, 1290 AS end_minute
  FROM room_days rd
  WHERE NOT EXISTS (
    SELECT 1 FROM merged_blocks mb
    WHERE mb.room_id = rd.room_id AND mb.day = rd.day
  )
),
available_ranges AS (
  SELECT room_id, day, start_minute, end_minute FROM gaps_before_blocks
  UNION ALL
  SELECT room_id, day, start_minute, end_minute FROM gaps_after_blocks
  UNION ALL
  SELECT room_id, day, start_minute, end_minute FROM fully_open_days
),
grouped_ranges AS (
  SELECT room_id, start_minute, end_minute, json_agg(day ORDER BY day) AS days
  FROM available_ranges
  WHERE end_minute > start_minute
  GROUP BY room_id, start_minute, end_minute
)
INSERT INTO roompolicy ("roomId", days, "startTime", "endTime", enabled)
SELECT
  room_id,
  days,
  json_build_array(start_minute / 60, start_minute % 60),
  json_build_array(end_minute / 60, end_minute % 60),
  true
FROM grouped_ranges;
