-- #!mysql
-- #{ double_enderchest

-- #  { init
CREATE TABLE IF NOT EXISTS double_enderchest_inventories
(
    uuid            VARCHAR(36) PRIMARY KEY NOT NULL,
    inventory       BLOB        NOT NULL
);
-- #  }

-- #  { load
-- #    :uuid string
SELECT UNCOMPRESS(inventory) AS inventory FROM double_enderchest_inventories
WHERE uuid = :uuid;
-- #  }

-- #  { save
-- #    :uuid string
-- #    :inventory string
REPLACE INTO double_enderchest_inventories(uuid, inventory)
VALUES (:uuid, COMPRESS(:inventory));
-- #  }

-- #}