<?php

namespace App\Jobs;

use App\Helpers\GraphQL;
use App\Models\Account;
use App\Models\DataUsage;
use App\Models\NetflowOnPremise;
use Carbon\Carbon;
use Exception;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Storage;
use InvalidArgumentException;

class ProcessNetflowData implements ShouldQueue, ShouldBeUnique
{
    use Queueable;

    protected GraphQL $gql;
    protected array $accountMap = [];
    protected array $usageMap = [];
    protected array $statistics = [];

    public function __construct(
        public string $file,
        public ?string $storagePath = "/netflowData/"
    ) {
        $this->initializeAccountMap();
    }

    public function handle(): void
    {
        $this->gql = new GraphQL();
        $netflow = NetflowOnPremise::first();

        if (is_null($netflow)) {
            return;
        }

        if (! Storage::exists($this->file)) {
            throw new Exception("File does not exist: '{$this->file}'");
        }

        $filename = basename($this->file);
        $size = Storage::size($this->file);

        if ($filename === "" || $size === 0) {
            return;
        }

        // If we have never processed files then initialize statistics and set the last processed filename to the one
        // prior to what we received.
        if (is_null($netflow->last_processed_filename)) {
            $this->initializeNetflow($netflow, $filename);;
        }
        $this->statistics = $netflow->statistics;

        do {
            $dumpFile = $this->incrementFile($netflow);
            if ($dumpFile === "") {
                throw new Exception("Corrupt last processed filename, cannot continue");
            }
            $this->statistics["last"] = [
                "bytes_in" => 0,
                "bytes_out" => 0,
                "flows" => 0,
            ];

            $netflow->last_processed_timestamp = Carbon::now("UTC")->toDateTimeString();
            $processed = $this->processFile($dumpFile);
            if (! $processed) {
                return;
            }

            $this->writeUsage();

            $this->adjustTotals();
            $netflow->statistics = $this->statistics;
            $this->updateProgress($netflow);
            $this->initializeAccountMap();
        } while ($netflow->last_processed_filename !== $filename);
    }

    private function writeUsage(): void {
        foreach ($this->usageMap as $accountId => $usage) {
            $account = Account::find($accountId);
            if (is_null($account)) {
                $account = Account::create([
                    "id" => $accountId,
                    "bytes_in" => 0,
                    "bytes_out" => 0,
                ]);
                DataUsage::create([
                    "end_time" => $usage["end"]->subMillisecond(),
                    "bytes_in" => 0,
                    "bytes_out" => 0,
                    "account_id" => $accountId,
                ]);
            }
            $account->bytes_in += $usage["bytes_in"];
            $account->bytes_out += $usage["bytes_out"];
            $account->save();
            DataUsage::create([
                "end_time" => $usage["end"]->toISOString(),
                "bytes_in" => $account->bytes_in,
                "bytes_out" => $account->bytes_out,
                "account_id" => $accountId,
            ]);
        }
        $this->usageMap = [];
    }

    private function processFile(string $file): bool
    {
        $ipList = $this->findAllIpAssignments();
        if (empty($ipList)) {
            return false;
        }
        $this->createAccountMap($ipList);
        $flows = $this->getNetflowDataFromFile($file);
        if (empty($flows)) {
            return true;
        }
        foreach ($flows as $flow) {
            $this->processFlow($flow);
        }
        return true;
    }

    private function createAccountMap(array $ipList): void
    {
        $this->initializeAccountMap();

        foreach ($ipList as $ip) {
            if (! isset($ip->subnet, $ip->account_id)) {
                continue;
            }

            $accountId = (int)$ip->account_id;
            $subnet = trim((string)$ip->subnet);

            try {
                if (str_contains($subnet, "/")) {
                    [$address, $prefix] = explode("/", $subnet, 2);
                    $address = trim($address);
                    $prefix = trim($prefix);
                    if ($address === "" || $prefix === "") {
                        continue;
                    }

                    if (! preg_match("/^\d+$/", $prefix)) {
                        continue;
                    }

                    $this->storeNetworkAssignment($address, (int)$prefix, $accountId);
                    continue;
                }

                $this->storeSingleAddress($subnet, $accountId);
            } catch (InvalidArgumentException) {
                // Invalid assignment values should not stop processing of all data.
                continue;
            }
        }

        $this->finalizePrefixOrdering(4);
        $this->finalizePrefixOrdering(6);
    }

