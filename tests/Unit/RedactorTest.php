<?php

namespace Tests\Unit;

use App\Services\Redactor;
use PHPUnit\Framework\Attributes\DataProvider;
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

    /**
     * @return array<string, array{string, string}>
     */
    public static function unknownSecrets(): array
    {
        return [
            'bearer header' => ['Authorization: Bearer YOUR_ACCESS_TOKEN.abc', 'Authorization: Bearer ********'],
            'basic header in json' => ['"authorization": "Basic WU9VUl9QQVNTV09SRA=="', '"authorization": "Basic ********"'],
            'proxy authorization' => ['Proxy-Authorization: Digest YOUR_DIGEST', 'Proxy-Authorization: Digest ********'],
            'bare bearer token' => ['got Bearer YOUR_ACCESS_TOKEN_1234', 'got Bearer ********'],
            'set-cookie' => ['Set-Cookie: session=YOUR_SESSION; Path=/; HttpOnly', 'Set-Cookie: ********'],
            'cookie' => ['Cookie: a=YOUR_A; b=YOUR_B', 'Cookie: ********'],
            'json members' => ['{"password":"YOUR_PASSWORD","access_token": "YOUR_ACCESS_TOKEN","user":"me"}', '{"password":"********","access_token": "********","user":"me"}'],
            'json escaped quote' => ['{"client_secret":"YOUR\\"CLIENT_SECRET"}', '{"client_secret":"********"}'],
            'query pairs' => ['/x?api_key=YOUR_API_KEY&page=2&token=YOUR_TOKEN&client_secret=YOUR_CLIENT_SECRET#f', '/x?api_key=********&page=2&token=********&client_secret=********#f'],
            'form pairs' => ['apikey=YOUR_API_KEY password=YOUR_PASSWORD', 'apikey=******** password=********'],
        ];
    }

    #[DataProvider('unknownSecrets')]
    public function test_patterns_catch_secrets_it_was_not_told_about(string $text, string $expected): void
    {
        $this->assertSame($expected, (new Redactor)->redact($text));
    }

    public function test_ordinary_text_is_left_alone(): void
    {
        foreach ([
            'Bearer token missing',
            'author=Jane&page=2',
            '"cookies": []',
            'expected response to have status code 201 but got 200',
            'api_key=******** already masked',
        ] as $text) {
            $this->assertSame($text, (new Redactor)->redact($text));
        }
    }
}
