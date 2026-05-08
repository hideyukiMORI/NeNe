CREATE TABLE IF NOT EXISTS users (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    user_id VARCHAR(64) NOT NULL,
    user_pass VARCHAR(64) NOT NULL,
    user_name VARCHAR(255) NOT NULL,
    e_mail VARCHAR(255) NOT NULL,
    is_deleted TINYINT(1) NOT NULL DEFAULT 0,
    PRIMARY KEY (id),
    UNIQUE KEY users_user_id_unique (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS todos (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    user_id BIGINT UNSIGNED NOT NULL,
    title VARCHAR(255) NOT NULL,
    is_completed TINYINT(1) NOT NULL DEFAULT 0,
    is_deleted TINYINT(1) NOT NULL DEFAULT 0,
    PRIMARY KEY (id),
    KEY todos_user_id_index (user_id),
    CONSTRAINT todos_user_id_foreign
        FOREIGN KEY (user_id) REFERENCES users (id)
        ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO users (user_id, user_pass, user_name, e_mail, is_deleted)
SELECT 'admin', 'admin', 'admin', 'admin@example.com', 0
WHERE NOT EXISTS (
    SELECT 1 FROM users WHERE user_id = 'admin'
);

INSERT INTO todos (user_id, title, is_completed, is_deleted)
SELECT users.id, 'Read the routing guide', 1, 0
FROM users
WHERE users.user_id = 'admin'
AND NOT EXISTS (
    SELECT 1 FROM todos
    WHERE todos.user_id = users.id
    AND todos.title = 'Read the routing guide'
);

INSERT INTO todos (user_id, title, is_completed, is_deleted)
SELECT users.id, 'Create a controller action', 0, 0
FROM users
WHERE users.user_id = 'admin'
AND NOT EXISTS (
    SELECT 1 FROM todos
    WHERE todos.user_id = users.id
    AND todos.title = 'Create a controller action'
);
