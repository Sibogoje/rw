<?php
echo "=== Testing new last_readings API ===\n";

$ch = curl_init();
curl_setopt($ch, CURLOPT_URL, 'https://zenmark.grinpath.com/api/last_readings?house_ids=1474,1434,1412');
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
$response = curl_exec($ch);
$http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

echo "HTTP Code: $http_code\n";
echo "Response: $response\n";

if ($response) {
    $data = json_decode($response, true);
    if ($data && $data['status'] == 'success') {
        echo "\n✅ API working! Last readings:\n";
        foreach ($data['data'] as $reading) {
            echo "House {$reading['house_id']} ({$reading['house_code']}): Water={$reading['water_previous']}, Sewage={$reading['sewage_previous']}, Electricity={$reading['electricity_previous']} (Date: {$reading['water_previous_date']})\n";
        }
    }
}
?>