<?php
require_once __DIR__ . '/../Config/session_bootstrap.php';
header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode([
        'success' => false,
        'message' => 'Method not allowed.'
    ]);
    exit();
}

$action = strtolower(trim((string) ($_POST['action'] ?? '')));

if ($action === '') {
    http_response_code(422);
    echo json_encode([
        'success' => false,
        'message' => 'Missing geocoding action.'
    ]);
    exit();
}

function callNominatim(string $url): ?array
{
    $response = false;
    $userAgent = 'ScholarshipFinder/1.0 (signup geocoding)';

    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT => 15,
            CURLOPT_HTTPHEADER => [
                'Accept: application/json',
                'User-Agent: ' . $userAgent
            ]
        ]);
        $response = curl_exec($ch);
        curl_close($ch);
    } else {
        $context = stream_context_create([
            'http' => [
                'method' => 'GET',
                'timeout' => 15,
                'header' => "Accept: application/json\r\nUser-Agent: {$userAgent}\r\n"
            ]
        ]);
        $response = @file_get_contents($url, false, $context);
    }

    if ($response === false || $response === '') {
        return null;
    }

    $decoded = json_decode($response, true);
    return is_array($decoded) ? $decoded : null;
}

if ($action === 'search') {
    $query = trim((string) ($_POST['query'] ?? ''));
    if ($query === '') {
        http_response_code(422);
        echo json_encode([
            'success' => false,
            'message' => 'Address query is required.'
        ]);
        exit();
    }

    $searchUrl = 'https://nominatim.openstreetmap.org/search?' . http_build_query([
        'q' => $query,
        'format' => 'json',
        'limit' => 1,
        'addressdetails' => 1,
        'countrycodes' => 'ph'
    ]);

    $data = callNominatim($searchUrl);
    if (!is_array($data) || empty($data[0]['lat']) || empty($data[0]['lon'])) {
        http_response_code(404);
        echo json_encode([
            'success' => false,
            'message' => 'Address not found.'
        ]);
        exit();
    }

    $first = $data[0];

    echo json_encode([
        'success' => true,
        'data' => [
            'lat' => (float) $first['lat'],
            'lng' => (float) $first['lon'],
            'display_name' => (string) ($first['display_name'] ?? ''),
            'address' => (array) ($first['address'] ?? [])
        ]
    ]);
    exit();
}

if ($action === 'reverse') {
    $latRaw = trim((string) ($_POST['lat'] ?? ''));
    $lngRaw = trim((string) ($_POST['lng'] ?? ''));

    if ($latRaw === '' || $lngRaw === '' || !is_numeric($latRaw) || !is_numeric($lngRaw)) {
        http_response_code(422);
        echo json_encode([
            'success' => false,
            'message' => 'Valid coordinates are required.'
        ]);
        exit();
    }

    $lat = (float) $latRaw;
    $lng = (float) $lngRaw;

    if ($lat < -90 || $lat > 90 || $lng < -180 || $lng > 180) {
        http_response_code(422);
        echo json_encode([
            'success' => false,
            'message' => 'Coordinates are out of range.'
        ]);
        exit();
    }

    $reverseUrl = 'https://nominatim.openstreetmap.org/reverse?' . http_build_query([
        'format' => 'json',
        'addressdetails' => 1,
        'lat' => $lat,
        'lon' => $lng
    ]);

    $data = callNominatim($reverseUrl);
    if (!is_array($data)) {
        http_response_code(404);
        echo json_encode([
            'success' => false,
            'message' => 'Unable to resolve address from coordinates.'
        ]);
        exit();
    }

    echo json_encode([
        'success' => true,
        'data' => [
            'display_name' => (string) ($data['display_name'] ?? ''),
            'address' => (array) ($data['address'] ?? [])
        ]
    ]);
    exit();
}

http_response_code(422);
echo json_encode([
    'success' => false,
    'message' => 'Unsupported geocoding action.'
]);
exit();
?>
