# php shadowsocks多用户服务端

### 初始化
    composer update

### 设置客户端连接端口和密码
    // 编辑boot.php
    // 设置客户端连接信息
    $client_config = [
        ['port' => 50005, 'password' => '123'],
        ['port' => 60005, 'password' => '123']
    ];

### 开启服务
    php boot.php start
    
### 关闭服务    
    php boot.php stop
