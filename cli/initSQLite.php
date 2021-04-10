<?php

ini_set('display_errors', 1);               // DISPLAY ERROR
error_reporting(E_ALL);                     // ERROR REPORT
session_cache_expire(180);                  // SESSION => 3H

echo ('Welcome NeNe-PHP CLI!!!' . PHP_EOL . PHP_EOL);
echo ('Do you want to initialize SQLite? (Y/N)' . PHP_EOL);

$il = trim(fgets(STDIN));
if ($il !== 'Y' && $il !== 'y') {
    echo ('OK. Bye!' . PHP_EOL . PHP_EOL);
    exit();
}

echo ('Yes, initialize SQLite.' . PHP_EOL);

try {
    $db = new PDO('sqlite:./data/nene.db');
    if (!$db) {
        echo ('Oops. Database creation failed.' . PHP_EOL);
        echo ('Bye!' . PHP_EOL . PHP_EOL);
        exit();
    }
} catch (Exception $e) {
    echo ('CONNECT ERROR.' . PHP_EOL);
    echo ($e->getMessage() . PHP_EOL);
    exit();
}

echo ('The database was created successfully.' . PHP_EOL);

$db->exec(<<<_SQL_
CREATE TABLE IF NOT EXISTS users (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    created_at TEXT,
    updated_at TEXT,
    user_id TEXT,
    user_pass TEXT,
    user_name TEXT,
    e_mail TEXT,
    is_deleted INTEGER
)
_SQL_);
$res = $db->query("SELECT COUNT(*) FROM users WHERE user_id = 'admin'");
$count = $res->fetchColumn();
if ($count == 0) {
    $date = date('YmdHis');
    $res = $db->query(<<<_SQL_
INSERT INTO users (
    created_at,
    updated_at,
    user_id, user_pass,
    user_name,
    e_mail, is_deleted
) values (
    {$date},
    {$date},
    'admin',
    'admin',
    'admin',
    '',
    0
)
_SQL_);
    echo ('The "admin" account has been added. The password is "admin".' . PHP_EOL . PHP_EOL);
} else {
    echo ('The admin account already exists in the user table.' . PHP_EOL . PHP_EOL);
}

echo ('Processing has been completed. Thank you very much.');
