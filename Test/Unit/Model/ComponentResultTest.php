<?php
/**
 * Copyright (c) 2016 CTI Digital
 * Copyright (c) 2026 Magebit, Ltd.
 *
 * Licensed under the MIT License; see the LICENSE file in the project root.
 */

declare(strict_types=1);

namespace Magebit\Configurator\Test\Unit\Model;

use Magebit\Configurator\Model\ComponentResult;
use PHPUnit\Framework\TestCase;

class ComponentResultTest extends TestCase
{
    private ComponentResult $result;

    protected function setUp(): void
    {
        $this->result = new ComponentResult();
    }

    public function testStartsEmptyAndSuccessful(): void
    {
        $this->assertSame(0, $this->result->getCreated());
        $this->assertSame(0, $this->result->getUpdated());
        $this->assertSame(0, $this->result->getSkipped());
        $this->assertSame([], $this->result->getErrors());
        $this->assertTrue($this->result->isSuccessful());
    }

    public function testRecordCreatedDefaultsToOneAndAccumulates(): void
    {
        $this->result->recordCreated();
        $this->result->recordCreated();

        $this->assertSame(2, $this->result->getCreated());
    }

    public function testRecordCreatedAcceptsAnExplicitCount(): void
    {
        $this->result->recordCreated(5);

        $this->assertSame(5, $this->result->getCreated());
    }

    public function testRecordUpdatedDefaultsToOneAndAccumulates(): void
    {
        $this->result->recordUpdated();
        $this->result->recordUpdated(3);

        $this->assertSame(4, $this->result->getUpdated());
    }

    public function testRecordSkippedDefaultsToOneAndAccumulates(): void
    {
        $this->result->recordSkipped();
        $this->result->recordSkipped(2);

        $this->assertSame(3, $this->result->getSkipped());
    }

    public function testCountersAreIndependent(): void
    {
        $this->result->recordCreated(1);
        $this->result->recordUpdated(2);
        $this->result->recordSkipped(3);

        $this->assertSame(1, $this->result->getCreated());
        $this->assertSame(2, $this->result->getUpdated());
        $this->assertSame(3, $this->result->getSkipped());
    }

    public function testAddErrorCollectsMessagesInOrder(): void
    {
        $this->result->addError('first');
        $this->result->addError('second');

        $this->assertSame(['first', 'second'], $this->result->getErrors());
    }

    public function testAnyErrorMakesResultUnsuccessful(): void
    {
        $this->result->addError('boom');

        $this->assertFalse($this->result->isSuccessful());
        $this->assertNotEmpty($this->result->getErrors());
    }

    public function testRecordingCountsDoesNotAffectSuccess(): void
    {
        $this->result->recordCreated();
        $this->result->recordUpdated();
        $this->result->recordSkipped();

        $this->assertTrue($this->result->isSuccessful());
    }

    public function testMergeFoldsCountersAndErrors(): void
    {
        $this->result->recordCreated(2);
        $this->result->recordUpdated(1);
        $this->result->recordSkipped(4);
        $this->result->addError('a');

        $other = new ComponentResult();
        $other->recordCreated(3);
        $other->recordUpdated(5);
        $other->recordSkipped(1);
        $other->addError('b');

        $this->result->merge($other);

        $this->assertSame(5, $this->result->getCreated());
        $this->assertSame(6, $this->result->getUpdated());
        $this->assertSame(5, $this->result->getSkipped());
        $this->assertSame(['a', 'b'], $this->result->getErrors());
        $this->assertFalse($this->result->isSuccessful());
    }

    public function testMergeLeavesTheOtherResultUntouched(): void
    {
        $other = new ComponentResult();
        $other->recordCreated(2);
        $other->addError('x');

        $this->result->merge($other);

        $this->assertSame(2, $other->getCreated());
        $this->assertSame(['x'], $other->getErrors());
    }

    public function testMergingAnEmptyResultIsANoOp(): void
    {
        $this->result->recordCreated(7);
        $this->result->addError('keep');

        $this->result->merge(new ComponentResult());

        $this->assertSame(7, $this->result->getCreated());
        $this->assertSame(['keep'], $this->result->getErrors());
    }

    public function testMergeIntoASuccessfulResultCanFlipItUnsuccessful(): void
    {
        $other = new ComponentResult();
        $other->addError('late failure');

        $this->assertTrue($this->result->isSuccessful());
        $this->result->merge($other);
        $this->assertFalse($this->result->isSuccessful());
    }

    public function testSummaryReportsAllCounters(): void
    {
        $this->result->recordCreated(1);
        $this->result->recordUpdated(2);
        $this->result->recordSkipped(3);
        $this->result->recordRemoved(4);
        $this->result->addError('e1');
        $this->result->addError('e2');

        $this->assertSame('created 1, updated 2, skipped 3, removed 4, errors 2', $this->result->summary());
    }

    public function testSummaryOnFreshResultIsAllZeroes(): void
    {
        $this->assertSame('created 0, updated 0, skipped 0, removed 0, errors 0', $this->result->summary());
    }

    public function testRecordRemovedAndMergeAccumulate(): void
    {
        $this->result->recordRemoved();
        $this->result->recordRemoved(2);
        $this->assertSame(3, $this->result->getRemoved());

        $other = new ComponentResult();
        $other->recordRemoved(4);
        $this->result->merge($other);
        $this->assertSame(7, $this->result->getRemoved());
    }
}
