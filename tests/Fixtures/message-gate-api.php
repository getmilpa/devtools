<?php

/**
 * This file is part of milpa/devtools — the generate-verify-inspect developer loop of the Milpa
 * PHP framework.
 *
 * (c) Rodrigo Vicente - TeamX Agency — https://teamx.agency <hola@teamx.agency>
 *
 * @license Apache-2.0
 *
 * @link    https://github.com/getmilpa/devtools
 */

declare(strict_types=1);

/**
 * A stand-in for the API the message gate reads, served by `php -S` during its tests.
 *
 * It answers the paths listed in the file MESSAGE_GATE_ROUTES names (path with its query → status and
 * answer) and writes each request to MESSAGE_GATE_LOG as "<authorization> <path>", so a test can see
 * what was asked, how many times, and with which token.
 */
$routes = json_decode((string) file_get_contents((string) getenv('MESSAGE_GATE_ROUTES')), true);
$path = (string) ($_SERVER['REQUEST_URI'] ?? '');

file_put_contents((string) getenv('MESSAGE_GATE_LOG'), ($_SERVER['HTTP_AUTHORIZATION'] ?? '-') . ' ' . $path . "\n", \FILE_APPEND);

[$status, $answer] = $routes[$path] ?? [404, ['message' => 'Not Found']];

http_response_code($status);
header('Content-Type: application/json');
echo json_encode($answer);
