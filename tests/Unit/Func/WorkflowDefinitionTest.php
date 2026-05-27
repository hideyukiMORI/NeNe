<?php

declare(strict_types=1);

namespace Nene\Tests\Unit\Func;

use InvalidArgumentException;
use Nene\Func\WorkflowDefinition;
use Nene\Xion\HttpTermination;
use PHPUnit\Framework\TestCase;

final class WorkflowDefinitionTest extends TestCase
{
    // ── isValidWorkflow ───────────────────────────────────────────────────

    public function testIsValidWorkflowReturnsTrueForKnownWorkflow(): void
    {
        self::assertTrue(WorkflowDefinition::isValidWorkflow('order'));
    }

    public function testIsValidWorkflowReturnsFalseForUnknownWorkflow(): void
    {
        self::assertFalse(WorkflowDefinition::isValidWorkflow('unknown'));
    }

    // ── initialState ─────────────────────────────────────────────────────

    public function testInitialStateReturnsFirstStateForOrder(): void
    {
        self::assertSame('draft', WorkflowDefinition::initialState('order'));
    }

    public function testInitialStateThrowsForUnknownWorkflow(): void
    {
        $this->expectException(InvalidArgumentException::class);
        WorkflowDefinition::initialState('unknown');
    }

    // ── allowedNext ───────────────────────────────────────────────────────

    public function testAllowedNextReturnsTransitionsFromDraft(): void
    {
        self::assertSame(['submitted'], WorkflowDefinition::allowedNext('order', 'draft'));
    }

    public function testAllowedNextReturnsEmptyArrayForTerminalState(): void
    {
        self::assertSame([], WorkflowDefinition::allowedNext('order', 'fulfilled'));
    }

    public function testAllowedNextThrowsForUnknownState(): void
    {
        $this->expectException(InvalidArgumentException::class);
        WorkflowDefinition::allowedNext('order', 'unknown-state');
    }

    // ── canTransition ─────────────────────────────────────────────────────

    public function testCanTransitionReturnsTrueForValidTransition(): void
    {
        self::assertTrue(WorkflowDefinition::canTransition('order', 'draft', 'submitted'));
    }

    public function testCanTransitionReturnsFalseForInvalidTransition(): void
    {
        self::assertFalse(WorkflowDefinition::canTransition('order', 'draft', 'approved'));
    }

    public function testCanTransitionReturnsFalseFromTerminalState(): void
    {
        self::assertFalse(WorkflowDefinition::canTransition('order', 'fulfilled', 'anything'));
    }

    public function testCanTransitionReturnsFalseForUnknownWorkflow(): void
    {
        self::assertFalse(WorkflowDefinition::canTransition('unknown-workflow', 'draft', 'submitted'));
    }

    // ── assertTransition ──────────────────────────────────────────────────

    public function testAssertTransitionSucceedsForValidTransition(): void
    {
        // Must not throw
        WorkflowDefinition::assertTransition('order', 'draft', 'submitted');
        $this->addToAssertionCount(1);
    }

    public function testAssertTransitionThrowsHttpTerminationForInvalidTransition(): void
    {
        $this->expectException(HttpTermination::class);
        WorkflowDefinition::assertTransition('order', 'draft', 'approved');
    }

    public function testAssertTransitionResponseHas409StatusCode(): void
    {
        try {
            WorkflowDefinition::assertTransition('order', 'draft', 'approved');
            self::fail('Expected HttpTermination was not thrown.');
        } catch (HttpTermination $e) {
            self::assertSame(409, $e->response()->statusCode());
        }
    }

    // ── allStates ─────────────────────────────────────────────────────────

    public function testAllStatesReturnsCompleteListForOrder(): void
    {
        self::assertSame(
            ['draft', 'submitted', 'approved', 'rejected', 'fulfilled', 'cancelled'],
            WorkflowDefinition::allStates('order')
        );
    }

    public function testAllStatesThrowsForUnknownWorkflow(): void
    {
        $this->expectException(InvalidArgumentException::class);
        WorkflowDefinition::allStates('unknown');
    }
}
