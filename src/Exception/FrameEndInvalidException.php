<?php
namespace Lj\Amqp\Exception;

/**
 * Peer sent invalid frame end byte.
 *
 * @author liujie
 */
class FrameEndInvalidException extends ProtocolException
{

    public function __construct()
    {
        parent::__construct("AbstractFrame end byte is invalid.");
    }

}
