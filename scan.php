<?php
$url = $argv[1];
$domain = parse_url($url, PHP_URL_HOST);
$domain = preg_replace('|[^a-zA-Z0-9\.]+|', '', $domain);
$data = shell_exec('chromium-browser --headless --incognito --log-net-log=/dev/stdout "' . escapeshellcmd($url) . '" 2>/dev/null');
preg_match_all('|"url":"(https?:)?//([a-zA-Z0-9\-\.]+)|', $data, $matches);
$domains = [];
$ports = [];
foreach ($matches[2] as $i => $match) {
    $domains[$match] = true;
    $ports[$match] = ($matches[1][$i] == 'http:' ? 80 : 443);
}
$domains = array_values(array_unique(array_keys($domains)));
$ips = [];
foreach ($domains as $i => $domain) {
    $ip = gethostbyname($domain);
    if ($ip == $domain) {
        $domains[$i] = null;
    } else {
        $ips[$domain] = $ip;
    }
}
$domains = array_values(array_filter($domains));
$pings = [];
for ($i = 0; $i < 3; $i++) {
    foreach ($ips as $domain => $ip) {
        if (!isset($pings[$ip])) {
            $pings[$ip] = 1000;
        }
        usleep(10000);
        $socket = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
        $connect_timeval = array(
            "sec" => 1,
            "usec" => 0,
        );
        socket_set_option(
            $socket,
            SOL_SOCKET,
            SO_SNDTIMEO,
            $connect_timeval
        );
        socket_set_option(
            $socket,
            SOL_SOCKET,
            SO_RCVTIMEO,
            $connect_timeval
        );
        $time = 0;
        $port = $ports[$domain];
        $start = microtime(true);
        if ($socket && socket_connect($socket, $ip, $port)) {
            $time = round(1000 * (microtime(true) - $start));
            socket_close($socket);
        }
        $pings[$ip] = min($pings[$ip], $time);
    }
}
$ch = curl_init();
curl_setopt($ch, CURLOPT_URL, "http://ip-api.com/batch?fields=continentCode,country,org");
curl_setopt($ch, CURLOPT_POST, 1);
curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode(array_values($ips)));
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
$locations = json_decode(curl_exec($ch), true);
curl_close($ch);
$lines = [];
foreach ($domains as $i => $domain) {
    $location = $locations[$i];
    $ip = $ips[$domain];
    $ping = $pings[$ip];
    $continent = $location['continentCode'] == 'EU' ? 'EU' : '';
    $country = $location['country'];
    $organization = $location['org'];
    $line = ['domain' => $domain, 'ping' => $ping, 'continent' => $continent, 'country' => $country, 'organization' => $organization];
    $lines[] = $line;
}

foreach ($lines as $line) {
    echo json_encode(array_values($line)) . "\n";
}
