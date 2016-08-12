<?php
namespace Server\Connections;

use Server\Events\Event;

class Connection
{
    /**
     * 连接状态 连接中
     * @var int
     */
    const STATUS_CONNECTING = 1;

    /**
     * 连接状态 已经建立连接
     * @var int
     */
    const STATUS_ESTABLISH = 2;

    /**
     * 连接状态 连接关闭中，标识调用了close方法，但是发送缓冲区中任然有数据
     * 等待发送缓冲区的数据发送完毕（写入到socket写缓冲区）后执行关闭
     * @var int
     */
    const STATUS_CLOSING = 4;

    /**
     * 连接状态 已经关闭
     * @var int
     */
    const STATUS_CLOSED = 8;

    /**
     * 当前连接状态
     * @var int
     */
    protected $status = self::STATUS_ESTABLISH;

    /**
     * 统计信息
     * @var array
     */
    public static $statistics = [
        'connection_count' => 0,
        'data_usage' => 0,
    ];

    /**
     * id计数器
     * @var int
     */
    public static $id_recorder = 0;

    /**
     * 当前连接id
     * @var int
     */
    public $id = 0;

    /**
     * 保存socket
     * @var resource
     */
    protected $socket;

    /**
     * 所属进程
     * @var object
     */
    public $worker;

    /**
     * 发送缓冲区大小(byte)
     * @var int
     */
    protected $maxSendBufferSize = 1048576;

    /**
     * 当数据可读时，从socket缓冲区读取多少数据(byte)
     * @var int
     */
    protected $readBufferSize = 65535;

    /**
     * 接收缓冲区
     * @var string
     */
    protected $recv_buffer = '';

    /**
     * 发送缓冲区
     * @var string
     */
    protected $send_buffer = '';

    /**
     * 是否是停止接收数据
     * @var bool
     */
    protected $isPaused = false;

    /**
     * 有数据来时回调
     * @var callable
     */
    public $onMessage;

    /**
     * 发送缓冲区空时回调
     * @var callable
     */
    public $onBufferDrain;

    /**
     * 发送缓冲区满时回调
     * @var callable
     */
    public $onBufferFull;

    /**
     * 连接关闭时回调
     * @var callable
     */
    public $onClose;

    /**
     * 连接发生错误时回调
     * @var callable
     */
    public $onError;

    /**
     * 加密解密类
     * @var object
     */
    public $encryptor;

    /**
     * Connection constructor.
     * @param $socket
     * @param string $remote_address
     * @param $worker
     */
    public function __construct($socket, $remote_address = '', $worker)
    {
        self::$statistics['connection_count']++;
        self::$id_recorder++;
        $this->id = self::$id_recorder;
        $this->socket = $socket;
        $this->worker = $worker;
        stream_set_blocking($this->socket, 0);
        $this->worker->event->add($this->socket, Event::EV_READ, [$this, 'onRead']);
    }

    /**
     * socket有可读数据时触发
     * @param $socket
     * @param bool $check_eof
     */
    public function onRead($socket, $check_eof = true)
    {
        $buffer = fread($socket, $this->readBufferSize);

        // 检查连接是否关闭
        if ($buffer === '' || $buffer === false) {
            if ($check_eof && (feof($socket) || !is_resource($socket) || $buffer === false)) {
                $this->destroy();
                return;
            }
        } else {
            $this->recv_buffer .= $buffer;
        }

        // 读取数据为空或者设置了暂停 则返回
        if ($this->recv_buffer === '' || $this->isPaused) {
            return;
        }

        // 调用回调
        if (!$this->onMessage) {
            $this->recv_buffer = '';
            return;
        }
        try {
            call_user_func($this->onMessage, $this, $this->recv_buffer);
        } catch (\Exception $e) {
            exit(250);
        } catch (\Error $e) {
            exit(250);
        }
        // 清空接收缓冲区
        $this->recv_buffer = '';
    }

    /**
     * 销毁连接
     * @return bool
     */
    protected function destroy()
    {
        // 避免重复调用
        if($this->status === self::STATUS_CLOSED)
        {
            return false;
        }
        // 删除事件监听
        $this->worker->event->del($this->socket, Event::EV_READ);
        $this->worker->event->del($this->socket, Event::EV_WRITE);
        // 关闭socket
        @fclose($this->socket);
        // 从连接中删除
        if($this->worker)
        {
            unset($this->worker->connections[$this->id]);
        }
        // 标记该连接已经关闭
        $this->status = self::STATUS_CLOSED;

        if ($this->onClose) {
            try {
                call_user_func($this->onClose, $this);
            } catch (\Exception $e) {
                exit(250);
            } catch (\Error $e) {
                exit(250);
            }
        }

        $this->onMessage = $this->onError = $this->onClose = $this->onBufferFull = $this->onBufferDrain = null;
    }

