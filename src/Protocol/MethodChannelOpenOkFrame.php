<?php
namespace Lj\Amqp\Protocol;

use Lj\Amqp\Constants;

/**
 * AMQP 'channel.open-ok' (class #20, method #11) frame.
 *
 * THIS CLASS IS GENERATED FROM amqp-rabbitmq-0.9.1.json. **DO NOT EDIT!**
 *
 * @author liujie
 */
class MethodChannelOpenOkFrame extends MethodFrame
{

    /** @var string */
    public $channelId = '';

    public function __construct()
    {
        parent::__construct(Constants::CLASS_CHANNEL, Constants::METHOD_CHANNEL_OPEN_OK);
    }

}
