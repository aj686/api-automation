<?php

namespace Tests\Unit\Postman;

use App\Exceptions\ImportException;
use App\Services\CollectionImporter;
use App\Services\Postman\CollectionDocument;
use Tests\TestCase;

class CollectionDocumentTest extends TestCase
{
    private function document(): CollectionDocument
    {
        return CollectionDocument::fromJson(json_encode([
            'info' => ['name' => 'Demo', 'schema' => 'https://schema.getpostman.com/json/collection/v2.1.0/collection.json'],
            'event' => [['listen' => 'prerequest', 'script' => ['exec' => ['console.log(1);']]]],
            'variable' => [['key' => 'limit', 'value' => '10']],
            'auth' => ['type' => 'bearer', 'bearer' => [['key' => 'token', 'value' => '{{token}}']]],
            'item' => [
                ['id' => 'f', 'name' => 'Auth', 'item' => [
                    ['id' => 'login', 'name' => 'Login', 'request' => [
                        'method' => 'POST',
                        'header' => [['key' => 'Accept', 'value' => 'application/json'], ['key' => 'X-Off', 'value' => '1', 'disabled' => true]],
                        'body' => ['mode' => 'raw', 'raw' => '{"email":"{{email}}"}', 'options' => ['raw' => ['language' => 'json']]],
                        'url' => ['raw' => '{{base_url}}/api/login?x=1', 'host' => ['{{base_url}}'], 'path' => ['api', 'login'], 'query' => [['key' => 'x', 'value' => '1']]],
                    ], 'event' => [['listen' => 'test', 'script' => ['type' => 'text/javascript', 'exec' => ['pm.test("ok", () => {});', 'pm.environment.set("token", "x");']]]],
                        'protocolProfileBehavior' => new \stdClass],
                ]],
                ['id' => 'info', 'name' => 'Info', 'request' => ['method' => 'GET', 'url' => '{{base_url}}/info',
                    'auth' => ['type' => 'oauth2', 'oauth2' => [['key' => 'grant_type', 'value' => 'client_credentials']]],
                    'body' => ['mode' => 'formdata', 'formdata' => [['key' => 'file', 'type' => 'file', 'src' => 'x.png']]]]],
            ],
        ]));
    }

    public function test_tree_and_requests_follow_collection_order(): void
    {
        $doc = $this->document();

        $this->assertSame([
            ['id' => 'f', 'name' => 'Auth', 'folder' => true, 'depth' => 0, 'method' => null, 'parent' => null],
            ['id' => 'login', 'name' => 'Login', 'folder' => false, 'depth' => 1, 'method' => 'POST', 'parent' => 'f'],
            ['id' => 'info', 'name' => 'Info', 'folder' => false, 'depth' => 0, 'method' => 'GET', 'parent' => null],
        ], $doc->tree());
        $this->assertSame(['login', 'info'], array_column($doc->requests(), 'id'));
        $this->assertSame('Auth', $doc->requests()[0]['folder']);
        $this->assertTrue($doc->isRequest('login'));
        $this->assertFalse($doc->isRequest('f'));
    }

    public function test_request_form_reads_every_tab(): void
    {
        $form = $this->document()->requestForm('login');

        $this->assertSame('POST', $form['method']);
        $this->assertSame('{{base_url}}/api/login?x=1', $form['url']);
        $this->assertSame([['key' => 'x', 'value' => '1', 'enabled' => true, 'description' => '']], $form['params']);
        $this->assertFalse($form['headers'][1]['enabled']);
        $this->assertSame('inherit', $form['auth']['type']);
        $this->assertSame(['raw', '{"email":"{{email}}"}', 'json'], [$form['body']['mode'], $form['body']['raw'], $form['body']['language']]);
        $this->assertSame("pm.test(\"ok\", () => {});\npm.environment.set(\"token\", \"x\");", $form['test']);
        $this->assertSame('', $form['prerequest']);

        $info = $this->document()->requestForm('info');
        $this->assertSame(['other', 'oauth2'], [$info['auth']['type'], $info['auth']['other']]);
        $this->assertSame('other', $info['body']['mode'], 'form-data with files is not editable here');
    }

    public function test_an_unchanged_form_round_trips_to_a_valid_collection(): void
    {
        $doc = $this->document();
        foreach (['login', 'info'] as $id) {
            $doc->applyRequestForm($id, $doc->requestForm($id));
        }
        $json = $doc->toJson();

        (new CollectionImporter)->inspect($json);
        $data = json_decode($json);
        $login = $data->item[0]->item[0];
        $this->assertSame(['{{base_url}}'], $login->request->url->host);
        $this->assertSame(['api', 'login'], $login->request->url->path);
        $this->assertSame('json', $login->request->body->options->raw->language);
        $this->assertTrue($login->request->header[1]->disabled);
        $this->assertObjectNotHasProperty('auth', $login->request, 'inherit stays inherit');
        $this->assertStringContainsString('"protocolProfileBehavior": {}', $json);
        $this->assertSame('oauth2', $data->item[1]->request->auth->type, 'other auth types are kept');
        $this->assertSame('file', $data->item[1]->request->body->formdata[0]->type, 'file bodies are kept');
        $this->assertSame('text/javascript', $login->event[0]->script->type);
    }

