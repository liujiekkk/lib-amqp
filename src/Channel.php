<?php
namespace Lj\Amqp;

use Lj\Amqp\Connection\AbstractConnection;
use Lj\Amqp\Exception\ChannelException;
use Lj\Amqp\Protocol\Buffer;
use Lj\Amqp\Protocol\AbstractFrame;
use Lj\Amqp\ChannelStateEnum;
use Lj\Amqp\Protocol\MethodFrame;
use Lj\Amqp\Protocol\MethodChannelCloseOkFrame;
use Lj\Amqp\Protocol\MethodBasicReturnFrame;
use Lj\Amqp\Protocol\ContentBodyFrame;
use Lj\Amqp\Protocol\MethodBasicDeliverFrame;
use Lj\Amqp\Protocol\MethodBasicAckFrame;
use Lj\Amqp\Protocol\ContentHeaderFrame;
use Lj\Amqp\Protocol\MethodBasicNackFrame;
use Lj\Amqp\Protocol\HeartbeatFrame;
use Lj\Amqp\ChannelModeEnum;
use Lj\Amqp\Protocol\MethodBasicConsumeOkFrame;
use React\Promise\PromiseInterface;
use React\Promise\Deferred;

class Channel
{
    use ChannelMethods 
    {
        consume as private consumeImpl;
    }
    
    /**
     * Channel id
     * @var int
     */
    protected $id;
    
    /**
     * Channel mode
     * @var int
     */
    protected $mode;
    
    /**
     * 
     * @var AbstractConnection
     */
    protected $conntion;
    
    /**
     * channel status
     * @var int
     */
    protected $state;
    
    /**
     * 
     * @var AbstractFrame
     */
    protected $returnFrame;
    
    /**
     *
     * @var AbstractFrame
     */
    protected $deliverFrame;
    
    /**
     *
     * @var AbstractFrame
     */
    protected $headerFrame;
    
    /**
     * body content's left length
     * @var int
     */
    protected $bodySizeRemaining;
    
    /**
     * body buffer
     * @var Buffer
     */
    protected $bodyBuffer;
    
    protected $ackCallbacks;
    
    protected $deliverCallbacks;
    
    protected $returnCallbacks;
    
    protected $closePromise;
    
    protected $closeDeferred;
    
    /**
     * new a channel obj 
     * @param AbstractConnection $conn
     * @param int $id
     */
    public function __construct(AbstractConnection $conn, int $id) 
    {
        // init connection
        $this->conntion = $conn;
        // init channel id
        $this->id = $id;
        // init channel state
        $this->state = ChannelStateEnum::READY;
        // init channel mode
        $this->mode = ChannelModeEnum::REGULAR;
        // init body buufer
        $this->bodyBuffer = new Buffer();
    }
    
    /**
     * get channel id
     * @return int
     */
    public function getChannelId(): int
    {
        return $this->id;
    }
    
    /**
     * Returns the channel mode.
     * @return int
     */
    public function getMode()
    {
        return $this->mode;
    }
    
    /**
     * Get connection
     * @return AbstractConnection
     */
    public function getConnection(): AbstractConnection 
    {
        return $this->conntion;
    }
    