    private function initializeAccountMap(): void
    {
        $this->accountMap = [
            4 => [
                "single" => [],
                "prefixes" => [],
                "ordered_prefixes" => [],
            ],
            6 => [
                "single" => [],
                "prefixes" => [],
                "ordered_prefixes" => [],
            ],
        ];
    }

    private function storeSingleAddress(string $ip, int $accountId): void
    {
        if ($ip === "") {
            return;
        }

        $packed = $this->inetPton($ip);
        $version = $this->ipVersionFromPacked($packed);
        $this->accountMap[$version]["single"][bin2hex($packed)] = $accountId;
    }

    private function storeNetworkAssignment(string $ip, int $prefixLength, int $accountId): void
    {
        $packed = $this->inetPton($ip);
        $version = $this->ipVersionFromPacked($packed);
        $this->validatePrefixLength($prefixLength, $version);

        $masked = $this->applyPrefixMask($packed, $prefixLength);
        $key = bin2hex($masked);

        $this->accountMap[$version]["prefixes"][$prefixLength][$key] = $accountId;
    }

    private function finalizePrefixOrdering(int $version): void
    {
        if (empty($this->accountMap[$version]["prefixes"])) {
            $this->accountMap[$version]["ordered_prefixes"] = [];
            return;
        }

        $prefixes = array_keys($this->accountMap[$version]["prefixes"]);
        rsort($prefixes);
        $this->accountMap[$version]["ordered_prefixes"] = $prefixes;
    }

    private function lookupAccountId(string $ip): ?int
    {
        $ip = trim($ip);
        if ($ip === "") {
            return null;
        }

        $packed = @inet_pton($ip);
        if ($packed === false) {
            return null;
        }

        $version = $this->ipVersionFromPacked($packed);
        $singleKey = bin2hex($packed);

        if (isset($this->accountMap[$version]["single"][$singleKey])) {
            return $this->accountMap[$version]["single"][$singleKey];
        }

        foreach ($this->accountMap[$version]["ordered_prefixes"] as $prefixLength) {
            $masked = $this->applyPrefixMask($packed, $prefixLength);
            $key = bin2hex($masked);
            if (isset($this->accountMap[$version]["prefixes"][$prefixLength][$key])) {
                return $this->accountMap[$version]["prefixes"][$prefixLength][$key];
            }
        }

        return null;
    }

    private function applyPrefixMask(string $packedIp, int $prefixLength): string
    {
        $byteLength = strlen($packedIp);
        $maxPrefix = $byteLength * 8;

        if ($prefixLength < 0 || $prefixLength > $maxPrefix) {
            throw new InvalidArgumentException("Invalid prefix length '{$prefixLength}' for address length '{$byteLength}'.");
        }

        $masked = "";
        for ($i = 0; $i < $byteLength; $i++) {
            $bitsRemaining = $prefixLength - ($i * 8);

            if ($bitsRemaining >= 8) {
                $masked .= $packedIp[$i];
                continue;
            }

            if ($bitsRemaining <= 0) {
                $masked .= "\0";
                continue;
            }

            $byte = ord($packedIp[$i]);
            $mask = (0xFF << (8 - $bitsRemaining)) & 0xFF;
            $masked .= chr($byte & $mask);
        }

        return $masked;
    }

    private function validatePrefixLength(int $prefixLength, int $version): void
    {
        $maxPrefix = $version === 4 ? 32 : 128;
        if ($prefixLength < 0 || $prefixLength > $maxPrefix) {
            throw new InvalidArgumentException("Invalid prefix length '{$prefixLength}' for IPv{$version} subnet.");
        }
    }

