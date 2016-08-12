<?php
namespace Server\Process;

use Server\Encryption\Encryptor;
use Server\Events\Event;
use Server\Connections\Connection;
use Server\Connections\RemoteConnection;

/**
 * 子进程
 * @package Server\Process
 */
class Sub
{
    /**
     * socket host
     * @var string
     */
    public $local_socket_host = "tcp://0.0.0.0";

    /**
     * 加密方法
     * @var string
     */
    public $encrypt_method = 'aes-256-cfb';

    /**
     * 监听端口
     * @var int
     */
    protected $port;

    /**
     * 加密密码
     * @var string
     */
    protected $encrypt_password;

    /**
     * socket上下文
     * @var null
     */
    protected $context;

    /**
     * 当前进程的连接
     * @var array
     */
    public $connections = [];

    /**
     * 保存socket
     * @var resource
     */
    protected $socket;

    /**
     * 保存事件
     * @var object
     */
    public $event;

    // 请求地址类型
    const ADDRTYPE_IPV4 = 1;
    const ADDRTYPE_IPV6 = 4;
    const ADDRTYPE_HOST = 3;

    /**
     * Sub constructor.
     * @param $port
     * @param $encrypt_password
     */
    public function __construct($port, $encrypt_password)
    {
        // 保存监听端口
        $this->port = $port;
        // 保存加密密码
        $this->encrypt_password = $encrypt_password;
        // 设置socket上下文
        $this->context = $this->setContext();
        // 初始化事件
        $this->event = new Event();
    }

    /**
     * 监听
     */
    public function listen()
    {
        $local_socket = $this->local_socket_host . ':' . $this->port;

        // 监听
        $this->socket = stream_socket_server($local_socket, $errno, $errmsg, STREAM_SERVER_BIND | STREAM_SERVER_LISTEN, $this->context);
        if (! $this->socket) {
            throw new \Exception($errmsg);
        }

        // 尝试开启长连接 并关闭 Nagle 算法
        if (function_exists('socket_import_stream')) {
            $socket = socket_import_stream($this->socket);
            @socket_set_option($socket, SOL_SOCKET, SO_KEEPALIVE, 1);
            @socket_set_option($socket, SOL_TCP, TCP_NODELAY, 1);
        }

        // 设置非阻塞
        stream_set_blocking($this->socket, 0);

        // 添加事件监听
        $this->event->add($this->socket, Event::EV_READ, [$this, 'acceptConnection']);
    }

    /**
     * 接收连接
     * @param $socket
     */
    public function acceptConnection($socket)
    {
        $new_socket = @stream_socket_accept($socket, 0, $remote_address);
        if (!$new_socket) {
            return;
        }

        //实例化连接
        $connection = new Connection($new_socket, $remote_address, $this);
        $this->connections[$connection->id] = $connection;
        $connection->encryptor = new Encryptor($this->encrypt_password,$this->encrypt_method);
        // 设置当前连接的状态为init，初始状态
        $connection->stage = 'init';
        $connection->onMessage = $this->onMessage();
    }

