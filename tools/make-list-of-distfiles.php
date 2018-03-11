#!/usr/bin/env php
<?php

$platform_subplatform_arch_versions = array(
    'darwin' => array(
        'macosx' => array(
            'i386' => array(16, 15, 14, 13, 12, 11, 10, 9, 8),
            'powerpc' => array(9, 8),
        ),
    ),
);

# bootstrap_cmds, csu, openfst, osxfuse and re2 are testcases where distfiles vary by os.major
# sbcl is a testcase where distfiles vary by os.arch
# InsightToolkit and vtk5 are testcases where distfiles vary by variant

function usage() {
    fwrite(STDERR, sprintf("usage: %s <port> [ <port> ... ]\n", $_SERVER['argv'][0]));
    exit(1);
}

if ($_SERVER['argc'] < 2) usage();


function myescapeshellarg($arg) {
    // If arg is composed of safe characters, return arg directly, to save 2 bytes.
    // This regex contains the most commonly used safe chars. There are undoubtedly other safe chars that could be added.
    return preg_match('/^[a-z0-9.+=_-]+$/i', $arg) ? $arg : escapeshellarg($arg);
}

$pseudoports = array_slice($_SERVER['argv'], 1);
$pseudoports_escaped_shell_args = implode(' ', array_map('myescapeshellarg', $pseudoports));

/*
$lines = array();
define('ARG_MAX', (int)exec('getconf ARG_MAX', $lines, $code));
if ($code != 0) {
    fwrite(STDERR, "Error getting ARG_MAX\n");
    exit(1);
}
*/
define('ARG_MAX', 100000);

function print_distfiles_json($port_selector, $args, $description) {
    static $paths = array();

    //printf("Getting list of %s... ", $description);
    $ports = array();
    exec('port -pq echo ' . $port_selector, $ports, $code);
    if ($code != 0) {
        //printf("\n");
        fwrite(STDERR, sprintf("Error getting list of %s\n", $description));
        return false;
    }
    //printf("%d\n", count($ports));
    if (count($ports) == 0) return true;

    //printf("Getting list of distfiles for %s\n", $description);

    while (count($ports) > 0) {
        $cmd = 'port -pq distfiles';
        $i = 0;
        while ($port = array_pop($ports)) {
            $new_args = ' ' . myescapeshellarg($port) . $args;
            if (strlen($cmd) + strlen($new_args) > ARG_MAX) {
                $ports[] = $port;
                break;
            }
            $cmd .= $new_args;
            ++$i;
        }
        //printf("Doing a batch of %d ports\n", $i);
        //echo "$cmd\n";
        $lines = array();
        //printf("Getting distfiles for %s... ", $description);
        exec($cmd, $lines, $code);
        if ($code != 0) {
            //printf("\n");
            fwrite(STDERR, sprintf("Error getting distfiles for %s\n", $description));
            return false;
        }
        //printf("%d\n", count($lines));

        $skip = false;
        //printf("Processing distfiles for %s", $description);
        foreach ($lines as $line) {
            if (preg_match('/^\[.+\] (\/\S+)$/', $line, $matches)) {
                //printf('.');
                //flush();
                $path = $matches[1];
                $skip = in_array($path, $paths) || file_exists($path);//commented out for debugging
                if ($skip) continue;
                $paths[] = $path;
                //printf("Adding file %s\n", $path);
                $distfiles = array(
                    'path' => $path,
                    'urls' => array(),
                    'checksums' => array(),
                );
            } else if (!$skip) {
                if (preg_match('/^ ([^:]+): (.+)$/', $line, $matches)) {
                    $checksum_type = $matches[1];
                    $checksum_value = $matches[2];
                    $distfiles['checksums'][$checksum_type] = $checksum_value;
                } else if (preg_match('/^  (\S+)$/', $line, $matches)) {
                    $url = $matches[1];
                    $host = explode('/', $url)[2];
                    if (count($distfiles['urls']) > 0 && $host == 'svn.macports.org') continue;
                    if (preg_match('/(?:^|\.)distfiles\.macports\.org$/', $host)) continue;
                    $distfiles['urls'][] = $url;
                } else if ($line == '') {
                    if (count($distfiles['urls']) == 0) {
                        unset($path[$path]);
                    } else {
                        printf("%s\n", json_encode($distfiles, JSON_UNESCAPED_SLASHES));
                    }
                }
            }
        }
    }
    //printf("\n");
    return true;
}

$count_platforms = 0;
foreach ($platform_subplatform_arch_versions as $platform => $subplatform_arch_versions) {
    foreach ($subplatform_arch_versions as $subplatform => $arch_versions) {
        foreach ($arch_versions as $arch => $versions) {
            foreach ($versions as $version) {
                ++$count_platforms;
            }
        }
    }
}

$native_platform = strtolower(php_uname('s'));
switch ($native_platform) {
    case 'darwin':
        $native_subplatform = is_dir('/System/Library/Frameworks/Carbon.framework') ? 'macosx' : 'puredarwin';
        break;
    default:
        $native_subplatform = '';
}
$native_version = explode('.', php_uname('r'))[0];
$native_arch = php_uname('m');
switch ($native_arch) {
    case 'Power Macintosh':
        $native_arch = 'powerpc';
        break;
    case 'i586':
    case 'i686':
    case 'x86_64':
        $native_arch = 'i386';
        break;
}

$i = 0;
foreach ($platform_subplatform_arch_versions as $platform => $subplatform_arch_versions) {
    $platform_args = ($platform == $native_platform) ? '' : ' os.platform=' . myescapeshellarg($platform);
    foreach ($subplatform_arch_versions as $subplatform => $arch_versions) {
        $subplatform_args = ($subplatform == $native_subplatform) ? '' : ' os.subplatform=' . myescapeshellarg($subplatform);
        foreach ($arch_versions as $arch => $versions) {
            $arch_args = ($arch == $native_arch) ? '' : ' os.arch=' . myescapeshellarg($arch);
            foreach ($versions as $version) {
                $version_args = ($version == $native_version) ? '' : ' os.major=' . myescapeshellarg($version);
                $description = sprintf("ports with platform '%s_%s_%s (%s)' (%s of %s)", $platform, $version, $arch, $subplatform, ++$i, $count_platforms);
                //printf("Listing distfiles for %s\n", description);
                print_distfiles_json($pseudoports_escaped_shell_args, $platform_args . $subplatform_args . $arch_args . $version_args, $description);
            }
        }
    }
}

$variants = array();
//printf("Getting list of variants... ");
exec('port -pq info --index --line --variants ' . $pseudoports_escaped_shell_args . ' | tr "," "\n" | sort -u', $variants, $code);
if ($code != 0) {
    //printf("\n");
    fwrite(STDERR, sprintf("Error getting list of variants\n"));
    exit(1);
}
if ($variants[0] === '') array_shift($variants);
//printf("%d\n", count($variants));

$i = 0;
$count_variants = count($variants);
foreach ($variants as $variant) {
    $description = sprintf("ports with variant '%s' (%d of %d)", $variant, ++$i, $count_variants);
    //printf("Listing distfiles for %s\n", $description);
    $port_selector = '\( ' . $pseudoports_escaped_shell_args . ' \) and ' . myescapeshellarg('variant:(^|\s)' . preg_quote($variant) . '(\s|$)');
    $args = ' ' . myescapeshellarg('+' . $variant);
    print_distfiles_json($port_selector, $args, $description);
}
