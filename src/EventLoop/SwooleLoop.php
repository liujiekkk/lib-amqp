<?php
namespace Lj\Amqp\EventLoop;
use React\EventLoop\LoopInterface;
use React\EventLoop\Timer\Timer;
use Lj\Amqp\EventLoop\Timer\SwooleTimer;

class SwooleLoop implements LoopInterface
{
    private $streams = [];
    private $readEvents = [];
    private $writeEvents = [];
    private $timers = [];
    private $periodTimers = [];
    protected static $num=0;
    
    /**
     * {@inheritDoc}
     * @see \React\EventLoop\LoopInterface::addReadStream()
     */
    public function addReadStream($stream, $listener)
    {
        $key = (int) $stream;
        if (isset($this->readEvents[$key])) {
            return;
        }
        $callback = function () use ($stream, $listener) {
            \call_user_func($listener, $stream);
        };
        
        $this->readEvents[$key] = $callback;
        if ( isset($this->streams[$key]) ) {
            swoole_event_set($stream, $callback, null, null);
        } else {
            $this->streams[$key] = $key;
            swoole_event_add($stream, $callback, null, null);
        }
    }

    /**
     * {@inheritDoc}
     * @see \React\EventLoop\LoopInterface::addWriteStream()
     */
    public function addWriteStream($stream, $listener)
    {
        $key = (int) $stream;
        if (isset($this->writeEvents[$key])) {
            return;
        }
        
        $callback = function () use ($stream, $listener) {
            \call_user_func($listener, $stream);
        };
        
        $this->writeEvents[$key] = $callback;
        if ( isset($this->streams[$key]) ) {
            swoole_event_set($stream, null, $callback, SWOOLE_EVENT_WRITE);
        } else {
            $this->streams[$key] = $key;
            swoole_event_add($stream, null, $callback, SWOOLE_EVENT_WRITE | SWOOLE_EVENT_READ);
        }
    }

    /**
     * {@inheritDoc}
     * @see \React\EventLoop\LoopInterface::removeReadStream()
     */
    public function removeReadStream($stream)
    {
        $key = (int) $stream;
        if ( isset($this->readEvents[$key]) ) {
            unset($this->readEvents[$key]);
            swoole_event_del($stream);
            if ( isset($this->writeEvents[$key]) ) {
                swoole_event_set($stream, null, $this->writeEvents[$key], SWOOLE_EVENT_WRITE);
            } else {
                unset($this->streams[$key]);
            }
        }
    }

    /**
     * {@inheritDoc}
     * @see \React\EventLoop\LoopInterface::removeWriteStream()
     */
    public function removeWriteStream($stream)
    {
        $key = (int) $stream;
        if ( isset($this->writeEvents[$key]) ) {
            unset($this->writeEvents[$key]);
            swoole_event_del($stream);
            if ( isset($this->readEvents[$key]) ) {
                swoole_event_add($stream, $this->readEvents[$key], null, SWOOLE_EVENT_READ);
            } else {
                unset($this->streams[$key]);
            }
        }
    }

    /**
     * {@inheritDoc}
     * @see \React\EventLoop\LoopInterface::addTimer()
     */
    public function addTimer($interval, $callback)
    {
        $timer = new SwooleTimer($interval, $callback, false);
        $callback = function () use ($timer) {
            \call_user_func($timer->getCallback(), $timer);
        };
        $id = swoole_timer_after($timer->getInterval() * 1000, $callback);
        $timer->setId($id);
        return $timer;
    }

    /**
     * {@inheritDoc}
     * @see \React\EventLoop\LoopInterface::addPeriodicTimer()
     */
    public function addPeriodicTimer($interval, $callback)
    {
        $timer = new SwooleTimer($interval, $callback, true);
        $callback = function () use ($timer) {
            \call_user_func($timer->getCallback(), $timer);
        };
        $id = swoole_timer_tick($timer->getInterval() * 1000, $callback);
        $timer->setId($id);
        return $timer;
    }

    /**
     * {@inheritDoc}
     * @see \React\EventLoop\LoopInterface::cancelTimer()
     */
    public function cancelTimer(\React\EventLoop\TimerInterface $timer)
    {
        // TODO Auto-generated method stub
        if ( $timer instanceof SwooleTimer ) {
            swoole_timer_clear($timer->getId());
        }
    }

    /**
     * {@inheritDoc}
     * @see \React\EventLoop\LoopInterface::futureTick()
     */
    public function futureTick($listener)
    {
        // TODO Auto-generated method stub
        
    }

    /**
     * {@inheritDoc}
     * @see \React\EventLoop\LoopInterface::addSignal()
     */
    public function addSignal($signal, $listener)
    {
        // TODO Auto-generated method stub
        
    }

    /**
     * {@inheritDoc}
     * @see \React\EventLoop\LoopInterface::removeSignal()
     */
    public function removeSignal($signal, $listener)
    {
        // TODO Auto-generated method stub
        
    }

    /**
     * {@inheritDoc}
     * @see \React\EventLoop\LoopInterface::run()
     */
    public function run(bool $wait=false)
    {
        if ( $wait ) {
            swoole_event_wait();
        }
    }

    /**
     * {@inheritDoc}
     * @see \React\EventLoop\LoopInterface::stop()
     */
    public function stop()
    {
        swoole_event_exit();
    }
}