    /**
     * 接收到客户端消息
     * @return \Closure
     */
    public function onMessage()
    {
        return function($connection, $buffer){
            if ($connection->stage == 'init') {
                // 先解密数据
                $buffer = $connection->encryptor->decrypt($buffer);
                // 解析socket5头
                $header_data = $connection->worker->parse_socket5_header($buffer);
                // 头部长度
                $header_len = $header_data[3];
                // 解析头部出错，则关闭连接
                if(!$header_data)
                {
                    $connection->close();
                    return;
                }
                // 解析得到实际请求地址及端口
                $host = $header_data[1];
                $port = $header_data[2];
                $address = "tcp://$host:$port";
                // 异步建立与实际服务器的远程连接
                $remote_connection = new RemoteConnection($address);
                $remote_connection->worker = $connection->worker;
                $connection->opposite = $remote_connection;
                $remote_connection->opposite = $connection;
                // 流量控制，远程连接的发送缓冲区满，则停止读取shadowsocks客户端发来的数据
                // 避免由于读取速度大于发送速导致发送缓冲区爆掉
                $remote_connection->onBufferFull = function($remote_connection)
                {
                    $remote_connection->opposite->pauseRecv();
                };
                // 流量控制，远程连接的发送缓冲区发送完毕后，则恢复读取shadowsocks客户端发来的数据
                $remote_connection->onBufferDrain = function($remote_connection)
                {
                    $remote_connection->opposite->resumeRecv();
                };
                // 远程连接发来消息时，进行加密，转发给shadowsocks客户端，shadowsocks客户端会解密转发给浏览器
                $remote_connection->onMessage = function($remote_connection, $buffer)
                {
                    $remote_connection->opposite->send($remote_connection->opposite->encryptor->encrypt($buffer));
                };
                // 远程连接断开时，则断开shadowsocks客户端的连接
                $remote_connection->onClose = function($remote_connection)
                {
                    // 关闭对端
                    $remote_connection->opposite->close();
                    $remote_connection->opposite = null;
                };
                // 远程连接发生错误时（一般是建立连接失败错误），关闭shadowsocks客户端的连接
                $remote_connection->onError = function($remote_connection, $msg)use($address)
                {
                    echo "remote_connection $address error msg:$msg\n";
                    $remote_connection->close();
                    if(!empty($remote_connection->opposite))
                    {
                        $remote_connection->opposite->close();
                    }
                };
                // 流量控制，shadowsocks客户端的连接发送缓冲区满时，则停止读取远程服务端的数据
                // 避免由于读取速度大于发送速导致发送缓冲区爆掉
                $connection->onBufferFull = function($connection)
                {
                    $connection->opposite->pauseRecv();
                };
                // 流量控制，当shadowsocks客户端的连接发送缓冲区发送完毕后，继续读取远程服务端的数据
                $connection->onBufferDrain = function($connection)
                {
                    $connection->opposite->resumeRecv();
                };
                // 当shadowsocks客户端发来数据时，解密数据，并发给远程服务端
                $connection->onMessage = function($connection, $data)
                {
                    $connection->opposite->send($connection->encryptor->decrypt($data));
                };
                // 当shadowsocks客户端关闭连接时，关闭远程服务端的连接
                $connection->onClose = function($connection)
                {
                    $connection->opposite->close();
                    $connection->opposite = null;
                };
                // 当shadowsocks客户端连接上有错误时，关闭远程服务端连接
                $connection->onError = function($connection, $msg)
                {
                    echo "connection err msg:$msg\n";
                    $connection->close();
                    if(isset($connection->opposite))
                    {
                        $connection->opposite->close();
                    }
                };
                // 执行远程连接
                $remote_connection->connect();
                // 改变当前连接的状态为STAGE_STREAM，即开始转发数据流
                $connection->state = 'stream';
                // shadowsocks客户端第一次发来的数据超过头部，则要把头部后面的数据发给远程服务端
                if(strlen($buffer) > $header_len)
                {
                    $remote_connection->send(substr($buffer,$header_len));
                }
            }
        };
    }

    /**
     * 解析socket5头
     * @param $buffer
     * @return array|bool
     */
    protected function parse_socket5_header($buffer)
    {
        $addr_type = ord($buffer[0]);
        switch($addr_type)
        {
            case self::ADDRTYPE_IPV4:
                $dest_addr = ord($buffer[1]).'.'.ord($buffer[2]).'.'.ord($buffer[3]).'.'.ord($buffer[4]);
                $port_data = unpack('n', substr($buffer, 5, 2));
                $dest_port = $port_data[1];
                $header_length = 7;
                break;
            case self::ADDRTYPE_HOST:
                $addrlen = ord($buffer[1]);
                $dest_addr = substr($buffer, 2, $addrlen);
                $port_data = unpack('n', substr($buffer, 2 + $addrlen, 2));
                $dest_port = $port_data[1];
                $header_length = $addrlen + 4;
                break;
            case self::ADDRTYPE_IPV6:
                echo "todo ipv6 not support yet\n";
                return false;
            default:
                echo "unsupported addrtype $addr_type\n";
                return false;
        }
        return array($addr_type, $dest_addr, $dest_port, $header_length);
    }

    /**
     * 设置socket上下文
     * @return resource
     */
    protected function setContext()
    {
        $context_option['socket']['backlog'] = 1024;
        return stream_context_create($context_option);
    }
}