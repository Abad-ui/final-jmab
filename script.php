<?php
$timestamp = time();
$payload = '{
  "data": {
    "id": "evt_123456789",
    "type": "event",
    "attributes": {
      "type": "payment.failed",
      "livemode": false,
      "data": {
        "id": "pay_qLT9m4kV6CZtczfMBz147YgG",
        "type": "payment",
        "attributes": {
          "amount": 10000,
          "currency": "PHP",
          "description": "Order Payment",
          "status": "failed",
          "reference_number": "order_67c6a53d6361b",
          "paid_at": 1698765432
        }
      },
      "previous_data": {},
      "created_at": 1698765432,
      "updated_at": 1698765432
    }
  }
}';
$webhookSecret = 'whsk_LpK3Shz3HY9QYhitp4G1DR5M';
$dataToSign = $timestamp . '.' . $payload;
$signature = hash_hmac('sha256', $dataToSign, $webhookSecret);
$signatureHeader = "t=$timestamp,te=$signature";
echo $signatureHeader;
?>