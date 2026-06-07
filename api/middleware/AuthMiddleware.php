<?php
class AuthMiddleware
{
    public static function authenticate()
    {
        $headers = getallheaders();
        $authHeader = null;

        if (isset($headers['Authorization'])) {
            $authHeader = $headers['Authorization'];
        } elseif (isset($headers['authorization'])) {
            $authHeader = $headers['authorization'];
        }

        if (!$authHeader || !preg_match('/Bearer\s(\S+)/', $authHeader, $matches)) {
            http_response_code(401);
            echo json_encode(["message" => "Unauthorized: No token provided"]);
            exit();
        }

        $jwt = $matches[1];
        $tokenParts = explode('.', $jwt);

        if (count($tokenParts) != 3) {
            http_response_code(401);
            echo json_encode(["message" => "Unauthorized: Invalid token format"]);
            exit();
        }

        $headerRaw = base64_decode(str_replace(['-', '_'], ['+', '/'], $tokenParts[0]));
        $header = json_decode($headerRaw, true);

        if (!$header || !isset($header['alg']) || $header['alg'] !== 'HS256') {
            http_response_code(401);
            echo json_encode(["message" => "Unauthorized: Invalid or unsupported algorithm"]);
            exit();
        }

        $payloadRaw = base64_decode(str_replace(['-', '_'], ['+', '/'], $tokenParts[1]));
        $signature_provided = $tokenParts[2];

        $base64UrlHeader = str_replace(['+', '/', '='], ['-', '_', ''], base64_encode($headerRaw));
        $base64UrlPayload = str_replace(['+', '/', '='], ['-', '_', ''], base64_encode($payloadRaw));
        $signature = hash_hmac('sha256', $base64UrlHeader . "." . $base64UrlPayload, JWT_SECRET, true);
        $base64UrlSignature = str_replace(['+', '/', '='], ['-', '_', ''], base64_encode($signature));

        if (!hash_equals($base64UrlSignature, $signature_provided)) {
            http_response_code(401);
            echo json_encode(["message" => "Unauthorized: Invalid signature"]);
            exit();
        }

        $claims = json_decode($payloadRaw, true);
        if (!$claims) {
            http_response_code(401);
            echo json_encode(["message" => "Unauthorized: Invalid token payload"]);
            exit();
        }

        if (isset($claims['exp']) && $claims['exp'] < time()) {
            http_response_code(401);
            echo json_encode(["message" => "Unauthorized: Token expired"]);
            exit();
        }

        if (!isset($claims['data']['id'])) {
            http_response_code(401);
            echo json_encode(["message" => "Unauthorized: Missing user ID"]);
            exit();
        }

        return $claims['data'];
    }

    public static function verifyToken($jwt)
    {
        $tokenParts = explode('.', $jwt);

        if (count($tokenParts) != 3) {
            return null;
        }

        $headerRaw = base64_decode(str_replace(['-', '_'], ['+', '/'], $tokenParts[0]));
        $header = json_decode($headerRaw, true);

        if (!$header || !isset($header['alg']) || $header['alg'] !== 'HS256') {
            return null;
        }

        $payloadRaw = base64_decode(str_replace(['-', '_'], ['+', '/'], $tokenParts[1]));
        $signature_provided = $tokenParts[2];

        $base64UrlHeader = str_replace(['+', '/', '='], ['-', '_', ''], base64_encode($headerRaw));
        $base64UrlPayload = str_replace(['+', '/', '='], ['-', '_', ''], base64_encode($payloadRaw));
        $signature = hash_hmac('sha256', $base64UrlHeader . "." . $base64UrlPayload, JWT_SECRET, true);
        $base64UrlSignature = str_replace(['+', '/', '='], ['-', '_', ''], base64_encode($signature));

        if (!hash_equals($base64UrlSignature, $signature_provided)) {
            return null;
        }

        $claims = json_decode($payloadRaw, true);
        if (!$claims) {
            return null;
        }

        if (isset($claims['exp']) && $claims['exp'] < time()) {
            return null;
        }

        return $claims;
    }
}
?>