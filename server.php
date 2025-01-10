<?php

// 创建一个TCP服务器
$socket = stream_socket_server("tcp://0.0.0.0:9501", $errno, $errstr);
if (!$socket) {
    die("Error starting TCP server: [$errno] $errstr\n");
}
echo "TCP server started on 0.0.0.0:9501\n";

// 设置进程数
$worker_num = 4;
$workers = [];

// 创建子进程
for ($i = 0; $i < $worker_num; $i++) {
    $pid = pcntl_fork();
    if ($pid == -1) {
        die("Failed to fork worker process\n");
    } elseif ($pid == 0) {
        // 子进程处理连接

        // 创建event_base
        $base = event_base_new();
        if (!$base) {
            die("Could not initialize libevent\n");
        }

        // 创建event
        $event = event_new();
        if (!$event) {
            die("Could not create event\n");
        }

        // 设置event回调
        event_set($event, $socket, EV_READ | EV_PERSIST, function ($socket, $flags, $base) {
            $client = stream_socket_accept($socket);
            if (!$client) {
                echo "Failed to accept connection\n";
                return;
            }
            echo "Connection open: " . stream_socket_get_name($client, true) . "\n";
            handleClient($client, $base);
        }, $base);

        // 添加event到event_base
        event_base_set($event, $base);
        event_add($event);

        // 启动event_base循环
        event_base_loop($base);

        exit(0);
    } else {
        // 父进程记录子进程ID
        $workers[] = $pid;
    }
}

// 父进程等待子进程结束
foreach ($workers as $worker) {
    pcntl_waitpid($worker, $status);
}

// 新增函数：处理客户端连接
function handleClient($client, $base)
{
    // 创建event_base
    $client_base = event_base_new();
    if (!$client_base) {
        die("Could not initialize libevent\n");
    }

    // 创建event
    $client_event = event_new();
    if (!$client_event) {
        die("Could not create event\n");
    }

    // 设置event回调
    event_set($client_event, $client, EV_READ | EV_PERSIST, function ($client, $flags, $client_base) {
        $data = fread($client, 1024);
        if ($data === false || $data === '') {
            // 关闭连接
            echo "Connection close: " . stream_socket_get_name($client, true) . "\n";
            fclose($client);
            event_base_loopexit($client_base, NULL);
        } else {
            echo "Received data: $data from " . stream_socket_get_name($client, true) . "\n";
            fwrite($client, "Echo: $data");
        }
    }, $client_base);

    // 添加event到event_base
    event_base_set($client_event, $client_base);
    event_add($client_event);

    // 启动event_base循环
    event_base_loop($client_base);
}
