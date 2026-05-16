<?php
/**
 * `dbname` = MySQL **database** (schema), NOT the table name.
 * Your `contact_submissions` table is under the `mysql` DB in HeidiSQL — so dbname must be `mysql`.
 * For new apps, prefer a dedicated database (e.g. klashweb) instead of the system `mysql` DB.
 *
 * Do not add a closing `?>` — any output after the array breaks JSON from contact.php.
 */
declare(strict_types=1);

return [
    'host' => '127.0.0.1',
    'dbname' => 'mysql',
    'user' => 'root',
    'pass' => '',
];
