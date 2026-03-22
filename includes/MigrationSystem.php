<?php
// includes/MigrationSystem.php
class MigrationSystem {
    public static function enc(string $plaintext): string {
        $key = hash('sha256', ENCRYPTION_KEY, true);
        $iv = random_bytes(16);
        $cipher = openssl_encrypt($plaintext, 'AES-256-CBC', $key, OPENSSL_RAW_DATA, $iv);
        return base64_encode($iv . '::' . $cipher);
    }

    public static function dec(string $blob): string {
        $parts = explode('::', base64_decode($blob), 2);
        if (count($parts) !== 2) return '';
        $key = hash('sha256', ENCRYPTION_KEY, true);
        $plain = openssl_decrypt($parts[1], 'AES-256-CBC', $key, OPENSSL_RAW_DATA, $parts[0]);
        return $plain === false ? '' : (string)$plain;
    }

    public static function whmApiCall(string $host, string $user, string $token, string $function, array $params = []): array {
        $host = trim(preg_replace('#^https?://#i', '', $host), '/');
        $query = http_build_query(array_merge(['api.version' => 1], $params));
        $url = "https://{$host}:2087/json-api/" . rawurlencode($function) . "?{$query}";

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => false,
            CURLOPT_TIMEOUT => 180,
            CURLOPT_HTTPHEADER => [
                "Authorization: whm {$user}:{$token}",
                "Accept: application/json",
            ],
        ]);
        
        $raw = curl_exec($ch);
        $http = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        $json = json_decode((string)$raw, true);
        if (!is_array($json)) {
            return ['_ok' => false, 'error' => 'Invalid JSON response from WHM', '_raw' => $raw, 'http' => $http];
        }

        $result = (int)($json['metadata']['result'] ?? 0);
        $json['_ok'] = ($http >= 200 && $http < 300 && $result === 1);
        return $json;
    }

    public static function notify(PDO $db, $userId, string $message) {
        $stmt = $db->prepare("INSERT INTO notifications (user_id, message) VALUES (?, ?)");
        $stmt->execute([$userId, $message]);
        self::sendSmtpIfNotDisabled($db, $userId, $message);
    }

    /**
     * Send bulk password summary to the user AND admin.
     * $results = [['user' => 'foo', 'ok' => true, 'password' => 'abc'], ...]
     */
    public static function sendBulkPasswordEmail(PDO $db, int $userId, array $results): void {
        $stmt = $db->query("SELECT key_name, key_value FROM settings WHERE key_name IN ('smtp_from', 'admin_email', 'smtp_enabled')");
        $settings = [];
        while ($row = $stmt->fetch()) $settings[$row['key_name']] = $row['key_value'];
        if (($settings['smtp_enabled'] ?? '0') !== '1' || empty($settings['smtp_from'])) return;

        // Build list
        $lines = [];
        foreach ($results as $r) {
            if ($r['ok'] ?? false) $lines[] = "  {$r['user']}  =>  {$r['password']}";
        }
        if (empty($lines)) return;

        $body  = "Bulk Password Reset Summary\n";
        $body .= "Generated: " . date('Y-m-d H:i:s') . "\n\n";
        $body .= implode("\n", $lines);
        $body .= "\n\nPlease save these credentials immediately. They will not be shown again.";

        $subject = "[WHM Migration] Bulk Password Reset – " . count($lines) . " account(s)";
        $from    = $settings['smtp_from'];
        $headers = "From: {$from}\r\nReply-To: {$from}\r\nX-Mailer: PHP/" . phpversion();

        // Send to user
        if ($userId > 0) {
            $uStmt = $db->prepare("SELECT email FROM users WHERE id = ?");
            $uStmt->execute([$userId]);
            $email = $uStmt->fetchColumn();
            if ($email) @mail($email, $subject, $body, $headers);
        }

        // Always send a copy to admin
        $adminEmail = $settings['admin_email'] ?? '';
        if (empty($adminEmail)) {
            $aStmt = $db->query("SELECT email FROM users WHERE role = 'admin' LIMIT 1");
            $adminEmail = $aStmt->fetchColumn();
        }
        if ($adminEmail) @mail($adminEmail, "[ADMIN COPY] " . $subject, $body, $headers);
    }
    
    public static function sendSmtpIfNotDisabled(PDO $db, $userId, $message) {
        $stmt = $db->query("SELECT key_name, key_value FROM settings WHERE key_name IN ('smtp_host', 'smtp_user', 'smtp_pass', 'smtp_port', 'smtp_from', 'admin_email', 'smtp_enabled')");
        $settings = [];
        while($row = $stmt->fetch()) $settings[$row['key_name']] = $row['key_value'];

        if (($settings['smtp_enabled'] ?? '0') === '1' && !empty($settings['smtp_from'])) {
            $to = '';
            if ($userId > 0) {
                $uStmt = $db->prepare("SELECT email FROM users WHERE id = ?");
                $uStmt->execute([$userId]);
                $to = $uStmt->fetchColumn();
            } else {
                $to = $settings['admin_email'] ?? '';
                if (empty($to)) {
                    $uStmt = $db->query("SELECT email FROM users WHERE role = 'admin' LIMIT 1");
                    $to = $uStmt->fetchColumn();
                }
            }
            if ($to) {
                $subject = "WHM Migration Tool Notification";
                $headers = "From: " . $settings['smtp_from'] . "\r\nReply-To: " . $settings['smtp_from'] . "\r\nX-Mailer: PHP/" . phpversion();
                @mail($to, $subject, $message, $headers);
            }
        }
    }
}
