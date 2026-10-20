<?php
return [
    ['title' => 'API 密钥', 'name' => 'api_key', 'type' => 'password', 'required' => true],
    ['title' => '商户编号', 'name' => 'merchant_id', 'type' => 'input', 'required' => true],
    ['title' => '每 1 CNY 折合 USD', 'name' => 'usd_rate', 'type' => 'input', 'required' => true],
    ['title' => '私有存储目录（绝对路径）', 'name' => 'storage_dir', 'type' => 'input', 'required' => true],
    ['title' => '强制 HTTPS', 'name' => 'force_https', 'type' => 'switch', 'default' => 1],
];
