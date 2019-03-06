<?php
namespace Lj\Amqp\Protocol;

use Lj\Amqp\Constants;

/**
 * AMQP 'exchange.declare' (class #40, method #10) frame.
 *
 * THIS CLASS IS GENERATED FROM amqp-rabbitmq-0.9.1.json. **DO NOT EDIT!**
 *
 * @author liujie
 */
class MethodExchangeDeclareFrame extends MethodFrame
{

    /** @var int */
    public $reserved1 = 0;

    /** @var string */
    public $exchange;

    /** @var string */
    public $exchangeType = 'direct';

    /** @var boolean */
    public $passive = false;

    /** @var boolean */
    public $durable = false;

    /** @var boolean */
    public $autoDelete = false;

    /** @var boolean */
    public $internal = false;

    /** @var boolean */
    public $nowait = false;

    /** @var array */
    public $arguments = [];

    public function __construct()
    {
        parent::__construct(Constants::CLASS_EXCHANGE, Constants::METHOD_EXCHANGE_DECLARE);
    }

}
