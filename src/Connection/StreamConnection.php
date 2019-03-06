<?php
namespace Lj\Amqp\Connection;

use Lj\Amqp\Connection\AbstractConnection;
use Lj\Amqp\IO\StreamIO;
use React\EventLoop\LoopInterface;

class StreamConnection extends AbstractConnection
{
    
    public function __construct(LoopInterface $loop, string $host, int $port, string $user, string $pass, string $vhost, int $connTimeout, int $rwTimeout)
    {
        $this->eventLoop = $loop;
        // paraent construct init
        $this->init();
        // init io, enable keepalive
        $this->io = new StreamIO($host, $port, $connTimeout, $rwTimeout, true);
        $this->user = $user;
        $this->pass = $pass;
        $this->vhost = $vhost;
    }
}

