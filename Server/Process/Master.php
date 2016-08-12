<?php
namespace Server\Process;

/**
 * 主进程
 * @package Server\Worker
 */
class Master
{
    /**
     * 客户端配置
     * @var array [['port' => 'port1', 'password' => 'password1'], ...]
     */
    protected $client_config;

    /**
     * 最大进程数
     * @var int
     */
    public $max_process_num = 100;

    /**
     * 保存子进程信息
     * @var array
     */
    public static $subs = [];

    /**
     * pid文件
     * @var string
     */
    public $pid_file = '';

    /**
     * 构造
     * @param $client_config
     */
    public function __construct($client_config)
    {
        // 保存多端口配置
        $this->client_config = $client_config;
        // pid文件
        $this->pid_file = __DIR__ . '/../phpss.pid';
    }

    /**
     * 开始运行
     */
    public function start()
    {
        // 解析命令
        $this->parseCommand();
        // 注册信号
        $this->installSignal();
        // 以守护进程形式运行
        $this->deamon();
        // 保存pid文件
        $this->savePidFile();
        // 检查客户端绑定端口能否使用
        $this->checkClientPort();
        // fork进程
        $this->forkAllProcess();
        // 重定向标准输出
        $this->resetStd();
        // 中心控制
        $this->centerControl();
    }

    /**
     * 解析命令
     * php yourfile.php start | stop
     * @return void
     */
    protected function parseCommand()
    {
        global $argv;
        // 检查参数
        if (!isset($argv[1]) || ! in_array($argv[1], ['start','stop'])) {
            exit("Usage: php boot.php {start|stop}\n");
        }

        // 获得命令
        $command  = trim($argv[1]);
        // 获得主进程pid
        $master_pid = @file_get_contents($this->pid_file);
        $master_is_alive = $master_pid && @posix_kill($master_pid, 0);
        // 主进程已经存在
        if ($master_is_alive) {
            if ($command === 'start') {
                exit("phpss already running\n");
            }
        } else {
            if ($command === 'stop') {
                exit("phpss not running\n");
            }
        }
        switch ($command) {
            case "start":
                echo "phpss start success!\n";
                break;
            case "stop":
                unlink($this->pid_file);
                //给主进程发送结束信号
                $master_pid && posix_kill($master_pid, SIGINT);
                //删除发送命令的进程
                echo "stop success!\n";
                posix_kill(getmypid(), SIGINT);
                break;
        }
    }

    protected function savePidFile()
    {
        if (!is_file($this->pid_file)) {
            file_put_contents($this->pid_file, posix_getpid());
        }
    }

    /**
     * 注册信号
     */
    protected function installSignal()
    {
        // stop
        pcntl_signal(SIGINT, [$this, 'signalHandler'], false);
        // ignore
        pcntl_signal(SIGPIPE, SIG_IGN, false);
    }

    /**
     * 信号处理
     * @param $signal
     */
    public function signalHandler($signal)
    {
        switch ($signal) {
            // Stop.
            case SIGINT:
                $this->stopAll();
                break;
        }
    }

    /**
     * 终止程序
     */
    public function stopAll()
    {
        // 结束子进程
        foreach (self::$subs as $pid => $item) {
            posix_kill($pid, SIGKILL);
        }
        // 结束主进程
        exit("stop success!\n");
    }

    /**
     * 重定向标准输出
     */
    protected function resetStd()
    {
        //自定义标准输出和标准错误保存文件
        $stdoutFile = "/tmp/phpss.log";
        //全局的标准输出和标准错误
        global $STDOUT, $STDERR;
        $handle = fopen($stdoutFile,"a");
        if($handle) {
            unset($handle);
            //重新设置标准输出和标准错误的保存位置
            @fclose(STDOUT);
            @fclose(STDERR);
            $STDOUT = fopen($stdoutFile,"a");
            $STDERR = fopen($stdoutFile,"a");
        } else {
            throw new \Exception('can not open stdoutFile ' . $stdoutFile);
        }
    }

    /**
     * 守护进程
     */
    protected function deamon()
    {
        //让fork出的子进程拥有最大权限
        umask(0);

        $pid = pcntl_fork();
        if(-1 === $pid) {
            throw new \Exception('fork fail');
        } elseif ($pid > 0) {
            exit(0);
        }
        if(-1 === posix_setsid()) {
            throw new \Exception("setsid fail");
        }
        // 再次fork 防止终端重新获得控制权
        $pid = pcntl_fork();
        if(-1 === $pid) {
            throw new \Exception("fork fail");
        } elseif (0 !== $pid) {
            exit(0);
        }
    }

    /**
     * fork所有子进程
     */
    protected function forkAllProcess()
    {
        foreach ($this->client_config as $item) {
            $this->forkOneProcess($item['port'], $item['password']);
        }
    }

    /**
     * fork一个子进程
     * @param $port
     * @param $password
     * @throws \Exception
     */
    protected function forkOneProcess($port, $password)
    {
        // 检查进程数
        if(count(self::$subs) >= $this->max_process_num) {
            echo new \Exception('process too many');
            return;
        }

        $pid = pcntl_fork();
        if ($pid > 0) {
            //记录信息
            self::$subs[$pid] = ['port' => $port, 'password' => $password];
        } elseif ($pid === 0) {
            $this->resetStd();
            $sub = new Sub($port, $password);
            $sub->listen();
            $sub->event->loop();
            exit(0);
        } else {
            throw new \Exception('fork fail');
        }
    }

    /**
     * 主控制
     */
    protected function centerControl()
    {
        while(1) {
            pcntl_signal_dispatch();
            //挂起主进程,主进程用来控制子进程
            $status = 0;
            $pid = pcntl_wait($status, WUNTRACED);
            pcntl_signal_dispatch();
            if ($pid > 0) {
                //如果有进程退出,重新补充上
                if (!empty(self::$subs[$pid])) {
                    $this->forkOneProcess(self::$subs[$pid]['port'], self::$subs[$pid]['password']);
                }
            }
        }
    }

    /**
     * 检查客户端端口能否使用
     * @return bool
     * @throws \ErrorException
     */
    protected function checkClientPort()
    {
        foreach ($this->client_config as $item) {
            if (! $this->checkPortCanUse('0.0.0.0', $item['port'],$errno, $errstr)) {
                throw new \ErrorException("{$item['port']} can not use");
            }
        }

        return true;
    }

    /**
     * 检查端口是否可用
     * @param $host
     * @param $port
     * @param null $errno
     * @param null $errstr
     * @return bool
     */
    protected function checkPortCanUse($host, $port, &$errno=null, &$errstr=null)
    {
        $socket = stream_socket_server("tcp://$host:$port", $errno, $errstr);
        if (!$socket) {
            return false;
        }
        fclose($socket);
        unset($socket);
        return true;
    }
}