<?php
require_once __DIR__ . "/vendor/autoload.php";

//设置客户端连接信息
$client_config = [
    ['port' => 50005, 'password' => '123'],
    ['port' => 60005, 'password' => '123']
];

//启动服务
$server = new \Server\Process\Master($client_config);
$server->start();