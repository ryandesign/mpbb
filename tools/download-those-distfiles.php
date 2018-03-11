#!/usr/bin/env php
<?php

//printf("Getting MacPorts version\n");
$lines = array();
$macports_version = exec('port -q version', $lines, $code);
if ($code != 0) {
    fwrite(STDERR, sprintf("Error getting MacPorts version\n"));
    exit(1);
}
//printf("MacPorts version is '%s'\n", $macports_version);

//printf("Getting libcurl version\n");
$lines = array();
exec('curl --version', $lines, $code);
if ($code != 0) {
    fwrite(STDERR, sprintf("Error getting libcurl version\n"));
    exit(1);
}
if (preg_match('/ libcurl\/([^ ]+)/', $lines[0], $matches)) {
    $libcurl_version = $matches[1];
} else {
    fwrite(STDERR, sprintf("Error getting libcurl version\n"));
    exit(1);
}
//printf("libcurl version is '%s'\n", $libcurl_version);

$useragent = sprintf('MacPorts/%s libcurl/%s', $macports_version, $libcurl_version);

function getpingtime($host) {
    static $pingtimes = array();
    if (!isset($pingtimes[$host])) {
        //printf("Pinging %s\n", $host);
        $lines = array();
        exec('ping -noq -c2 -t3 ' . escapeshellarg($host), $lines, $code);
        if ($code != 0) {
            //fwrite(STDERR, sprintf("Cannot get ping time for host %s\n", $host));
            $pingtime = 5000;
        } else {
            $pingtime = floatval(explode('/', array_slice($lines, -1)[0])[4]);
        }
        $pingtimes[$host] = $pingtime;
    }
    return $pingtimes[$host];
}

function pingtimecmp($host_a, $host_b) {
    $ping_a = getpingtime($host_a);
    $ping_b = getpingtime($host_b);
    if ($ping_a == $ping_b) return 0;
    return ($ping_a < $ping_b) ? -1 : 1;
}

function urlpingtimecmp($url_a, $url_b) {
    return pingtimecmp(parse_url($url_a, PHP_URL_HOST), parse_url($url_b, PHP_URL_HOST));
}

while (($distfile_json = fgets(STDIN)) !== false) {
    $distfile = json_decode($distfile_json, true);
    if ($distfile === null) {
        fwrite(STDERR, sprintf("Cannot decode json data %s\n", rtrim($distfile_json)));
        continue;
    }
    if (count($distfile['urls']) > 1) {
        usort($distfile['urls'], 'urlpingtimecmp');
    }
    foreach ($distfile['urls'] as $url) {
        printf("%s: download from %s\n", $distfile['path'], $url);
        $dir = dirname($distfile['path']);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
        $fp = fopen($distfile['path'], 'w');
        if ($fp === false) {
            fwrite(STDERR, sprintf("Cannot write to file %s\n", $distfile['path']));
            continue 2;
        }
        $ch = curl_init($url);
        $options = array(
            CURLOPT_FAILONERROR => true,
            CURLOPT_FILE => $fp,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_USERAGENT => $useragent,
        );
        curl_setopt_array($ch, $options);
        if (curl_exec($ch) === false) {
            $errno = curl_errno($ch);
            $error = curl_error($ch);
        } else {
            $errno = false;
        }
        curl_close($ch);
        fclose($fp);
        if ($errno) {
            fwrite(STDERR, sprintf("%s: curl error %d (%s)\n", $distfile['path'], $errno, $error));
            continue;
        }
        $contents = file_get_contents($distfile['path']);
        foreach ($distfile['checksums'] as $algorithm => $expected_digest) {
            $actual_digest = openssl_digest($contents, $algorithm);
            if ($actual_digest != $expected_digest) {
                fwrite(STDERR, sprintf("%s: %s checksum mismatch, actual %s, expected %s\n", $distfile['path'], $algorithm, $actual_digest, $expected_digest));
                $errno = true;
            }
        }
        $contents = null;
        if ($errno) {
            unlink($distfile['path']);
            continue;
        }
        break;
    }
}
