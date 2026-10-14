<?php

declare(strict_types=1);

// Local protocol fixture. Started and removed by run.php; never deployed with the application.
$directory = getenv('UPDATER_TEST_DIRECTORY');
$scenario = json_decode(file_get_contents($directory . '/scenario.json'), true);
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
file_put_contents($directory . '/request.json', json_encode([
    'path' => $path, 'query' => $_GET, 'method' => $_SERVER['REQUEST_METHOD'],
    'product_key' => $_SERVER['HTTP_PRODUCT_KEY'] ?? '', 'client_key' => $_SERVER['HTTP_CLIENT_KEY'] ?? '',
    'upgrade_token' => $_SERVER['HTTP_UPGRADE_TOKEN'] ?? '',
    'body' => json_decode(file_get_contents('php://input'), true),
]));
if ($path === '/ready') {
    echo 'ready';
    return;
}
$action = $_GET['action'] ?? (json_decode(file_get_contents('php://input'), true)['action'] ?? '');
$mode = $scenario['mode'] ?? '';
if ($action === 'manifest') {
    if ($mode === 'expired') {
        http_response_code(403);
        return;
    }
    header('Content-Type: application/json');
    readfile($directory . '/manifest.json');
    return;
}
if ($action === 'download' || strpos($path, '/api/packages/') === 0) {
    if ($mode === 'download_redirect') {
        header('Location: /forbidden');
        return;
    }
    if ($mode === 'expired') {
        http_response_code(403);
        echo 'expired';
        return;
    }
    $deltaId = $scenario['release']['delta_package']['id'] ?? null;
    readfile($deltaId !== null && (int) ($_GET['package_id'] ?? 0) === $deltaId ? $directory . '/delta.zip' : $directory . '/package.zip');
    return;
}
header('Content-Type: application/json');
if ($mode === 'redirect') {
    header('Location: /forbidden');
    return;
}
if ($mode === 'forbidden') {
    http_response_code(403);
    echo '{"status":0,"error":"fixture-secret-value"}';
    return;
}
if ($mode === 'invalid_json') {
    echo '{';
    return;
}
if ($mode === 'business_error') {
    echo '{"status":0,"error":"fixture-secret-value","data":[]}';
    return;
}
if ($action === 'changelog') {
    $data = ['list' => [array_intersect_key($scenario['release'], array_flip(['version', 'version_code', 'changelog', 'published_at']))],
        'total' => 1, 'page' => (int) $_GET['page'], 'size' => (int) $_GET['size'],
        'direction' => $_GET['direction'], 'current_version_code' => (int) $_GET['version_code']];
} elseif ($action === 'report') {
    $data = ['registered' => true, 'client_id' => 1];
} elseif ($action === 'authorize') {
    $data = ['authorized' => true, 'license_value' => 'fixture-license'];
} elseif ((int) ($_GET['version_code'] ?? 0) >= ($scenario['release']['version_code'] ?? 1)) {
    $data = ['update' => false, 'status_code' => 304];
} else {
    $data = $scenario['release'];
}
echo json_encode(['status' => 1, 'error' => '', 'data' => $data]);
