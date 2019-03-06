<?php
namespace Lj\Amqp\Protocol;

use Lj\Amqp\Constants;

/**
 * AMQP 'basic.deliver' (class #60, method #60) frame.
 *
 * THIS CLASS IS GENERATED FROM amqp-rabbitmq-0.9.1.json. **DO NOT EDIT!**
 *
 * @author liujie
 */
class MethodBasicDeliverFrame extends MethodFrame
{

    /** @var string */
    public $consumerTag;

    /** @var int */
    public $deliveryTag;

    /** @var boolean */
    public $redelivered = false;

    /** @var string */
    public $exchange;

    /** @var string */
    public $routingKey;

    public function __construct()
    {
        parent::__construct(Constants::CLASS_BASIC, Constants::METHOD_BASIC_DELIVER);
    }

}
