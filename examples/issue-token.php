<?php

/*
 * The control panel's side of sign-on: authorise the user yourself, then
 * write a token and redirect. Run this on (or over SSH to) the host where
 * DB Simply is installed; the token file must be readable by its PHP-FPM pool
 * and by nothing else.
 */

$tokenDir = '/var/www/db-simply/storage/sso-tokens';

$token = bin2hex(random_bytes(32));

file_put_contents($tokenDir.'/'.$token, json_encode([
    'user' => 'acct42',               // a database user scoped to the account
    'password' => 'the-password',
    'database' => 'acct42_shop',      // optional: open this database
    'label' => 'example.com',         // optional: shown in the header
    'readonly' => false,              // optional: true for read-only access
]));

chmod($tokenDir.'/'.$token, 0600);

header('Location: https://db.example.com/sso.php?token='.$token);
