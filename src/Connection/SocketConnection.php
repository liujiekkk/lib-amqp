<?php
namespace Lj\Amqp\Connection;

use Lj\Amqp\Connection\AbstractConnection;
use Lj\Amqp\IO\SocketIO;
use React\EventLoop\LoopInterface;

class SocketConnection extends AbstractConnection
{
    
    public function __construct(LoopInterface $loop, string $host, int $port, string $user, string $pass, string $vhost, int $connTimeout, int $rwTimeout)
    {
        $this->eventLoop = $loop;
        // paraent construct init
        $this->init();
        // init io, enable keepalive
        $this->io = $this->io = new SocketIO($host, $port, $rwTimeout, null, true);
        $this->user = $user;
        $this->pass = $pass;
        $this->vhost = $vhost;
    }
}

