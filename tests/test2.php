<?php
include dirname(__DIR__). '/vendor/autoload.php';
use Lj\Amqp\Channel;
use Lj\Amqp\Message;
use Lj\Amqp\EventLoop\SwooleLoop;
use Lj\Amqp\Connection\SocketConnection;

$exchange = 'router';
$q1 = 'msg1';
$q2 = 'msg2';
$q3 = 'msg3';

$consumerTag = 'consumer';
$c1 = 'consumer1';
$c2 = 'consumer2';
$c3 = 'consumer3';

$host = '127.0.0.1';
$port = 5672;
$user = 'liujie';
$pass = 'liujie';
$vhost = 'vh';

$loop = new SwooleLoop();

$connection = new SocketConnection($loop, $host, $port, $user, $pass, $vhost, 5, 5);
$connection->connect()->then(function($connection) {
    
    $beginTime = microtime(true);
    $count = 0;
    
    $exchange = 'router';
    $q1 = 'msg1';
    $q2 = 'msg2';
    $q3 = 'msg3';
    
    $consumerTag = 'consumer';
    $c1 = 'consumer1';
    $c2 = 'consumer2';
    $c3 = 'consumer3';
    
    $bindStr = 'group-test';
    
    $channel = $connection->getChannel();
    
    /*
     name: $queue
     passive: false
     durable: true // the queue will survive server restarts
     exclusive: false // the queue can be accessed in other channels
     auto_delete: false //the queue won't be deleted once the channel is closed.
     */
    $channel->queueDeclare($q1, false, true, false, false);
    $channel->queueDeclare($q2, false, true, false, false);
    $channel->queueDeclare($q3, false, true, false, false);
    
    /*
     name: $exchange
     type: direct
     passive: false
     durable: true // the exchange will survive server restarts
     auto_delete: false //the exchange won't be deleted once the channel is closed.
     */
    $channel->exchangeDeclare($exchange, 'direct', false, true, false);
    $channel->queueBind($q1, $exchange, $bindStr);
    $channel->queueBind($q2, $exchange, $bindStr);
    $channel->queueBind($q3, $exchange, $bindStr);
    
    /**
     * @param \PhpAmqpLib\Message\AMQPMessage $message
     */
    $callback = function(Message $message) use ($channel, &$count, $beginTime)
    {
//         echo "\n--------\n";
//         echo $message->content;
//         echo "\n--------\n";
        // ack message
        $channel->ack($message->deliveryTag);
        $count++;
        if ($count == 900000 ) {
            echo $count. PHP_EOL;
            echo (microtime(true) - $beginTime). PHP_EOL;
        }
    };
    $channel->consume($callback, $q1, $c1, false, false, false, false);
    $channel->consume($callback, $q2, $c2, false, false, false, false);
    $channel->consume($callback, $q3, $c3, false, false, false, false);
});

