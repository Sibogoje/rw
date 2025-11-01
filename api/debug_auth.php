<?php
header('Access-Control-Allow-Origin: *');
header('Content-Type: application/json');

require_once '../config/db.php';
require_once '../lib/auth.php';

// Debug the auth system
try {
    // Check if we have authorization header
    $hdr = null;
    if (isset($_SERVER['HTTP_AUTHORIZATION'])) {
        $hdr = $_SERVER['HTTP_AUTHORIZATION'];
    } elseif (function_exists('apache_request_headers')) {
        $all = apache_request_headers();
        if (isset($all['Authorization'])) {
            $hdr = $all['Authorization'];
        }
    }
    
    $result = array(
        "auth_header_found" => $hdr ? true : false,
        "auth_header_value" => $hdr ? substr($hdr, 0, 30) . "..." : null
    );
    
    if ($hdr) {
        // Extract token
        $token = stripos($hdr, 'Bearer ') === 0 ? trim(substr($hdr, 7)) : $hdr;
        $result["token_extracted"] = substr($token, 0, 30) . "...";
        
        // Verify JWT
        $uid = verify_jwt($token);
        $result["jwt_verification"] = $uid ? "success" : "failed";
        $result["user_id"] = $uid;
    }
    
    echo json_encode($result);
    
} catch(Exception $e) {
    echo json_encode(array(
        "status" => "error",
        "message" => $e->getMessage()
    ));
}
?>