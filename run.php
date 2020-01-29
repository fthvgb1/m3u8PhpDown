<?php


$url = $argv[1] ?? '';
if (!$url) {
    throw new ErrorException('args error');
}
$threads = $argv[2] ?? 10;

preg_match('@/(\d+)\.m3u8@', strrchr($url, '/'), $ma);
$bag = $ma[1];

if (!is_dir($_SERVER['SAVEDIR'] . $bag)) {
    mkdir($_SERVER['SAVEDIR'] . $bag);
}
$prefix = dirname($url) . '/';

if (!file_exists($_SERVER['SAVEDIR'] . $bag . '/' . $bag . '.m3u8')) {
    $file = file_get_contents($url);
    file_put_contents($_SERVER['SAVEDIR'] . $bag . '/' . $bag . '.m3u8', $file);
} else {
    $file = file_get_contents($_SERVER['SAVEDIR'] . $bag . '/' . $bag . '.m3u8');
}

$c = preg_match_all('/\n(\d+)\.ts/', $file, $matches);

Swoole\Runtime::enableCoroutine();

Swoole\Runtime::enableCoroutine(SWOOLE_HOOK_ALL);
Swoole\Runtime::enableCoroutine(SWOOLE_HOOK_CURL);

$produce = new chan(10000);
$consume = new chan($threads);
go(function () use ($produce, $matches, $bag, $prefix) {

    foreach ($matches[1] as $num) {
        $url = $prefix . $num . '.ts';
        $savename = $_SERVER['SAVEDIR'] . $bag . '/' . $num . '.ts';

        if (file_exists($savename)) {
            continue;
        }
        $produce->push([$url, $savename]);
    }
    echo '生产者over', PHP_EOL;
});

go(function () use ($produce, $consume, $threads) {
    for ($i = 0; $i < $threads; ++$i) {
        $consume->push($produce->pop());
    }
    while (1) {
        list($url, $saveName) = $consume->pop();
        echo '开始下载' . $url, PHP_EOL;

        go(function () use ($url, $saveName, $produce, $consume) {
            $curl = curl_init($url);
            curl_setopt_array($curl, [
                CURLOPT_HTTPHEADER => ['authority: q65ms8.cdnlab.live', 'accept: */*', 'accept-encoding: gzip, deflate, br', 'accept-language: zh-CN,zh;q=0.9', 'sec-fetch-mode: cors', 'sec-fetch-site: cross-site', 'user-agent: Mozilla/5.0 (Macintosh; Intel Mac OS X 10_13_4) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/66.0.3359.139 Safari/537.36'],
                CURLOPT_SSL_VERIFYPEER => 0, CURLOPT_SSL_VERIFYHOST => 0,
                CURLOPT_RETURNTRANSFER => true,//CURLINFO_HEADER_OUT=>true,
                //CURLOPT_HEADER=>true,
                //CURLOPT_PROXY=>'192.168.44.113',
                //CURLOPT_PROXYPORT=>'10880',
                CURLOPT_TIMEOUT => 15,
            ]);
            $content = curl_exec($curl);
            $info = curl_getinfo($curl);
            print_r($info);
            if ($info['http_code'] == 200 && $content) {
                $f = fopen($saveName, 'w');
                fwrite($f, $content);
                fclose($f);
                echo $url, '下载完成', PHP_EOL;
                return;
            } else {
                //print_r(curl_error($curl));
                echo $url, '下载失败,放回队列', PHP_EOL;
                //$consume->pop();
                $produce->push([$url, $saveName]);
            }
            $consume->push($produce->pop());
        });

    }
    echo '消费者over', PHP_EOL;
});
swoole_event_wait();
echo '下载完成';
//todo ffmpeg
