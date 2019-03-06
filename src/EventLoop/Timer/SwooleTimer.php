<?php
namespace Lj\Amqp\EventLoop\Timer;
use React\EventLoop\TimerInterface;

class SwooleTimer implements TimerInterface
{
    const MIN_INTERVAL = 1;
    
    /**
     * timer id
     * @var int
     */
    private $id;
    
    private $interval;
    private $callback;
    private $periodic;
    
    /**
     * Constructor initializes the fields of the Timer
     *
     * @param float         $interval The interval after which this timer will execute, in seconds
     * @param callable      $callback The callback that will be executed when this timer elapses
     * @param bool          $periodic Whether the time is periodic
     */
    public function __construct($interval, $callback, $periodic = false)
    {
        if ($interval < self::MIN_INTERVAL) {
            $interval = self::MIN_INTERVAL;
        }
        
        $this->interval = (float) $interval;
        $this->callback = $callback;
        $this->periodic = (bool) $periodic;
    }
    
    public function getInterval()
    {
        return $this->interval;
    }
    
    public function getCallback()
    {
        return $this->callback;
    }
    
    public function isPeriodic()
    {
        return $this->periodic;
    }
    
    public function getId(): int
    {
        return $this->id;
    }
    
    public function setId(int $id): self
    {
        $this->id = $id;
        return $this;
    }
}

