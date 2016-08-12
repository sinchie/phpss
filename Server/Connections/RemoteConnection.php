<?php
namespace Server\Connections;

use Server\Events\Event;

class RemoteConnection extends Connection
{
    /**
     * 当连接成功时，如果设置了连接成功回调，则执行
     * @var callback
     */
    public $onConnect;

    /**
     * 连接状态
     * @var int
     */
    protected $status = self::STATUS_CONNECTING;

    /**
     * 远程地址
     * @var string
     */
    protected $remote_address;

    /**
     * 构造函数，创建连接
     * @param $remote_address
     */
    public function __construct($remote_address)
    {
        list(, $address) = explode(':', $remote_address, 2);
        $this->remote_address = substr($address, 2);
        $this->id = self::$id_recorder++;
        // 统计数据
        self::$statistics['connection_count']++;
    }

    /**
     * 创建异步连接
     */
    public function connect()
    {
        // 创建异步连接
        $this->socket = stream_socket_client("tcp://{$this->remote_address}", $errno, $errstr, 0, STREAM_CLIENT_ASYNC_CONNECT);
        // 如果失败
        if(!$this->socket) {
            $this->status = self::STATUS_CLOSED;
            if($this->onError) {
                try {
                    call_user_func($this->onError, $this, $errstr);
                } catch(\Exception $e) {
                    echo $e;
                    exit(250);
                }
            }
            return;
        }
        // 监听连接可写事件（可写意味着连接已经建立或者已经出错）
        $this->worker->event->add($this->socket, Event::EV_WRITE, array($this, 'checkConnection'));
    }

    /**
     * 检查连接状态，连接成功还是失败
     * @param resource $socket
     * @return void
     */
    public function checkConnection($socket)
    {
        // 判断两次连接是否已经断开
        if(!feof($this->socket) && !feof($this->socket) && is_resource($this->socket)) {
            // 删除连接可写监听
            $this->worker->event->del($this->socket, Event::EV_WRITE);
            // 设置非阻塞
            stream_set_blocking($this->socket, 0);
            // 监听可读事件
            $this->worker->event->add($this->socket, Event::EV_READ, array($this, 'onRead'));
            // 如果发送缓冲区有数据则执行发送
            if ($this->send_buffer) {
                $this->worker->event->add($this->socket, Event::EV_WRITE, array($this, 'onWrite'));
            }
            // 标记状态为连接已经建立
            $this->status = self::STATUS_ESTABLISH;
            // 获得远端实际ip端口
            $this->remote_address = stream_socket_get_name($this->socket, true);
            // 如果有设置onConnect回调，则执行
            if ($this->onConnect) {
                try {
                    call_user_func($this->onConnect, $this);
                } catch(\Exception $e) {
                    echo $e;
                    exit(250);
                }
            }
        } else {
            // 连接未建立成功
            if($this->onError) {
                try {
                    call_user_func($this->onError, $this, 'connect fail');
                } catch(\Exception $e) {
                    echo $e;
                    exit(250);
                }
            }
            $this->destroy();
            // 清理onConnect回调
            $this->onConnect = null;
        }
    }
}