    /**
     * Callback after channel-level frame has been received.
     * 
     * @param AbstractFrame $frame
     */
    public function onFrameReceived(AbstractFrame $frame)
    {
        if ($this->state === ChannelStateEnum::ERROR) {
            throw new ChannelException("Channel in error state.");
        }
        if ($this->state === ChannelStateEnum::CLOSED) {
            throw new ChannelException("Received frame #{$frame->type} on closed channel #{$this->id}.");
        }
        if ($frame instanceof MethodFrame) {
            if ($this->state === ChannelStateEnum::CLOSING && !($frame instanceof MethodChannelCloseOkFrame)) {
                // drop frames in closing state
                return;
            } elseif ($this->state !== ChannelStateEnum::READY && !($frame instanceof MethodChannelCloseOkFrame)) {
                $currentState = $this->state;
                $this->state = ChannelStateEnum::ERROR;
                if ($currentState === ChannelStateEnum::AWAITING_HEADER) {
                    $msg = "Got method frame, expected header frame.";
                } elseif ($currentState === ChannelStateEnum::AWAITING_BODY) {
                    $msg = "Got method frame, expected body frame.";
                } else {
                    throw new \LogicException("Unhandled channel state.");
                }
                // disconnect this channel
                $this->conntion->disconnect(Constants::STATUS_UNEXPECTED_FRAME, $msg);
                throw new ChannelException("Unexpected frame: " . $msg);
            }
            if ($frame instanceof MethodChannelCloseOkFrame) {
                $this->state = ChannelStateEnum::CLOSED;
                if ($this->closeDeferred !== null) {
                    $this->closeDeferred->resolve($this->id);
                }
                // break reference cycle, must be called after resolving promise
                $this->conntion = null;
                // break consumers' reference cycle
                $this->deliverCallbacks = [];
            } elseif ($frame instanceof MethodBasicReturnFrame) {
                $this->returnFrame = $frame;
                $this->state = ChannelStateEnum::AWAITING_HEADER;
            } elseif ($frame instanceof MethodBasicDeliverFrame) {
                $this->deliverFrame = $frame;
                $this->state = ChannelStateEnum::AWAITING_HEADER;
            } elseif ($frame instanceof MethodBasicAckFrame) {
                foreach ($this->ackCallbacks as $callback) {
                    $callback($frame);
                }
            } elseif ($frame instanceof MethodBasicNackFrame) {
                foreach ($this->ackCallbacks as $callback) {
                    $callback($frame);
                }
            } else {
                throw new ChannelException("Unhandled method frame " . get_class($frame) . ".");
            }
        } elseif ($frame instanceof ContentHeaderFrame) {
            if ($this->state === ChannelStateEnum::CLOSING) {
                // drop frames in closing state
                return;
            } elseif ($this->state !== ChannelStateEnum::AWAITING_HEADER) {
                $currentState = $this->state;
                $this->state = ChannelStateEnum::ERROR;
                if ($currentState === ChannelStateEnum::READY) {
                    $msg = "Got header frame, expected method frame.";
                } elseif ($currentState === ChannelStateEnum::AWAITING_BODY) {
                    $msg = "Got header frame, expected content frame.";
                } else {
                    throw new \LogicException("Unhandled channel state.");
                }
                $this->conntion->disconnect(Constants::STATUS_UNEXPECTED_FRAME, $msg);
                throw new ChannelException("Unexpected frame: " . $msg);
            }
            $this->headerFrame = $frame;
            $this->bodySizeRemaining = $frame->bodySize;
            if ($this->bodySizeRemaining > 0) {
                $this->state = ChannelStateEnum::AWAITING_BODY;
            } else {
                $this->state = ChannelStateEnum::READY;
                $this->onBodyComplete();
            }
        } elseif ($frame instanceof ContentBodyFrame) {
            if ($this->state === ChannelStateEnum::CLOSING) {
                // drop frames in closing state
                return;
            } elseif ($this->state !== ChannelStateEnum::AWAITING_BODY) {
                $currentState = $this->state;
                $this->state = ChannelStateEnum::ERROR;
                if ($currentState === ChannelStateEnum::READY) {
                    $msg = "Got body frame, expected method frame.";
                } elseif ($currentState === ChannelStateEnum::AWAITING_HEADER) {
                    $msg = "Got body frame, expected header frame.";
                } else {
                    throw new \LogicException("Unhandled channel state.");
                }
                $this->conntion->disconnect(Constants::STATUS_UNEXPECTED_FRAME, $msg);
                throw new ChannelException("Unexpected frame: " . $msg);
            }
            $this->bodyBuffer->append($frame->payload);
            $this->bodySizeRemaining -= $frame->payloadSize;
            if ($this->bodySizeRemaining < 0) {
                $this->state = ChannelStateEnum::ERROR;
                $this->conntion->disconnect(Constants::STATUS_SYNTAX_ERROR, "Body overflow, received " . (-$this->bodySizeRemaining) . " more bytes.");
            } elseif ($this->bodySizeRemaining === 0) {
                $this->state = ChannelStateEnum::READY;
                $this->onBodyComplete();
            }
        } elseif ($frame instanceof HeartbeatFrame) {
            $this->conntion->disconnect(Constants::STATUS_UNEXPECTED_FRAME, "Got heartbeat on non-zero channel.");
            throw new ChannelException("Unexpected heartbeat frame.");
        } else {
            throw new ChannelException("Unhandled frame " . get_class($frame) . ".");
        }
    }
    
