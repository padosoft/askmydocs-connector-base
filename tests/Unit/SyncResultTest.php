<?php

declare(strict_types=1);

namespace Padosoft\AskMyDocsConnectorBase\Tests\Unit;

use Carbon\Carbon;
use Padosoft\AskMyDocsConnectorBase\SyncResult;
use PHPUnit\Framework\TestCase;

final class SyncResultTest extends TestCase
{
    public function test_empty_returns_zero_counters(): void
    {
        $r = SyncResult::empty();

        $this->assertSame(0, $r->documentsAdded);
        $this->assertSame(0, $r->documentsUpdated);
        $this->assertSame(0, $r->documentsRemoved);
        $this->assertSame([], $r->errors);
        $this->assertFalse($r->hasErrors());
        $this->assertSame(0, $r->totalChanged());
    }

    public function test_total_changed_sums_three_counters(): void
    {
        $r = new SyncResult(
            documentsAdded: 3,
            documentsUpdated: 5,
            documentsRemoved: 2,
            errors: [],
            completedAt: Carbon::now(),
        );

        $this->assertSame(10, $r->totalChanged());
    }

    public function test_has_errors_reflects_errors_list(): void
    {
        $without = new SyncResult(0, 0, 0, [], Carbon::now());
        $with = new SyncResult(0, 0, 0, ['fetch failed'], Carbon::now());

        $this->assertFalse($without->hasErrors());
        $this->assertTrue($with->hasErrors());
    }

    public function test_to_array_shape(): void
    {
        $r = new SyncResult(1, 2, 3, ['e1'], Carbon::parse('2026-05-12T10:00:00+00:00'));

        $this->assertSame([
            'documents_added' => 1,
            'documents_updated' => 2,
            'documents_removed' => 3,
            'errors' => ['e1'],
            'completed_at' => '2026-05-12T10:00:00+00:00',
        ], $r->toArray());
    }
}
