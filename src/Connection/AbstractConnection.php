<?php
namespace Lj\Amqp\Connection;
use Lj\Amqp\Exception\ConnectionException;
use Lj\Amqp\Channel;
use Lj\Amqp\Constants;
use Lj\Amqp\Protocol\Buffer;
use Lj\Amqp\Protocol\ProtocolReader;
use Lj\Amqp\Protocol\ProtocolWriter;
use Lj\Amqp\Protocol\HeartbeatFrame;
use Lj\Amqp\Protocol\AbstractFrame;
use Lj\Amqp\Protocol\MethodFrame;
use Lj\Amqp\Protocol\MethodConnectionCloseFrame;
use Lj\Amqp\Protocol\ContentHeaderFrame;
use Lj\Amqp\Protocol\ContentBodyFrame;
use Lj\Amqp\Protocol\MethodConnectionTuneFrame;
use Lj\Amqp\Protocol\MethodConnectionStartFrame;
use function React\Promise\reject;
use function React\Promise\all;
use React\Promise;
use Lj\Amqp\IO\AbstractIO;

abstract class AbstractConnection
{
    use ConnectionMethods;
    
    /**
     * 
     * @var string
     */
    protected $user;
    
    /**
     * 
     * @var string
     */
    protected $pass;
    
    /**
     * vhost
     * @var string
     */
    protected $vhost;
    
    /**
     * heartbeat timeinterval
     * @var float
     */
    protected $heartbeat = 30.0;
    
    /**
     * 
     * @var Timer
     */
    protected $heartbeatTimer;
    
    /**
     * 
     * @var EventLoop
     */
    protected $eventLoop;
    
    /**
     * Connection state
     * @var int
     */
    protected $state;
    
    /**
     * Channels array
     * @var array
     */
    protected $channels;
    
    /**
     * @var AbstractIO
     */
    protected $io;
    
    /**
     * 
     * @var ProtocolReader
     */
    protected $reader;
    
    /**
     * 
     * @var ProtocolWriter
     */
    protected $writer;
    
    /**
     * read buffer
     * @var Buffer
     */
    protected $readBuffer;
    
    /**
     * write buffer
     * @var Buffer
     */
    protected $writeBuffer;
    
    /**
     * frame queue
     * @var array
     */
    protected $frameQueue = [];
    
    /** @var int */
    protected $frameMax = 0xFFFF;
    
    /** 
     * @var callable[] 
     */
    protected $awaitCallbacks = [];
    
    /**
     * 
     * @var Promise | null
     */
    protected $flushWriteBufferPromise;
    
    /**
     *
     * @var Promise | null
     */
    protected $disconnectPromise;
    
    /**
     * init all
     */
    protected function init() 
    {
        // init connection state
        $this->state = ConnectionStateEnum::NOT_CONNECTED;
        // read buffer
        $this->readBuffer = new Buffer();
        // write buffer
        $this->writeBuffer = new Buffer();
        // reader
        $this->reader = new ProtocolReader();
        // wirter
        $this->writer = new ProtocolWriter();
        // frame queue
        $this->frameQueue = [];
    }
    
    /**
     * connect broker
     */
    public function connect()
    {
        if ($this->state !== ConnectionStateEnum::NOT_CONNECTED) {
            throw new ConnectionException("Client already connected/connecting.");
        }
        $this->state = ConnectionStateEnum::CONNECTING;
        $this->writer->appendProtocolHeader($this->writeBuffer);
        
        try {
            // Start to connect socket
            $this->io->connect();
            $this->eventLoop->addReadStream($this->io->getSocket(), function() {
                $this->onDataAvaliable();
            });
        } catch (\Exception $e) {
            return Promise\reject($e);
        }
        return $this->flushWriteBuffer()->then(function () {
            return $this->awaitConnectionStart();
            
        })->then(function (MethodConnectionStartFrame $start) {
            return $this->authResponse($start);
            
        })->then(function () {
            return $this->awaitConnectionTune();
            
        })->then(function (MethodConnectionTuneFrame $tune) {
            $this->frameMax = $tune->frameMax;
            if ($tune->channelMax > 0) {
                $this->channelMax = $tune->channelMax;
            }
            return $this->connectionTuneOk($tune->channelMax, $tune->frameMax, $this->heartbeat);
            
        })->then(function () {
            return $this->connectionOpen($this->vhost);
            
        })->then(function () {
            $this->heartbeatTimer = $this->eventLoop->addTimer($this->heartbeat, [$this, "onHeartbeat"]);
            
            $this->state = ConnectionStateEnum::CONNECTED;
            return $this;
            
        });
    }
    
