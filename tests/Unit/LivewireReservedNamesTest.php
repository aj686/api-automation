<?php

namespace Tests\Unit;

use Livewire\Component;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;
use ReflectionProperty;

/**
 * In the browser, a component's methods and properties are reached through
 * Livewire's $wire object, where some names are taken by Livewire itself:
 * `wire:submit="upload"` calls $wire.upload() — Livewire's JS uploader — and
 * never reaches the server. Server-side tests (Livewire::test) cannot see
 * this, so the names are checked here against the installed livewire.js
 * (decisions.md D-026).
 */
class LivewireReservedNamesTest extends TestCase
{
    /**
     * @return list<string>
     */
    private function reservedNames(): array
    {
        $js = file_get_contents(__DIR__.'/../../vendor/livewire/livewire/dist/livewire.js');

        $aliases = substr($js, strpos($js, 'var aliases = {'));
        $aliases = substr($aliases, 0, strpos($aliases, '};'));
        preg_match_all('/"([A-Za-z]+)":/', $aliases, $aliasMatches);
        preg_match_all('/wireProperty\("\$?([A-Za-z]+)"/', $js, $magicMatches);

        $names = array_values(array_unique([...$aliasMatches[1], ...$magicMatches[1]]));
        $this->assertContains('upload', $names, 'the alias table was found in livewire.js');

        return $names;
    }

    public function test_no_component_uses_a_name_livewire_reserves_in_the_browser(): void
    {
        $reserved = $this->reservedNames();
        $collisions = [];

        foreach (glob(__DIR__.'/../../app/Livewire/*.php') as $file) {
            $class = new ReflectionClass('App\\Livewire\\'.basename($file, '.php'));
            if (! $class->isSubclassOf(Component::class)) {
                continue;
            }

            $own = fn ($member) => $member->getDeclaringClass()->getName() === $class->getName();
            $names = [
                ...array_map(fn ($m) => $m->getName(), array_filter($class->getMethods(ReflectionMethod::IS_PUBLIC), $own)),
                ...array_map(fn ($p) => $p->getName(), array_filter($class->getProperties(ReflectionProperty::IS_PUBLIC), $own)),
            ];

            foreach (array_intersect($names, $reserved) as $name) {
                $collisions[] = $class->getShortName().'::'.$name;
            }
        }

        $this->assertSame([], $collisions, 'These names are taken by Livewire in the browser; rename them.');
    }
}
