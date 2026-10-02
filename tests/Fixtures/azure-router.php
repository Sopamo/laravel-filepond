<?php

// HTTP fixture for the real SDK's stage/commit requests. Manifest operations
// use a local disk; this is not a general Azure emulator.
$root = getenv('FILEPOND_AZURE_TEST_ROOT');
$path = rawurldecode(substr(parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH), strlen('/devstoreaccount1/container/')));
$body = file_get_contents('php://input');
$request = ['method' => $_SERVER['REQUEST_METHOD'], 'path' => $path, 'query' => $_GET,
    'headers' => getallheaders(), 'body' => base64_encode($body)];
file_put_contents($root.'/requests.jsonl', json_encode($request)."\n", FILE_APPEND);

if ($_SERVER['REQUEST_METHOD'] !== 'PUT') {
    http_response_code(405);
    exit;
}

$blocks = $root.'/blocks-'.sha1($path).'.json';
$staged = is_file($blocks) ? json_decode(file_get_contents($blocks), true) : [];
if (($_GET['comp'] ?? '') === 'block') {
    $staged[$_GET['blockid']] = base64_encode($body);
    file_put_contents($blocks, json_encode($staged));
} elseif (($_GET['comp'] ?? '') === 'blocklist') {
    if (is_file($root.'/fail-commit')) {
        http_response_code(400);
        header('Content-Type: application/xml');
        echo '<Error><Code>InvalidBlockList</Code><Message>Test commit failure</Message></Error>';
        exit;
    }
    $content = '';
    foreach (simplexml_load_string($body)->Latest as $blockId) {
        if (!isset($staged[(string) $blockId])) {
            http_response_code(400);
            exit;
        }
        $content .= base64_decode($staged[(string) $blockId]);
    }
    $target = $root.'/files/'.$path;
    if (!is_dir(dirname($target))) {
        mkdir(dirname($target), 0777, true);
    }
    file_put_contents($target, $content);
} else {
    http_response_code(400);
    exit;
}

http_response_code(201);