    /**
     * Callback after content body has been completely received.
     */
    protected function onBodyComplete()
    {
        if ($this->returnFrame) {
            $content = $this->bodyBuffer->consume($this->bodyBuffer->getLength());
            $message = new Message(
                null,
                null,
                false,
                $this->returnFrame->exchange,
                $this->returnFrame->routingKey,
                $this->headerFrame->toArray(),
                $content
            );
            foreach ($this->returnCallbacks as $callback) {
                $callback($message, $this->returnFrame);
            }
            $this->returnFrame = null;
            $this->headerFrame = null;
        } elseif ($this->deliverFrame) {
            $content = $this->bodyBuffer->consume($this->bodyBuffer->getLength());
            if (isset($this->deliverCallbacks[$this->deliverFrame->consumerTag])) {
                $message = new Message(
                    $this->deliverFrame->consumerTag,
                    $this->deliverFrame->deliveryTag,
                    $this->deliverFrame->redelivered,
                    $this->deliverFrame->exchange,
                    $this->deliverFrame->routingKey,
                    $this->headerFrame->toArray(),
                    $content
                    );
                $callback = $this->deliverCallbacks[$this->deliverFrame->consumerTag];
                $callback($message, $this, $this->conntion);
            }
            $this->deliverFrame = null;
            $this->headerFrame = null;
        } elseif ($this->getOkFrame) {
            $content = $this->bodyBuffer->consume($this->bodyBuffer->getLength());
            // deferred has to be first nullified and then resolved, otherwise results in race condition
            $deferred = $this->getDeferred;
            $this->getDeferred = null;
            $deferred->resolve(new Message(
                null,
                $this->getOkFrame->deliveryTag,
                $this->getOkFrame->redelivered,
                $this->getOkFrame->exchange,
                $this->getOkFrame->routingKey,
                $this->headerFrame->toArray(),
                $content
                ));
            $this->getOkFrame = null;
            $this->headerFrame = null;
        } else {
            throw new \LogicException("Either return or deliver frame has to be handled here.");
        }
    }
    
    /**
     * Creates new consumer on channel.
     *
     * @param callable $callback
     * @param string $queue
     * @param string $consumerTag
     * @param bool $noLocal
     * @param bool $noAck
     * @param bool $exclusive
     * @param bool $nowait
     * @param array $arguments
     * @return MethodBasicConsumeOkFrame|PromiseInterface
     */
    public function consume(callable $callback, $queue = "", $consumerTag = "", $noLocal = false, $noAck = false, $exclusive = false, $nowait = false, $arguments = [])
    {
        $response = $this->consumeImpl($queue, $consumerTag, $noLocal, $noAck, $exclusive, $nowait, $arguments);
        
        if ($response instanceof MethodBasicConsumeOkFrame) {
            $this->deliverCallbacks[$response->consumerTag] = $callback;
            return $response;
            
        } elseif ($response instanceof PromiseInterface) {
            return $response->then(function (MethodBasicConsumeOkFrame $response) use ($callback) {
                $this->deliverCallbacks[$response->consumerTag] = $callback;
                return $response;
            });
                
        } else {
            throw new ChannelException(
                "basic.consume unexpected response of type " . gettype($response) .
                (is_object($response) ? " (" . get_class($response) . ")" : "") .
                "."
            );
        }
    }
    
    public function close($replyCode, $replyText) 
    {
        if ($this->state === ChannelStateEnum::CLOSED) {
            throw new ChannelException("Trying to close already closed channel #{$this->id}.");
        }
        if ($this->state === ChannelStateEnum::CLOSING) {
            return $this->closePromise;
        }
        $this->state = ChannelStateEnum::CLOSING;
        $this->conntion->channelClose($this->id, $replyCode, $replyText, 0, 0);
        
        $this->closeDeferred = new Deferred();
        return $this->closePromise = $this->closeDeferred->promise()->then(function ($channelId) {
            $this->conntion->removeChannel($channelId);
        })->otherwise(function(\Throwable $t){
            echo $t->getMessage();
        });
    }
}