    /**
     * 发送数据
     * @param $buffer
     * @return bool|null
     */
    public function send($buffer)
    {
        // 如果当前状态是连接中，则把数据放入发送缓冲区
        if ($this->status === self::STATUS_CONNECTING) {
            $this->send_buffer .= $buffer;
            return null;
        } elseif ($this->status === self::STATUS_CLOSING || $this->status === self::STATUS_CLOSED) {
            // 如果当前连接是关闭，则返回false
            return false;
        }

        // 如果发送缓冲区为空，尝试直接发送
        if ($this->send_buffer === '') {
            // 直接发送
            $len = @fwrite($this->socket, $buffer);
            // 所有数据都发送完毕
            if ($len === strlen($buffer)) {
                return true;
            }

            // 只有部分数据发送成功
            if ($len > 0) {
                // 未发送成功部分放入发送缓冲区
                $this->send_buffer = substr($buffer, $len);
            } else {
                // 如果连接断开
                if(!is_resource($this->socket) || feof($this->socket)) {
                    if ($this->onError) {
                        try {
                            call_user_func($this->onError, $this, 'client closed');
                        } catch (\Exception $e) {
                            exit(250);
                        } catch (\Error $e) {
                            exit(250);
                        }
                    }
                    // 销毁连接
                    $this->destroy();
                    return false;
                }
                // 连接未断开，发送失败，则把所有数据放入发送缓冲区
                $this->send_buffer = $buffer;
            }
            // 监听对端可写事件
            $this->worker->event->add($this->socket, Event::EV_WRITE, array($this, 'onWrite'));
            // 检查发送缓冲区是否已满，如果满了尝试触发onBufferFull回调
            $this->checkBufferIsFull();
            return null;
        } else {
            // 缓冲区已经标记为满，仍然然有数据发送，则丢弃数据包
            if($this->maxSendBufferSize <= strlen($this->send_buffer)) {
                if ($this->onError) {
                    try {
                        call_user_func($this->onError, $this, 'send buffer full and drop package');
                    } catch (\Exception $e) {
                        exit(250);
                    } catch (\Error $e) {
                        exit(250);
                    }
                }
                return false;
            }
            // 将数据放入放缓冲区
            $this->send_buffer .= $buffer;
            // 检查发送缓冲区是否已满，如果满了尝试触发onBufferFull回调
            $this->checkBufferIsFull();
        }
    }

    /**
     * socket可写时的回调
     * @return void
     */
    public function onWrite()
    {
        $len = @fwrite($this->socket, $this->send_buffer);
        if ($len === strlen($this->send_buffer)) {
            $this->worker->event->del($this->socket, Event::EV_WRITE);
            $this->send_buffer = '';
            // 发送缓冲区的数据被发送完毕，尝试触发onBufferDrain回调
            if ($this->onBufferDrain) {
                try {
                    call_user_func($this->onBufferDrain, $this);
                }
                catch(\Exception $e) {
                    echo $e;
                    exit(250);
                }
            }
            // 如果连接状态为关闭，则销毁连接
            if($this->status === self::STATUS_CLOSING) {
                $this->destroy();
            }
            return true;
        }
        if($len > 0) {
            $this->send_buffer = substr($this->send_buffer, $len);
        } else {
            // 发送失败，说明连接断开
            $this->destroy();
        }
    }

    /**
     * 检查发送缓冲区是否满了
     */
    protected function checkBufferIsFull()
    {
        if ($this->maxSendBufferSize <= strlen($this->send_buffer)) {
            if($this->onBufferFull) {
                try {
                    call_user_func($this->onBufferFull, $this);
                } catch(\Exception $e) {
                    echo $e;
                    exit(250);
                }
            }
        }
    }

    /**
     * 关闭连接
     * @param null $data
     * @return bool
     */
    public function close($data = null)
    {
        if ($this->status === self::STATUS_CLOSING || $this->status === self::STATUS_CLOSED) {
            return false;
        } else {
            if($data !== null)
            {
                $this->send($data);
            }
            $this->status = self::STATUS_CLOSING;
        }
        if ($this->send_buffer === '') {
            $this->destroy();
        }
    }

    /**
     * 暂停接收数据
     * @return void
     */
    public function pauseRecv()
    {
        $this->worker->event->del($this->socket, Event::EV_READ);
        $this->isPaused = true;
    }

    /**
     * 恢复暂停接收数据
     * @return void
     */
    public function resumeRecv()
    {
        if ($this->isPaused === true) {
            $this->worker->event->add($this->socket, Event::EV_READ, array($this, 'onRead'));
            $this->isPaused = false;
            $this->onRead($this->socket, false);
        }
    }

    /**
     * 析构
     * @return void
     */
    public function __destruct()
    {
        self::$statistics['connection_count']--;
    }
}