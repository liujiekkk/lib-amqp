<?php
namespace Lj\Amqp\Protocol;

use Lj\Amqp\Constants;

/**
 * AMQP 'tx.select' (class #90, method #10) frame.
 *
 * THIS CLASS IS GENERATED FROM amqp-rabbitmq-0.9.1.json. **DO NOT EDIT!**
 *
 * @author liujie
 */
class MethodTxSelectFrame extends MethodFrame
{

    public function __construct()
    {
        parent::__construct(Constants::CLASS_TX, Constants::METHOD_TX_SELECT);
    }

}
