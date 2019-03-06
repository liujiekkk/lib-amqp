<?php

/**
 * 通过消息队列来动态增加消费者，每个进程监听一个动态消息队列
 * @author liujie <king.2oo8@163.com>
 * @date 2019年2月11日
 * @time 下午5:13:49
 */
include dirname(__DIR__). '/vendor/autoload.php';
use Lj\Amqp\EventLoop\SwooleLoop;
use Lj\Amqp\Connection\StreamConnection;
use Lj\Amqp\Message;

swoole_set_process_name(sprintf('php-ps:%s', 'master'));

$serv = new Swoole\Server('0.0.0.0', 8089, SWOOLE_BASE, SWOOLE_SOCK_TCP);

$serv->set(array(
    'worker_num' => 3,    //worker process num
    'backlog' => 128,   //listen backlog
    'max_request' => 50,
    'dispatch_mode'=>1,
    'enable_coroutine' => true,
    'daemonize' => 0
));

$serv->on('Connect', function($server, $fd) {
    echo 'A client connect in.'. PHP_EOL;
});
$serv->on('Receive', function($server, $fd, $reactor_id, $data) {
    $dataArr = json_decode($data, true);
    if (json_last_error()) {
        echo json_last_error_msg(). PHP_EOL;
        return;
    }
    if (!isset($dataArr['action'])) {
        $server->send($fd, 'Invalid data, No action.');
        echo 'Invalid data, No action.'. PHP_EOL;
        return;
    }
    if (!isset($dataArr['uid'])) {
        $server->send($fd, 'Invalid data, No uid.');
        echo 'Invalid data, No uid.'. PHP_EOL;
        return;
    }
    switch ($dataArr['action']) 
    {
        // 登录信息包
        case 'login':
            // 记录当前登录用户
            $server->users[$dataArr['uid']] = $fd;
            // 队列名称
            $queue = 'q-'. $dataArr['uid'];
            // 消费者名称
            $consumer = 'c-'. $dataArr['uid'];
            // 声明一个队列
            $server->channel->queueDeclare($queue, false, true, false, false);
            // 将队列绑定到指定的 exchange
            $server->channel->queueBind($queue, $server->exchange, 'group-test');
            // 动态添加一个消费者
            $server->channel->consume(
                function($message) use ($server, $queue) {
                    // 将消息输出 @todo 将来切换成写入客户端响应fd
                    echo '------------------------------------------'. PHP_EOL;
                    echo 'From '. $queue.' message:'. $message->body. PHP_EOL;
//                     $server->channel->basic_ack($message->delivery_info['delivery_tag']);
                    // 将消费信息发送给客户端
//                     $server->send($server->users, $message->body);
//                     $message->delivery_info['channel']->basic_ack($message->delivery_info['delivery_tag']);
                },
                $queue,
                $consumer,
                false,
                false,
                false,
                false
            );
            echo "User id: {$dataArr['uid']} login in. Queue: {$queue}, Consumer: {$consumer}.". PHP_EOL;
            break;
        // 业务消息包
        case 'message':
            $server->send($fd, 'Waiting coding...');
    }
});
$serv->on('Close', function() {
    
});
$serv->on('Start', function() {
    
});
$serv->on('ManagerStart', function() {
    swoole_set_process_name(sprintf('php-ps:%s', 'manager'));
    echo 'hello world....'. PHP_EOL;
});
$serv->on('WorkerStart', function($serv) {
    // worker 进程命名
    swoole_set_process_name(sprintf('php-ps:%s', 'worker'));
    
    // 创建 rabbitmq 链接
    $serv->exchange = $exchange = 'router';
    
    $host = '172.17.0.2';
    $port = '5672';
    $user = 'liujie';
    $pass = 'liujie';
    $vhost = 'vh';
    
    $loop = new SwooleLoop();
    
    $connection = new StreamConnection($loop, $host, $port, $user, $pass, $vhost, 5, 5);
    $connection->connect()->then(function($connection) use ($loop, $serv) {
        $exchange = 'router';
        $serv->channel = $channel = $connection->getChannel();
        $channel->exchangeDeclare($exchange, 'direct', false, true, false);
    });
    
    echo 'worker ready...'. PHP_EOL;
});
$serv->start();