    public function test_edits_are_written_back(): void
    {
        $doc = $this->document();
        $form = $doc->requestForm('login');
        $form['name'] = 'Sign in';
        $form['method'] = 'put';
        $form['url'] = '{{base_url}}/api/v2/login';
        $form['params'] = [['key' => 'debug', 'value' => '1', 'enabled' => false]];
        $form['headers'][] = ['key' => '', 'value' => '', 'enabled' => true];
        $form['auth'] = ['type' => 'basic', 'username' => '{{user}}', 'password' => '{{password}}'] + $form['auth'];
        $form['body'] = ['mode' => 'urlencoded', 'fields' => [['key' => 'a', 'value' => 'b', 'enabled' => true]]] + $form['body'];
        $form['prerequest'] = "const t = 1;\r\nconsole.log(t);";
        $form['test'] = '   ';
        $doc->applyRequestForm('login', $form);

        $login = json_decode($doc->toJson())->item[0]->item[0];
        $this->assertSame('Sign in', $login->name);
        $this->assertSame('PUT', $login->request->method);
        $this->assertSame(['api', 'v2', 'login'], $login->request->url->path);
        $this->assertTrue($login->request->url->query[0]->disabled);
        $this->assertCount(2, $login->request->header, 'empty rows are dropped');
        $this->assertSame('basic', $login->request->auth->type);
        $this->assertSame(['username', 'password'], array_column($login->request->auth->basic, 'key'));
        $this->assertSame('urlencoded', $login->request->body->mode);
        $this->assertSame([['listen' => 'prerequest', 'exec' => ['const t = 1;', 'console.log(t);']]], array_map(fn ($e) => ['listen' => $e->listen, 'exec' => $e->script->exec], $login->event), 'empty test script removed');

        $form = $doc->requestForm('login');
        $form['auth']['type'] = 'noauth';
        $form['body']['mode'] = 'none';
        $doc->applyRequestForm('login', $form);
        $login = json_decode($doc->toJson())->item[0]->item[0];
        $this->assertSame('noauth', $login->request->auth->type);
        $this->assertObjectNotHasProperty('body', $login->request);
    }

    public function test_tree_edits(): void
    {
        $doc = $this->document();

        $new = $doc->addRequest('f', 'Logout');
        $folder = $doc->addFolder(null, 'Orders');
        $copy = $doc->duplicate('login');
        $doc->move('info', -1);
        $doc->rename($folder, 'Orders v2');
        $doc->delete('f');

        $this->assertSame([['info', 'Info'], [$folder, 'Orders v2']], array_map(fn ($r) => [$r['id'], $r['name']], $doc->tree()));
        $this->assertFalse($doc->has($new));
        $this->assertFalse($doc->has($copy));

        $doc = $this->document();
        $copy = $doc->duplicate('f');
        $tree = $doc->tree();
        $this->assertSame('Auth copy', $tree[2]['name']);
        $this->assertNotSame('login', $tree[3]['id'], 'copied children get new ids');
        $this->assertSame($copy, $tree[3]['parent']);

        $doc->move('info', 1);
        $this->assertSame('info', $doc->tree()[4]['id'], 'moving past the end is a no-op');

        $this->expectException(ImportException::class);
        $doc->addRequest('info', 'x');
    }

    public function test_collection_summary_is_read_only_data(): void
    {
        $summary = $this->document()->collectionSummary();

        $this->assertSame('bearer', $summary['auth']);
        $this->assertSame('console.log(1);', $summary['prerequest']);
        $this->assertSame([['key' => 'limit', 'value' => '10']], $summary['variables']);
    }

    public function test_a_very_large_raw_body_is_read_only_and_kept(): void
    {
        $big = str_repeat('x', CollectionDocument::RAW_EDIT_MAX_BYTES + 1);
        $doc = CollectionDocument::fromJson(json_encode([
            'info' => ['name' => 'Big', 'schema' => 'https://schema.getpostman.com/json/collection/v2.1.0/collection.json'],
            'item' => [['id' => 'big', 'name' => 'Big', 'request' => ['method' => 'POST', 'url' => '{{u}}', 'body' => ['mode' => 'raw', 'raw' => $big]]]],
        ]));

        $form = $doc->requestForm('big');
        $this->assertSame('other', $form['body']['mode']);
        $this->assertSame('', $form['body']['raw'], 'not sent to the browser');

        $doc->applyRequestForm('big', $form);
        $this->assertSame($big, json_decode($doc->toJson())->item[0]->request->body->raw);
    }
}
