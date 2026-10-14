<?php

namespace Tests\Unit\Postman;

use App\Services\Postman\VariableCapture;
use Tests\TestCase;

/**
 * Against output captured from Postman CLI 1.71.0 (verify-postman-cli.sh "keepvars", D-039).
 */
class VariableCaptureTest extends TestCase
{
    private function stdout(): string
    {
        return file_get_contents(__DIR__.'/../../fixtures/postman/keepvars.stdout.txt');
    }

    public function test_the_last_environment_is_read_back_intact_from_wrapped_cli_output(): void
    {
        $values = (new VariableCapture)->extract($this->stdout());

        $this->assertSame('YOUR_ACCESS_TOKEN_'.str_repeat('x', 1200).'|"q" end', $values['token'], 'long, with | and quotes, across wrapped lines');
        $this->assertSame('2', $values['second'], 'set by a script that then threw');
        $this->assertSame('http://nginx', $values['base_url']);
        $this->assertNull((new VariableCapture)->extract("no markers here\n"));
        $this->assertNull((new VariableCapture)->extract('AAVARSBEGINzzAAVARSEND'));
    }

    public function test_strip_removes_every_capture_block_and_keeps_the_rest(): void
    {
        $stripped = (new VariableCapture)->strip($this->stdout());

        $this->assertStringNotContainsString('AAVARS', $stripped);
        $this->assertStringNotContainsString('YOUR_ACCESS_TOKEN', $stripped, 'values never reach the log');
        $this->assertStringNotContainsString('7878787878', $stripped, 'no hex fragment of a wrapped block is left');
        $this->assertStringContainsString('Login', $stripped);
        $this->assertStringContainsString('undefinedFn is not defined', $stripped);
    }

    public function test_inject_adds_a_second_test_event_to_every_request_only(): void
    {
        $json = (new VariableCapture)->inject(json_encode([
            'info' => ['name' => 'C', 'schema' => 'https://schema.getpostman.com/json/collection/v2.1.0/collection.json'],
            'item' => [
                ['id' => 'f', 'name' => 'F', 'item' => [['id' => 'a', 'name' => 'A', 'request' => ['url' => '{{u}}'], 'event' => [['listen' => 'test', 'script' => ['exec' => ['own();']]]]]]],
                ['id' => 'b', 'name' => 'B', 'request' => ['url' => '{{u}}']],
            ],
        ]));
        $data = json_decode($json);

        $this->assertObjectNotHasProperty('event', $data->item[0], 'folders get nothing');
        $a = $data->item[0]->item[0]->event;
        $this->assertSame(['own();'], $a[0]->script->exec, 'the request script stays first');
        $this->assertSame([VariableCapture::script()], $a[1]->script->exec);
        $this->assertSame('test', $data->item[1]->event[0]->listen);
    }
}
