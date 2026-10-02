<?php

namespace Modules\Login\Tests\Unit;

use Modules\Login\Services\DeviceService\DeviceIdHasher;
use PHPUnit\Framework\TestCase;

class DeviceIdHasherTest extends TestCase
{
    public function test_hash_is_raw_16_bytes_of_md5(): void
    {
        $hash = DeviceIdHasher::hashDevice(3, '9774d56d682e549c');

        $this->assertSame(16, strlen($hash));
        $this->assertSame(md5('3:9774d56d682e549c', true), $hash);
        $this->assertSame(bin2hex($hash), DeviceIdHasher::hashHex(3, '9774d56d682e549c'));
    }

    public function test_normalizes_case_and_whitespace(): void
    {
        $this->assertSame(
            DeviceIdHasher::hashDevice(1, 'e621e1f8-c36c-495a-93fc-0c247a3e6e5f'),
            DeviceIdHasher::hashDevice(1, "  E621E1F8-C36C-495A-93FC-0C247A3E6E5F \n")
        );
    }

    public function test_same_device_in_different_apps_has_different_hash(): void
    {
        $this->assertNotSame(DeviceIdHasher::hashDevice(1, 'abc'), DeviceIdHasher::hashDevice(2, 'abc'));
    }

    public function test_app_id_and_device_id_are_separated(): void
    {
        // Noi thang "1"."2abc" va "12"."abc" se trung nhau
        $this->assertNotSame(DeviceIdHasher::hashDevice(1, '2abc'), DeviceIdHasher::hashDevice(12, 'abc'));
    }

    public function test_accepts_string_app_id_and_numeric_device_id_string(): void
    {
        $this->assertSame(DeviceIdHasher::hashDevice(5, '12345'), DeviceIdHasher::hashDevice('5', (string) 12345));
    }

    public function test_has_version(): void
    {
        $this->assertSame(1, DeviceIdHasher::VERSION);
    }
}
