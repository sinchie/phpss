<?php
namespace Server\Events;

class Event
{
    /**
     * 读事件
     * @var int
     */
    const EV_READ = 1;

    /**
     * 写事件
     * @var int
     */
    const EV_WRITE = 2;

    /**
     * 保存主事件
     * @var object
     */
    protected $event_base;

    /**
     * 保存读事件
     * @var array
     */
    protected $read_events = [];

    /**
     * 保存写事件
     * @var array
     */
    protected $write_events = [];

    /**
     * Event constructor.
     */
    public function __construct()
    {
        $this->event_base = new \EventBase();
    }

    /**
     * 添加事件
     * @param $fd
     * @param $flag
     * @param $func
     * @param null $args
     * @return bool
     */
    public function add($fd, $flag, $func, $args = null)
    {
        switch($flag) {
            case self::EV_READ:
                $fd_key = (int)$fd;
                $real_flag = \Event::READ | \Event::PERSIST;
                $event = new \Event($this->event_base, $fd, $real_flag, $func, $fd);
                if (!$event||!$event->add()) {
                    return false;
                }
                $this->read_events[$fd_key] = $event;
                return true;
            case self::EV_WRITE:
                $fd_key = (int)$fd;
                $real_flag = \Event::WRITE | \Event::PERSIST;
                $event = new \Event($this->event_base, $fd, $real_flag, $func, $fd);
                if (!$event||!$event->add()) {
                    return false;
                }
                $this->write_events[$fd_key] = $event;
                return true;
        }
    }

    public function del($fd, $flag)
    {
        switch ($flag) {
            case self::EV_READ:
                $fd_key = (int)$fd;
                if (isset($this->read_events[$fd_key])) {
                    $this->read_events[$fd_key]->del();
                    unset($this->read_events[$fd_key]);
                }
                break;
            case self::EV_WRITE:
                $fd_key = (int)$fd;
                if (isset($this->write_events[$fd_key])) {
                    $this->write_events[$fd_key]->del();
                    unset($this->write_events[$fd_key]);
                }
                break;
        }

        return true;
    }

    public function loop()
    {
        $this->event_base->loop();
    }
}