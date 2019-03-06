<?php
namespace Lj\Amqp\Protocol;

use Lj\Amqp\Constants;

/**
 * Heartbeat AMQP frame.
 *
 * Heartbeat frames are empty.
 *
 * @author liujie
 */
class HeartbeatFrame extends AbstractFrame
{

    public function __construct()
    {
        parent::__construct(Constants::FRAME_HEARTBEAT, Constants::CONNECTION_CHANNEL, 0, "");
    }

}
