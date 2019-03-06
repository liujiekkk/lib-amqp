<?php
namespace Lj\Amqp\IO;

abstract class AbstractIO
{
    
    /**
     * socket
     * @var resource | null
     */
    protected $socket;
    
    /**
     * connect to host
     * @var string
     */
    protected $host;
    
    /**
     * port
     * @var int
     */
    protected $port;
    
    /**
     * connect timeout
     * @var int
     */
    protected $connTimeout;
    
    /**
     * Last read timestamp
     * @var float
     */
    protected $lastReadTime;
    
    /**
     * Last write timestamp
     * @var float
     */
    protected $lastWriteTime;
    
    /**
     * enable keepalive or not
     * @var bool
     */
    protected $keepalive;
    
    /**
     * Read from a socket
     * @param int $length the max length to read
     * @return string
     */
    abstract public function read(int $length): string;

    /**
     * Write to socket
     * @param string $str The str write in
     * @param int $length The max length to wirte
     * @return int
     */
    abstract public function write(string $str, int $length): int;

    /**
     * Close the socket
     */
    abstract public function close(): void;

    /**
     * Connect to server
     */
    abstract public function connect(): void;

    /**
     * Retry to connect
     */
    abstract public function reconnect(): void;

    /**
     * Return the socket or null
     * @return resouce | null
     */
    abstract public function getSocket();
    
    /**
     * Enable keepalive
     */
    abstract protected function keepalive(): void;
    
    /**
     * get the socket last read micro timestamp
     * @return float
     */
    public function getLastWriteTime(): float 
    {
        return $this->lastWriteTime;
    }
    
    /**
     * Get the socket last write micro timestamp
     * @return float
     */
    public function getLastReadTime(): float 
    {
        return $this->lastReadTime;
    }
}
