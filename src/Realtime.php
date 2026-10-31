<?php

namespace Hibira\FilamentCollab;

use Hibira\Laravel\Hibira;
use Throwable;

/**
 * Sends document events over Hibira.
 *
 * A failed send is reported but never fails the request: changes are stored
 * first, and editors fetch what they missed when they reconnect.
 */
class Realtime
{
    public function __construct(protected Hibira $hibira) {}

    /**
     * @param  list<string>  $channels
     * @param  array<string, mixed>  $data
     */
    public function trigger(array $channels, string $event, array $data, ?string $exceptSocket = null): void
    {
        if ($channels === []) {
            return;
        }

        try {
            $this->hibira->realtime()->trigger(
                $channels,
                $event,
                $data,
                $exceptSocket ? ['socket_id' => $exceptSocket] : [],
            );
        } catch (Throwable $e) {
            report($e);
        }
    }
}
