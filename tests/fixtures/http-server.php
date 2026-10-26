<?php
/**
 * Router for PHP's built-in web server, used by TokenRedirectTest.
 *
 *     /redirect?to=<url>  302 to <url>
 *     /echo               the request headers this server received, as JSON
 *
 * @package AutoSEOManager\Tests
 */

$asm_path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);

if ('/redirect' === $asm_path && isset($_GET['to'])) {
    header('Location: ' . $_GET['to'], true, 302);
    exit;
}

if ('/echo' === $asm_path) {
    header('Content-Type: application/json');
    echo json_encode(array_change_key_case(getallheaders(), CASE_LOWER));
    exit;
}

http_response_code(404);
