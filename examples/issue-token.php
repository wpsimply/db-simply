<?php

/*
 * The control panel's side of sign-on: authorise the user yourself, then
 * write a token and redirect. This page runs in the panel, e.g. at
 * https://panel.example.com/db-simply/open?account=42, on (or over SSH to)
 * the host where DB Simply is installed; the token file must be readable by
 * its PHP-FPM pool and by nothing else.
 *
 * Tokens are bound to the browser that asked for them. The panel's "Open
 * database" button links here as it always did; without a binding, this page
 * sends the browser to DB Simply's sso.php?start first, which gives it a proof
 * in a cookie and sends it back here (DB_SIMPLY_SSO_ISSUE_URL points at this
 * page) with ?binding=<hash>&account=42. The token carries the binding, and
 * only the browser holding the proof can spend it.
 *
 * Never show the token URL to anyone or let it be copied: redirect to it.
 */

$dbSimply = 'https://db.example.com';
$tokenDir = '/var/www/db-simply/storage/sso-tokens';

// Your own checks: the user is signed in to the panel, and the account is theirs.
$account = (string) ($_GET['account'] ?? '');

$binding = $_GET['binding'] ?? null;

if ($binding === null) {
    header('Location: '.$dbSimply.'/sso.php?'.http_build_query(['start' => 1, 'account' => $account]));
    exit;
}

if (! is_string($binding) || preg_match('/^[a-f0-9]{64}$/', $binding) !== 1) {
    http_response_code(400);
    exit('Invalid sign-on request.');
}

$token = bin2hex(random_bytes(32));

// Created readable by its owner only, before the password is in it.
$umask = umask(0077);
file_put_contents($tokenDir.'/'.$token, json_encode([
    'user' => 'acct42',               // a database user scoped to the account
    'password' => 'the-password',
    'database' => 'acct42_shop',      // optional: open this database
    'label' => 'example.com',         // optional: shown in the header
    'readonly' => false,              // optional: true for read-only access
    'binding' => $binding,            // the browser the token is for
]));
umask($umask);

header('Location: '.$dbSimply.'/sso.php?token='.$token);