    private function ipVersionFromPacked(string $packedIp): int
    {
        $length = strlen($packedIp);
        if ($length === 4) {
            return 4;
        }
        if ($length === 16) {
            return 6;
        }

        throw new InvalidArgumentException("Unknown IP version for packed address of length '{$length}'.");
    }

    private function inetPton(string $ip): string
    {
        $packed = @inet_pton($ip);
        if ($packed === false) {
            throw new InvalidArgumentException("Invalid IP address '{$ip}'.");
        }

        return $packed;
    }


    /**
     * This will process each line of output from the nfdump program using pipe output mode. A valid line has 22 fields
     * defined as follows:
     *
     *  0    Address family PF_INET or PF_INET6
     *  1    Timestamp with ms as first seen
     *  2    Timestamp with ms as last seen
     *  3    Protocol
     *  4-7  Source address as 4 32 bit numbers
     *  8    Source port
     *  9-12 Destination address as 4 32 but number
     *  13   Destination port
     *  14   Source AS number
     *  15   Destination AS number
     *  16   Input interface
     *  17   Output interface
     *  18   TCP Flags (uses set bits: 0 - FIN, 1 - SYN, 2 - RESET, 3 - PUSH, 4 - ACK, 5 - URGENT, ie 6 = SYN + RESET)
     *  19   Type of Service (Tos)
     *  20   Packets
     *  21   Bytes
     */
    private function processFlow(string $flow): void
    {
        $flowFields = explode("|", $flow);
        if (count($flowFields) !== 22) {
            return;
        }
        $srcIp = $this->longs2Ip([$flowFields[4], $flowFields[5], $flowFields[6], $flowFields[7]]);
        $dstIp = $this->longs2Ip([$flowFields[9], $flowFields[10], $flowFields[11], $flowFields[12]]);
        if (is_null($srcIp) || is_null($dstIp)) {
            return;
        }
        $srcAccountId = $this->lookupAccountId($srcIp);
        $dstAccountId = $this->lookupAccountId($dstIp);

        if (is_null($srcAccountId) && is_null($dstAccountId)) {
            return;
        }

        $startDate = Carbon::createFromTimestampMs($flowFields[1]);
        $endDate = Carbon::createFromTimestampMs($flowFields[2]);

        if (! is_null($srcAccountId)) {
            $this->collectUsage($srcAccountId, $startDate, $endDate, 0, (int)$flowFields[21]);
        }

        if (! is_null($dstAccountId)) {
            $this->collectUsage($dstAccountId, $startDate, $endDate, (int)$flowFields[21], 0);
        }
    }

    private function collectUsage(int $account, Carbon $startDate, Carbon $endDate, int $bytesIn, int $bytesOut): void
    {
        if(! isset($this->usageMap[$account])) {
            $this->usageMap[$account] = [
                "start" => $startDate,
                "end" => $endDate,
                "bytes_in" => 0,
                "bytes_out" => 0
            ];
        }
        if ($startDate->lessThan($this->usageMap[$account]["start"])) {
            $this->usageMap[$account]["start"] = $startDate;
        }
        if ($endDate->greaterThan($this->usageMap[$account]["end"])) {
            $this->usageMap[$account]["end"] = $endDate;
        }
        $this->usageMap[$account]["bytes_in"] += $bytesIn;
        $this->usageMap[$account]["bytes_out"] += $bytesOut;

        $this->statistics["last"]["flows"]++;
        $this->statistics["last"]["bytes_in"] += $bytesIn;
        $this->statistics["last"]["bytes_out"] += $bytesOut;
    }

    private function longs2Ip(array $integers): ?string
    {
        if ($integers[0] == 0 && $integers[1] == 0 && $integers[2] == 0)
        {
            $stringIp = long2ip($integers[3]);
        } else {
            $packed = pack('N4', $integers[0], $integers[1], $integers[2], $integers[3]);
            $stringIp = inet_ntop($packed);
        }
        if ($stringIp === false) {
            return null;
        }
        return $stringIp;
    }

