<?php

declare(strict_types=1);

use Symfony\Component\Dotenv\Dotenv;
use League\Csv\Writer;

require_once 'vendor/autoload.php';

$dotenv = new Dotenv();
$dotenv->load(__DIR__ . '/.env');

if (!empty($_GET['action'])) {
    if ($_GET['action'] === 'to_csv') {
        if (file_exists('output/sites.php')) {
            require_once 'output/sites.php';
        }
        if (!empty($installs)) {
            to_csv($installs);
        } else {
            throw new Exception("No installs");
        }
    } else if ($_GET['action'] === 'get_installs') {
        get_installs();
    } else {
        throw new Exception("Invalid action");
    }
} else {
    get_installs();
}


// Git DNS records
function get_installs(): void
{
    dump("Getting installs...");

    $ch = curl_init();
    $installs = [];

    $limit = !empty($_GET['limit']) ? $_GET['limit'] : 999;
    $offset = !empty($_GET['offset']) ? $_GET['offset'] : 0;

    curl_setopt($ch, CURLOPT_URL, 'https://api.wpengineapi.com/v1/installs?limit=' . $limit . '&offset=' . $offset);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'GET');

    $headers = array();
    $credString = $_ENV['WPE_API_UN'] . ":" . $_ENV['WPE_API_PW'];
    $headers[] = "Authorization: Basic " . base64_encode($credString);
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);

    $result = curl_exec($ch);
    if (curl_errno($ch)) {
        throw new Exception(curl_error($ch));
    }

    $data = json_decode($result, true);
    if (empty($data['results'])) throw new Exception("No installs");

    foreach ($data['results'] as $result) {
        if ($result['environment'] !== 'production') continue;

        $installs[$result['name']] = [
            'id' => $result['id']
        ];
    }

    // Dump to unused file for debugging purposes
    $installs_str = var_export($installs, true);
    $var = "<?php" . PHP_EOL . "\$installs = $installs_str;" . PHP_EOL . "?>";
    fopen('output/all_sites.php', 'w');
    file_put_contents('output/all_sites.php', $var);

    dump("Done");
    dump("Total installs: " . count($installs));

    dump("Getting DNS info...");

    foreach ($installs as $key => $installData) {
        $ch = curl_init();

        curl_setopt($ch, CURLOPT_URL, 'https://api.wpengineapi.com/v1/installs/' . $installData['id'] . '/domains');
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'GET');

        $headers = [];
        $credString = $_ENV['WPE_API_UN'] . ":" . $_ENV['WPE_API_PW'];
        $headers[] = "Authorization: Basic " . base64_encode($credString);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);

        $result = curl_exec($ch);
        if (curl_errno($ch)) {
            throw new Exception(curl_error($ch));
        }

        $data = json_decode($result, true);
        if (!empty($data['results'])) {
            foreach ($data['results'] as $domain) {
                if (str_contains($domain['name'], 'wpengine')) {
                    continue;
                }

                $ch = curl_init();

                curl_setopt($ch, CURLOPT_URL, 'https://' . $domain['name']);
                curl_setopt($ch, CURLOPT_HEADER, true);
                curl_setopt($ch, CURLOPT_NOBODY, true);  // we don't need body
                curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                curl_exec($ch);
                $responseCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);

                if ($responseCode !== 200) {
                    continue;
                }

                ob_start();
                passthru("dig A +short {$domain['name']} | grep '^[0-9]\+\.[0-9]\+\.[0-9]\+\.[0-9]\+$'");
                $domainRecords = ob_get_contents();
                ob_end_clean();

                $domainRecords = explode(PHP_EOL, $domainRecords);
                if (empty($domainRecords)) continue;

                $ipsToIgnore = ['141.193.213.20', '141.193.213.21', '141.192.213.10', '141.192.213.11'];
                foreach ($domainRecords as $domainRecord) {
                    if (empty($domainRecord) || in_array($domainRecord, $ipsToIgnore)) continue;

                    $installs[$key]['domains'][$domain['name']]['ip'] = $domainRecord;
                }
            }
        }

        // If no domains on this install are "real"
        if (empty($installs[$key]['domains'])) {
            unset($installs[$key]);
        }

        // Slows it down a little but so you can see that it's still working
        $installs_str = var_export($installs, true);
        $var = "<?php" . PHP_EOL . "\$installs = $installs_str;" . PHP_EOL . "?>";
        fopen('output/sites.php', 'w');
        file_put_contents('output/sites.php', $var);
    }

    dump("Done");
    dump("Total installs with \"real\" domains: " . count($installs));

    dump("Getting SSL expiration date...");

    $currentDateTime = time();

    foreach ($installs as &$install) {
        foreach ($install['domains'] as $domainName => &$domainData) {

            $get = stream_context_create(array("ssl" => array("capture_peer_cert" => TRUE, "peer_name" => $domainName)));
            $read = stream_socket_client(
                "ssl://" . $domainName . ":443",
                $errno,
                $errstr,
                30,
                STREAM_CLIENT_CONNECT,
                $get
            );
            $cert = stream_context_get_params($read);
            $certinfo = openssl_x509_parse($cert['options']['ssl']['peer_certificate']);

            $expirationDateTime = $certinfo['validTo_time_t'];
            $domainData['expired'] = $expirationDateTime <= $currentDateTime;
            $domainData['expiration_date'] = date('Y-m-d H:i:s', $expirationDateTime);
        }
    }

    $installs_str = var_export($installs, true);
    $var = "<?php" . PHP_EOL . "\$installs = $installs_str;" . PHP_EOL . "?>";
    fopen('output/sites.php', 'w');
    file_put_contents('output/sites.php', $var);

    dump("Done");
}

// Array to CSV, domain per row
function to_csv(array $installs): void
{
    if (empty($installs)) throw new Exception("No installs");

    $header = ['install', 'domain', 'current_ip', 'expired', 'expiration_date'];
    $records = [];

    $csv = Writer::createFromString();
    $csv->insertOne($header);

    foreach ($installs as $installname => $installData) {
        if (empty($installData['domains'])) continue;
        foreach ($installData['domains'] as $domainName => $domainData) {
            $records[] = [
                $installname,
                $domainName,
                $domainData['ip'],
                $domainData['expired'] ? 'yes' : 'no',
                $domainData['expiration_date']
            ];
        }
    }

    $csv->insertAll($records);

    fopen('output/sites.csv', 'w');
    file_put_contents('output/sites.csv', $csv->toString());
}
