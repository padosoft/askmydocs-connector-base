<?php

declare(strict_types=1);

namespace Padosoft\AskMyDocsConnectorBase\Tests\Unit;

use Carbon\Carbon;
use Padosoft\AskMyDocsConnectorBase\HealthStatus;
use PHPUnit\Framework\TestCase;

final class HealthStatusTest extends TestCase
{
    public function test_factories_set_state(): void
    {
        $this->assertSame(HealthStatus::STATE_HEALTHY, HealthStatus::healthy()->state);
        $this->assertSame(HealthStatus::STATE_DEGRADED, HealthStatus::degraded('warn')->state);
        $this->assertSame(HealthStatus::STATE_ERRORED, HealthStatus::errored('boom')->state);
    }

    public function test_invalid_state_throws(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new HealthStatus('mystery', Carbon::now());
    }

    public function test_to_array_shape(): void
    {
        $h = new HealthStatus(HealthStatus::STATE_HEALTHY, Carbon::parse('2026-05-12T10:00:00+00:00'), 'ok');

        $this->assertSame([
            'state' => 'healthy',
            'last_check' => '2026-05-12T10:00:00+00:00',
            'message' => 'ok',
        ], $h->toArray());
    }
}
