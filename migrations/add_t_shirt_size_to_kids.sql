-- Add T-shirt size for each child. Run on DBs created before this column.
ALTER TABLE registration_kids
  ADD COLUMN t_shirt_size VARCHAR(20) DEFAULT NULL AFTER last_grade_completed;
