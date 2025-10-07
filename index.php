<?php
$uri = $_SERVER['REQUEST_URI'];
if (strpos($uri, '/auth') !== false) require 'api/auth.php';
elseif (strpos($uri, '/invoices') !== false) require 'api/invoices.php';
elseif (strpos($uri, '/houses') !== false) require 'api/houses.php';
// ...other routes...
else { http_response_code(404); echo '{"status":"error","message":"Not found"}'; }
?>