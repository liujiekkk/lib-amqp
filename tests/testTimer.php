<?php
use Lj\Amqp\EventLoop\SwooleLoop;

include dirname(__DIR__). '/vendor/autoload.php';

$loop = new SwooleLoop();
$timer = $loop->addTimer(1.0, function() {
    echo 'hello';
});
$loop->cancelTimer($timer);
$loop->run();
echo '====='.PHP_EOL;
$loop->stop();
echo '******'. PHP_EOL;
