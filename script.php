<?php
//script.php
$timestamp = time();
$payload = '{
    "data": {
        "id": "evt_test_123",
        "type": "event",
        "attributes": {
            "type": "payment.failed",
            "data": {
                "id": "pay_3J5qwGgGcfZvFfw47ZEcCCc7",
                "type": "payment",
                "attributes": {
                    "amount": 10000,
                    "currency": "PHP",
                    "description": "Test Payment",
                    "status": "fail",
                    "reference_number": "order_67cab7ac93545",
                    "created_at": 1677654321,
                    "updated_at": 1677654321
                }
            },
            "livemode": false,
            "created_at": 1677654321,
            "updated_at": 1677654321
        }
    }
}';
$webhookSecret = 'whsk_Ji7eKUdZEYD4hQhbUYFUu9NL';
$dataToSign = $timestamp . '.' . $payload;
$signature = hash_hmac('sha256', $dataToSign, $webhookSecret);
$signatureHeader = "t=$timestamp,te=$signature";
echo $signatureHeader;
?>