<?php

namespace Tests\Feature\Jobs;

use App\Helpers\GraphQL;
use App\Jobs\ProcessNetflowData;
use App\Models\Account;
use App\Models\DataUsage;
use App\Models\NetflowOnPremise;
use Exception;
use Illuminate\Foundation\Testing\RefreshDatabase;
use ReflectionClass;
use Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;

class ProcessNetflowDataTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function nothing_processed_if_netflow_not_initialized(): void
    {
        $this->assertEquals(0, NetflowOnPremise::count());
        $this->assertEquals(0, DataUsage::count());

        $process = new ProcessNetflowData("");
        $process->handle();

        $this->assertEquals(0, DataUsage::count());
    }

    #[Test]
    public function invalid_file_for_processing_causes_exception(): void
    {
        NetflowOnPremise::factory()->create();

        $this->expectException(Exception::class);
        $this->expectExceptionMessage("File does not exist: 'NOT REAL NAME'");

        $process = new ProcessNetflowData("NOT REAL NAME");
        $process->handle();
    }

    #[Test]
    public function zero_byte_files_are_quietly_ignored(): void
    {
        NetflowOnPremise::factory()->create();
        $this->assertEquals(0, DataUsage::count());

        $process = new ProcessNetflowData("testData/file.zerobytes");
        $process->handle();

        $this->assertEquals(0, DataUsage::count());
    }

    // This test (and others) rely on the fact that we have a clean demo seeded instance that the environment is pointed
    // at and of course that the token is valid so that it can access the instance.
    #[Test]
    public function a_valid_file_updates_statistics_creates_usage_and_saves_account_counters(): void
    {
        $this->artisan("sonar:netflow:initialize")
            ->assertSuccessful();
        $netflow = NetflowOnPremise::first();
        $this->assertNull($netflow->last_processed_filename);

        $this->assertEquals(0, DataUsage::count());

        $process = new ProcessNetflowData("/testData/2024/08/28/nfcapd.202408281445", "/testData/");
        $process->handle();

        $netflow->refresh();
        $statistics = $netflow->statistics;
        $this->assertEqualsCanonicalizing(
            [
                "last" => [
                    "bytes_in" => 0,
                    "bytes_out" => 2795,
                    "flows" => 2,
                ],
                "total" => [
                    "files" => 1,
                    "bytes_in" => 0,
                    "bytes_out" => 2795,
                    "flows" => 2,
                ],
                "usage_records" => 2,
            ],
            $statistics
        );

        $this->assertEquals(2, DataUsage::count());
        $this->assertEquals(0, DataUsage::sum("bytes_in"));
        $this->assertEquals(2795, DataUsage::sum("bytes_out"));
        $this->assertEquals(2, DataUsage::where("account_id", 3)->count());

        $this->assertEquals(1, Account::count());
        $account = Account::first();
        $this->assertEquals(3, $account->id);
        $this->assertEquals(0, $account->bytes_in);
        $this->assertEquals(2795, $account->bytes_out);

        $this->appCleanup();
    }

    #[Test]
    public function ipv6_ranges_match_without_expansion_and_prefer_specific_assignments(): void
    {
        $job = new ProcessNetflowData("");

        $assignments = [
            (object)["subnet" => "2001:db8::/64", "account_id" => 10],
            (object)["subnet" => "2001:db8::1", "account_id" => 20],
        ];

        $this->invokePrivateMethod($job, "createAccountMap", [$assignments]);

        $map = $this->readAccountMap($job);
        $this->assertArrayHasKey(6, $map);
        $this->assertCount(1, $map[6]["prefixes"][64]);
        $this->assertCount(1, $map[6]["single"]);

        $this->assertSame(20, $this->invokePrivateMethod($job, "lookupAccountId", ["2001:db8::1"]));
        $this->assertSame(10, $this->invokePrivateMethod($job, "lookupAccountId", ["2001:db8::1234"]));
        $this->assertNull($this->invokePrivateMethod($job, "lookupAccountId", ["2001:db8:1::1"]));
    }

    #[Test]
    public function ipv4_and_ipv6_networks_are_resolved_via_prefix_matching(): void
    {
        $job = new ProcessNetflowData("");

        $assignments = [
            (object)["subnet" => "192.0.2.0/31", "account_id" => 1],
            (object)["subnet" => "192.0.2.2", "account_id" => 2],
            (object)["subnet" => "2001:db8::/126", "account_id" => 3],
        ];

        $this->invokePrivateMethod($job, "createAccountMap", [$assignments]);

        $this->assertSame(1, $this->invokePrivateMethod($job, "lookupAccountId", ["192.0.2.1"]));
        $this->assertSame(2, $this->invokePrivateMethod($job, "lookupAccountId", ["192.0.2.2"]));
        $this->assertNull($this->invokePrivateMethod($job, "lookupAccountId", ["192.0.2.255"]));
        $this->assertSame(3, $this->invokePrivateMethod($job, "lookupAccountId", ["2001:db8::1"]));
    }

    #[Test]
    public function invalid_assignments_are_ignored_and_do_not_create_broad_matches(): void
    {
        $job = new ProcessNetflowData("");

        $assignments = [
            (object)["subnet" => "2001:db8::/foo", "account_id" => 10],
            (object)["subnet" => "2001:db8::/64abc", "account_id" => 11],
            (object)["subnet" => "not-an-ip", "account_id" => 12],
            (object)["subnet" => "2001:db8::/64", "account_id" => 13],
        ];

        $this->invokePrivateMethod($job, "createAccountMap", [$assignments]);

        $this->assertSame(13, $this->invokePrivateMethod($job, "lookupAccountId", ["2001:db8::1234"]));
        $this->assertNull($this->invokePrivateMethod($job, "lookupAccountId", ["2001:db9::1"]));
    }

    private function appCleanup(): void
    {
        $gql = new GraphQL();
        $netflow = NetflowOnPremise::first();
        $gql->post($netflow->deleteMutation());
    }

    /**
     * @param array<int, mixed> $arguments
     */
    private function invokePrivateMethod(object $object, string $method, array $arguments = []): mixed
    {
        $reflection = new ReflectionClass($object);
        $methodReflection = $reflection->getMethod($method);
        $methodReflection->setAccessible(true);

        return $methodReflection->invokeArgs($object, $arguments);
    }

    private function readAccountMap(ProcessNetflowData $job): array
    {
        $reflection = new ReflectionClass($job);
        $property = $reflection->getProperty("accountMap");
        $property->setAccessible(true);

        /** @var array $map */
        $map = $property->getValue($job);

        return $map;
    }
}