    private function getNetflowDataFromFile(string $file): array
    {
        exec("/usr/local/bin/nfdump -r " .escapeshellarg($file) . " -A srcip,dstip -O tstart -o pipe",$output,$returnVar);
        if ($returnVar !== 0)
        {
            $outputAsLine = implode(",",$output);
            throw new Exception("NFDUMP ERROR: {$returnVar} ({$outputAsLine}) when trying to parse netflow data from {$file}!");
        }
        return $output;
    }


    public function findAllIpAssignments(): array
    {
        $query = <<<GQL
query accountIpAssignments {
  account_ip_assignments {
    entities {
      account_id
      subnet
    }
  }
}

GQL;
        $assignmentResponse = $this->gql->post(["query" => $query]);
        $body = json_decode($assignmentResponse->getBody());
        if ($assignmentResponse->getStatusCode() !== 200) {
            return [];
        }
        return $body?->data?->account_ip_assignments?->entities ?? [];
    }

    public function updateProgress(NetflowOnPremise $netflow): void
    {
        $netflow->save();
        $response = $this->gql->post($netflow->updateMutation());
        $body = json_decode($response->getBody());
        if (
            ! $response->getStatusCode() === 200
            || is_null($body?->data?->updateNetflowOnPremise)
        ) {
            throw new Exception($body->errors[0]->message ?? "Could not update progress!");
        }
    }

    private function adjustTotals(): void
    {
        $this->statistics["total"]["files"]++;
        $this->statistics["total"]["bytes_in"] += $this->statistics["last"]["bytes_in"];
        $this->statistics["total"]["bytes_out"] += $this->statistics["last"]["bytes_out"];
        $this->statistics["total"]["flows"] += $this->statistics["last"]["flows"];
        $this->statistics["usage_records"] = DataUsage::count();
    }

    private function incrementFile(NetflowOnPremise $netflow): string
    {
        $fileParts = explode(".", $netflow->last_processed_filename);
        if (count($fileParts) !== 2 || $fileParts[0] !== "nfcapd") {
            return "";
        }
        $lastDate = Carbon::parse($fileParts[1]);
        $newDate = $lastDate->clone();
        $newDate->addMinutes(5);

        $netflow->last_processed_filename = "nfcapd." . $newDate->format("YmdHi");
        $filename = $this->storagePath . $newDate->year . "/" . $newDate->format("m") . "/" .
            $newDate->format("d") . "/" . $netflow->last_processed_filename;
        //  This attempts to skip missing filenames / already processed files until we are in the future
        if (
            ! Storage::exists($filename)
            || $newDate->lessThan($lastDate)
        ) {
            if ($newDate->isFuture()) {
                return "";
            }
            return $this->incrementFile($netflow);
        }
        $netflow->last_processed_size = Storage::size($filename);
        return Storage::path($filename);
    }

    private function initializeNetflow(NetflowOnPremise $netflow, string $filename): void
    {
        $statistics = [
            "last" => [
                "bytes_in" => 0,
                "bytes_out" => 0,
                "flows" => 0,
            ],
            "total" => [
                "files" => 0,
                "bytes_in" => 0,
                "bytes_out" => 0,
                "flows" => 0,
            ],
            "usage_records" => 0,
        ];
        $fileParts = explode(".", $filename);
        if (count($fileParts) !== 2 || $fileParts[0] !== "nfcapd") {
            throw new Exception("Cannot initialize application, unknown filename format: '{$filename}'");
        }
        $lastDate = Carbon::parse($fileParts[1]);
        $lastDate->subMinutes(5);

        $netflow->last_processed_filename = "nfcapd." . $lastDate->format("YmdHi");
        $netflow->last_processed_size = 0;
        $netflow->statistics = $statistics;
    }
}
