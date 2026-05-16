<?php
/**
 * Local development: copy to `database.php` (gitignored).
 *
 * Railway production: add a MySQL service and link variables — the app uses
 * MYSQLHOST, MYSQLPORT, MYSQLUSER, MYSQLPASSWORD, MYSQLDATABASE automatically.
 * You do not need database.php on Railway unless you prefer file-based config.
 *
 * Run `sql/contact_submissions.sql` on the same database as `dbname`.
 *
 * Do not add a closing `?>` — trailing output breaks JSON responses.
 */
return [
    'host' => '127.0.0.1',
    'port' => '3306',
    'dbname' => 'klashweb',
    'user' => 'root',
    'pass' => '',
];
