<?php

namespace Tests\Unit\Postman;

use App\Services\Postman\PostmanUrl;
use PHPUnit\Framework\TestCase;

/**
 * Postman CLI sends to url.host/path/query and ignores url.raw (D-038), so the
 * parts must match what Postman's app stores for the same raw URL.
 */
class PostmanUrlTest extends TestCase
{
    public function test_a_variable_base_url_becomes_one_host_segment(): void
    {
        $url = PostmanUrl::toObject('{{base_url}}/api/login', []);

        $this->assertSame('{{base_url}}/api/login', $url->raw);
        $this->assertSame(['{{base_url}}'], $url->host);
        $this->assertSame(['api', 'login'], $url->path);
        $this->assertObjectNotHasProperty('protocol', $url);
        $this->assertObjectNotHasProperty('query', $url);
    }

    public function test_protocol_host_port_path_query_and_hash(): void
    {
        $url = PostmanUrl::toObject('https://api.example.test:8443/v1/items/?page=2#top', [
            ['key' => 'page', 'value' => '2', 'enabled' => true],
            ['key' => 'debug', 'value' => '1', 'enabled' => false, 'description' => 'off'],
        ]);

        $this->assertSame('https', $url->protocol);
        $this->assertSame(['api', 'example', 'test'], $url->host);
        $this->assertSame('8443', $url->port);
        $this->assertSame(['v1', 'items', ''], $url->path);
        $this->assertSame('top', $url->hash);
        $this->assertEquals([(object) ['key' => 'page', 'value' => '2'], (object) ['key' => 'debug', 'value' => '1', 'disabled' => true, 'description' => 'off']], $url->query);
    }

    public function test_dots_and_slashes_inside_variables_do_not_split(): void
    {
        $url = PostmanUrl::toObject('{{api.host}}:{{port}}/{{path/with/slash}}/x', []);

        $this->assertSame(['{{api.host}}'], $url->host);
        $this->assertSame('{{port}}', $url->port);
        $this->assertSame(['{{path', 'with', 'slash}}', 'x'], $url->path, 'path splits like Postman');
    }

    public function test_path_variables_are_kept_from_the_stored_url(): void
    {
        $previous = (object) ['raw' => '{{base_url}}/items/:id', 'variable' => [(object) ['key' => 'id', 'value' => '7']]];

        $url = PostmanUrl::toObject('{{base_url}}/items/:id', [], $previous);

        $this->assertEquals([(object) ['key' => 'id', 'value' => '7']], $url->variable);
    }

    public function test_query_rows_and_raw_stay_in_step(): void
    {
        $this->assertSame([['key' => 'a', 'value' => '1', 'enabled' => true], ['key' => 'b', 'value' => '', 'enabled' => true]], PostmanUrl::queryFromRaw('{{u}}/x?a=1&b#h'));

        $raw = PostmanUrl::withQuery('{{u}}/x?old=1#h', [
            ['key' => 'a', 'value' => '{{v}}', 'enabled' => true],
            ['key' => 'off', 'value' => '1', 'enabled' => false],
            ['key' => 'flag', 'value' => '', 'enabled' => true],
        ]);
        $this->assertSame('{{u}}/x?a={{v}}&flag#h', $raw);
    }

    public function test_reading_stored_urls(): void
    {
        $this->assertSame('{{u}}/x', PostmanUrl::raw('{{u}}/x'));
        $this->assertSame('http://h.test:81/a/b', PostmanUrl::raw((object) ['protocol' => 'http', 'host' => ['h', 'test'], 'port' => '81', 'path' => ['a', 'b']]));
        $this->assertSame([['key' => 'q', 'value' => '1', 'enabled' => false, 'description' => '']], PostmanUrl::params((object) ['query' => [(object) ['key' => 'q', 'value' => '1', 'disabled' => true]]]));
    }
}
