<?php

declare(strict_types=1);

namespace Padosoft\AskMyDocsConnectorBase\Tests\Unit;

use DateTimeImmutable;
use Padosoft\AskMyDocsConnectorBase\Support\Metadata\RecencyBucketer;
use Padosoft\AskMyDocsConnectorBase\Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;

final class RecencyBucketerTest extends TestCase
{
    private RecencyBucketer $bucketer;
    private DateTimeImmutable $now;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bucketer = new RecencyBucketer();
        $this->now = new DateTimeImmutable('2026-05-12T00:00:00Z');
    }

    #[Test]
    public function null_input_buckets_to_older(): void
    {
        $this->assertSame(RecencyBucketer::BUCKET_OLDER, $this->bucketer->bucket(null, $this->now));
    }

    #[Test]
    public function unparseable_string_buckets_to_older(): void
    {
        $this->assertSame(RecencyBucketer::BUCKET_OLDER, $this->bucketer->bucket('not a date', $this->now));
    }

    #[Test]
    public function within_seven_days_is_this_week(): void
    {
        $iso = (new DateTimeImmutable('2026-05-08T00:00:00Z'))->format(DATE_ATOM);
        $this->assertSame(RecencyBucketer::BUCKET_WEEK, $this->bucketer->bucket($iso, $this->now));
    }

    #[Test]
    public function within_thirty_days_is_this_month(): void
    {
        $iso = (new DateTimeImmutable('2026-04-20T00:00:00Z'))->format(DATE_ATOM);
        $this->assertSame(RecencyBucketer::BUCKET_MONTH, $this->bucketer->bucket($iso, $this->now));
    }

    #[Test]
    public function within_ninety_days_is_this_quarter(): void
    {
        $iso = (new DateTimeImmutable('2026-03-01T00:00:00Z'))->format(DATE_ATOM);
        $this->assertSame(RecencyBucketer::BUCKET_QUARTER, $this->bucketer->bucket($iso, $this->now));
    }

    #[Test]
    public function very_old_input_is_older(): void
    {
        $iso = (new DateTimeImmutable('2024-01-01T00:00:00Z'))->format(DATE_ATOM);
        $this->assertSame(RecencyBucketer::BUCKET_OLDER, $this->bucketer->bucket($iso, $this->now));
    }

    #[Test]
    public function future_dated_input_is_treated_as_fresh(): void
    {
        $iso = (new DateTimeImmutable('2026-06-01T00:00:00Z'))->format(DATE_ATOM);
        $this->assertSame(RecencyBucketer::BUCKET_WEEK, $this->bucketer->bucket($iso, $this->now));
    }

    #[Test]
    public function datetime_object_is_accepted(): void
    {
        $dt = new DateTimeImmutable('2026-05-10T00:00:00Z');
        $this->assertSame(RecencyBucketer::BUCKET_WEEK, $this->bucketer->bucket($dt, $this->now));
    }
}
