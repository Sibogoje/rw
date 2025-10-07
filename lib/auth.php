<?php
// Minimal JWT encode/decode for HS256 (no Composer required)
// WARNING: This is for development only. For production, use a tested library.

function base64url_encode($data) {
    return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
}

function base64url_decode($data) {
    $remainder = strlen($data) % 4;
    if ($remainder) {
        $padlen = 4 - $remainder;
        $data .= str_repeat('=', $padlen);
    }
    return base64_decode(strtr($data, '-_', '+/'));
}

function generate_jwt($user_id) {
    $key = 'your_secret_key';
    $header = ['alg' => 'HS256', 'typ' => 'JWT'];
    $payload = [
        'iss' => 'zenmark',
        'iat' => time(),
        'exp' => time() + 3600,
        'uid' => $user_id
    ];
    $segments = [];
    $segments[] = base64url_encode(json_encode($header));
    $segments[] = base64url_encode(json_encode($payload));
    $signing_input = implode('.', $segments);
    $signature = hash_hmac('sha256', $signing_input, $key, true);
    $segments[] = base64url_encode($signature);
    return implode('.', $segments);
}

function verify_jwt($jwt) {
    $key = 'your_secret_key';
    $tks = explode('.', $jwt);
    if (count($tks) != 3) return false;
    list($headb64, $bodyb64, $cryptob64) = $tks;
    $header = json_decode(base64url_decode($headb64), true);
    $payload = json_decode(base64url_decode($bodyb64), true);
    $sig = base64url_decode($cryptob64);
    $valid_sig = hash_hmac('sha256', "$headb64.$bodyb64", $key, true);
    if (!hash_equals($valid_sig, $sig)) return false;
    if (isset($payload['exp']) && time() > $payload['exp']) return false;
    return isset($payload['uid']) ? $payload['uid'] : false;
}
?>