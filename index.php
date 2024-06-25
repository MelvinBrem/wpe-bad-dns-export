<?php

declare(strict_types=1);

require_once 'vendor/autoload.php';

if (file_exists('output/sites.php')) {
    require_once 'output/sites.php';
}

if (!empty($_GET['action'])) {
    switch ($_GET['action']) {
        case 'getinstalls':
            get_installs();
            break;
        case 'getdns':
            if (!empty($installs)) {
                get_dns($installs);
            }
            break;
        case 'clean':
            if (!empty($installs)) {
                clean_installs($installs);
            }
            break;
        case 'tocsv':
            if (!empty($installs)) {
                to_csv($installs);
            }
            break;
    }
} else {
    $installs = get_installs();
    get_dns($installs);
    clean_installs($installs);
    to_csv($installs);
}

// Git installs
function get_installs(): array
{
    $ch = curl_init();
    $installs = [];

    curl_setopt($ch, CURLOPT_URL, 'https://api.wpengineapi.com/v1/installs?limit=1000');
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'GET');

    $headers = array();
    $cred_string = '16f91498-4edb-434c-897d-93c2110d8e8d' . ":" . 'g4AfA4W5CjVzIrv1TAuG3lCYmz0dY44s';
    $headers[] = "Authorization: Basic " . base64_encode($cred_string);
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);

    $result = curl_exec($ch);
    if (curl_errno($ch)) {
        echo 'Error:' . curl_error($ch);
    }

    $data = json_decode($result, true);
    foreach ($data['results'] as $result) {
        if ($result['environment'] !== 'production') continue;

        $installs[$result['name']] = [
            'id' => $result['id']
        ];
    }

    // Save output to minimize API calls
    $installs_str = var_export($installs, true);
    $var = "<?php\n\n\$installs = $installs_str;\n\n?>";
    fopen('output/sites.php', 'w');
    file_put_contents('output/sites.php', $var);

    return $installs;
}

// Git DNS records
function get_dns(array $installs): void
{
    $ch = curl_init();

    foreach ($installs as &$install) {
        curl_setopt($ch, CURLOPT_URL, 'https://api.wpengineapi.com/v1/installs/' . $install['id'] . '/domains');
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'GET');

        $headers = array();
        $cred_string = '16f91498-4edb-434c-897d-93c2110d8e8d' . ":" . 'g4AfA4W5CjVzIrv1TAuG3lCYmz0dY44s';
        $headers[] = "Authorization: Basic " . base64_encode($cred_string);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);

        $result = curl_exec($ch);
        if (curl_errno($ch)) {
            echo 'Error:' . curl_error($ch);
        }

        $domainRequest = json_decode($result, true);

        foreach ($domainRequest['results'] as $domain) {

            if (
                str_contains($domain['name'], 'wpengine') ||
                in_array($domain['name'], ['wpengine.com', 'wpengine.net']) ||
                !empty($installs['domains']) && in_array($domain['name'],  $install['domains'])
            ) {
                continue;
            };

            $domainRecords = dns_get_record($domain['name'], DNS_A);

            $ipsToIgnore = ['141.193.213.10', '141.193.213.11', '141.193.213.20', '141.193.213.21'];

            if (!empty($domainRecords)) {
                foreach ($domainRecords as $domainRecord) {
                    if (in_array($domainRecord['ip'], $ipsToIgnore)) {
                        continue;
                    }

                    $install['domains'][$domain['name']] = $domainRecord['ip'];
                }
            }
        }

        $installs_str = var_export($installs, true);
        $var = "<?php\n\n\$installs = $installs_str;\n\n?>";
        fopen('output/sites.php', 'w');
        file_put_contents('output/sites.php', $var);
    }
}

// Clean up empty/ installs with no DNS issues
function clean_installs(array $installs): void
{
    if (empty($_GET['justclean']) || empty($_GET['justclean']) && $_GET['justclean'] !== 'true') {
        foreach ($installs as $key => $install) {
            if (empty($install['domains'])) {
                unset($installs[$key]);
            }
        }
    }

    $installs_str = var_export($installs, true);
    $var = "<?php\n\n\$installs = $installs_str;\n\n?>";
    fopen('output/sites_clean.php', 'w');
    file_put_contents('output/sites_clean.php', $var);
}

// Array to CSV, domain per row
function to_csv(array $installs): void
{
    if (empty($_GET['justcsv']) || empty($_GET['justcsv']) && $_GET['justcsv'] !== 'true') {
        foreach ($$installs as $install) {
            dump($install['domains']);
        }
    }
}
