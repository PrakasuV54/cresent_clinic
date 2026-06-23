<?php

function upload_to_supabase($file_path, $bucket, $object_key, $mime_type) {
    $supabase_url = getenv('SUPABASE_URL');
    $supabase_key = getenv('SUPABASE_SERVICE_ROLE_KEY');

    if (!$supabase_url || !$supabase_key) {
        // Fallback to local storage if not configured (development mode)
        $local_dir = __DIR__ . '/uploads/' . $bucket;
        if (!is_dir($local_dir)) {
            mkdir($local_dir, 0777, true);
        }
        $dest = $local_dir . '/' . basename($object_key);
        if (copy($file_path, $dest)) {
            return true;
        }
        return false;
    }

    $url = rtrim($supabase_url, '/') . "/storage/v1/object/$bucket/$object_key";
    $file_content = file_get_contents($file_path);

    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'POST');
    curl_setopt($ch, CURLOPT_POSTFIELDS, $file_content);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        "Authorization: Bearer $supabase_key",
        "Content-Type: $mime_type"
    ]);

    $response = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    return $http_code === 200 || $http_code === 201;
}

function get_supabase_signed_url($bucket, $object_key, $expires_in = 3600) {
    $supabase_url = getenv('SUPABASE_URL');
    $supabase_key = getenv('SUPABASE_SERVICE_ROLE_KEY');

    if (!$supabase_url || !$supabase_key) {
        // Fallback for local files
        return "/static/uploads/$bucket/" . basename($object_key);
    }

    $url = rtrim($supabase_url, '/') . "/storage/v1/object/sign/$bucket/$object_key";
    $payload = json_encode(['expiresIn' => $expires_in]);

    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'POST');
    curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        "Authorization: Bearer $supabase_key",
        "Content-Type: application/json"
    ]);

    $response = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($http_code === 200) {
        $data = json_decode($response, true);
        if (isset($data['signedURL'])) {
            return rtrim($supabase_url, '/') . "/storage/v1" . $data['signedURL'];
        }
    }
    return null;
}

function upload_buffer_to_supabase($buffer, $bucket, $object_key, $mime_type) {
    $supabase_url = getenv('SUPABASE_URL');
    $supabase_key = getenv('SUPABASE_SERVICE_ROLE_KEY');

    if (!$supabase_url || !$supabase_key) {
        // Fallback to local storage if not configured (development mode)
        $local_dir = __DIR__ . '/uploads/' . $bucket;
        if (!is_dir($local_dir)) {
            mkdir($local_dir, 0777, true);
        }
        $dest = $local_dir . '/' . basename($object_key);
        return file_put_contents($dest, $buffer) !== false;
    }

    $url = rtrim($supabase_url, '/') . "/storage/v1/object/$bucket/$object_key";

    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'POST');
    curl_setopt($ch, CURLOPT_POSTFIELDS, $buffer);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        "Authorization: Bearer $supabase_key",
        "Content-Type: $mime_type"
    ]);

    $response = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    return $http_code === 200 || $http_code === 201;
}
