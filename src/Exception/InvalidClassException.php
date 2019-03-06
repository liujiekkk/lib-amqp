<?php
namespace Lj\Amqp\Exception;

/**
 * Peer sent frame with invalid method class id.
 *
 * @author liujie
 */
class InvalidClassException extends ProtocolException
{

    /** @var int */
    private $classId;

    public function __construct($classId)
    {
        parent::__construct("Unhandled method frame class '{$classId}'.");
        $this->classId = $classId;
    }

    /**
     * @return int
     */
    public function getClassId()
    {
        return $this->classId;
    }

}
