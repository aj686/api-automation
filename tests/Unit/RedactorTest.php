<?php

namespace Tests\Unit;

use App\Services\Redactor;
use PHPUnit\Framework\TestCase;

class RedactorTest extends TestCase
{
    public function test_known_values_are_masked_raw_and_url_encoded(): void
    {
        $redactor = new Redactor(['YOUR_PASSWORD/+= x', null, 'abc']);

        $this->assertSame('p=******** q=******** r=********', $redactor->redact('p=YOUR_PASSWORD/+= x q=YOUR_PASSWORD%2F%2B%3D%20x r=YOUR_PASSWORD%2F%2B%3D+x'));
        $this->assertSame('abc stays', $redactor->redact('abc stays'), 'values under 4 characters are ignored');
    }

    public function test_longest_value_wins(): void
    {
        $this->assertSame('********', (new Redactor(['YOUR_TOKEN', 'YOUR_TOKEN_LONG']))->redact('YOUR_TOKEN_LONG'));
    }

    public function test_credential_named_query_values(): void
    {
        $redactor = new Redactor;

        $this->assertSame('********', $redactor->redactQueryValue('access_token', 'anything'));
        $this->assertSame('42', $redactor->redactQueryValue('page', '42'));
        $this->assertNull($redactor->redactQueryValue('api_key', null));
    }
}
