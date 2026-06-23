<?php
/**
 * WhatsApp Sending Service Integration
 */

require_once __DIR__ . '/db.php';

function send_whatsapp_pdf($to, $pdf_content, $filename, $text = '') {
    $conn = get_db();
    
    // Create system_settings table if not exists (fail-safe)
    $conn->exec("CREATE TABLE IF NOT EXISTS system_settings (
        setting_key TEXT PRIMARY KEY,
        setting_value TEXT
    )");

    // Fetch settings
    $settings = [];
    $stmt = $conn->query("SELECT setting_key, setting_value FROM system_settings");
    if ($stmt) {
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $settings[$row['setting_key']] = $row['setting_value'];
        }
    }

    $provider = $settings['whatsapp_api_provider'] ?? 'mock';
    $to = preg_replace('/\D/', '', $to); // Strip non-numeric characters from phone

    if ($provider === 'mock') {
        // Mock Mode: Simulates sending by saving files to outbox directory and logging it
        $outbox_dir = __DIR__ . '/mock_whatsapp_outbox';
        if (!file_exists($outbox_dir)) {
            mkdir($outbox_dir, 0777, true);
        }
        file_put_contents($outbox_dir . '/' . $filename, $pdf_content);
        file_put_contents($outbox_dir . '/log.txt', "[" . date('Y-m-d H:i:s') . "] Simulated send to +{$to}:\nFile: {$filename}\nText Summary:\n{$text}\n\n", FILE_APPEND);
        return [
            'success' => true, 
            'message' => 'WhatsApp backup sent successfully (MOCK mode). File saved to mock_whatsapp_outbox/' . $filename
        ];
    }

    if ($provider === 'meta') {
        $token = $settings['whatsapp_meta_token'] ?? '';
        $phone_id = $settings['whatsapp_meta_phone_id'] ?? '';
        if (!$token || !$phone_id) {
            return ['success' => false, 'error' => 'Meta API credentials missing (whatsapp_meta_token or whatsapp_meta_phone_id)'];
        }

        // 1. Upload Media
        $media_url = "https://graph.facebook.com/v18.0/{$phone_id}/media";
        $tmp_file = tempnam(sys_get_temp_dir(), 'wa_pdf_');
        file_put_contents($tmp_file, $pdf_content);
        
        $cfile = new CURLFile($tmp_file, 'application/pdf', $filename);
        $data = [
            'file' => $cfile,
            'messaging_product' => 'whatsapp',
            'type' => 'application/pdf'
        ];

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $media_url);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $data);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            "Authorization: Bearer {$token}"
        ]);
        $response = curl_exec($ch);
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        
        @unlink($tmp_file);

        if ($http_code !== 200) {
            return ['success' => false, 'error' => "Meta Media upload failed with status code {$http_code}. Response: {$response}"];
        }

        $res_arr = json_decode($response, true);
        $media_id = $res_arr['id'] ?? '';
        if (!$media_id) {
            return ['success' => false, 'error' => "Meta Media ID not found in upload response. Response: {$response}"];
        }

        // 2. Send Message containing the document
        $msg_url = "https://graph.facebook.com/v18.0/{$phone_id}/messages";
        $payload = [
            'messaging_product' => 'whatsapp',
            'recipient_type' => 'individual',
            'to' => $to,
            'type' => 'document',
            'document' => [
                'id' => $media_id,
                'filename' => $filename,
                'caption' => $text
            ]
        ];

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $msg_url);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            "Authorization: Bearer {$token}",
            "Content-Type: application/json"
        ]);
        $response = curl_exec($ch);
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($http_code === 200 || $http_code === 201) {
            return ['success' => true, 'response' => $response];
        } else {
            return ['success' => false, 'error' => "Meta Message send failed with status code {$http_code}. Response: {$response}"];
        }
    }

    if ($provider === 'custom') {
        $url = $settings['whatsapp_custom_url'] ?? '';
        $custom_token = $settings['whatsapp_api_token'] ?? '';
        if (!$url) {
            return ['success' => false, 'error' => 'Custom gateway URL missing (whatsapp_custom_url)'];
        }

        // Send PDF base64 payload to custom gateway
        $base64_pdf = base64_encode($pdf_content);
        $payload = [
            'token' => $custom_token,
            'to' => $to,
            'filename' => $filename,
            'document' => 'data:application/pdf;base64,' . $base64_pdf,
            'caption' => $text
        ];

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($payload));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        $response = curl_exec($ch);
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($http_code >= 200 && $http_code < 300) {
            return ['success' => true, 'response' => $response];
        } else {
            return ['success' => false, 'error' => "Custom gateway request failed with code {$http_code}. Response: {$response}"];
        }
    }

    return ['success' => false, 'error' => "Unknown WhatsApp provider setting: {$provider}"];
}
