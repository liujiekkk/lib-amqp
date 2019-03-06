<?php
namespace Lj\Amqp\Protocol;

use Lj\Amqp\Constants;

/**
 * AMQP 'access.request' (class #30, method #10) frame.
 *
 * THIS CLASS IS GENERATED FROM amqp-rabbitmq-0.9.1.json. **DO NOT EDIT!**
 *
 * @author liujie
 */
class MethodAccessRequestFrame extends MethodFrame
{

    /** @var string */
    public $realm = '/data';

    /** @var boolean */
    public $exclusive = false;

    /** @var boolean */
    public $passive = true;

    /** @var boolean */
    public $active = true;

    /** @var boolean */
    public $write = true;

    /** @var boolean */
    public $read = true;

    public function __construct()
    {
        parent::__construct(Constants::CLASS_ACCESS, Constants::METHOD_ACCESS_REQUEST);
    }

}
