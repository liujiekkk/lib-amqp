<?php
namespace Lj\Amqp\IO;

use Lj\Amqp\Lib\MiscHelper;
use Lj\Amqp\Exception\IOException;

class SocketIO extends AbstractIO
{
    /**
     * 
     * @var float
     */
    protected $readTimeout;
    
    /**
     * 
     * @var float
     */
    protected $writeTimeout;
    
    /**
     * 
     * @param string $host
     * @param int $port
     * @param float $readTimeout
     * @param float $writeTimeout
     * @param bool $keepalive
     */
    public function __construct(string $host, int $port, float $readTimeout, float $writeTimeout = null, bool $keepalive = false)
    {
        $this->host = $host;
        $this->port = $port;
        $this->readTimeout = $readTimeout;
        $this->writeTimeout = $writeTimeout ?: $readTimeout;
        $this->keepalive = $keepalive;
    }

    /**
     * Sets up the socket connection
     *
     * @throws \Exception
     */
    public function connect(): void
    {
        $this->socket = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);

        list($sec, $uSec) = MiscHelper::splitSecondsMicroseconds($this->writeTimeout);
        socket_set_option($this->socket, SOL_SOCKET, SO_SNDTIMEO, array('sec' => $sec, 'usec' => $uSec));
        list($sec, $uSec) = MiscHelper::splitSecondsMicroseconds($this->readTimeout);
        socket_set_option($this->socket, SOL_SOCKET, SO_RCVTIMEO, array('sec' => $sec, 'usec' => $uSec));

        if (!socket_connect($this->socket, $this->host, $this->port)) {
            $errno = socket_last_error($this->socket);
            $errstr = socket_strerror($errno);
            throw new IOException(sprintf(
                'Error Connecting to server (%s): %s',
                $errno,
                $errstr
            ), $errno);
        }

        socket_set_block($this->socket);
        socket_set_option($this->socket, SOL_TCP, TCP_NODELAY, 1);

        if ($this->keepalive) {
            $this->keepalive();
        }
    }

    /**
     * The socket
     * @return resource | null
     */
    public function getSocket()
    {
        return $this->socket;
    }

    /**
     * Reconnects the socket
     */
    public function reconnect(): void
    {
        $this->close();
        $this->connect();
    }

    /**
     * Reads a maximum of length bytes from a socket
     * {@inheritDoc}
     * @see \Lj\Amqp\IO\AbstractIO::read()
     */
    public function read(int $length): string
    {
        if (is_null($this->socket)) {
            throw new IOException(sprintf(
                'Socket was null! Last SocketError was: %s',
                socket_strerror(socket_last_error())
            ));
        }
        $buf = @socket_read($this->socket, $length);
        $this->lastReadTime = microtime(true);
        return $buf;
    }

    /**
     * @param string $data
     * @return void
     *
     * @throws \PhpAmqpLib\Exception\AMQPIOException
     * @throws \PhpAmqpLib\Exception\AMQPSocketException
     */
    public function write(string $str, int $length): int
    {
        if (is_null($this->socket)) {
            throw new IOException(sprintf(
                'Socket was null! Last SocketError was: %s',
                socket_strerror(socket_last_error())
            ));
        }

        $writen = socket_write($this->socket, $str, $length);
        if ($writen === false) {
            throw new IOException(sprintf(
                'Error sending data. Last SocketError: %s',
                socket_strerror(socket_last_error())
            ));
        }
        $this->lastWriteTime = microtime(true);
        return $writen;
    }

    public function close(): void
    {
        if (is_resource($this->socket)) {
            socket_close($this->socket);
        }
        $this->socket = null;
        $this->lastReadTime = null;
        $this->lastWriteTime = null;
    }

    /**
     * 
     * @throws IOException
     */
    protected function keepalive(): void
    {
        if (!defined('SOL_SOCKET') || !defined('SO_KEEPALIVE')) {
            throw new IOException('Can not enable keepalive: SOL_SOCKET or SO_KEEPALIVE is not defined');
        }
        socket_set_option($this->socket, SOL_SOCKET, SO_KEEPALIVE, 1);
    }
}
