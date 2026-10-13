<?php

namespace Tests\Unit;

use App\Services\Postman\RunCommandBuilder;
use App\Services\RunOptions;
use Tests\TestCase;

class RunOptionsTest extends TestCase
{
    public function test_everything_ticked_in_collection_order_is_no_selection(): void
    {
        $all = ['a', 'b', 'c'];
        $items = fn (array $ids) => array_map(fn ($id) => ['id' => $id, 'name' => strtoupper($id)], $ids);

        $this->assertTrue(RunOptions::forSelection($items($all), $all, false)->isDefault());
        $this->assertNull(RunOptions::forSelection($items($all), $all, false)->toArray());
        $this->assertSame([], RunOptions::forSelection($items($all), $all, true)->items, 'bail alone keeps the full run');
        $this->assertSame(['c', 'a', 'b'], RunOptions::forSelection($items(['c', 'a', 'b']), $all, false)->itemIds(), 'reordered');
        $this->assertSame(['a', 'c'], RunOptions::forSelection($items(['a', 'c']), $all, false)->itemIds(), 'unticked');
    }

    public function test_stored_options_are_read_defensively(): void
    {
        $this->assertTrue(RunOptions::fromArray(null)->isDefault());
        $options = RunOptions::fromArray(['items' => [['id' => 'x', 'name' => 'X'], ['name' => 'no id'], 'junk'], 'bail' => 1]);
        $this->assertSame([['id' => 'x', 'name' => 'X']], $options->items);
        $this->assertTrue($options->bail);
    }

    public function test_the_command_gets_one_i_per_request_in_order_and_bail_last(): void
    {
        config(['automation.postman_cli_path' => 'postman']);
        $builder = new RunCommandBuilder;

        $default = $builder->build('c.json', 'e.json', '/w', 'r.json', 30000);
        $this->assertNotContains('-i', $default);
        $this->assertNotContains('--bail', $default);

        $command = $builder->build('c.json', 'e.json', '/w', 'r.json', 30000, ['id-c', 'id-a'], true);
        $this->assertSame(['--timeout-request', '30000', '-i', 'id-c', '-i', 'id-a', '--bail'], array_slice($command, -7));
    }
}