    /**
     * disconnect broker, if code not equal 0, cancel the
     * heartbeat timer, broker will force the connection to close 
     * as the heartbeat is not detected
     * @param number $replyCode
     * @param string $replyText
     * @throws \LogicException
     * @return \React\Promise|\React\Promise\PromiseInterface
     */
    public function disconnect($replyCode = 0, $replyText = "")
    {
        if ($this->state === ConnectionStateEnum::DISCONNECTING) {
            return $this->disconnectPromise;
        }
        
        if ($this->state !== ConnectionStateEnum::CONNECTED) {
            return Promise\reject(new ConnectionException("Client is not connected."));
        }
        
        $this->state = ConnectionStateEnum::DISCONNECTING;
        
        $promises = [];
        if ($replyCode === 0) {
            foreach ($this->channels as $channel) {
                $promises[] = $channel->close($replyCode, $replyText);
            }
        }
        
        if ($this->heartbeatTimer) {
            $this->eventLoop->cancelTimer($this->heartbeatTimer);
            $this->heartbeatTimer = null;
        }
        return $this->disconnectPromise = Promise\all($promises)->then(function () use ($replyCode, $replyText) {
            if (!empty($this->channels)) {
                throw new \LogicException("All channels have to be closed by now.");
            }
            return $this->connectionClose($replyCode, $replyText, 0, 0);
        })->then(function () {
            $this->eventLoop->removeReadStream($this->io->getSocket());
            $this->io->close();
            $this->init();
            return $this;
        })->otherwise(function(\Throwable $t) {
            echo $t->getMessage();
        });
    }
    
    
    /**
     * Get the channel
     * @param int $index channel id
     * @return Channel
     */
    public function getChannel(int $channelId=1 ): Channel 
    {
        
        if ( !isset($this->channels[$channelId]) ) {
            $this->channels[$channelId] = new Channel($this, $channelId);
            
            // open channel
            $response = $this->channelOpen($channelId);
            if ($response instanceof Promise\PromiseInterface) {
                $response->then(function () use ($channelId) {
                    return $this->channels[$channelId];
                });
            } else {
                $this->state = ConnectionStateEnum::ERROR;
                
                throw new ConnectionException(
                    "channel.open unexpected response of type " . gettype($response) .
                    (is_object($response) ? "(" . get_class($response) . ")" : "") .
                    "."
                );
            }
        }
        return $this->channels[$channelId];
    }
    
    /**
     * Removes channel.
     *
     * @param int $channelId
     * @return void
     */
    public function removeChannel(int $channelId): void
    {
        unset($this->channels[$channelId]);
    }
    
    /**
     * get protocol reader
     * @return ProtocolReader
     */
    public function getReader(): ProtocolReader 
    {
        return $this->reader;
    }
    
    /**
     * get protocol writer
     * @return ProtocolWriter
     */
    public function getWriter(): ProtocolWriter 
    {
        return $this->writer;
    }
    
    public function getWriteBuffer(): Buffer 
    {
        return $this->writeBuffer;
    }
    
    public function getReadBuffer(): Buffer 
    {
        return $this->readBuffer;
    }
    
    /**
     * Reads data from stream to {@link readBuffer}.
     *
     * @return boolean
     */
    protected function feedReadBuffer()
    {
        $s = $this->io->read($this->frameMax);
        $this->readBuffer->append($s);
        return true;
    }
    
