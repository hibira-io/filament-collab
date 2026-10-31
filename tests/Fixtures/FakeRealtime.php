<?php

namespace Hibira\FilamentCollab\Tests\Fixtures;

use Hibira\FilamentCollab\Realtime;

class FakeRealtime extends Realtime
{
    /** @var list<array{channels: list<string>, event: string, data: array<string, mixed>, except: ?string}> */
    public array $sent = [];

    public function __construct() {}

    public function trigger(array $channels, string $event, array $data, ?string $exceptSocket = null): void
    {
        $this->sent[] = ['channels' => $channels, 'event' => $event, 'data' => $data, 'except' => $exceptSocket];
    }

    /** @return list<array{channels: list<string>, event: string, data: array<string, mixed>, except: ?string}> */
    public function events(string $event): array
    {
        return array_values(array_filter($this->sent, fn ($s) => $s['event'] === $event));
    }
}
