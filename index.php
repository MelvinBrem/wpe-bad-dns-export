<?php

declare(strict_types=1);

require_once 'vendor/autoload.php';

$installs = [];

if (file_exists('output/sites.php')) {
    require_once 'output/sites.php';
}

$ch = curl_init();

// Git installs
if (empty($_GET['justinstalls']) || empty($_GET['justinstalls']) && $_GET['justinstalls'] !== 'true') {
    if (empty($installs)) {
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
    }
}


// Git DNS records
if (empty($_GET['justdns']) || empty($_GET['justdns']) && $_GET['justdns'] !== 'true') {
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