    /**
     * Asynchronously sends buffered data over the wire.
     *
     * - Calls {@link eventLoops}'s addWriteStream() with client's stream.
     * - Consecutive calls will return the same instance of promise.
     *
     * @return Promise\PromiseInterface
     */
    protected function flushWriteBuffer()
    {
        if ($this->flushWriteBufferPromise) {
            return $this->flushWriteBufferPromise;
        } else {
            $deferred = new Promise\Deferred();
            $this->eventLoop->addWriteStream($this->io->getSocket(), function ($stream) use ($deferred) {
                try {
                    // write data to socket, mybe writen length is less than expected length
                    $length = $this->writeBuffer->getLength();
                    $writen = $this->io->write($this->writeBuffer->read($length), $length);
                    $this->writeBuffer->discard($writen);
                    
                    if ($this->writeBuffer->isEmpty()) {
                        $this->eventLoop->removeWriteStream($stream);
                        $this->flushWriteBufferPromise = null;
                        $deferred->resolve(true);
                    }
                } catch (\Exception $e) {
                    $this->eventLoop->removeWriteStream($stream);
                    $this->flushWriteBufferPromise = null;
                    $deferred->reject($e);
                }
            });
            return $this->flushWriteBufferPromise = $deferred->promise();
        }
    }
    
    /**
     * sock is readable
     */
    public function onDataAvaliable(): void
    {
        // read the socket
        $this->feedReadBuffer();
        
        while ( ($frame = $this->reader->consumeFrame($this->readBuffer)) !== null ) {
            foreach ($this->awaitCallbacks as $k => $callback) {
                if ($callback($frame) === true) {
                    unset($this->awaitCallbacks[$k]);
                    continue 2; // CONTINUE WHILE LOOP
                }
            }
            // channel 0 receive heartbeat frame
            if ($frame->channel === 0) {
                $this->onFrameReceived($frame);
            } else {
                if (!isset($this->channels[$frame->channel])) {
                    throw new ConnectionException(
                        "Received frame #{$frame->type} on closed channel #{$frame->channel}."
                    );
                }
                $this->channels[$frame->channel]->onFrameReceived($frame);
            }
        }
    }
    
    /**
     * Callback after connection-level frame has been received.
     *
     * @param AbstractFrame $frame
     */
    public function onFrameReceived(AbstractFrame $frame)
    {
        if ($frame instanceof HeartbeatFrame) {
            $this->lastReadTime = microtime(true);
            
        } elseif ($frame instanceof MethodFrame) {
            if ($frame instanceof MethodConnectionCloseFrame) {
                throw new ConnectionException("Connection closed by server: " . $frame->replyText, $frame->replyCode);
            } else {
                throw new ConnectionException("Unhandled method frame " . get_class($frame) . ".");
            }
            
        } elseif ($frame instanceof ContentHeaderFrame) {
            $this->disconnect(Constants::STATUS_UNEXPECTED_FRAME, "Got header frame on connection channel (#0).");
            
        } elseif ($frame instanceof ContentBodyFrame) {
            $this->disconnect(Constants::STATUS_UNEXPECTED_FRAME, "Got body frame on connection channel (#0).");
            
        } else {
            throw new ConnectionException("Unhandled frame " . get_class($frame) . ".");
        }
    }
    
    /**
     * Adds callback to process incoming frames.
     *
     * Callback is passed instance of {@link \Bunny\Protocol|AbstractFrame}. If callback returns TRUE, frame is said to
     * be handled and further handlers (other await callbacks, default handler) won't be called.
     *
     * @param callable $callback
     */
    public function addAwaitCallback(callable $callback): void
    {
        $this->awaitCallbacks[] = $callback;
    }
    
    /**
     * Callback when heartbeat timer timed out.
     */
    public function onHeartbeat()
    {
        $now = microtime(true);
        $nextHeartbeat = ($this->io->getLastWriteTime() ?: $now) + $this->heartbeat;
        if ( $now >= $nextHeartbeat ) {
            $this->writer->appendFrame(new HeartbeatFrame(), $this->writeBuffer);
            $this->flushWriteBuffer()->done(function () {
                $this->heartbeatTimer = $this->eventLoop->addTimer($this->heartbeat, [$this, "onHeartbeat"]);
            });
        } else {
            $this->heartbeatTimer = $this->eventLoop->addTimer(($nextHeartbeat - $now), [$this, "onHeartbeat"]);
        }
    }
}
