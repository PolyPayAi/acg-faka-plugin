<?php
use App\Consts\Pay;
return [
    'name' => 'PolyPay', 'author' => 'PolyPay', 'version' => '0.2.0',
    'link' => 'https://polypay.ai', 'description' => 'PolyPay Hosted Checkout',
    'options' => ['PolyPay' => 'PolyPay'],
    'callback' => [Pay::IS_SIGN => true, Pay::IS_STATUS => true, Pay::FIELD_STATUS_KEY => 'paid',
        Pay::FIELD_STATUS_VALUE => 'yes', Pay::FIELD_ORDER_KEY => 'order_no',
        Pay::FIELD_AMOUNT_KEY => 'amount', Pay::FIELD_RESPONSE => 'OK'],
];
