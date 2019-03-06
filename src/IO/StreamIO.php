<?php
namespace Lj\Amqp\IO;

use Lj\Amqp\Exception\IOException;
use Lj\Amqp\Lib\MiscHelper;

class StreamIO extends AbstractIO
{
    /**
     * protocol, default tcp
     * @var string
     */
    protected $protocol = 'tcp';
    
    /**
     * read and write timeout
     * @var int
     */
    protected $readWriteTimeout;
    
    /**
     * resource context
     * @var resource
     */
    protected $context;
    
    /**
     * 
     * @var bool
     */
    protected $canDispatchPcntlSignal;
    
    /**
     * @param string $host
     * @param int $port
     * @param float $connTimeout
     * @param float $rwTimeout
     * @param bool $keepalive
     * @param null $context
     */
    public function __construct(string $host, int $port, int $connTimeout, int $rwTimeout, bool $keepalive = false, $context=null, $protocol='tcp')
    {
        // default protocol
        $this->protocol = $protocol;
        $this->host = $host;
        $this->port = $port;
        $this->connTimeout = $connTimeout;
        $this->readWriteTimeout = $rwTimeout;
        $this->context = $context;
        $this->keepalive = $keepalive;
        $this->canDispatchPcntlSignal = $this->isPcntlSignalEnabled();
        if ( is_null($this->context) ) {
            $this->context = $this->getContext();
        }
    }
    
    /**
     * Get a default context
     * @return resource
     */
    private function getContext() 
    {
        // tcp_nodelay was added in 7.1.0
        if ( PHP_VERSION_ID >= 70100 ) {
            return stream_context_create(array(
                "socket" => array(
                    "tcp_nodelay" => true
                )
            ));
        } else {
            return stream_context_create();
        }
    }

    /**
     * Check signals enable or not
     * @return bool
     */
    private function isPcntlSignalEnabled()
    {
        return true;
    }

    /**
     * Sets up the stream connection
     * {@inheritDoc}
     * @see \Lj\Amqp\IO\AbstractIO::connect()
     */
    public function connect(): void
    {
        $errstr = $errno = null;

        $remote = sprintf(
            '%s://%s:%s',
            $this->protocol,
            $this->host,
            $this->port
        );
        try {
            $this->socket = stream_socket_client(
                $remote,
                $errno,
                $errstr,
                $this->connTimeout,
                STREAM_CLIENT_CONNECT,
                $this->context
            );
        } catch (\ErrorException $e) {
            throw $e;
        }

        if (false === $this->socket) {
            throw new IOException(
                sprintf(
                    'Error Connecting to server(%s): %s ',
                    $errno,
                    $errstr
                ),
                $errno
            );
        }

        if (false === stream_socket_get_name($this->socket, true)) {
            throw new IOException(
                sprintf(
                    'Connection refused: %s ',
                    $remote
                )
            );
        }
        list($sec, $uSec) = MiscHelper::splitSecondsMicroseconds($this->readWriteTimeout);
        if (!stream_set_timeout($this->socket, $sec, $uSec)) {
            throw new IOException('Timeout could not be set');
        }

        // php cannot capture signals while streams are blocking
        if ($this->canDispatchPcntlSignal) {
            stream_set_blocking($this->socket, 0);
            stream_set_write_buffer($this->socket, 0);
            if (function_exists('stream_set_read_buffer')) {
                stream_set_read_buffer($this->socket, 0);
            }
        } else {
            stream_set_blocking($this->socket, 1);
        }

        if ($this->keepalive) {
            $this->keepalive();
        }
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
     * Read data from the socket
     * {@inheritDoc}
     * @see \Lj\Amqp\IO\AbstractIO::read()
     */
    public function read(int $length): string
    {
        if (!is_resource($this->socket) || feof($this->socket)) {
            throw new IOException('Broken pipe or closed connection');
        }
        $s = @fread($this->socket, $length);
        if ($s === false) {
            throw new IOException($this->timeout());
        }
        if (@feof($this->socket)) {
            throw new IOException("Broken pipe or closed connection.");
        }
        $this->lastReadTime = microtime(true);
        return $s;
    }

    /**
     * Write data to the socket
     * {@inheritDoc}
     * @see \Lj\Amqp\IO\AbstractIO::write()
     */
    public function write(string $str, int $length): int
    {
        if (!is_resource($this->socket)) {
            throw new IOException('Broken pipe or closed connection');
        }
        $written = 0;
        // ignore all the error(for example: EAGAIN, EWOULDBLOCK)
        if ( ($written = @fwrite($this->socket, $str)) === false ) {
            throw new IOException("Could not write data to socket.");
        }
        if ($written === 0) {
            throw new IOException("Broken pipe or closed connection.");
        }
        fflush($this->socket); // flush internal PHP buffers
        $this->lastWriteTime = microtime(true);
        return $written;
    }

    /**
     * Close the socket
     * {@inheritDoc}
     * @see \Lj\Amqp\IO\AbstractIO::close()
     */
    public function close(): void
    {
        if (is_resource($this->socket)) {
            fclose($this->socket);
        }
        $this->socket = null;
        $this->lastReadTime = null;
        $this->lastWriteTime = null;
    }

    /**
     * @return resource
     */
    public function getSocket()
    {
        return $this->socket;
    }

    /**
     * @return mixed
     */
    protected function timeout(): string
    {
        // get status of socket to determine whether or not it has timed out
        $info = stream_get_meta_data($this->socket);
        return $info['timed_out'];
    }

    /**
     * Enable keepalive
     * @throws IOException
     */
    protected function keepalive(): void
    {
        if (!function_exists('socket_import_stream')) {
            throw new IOException('Can not enable keepalive: function socket_import_stream does not exist');
        }
        if (!defined('SOL_SOCKET') || !defined('SO_KEEPALIVE')) {
            throw new IOException('Can not enable keepalive: SOL_SOCKET or SO_KEEPALIVE is not defined');
        }
        $socket = socket_import_stream($this->socket);
        socket_set_option($socket, SOL_SOCKET, SO_KEEPALIVE, 1);
    }
}
