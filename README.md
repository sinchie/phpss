# php shadowsocks多用户服务端
学习php网络编程，参考 workerman 练习之作

### 依赖Event的扩展
    php -m 如果没有event,请先安装Event扩展

### 初始化
    composer update

### 设置客户端连接端口和密码
    // 编辑boot.php
    // 设置客户端连接信息(默认加密方式为aes-256-cfb)
    $client_config = [
        ['port' => 50005, 'password' => '123'],
        ['port' => 60005, 'password' => '123']
    ];

### 开启服务
    php boot.php start
    
### 关闭服务    
    php boot.php stop
    
