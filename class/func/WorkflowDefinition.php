<?php

/**
 * AYANE : ayane.co.jp
 * powered by NENE.
 *
 * PHP Version >= 8.4
 *
 * @package   AYANE
 * @author    hideyukiMORI <info@ayane.co.jp>
 * @copyright 2021 AYANE
 * @license   https://opensource.org/licenses/MIT MIT License
 * @link      https://ayane.co.jp/
 */

declare(strict_types=1);

namespace Nene\Func;

/**
 * Static workflow definitions for state-machine workflows.
 *
 * Stores the allowed-transitions map for each named workflow.
 * Definitions are code-level (not DB-driven) to keep the state machine
 * auditable and type-safe.
 *
 * ## Adding a new workflow
 *
 * Add an entry to DEFINITIONS:
 *   'my-workflow' => [
 *       'pending'  => ['active', 'cancelled'],
 *       'active'   => ['completed', 'cancelled'],
 *       'completed'=> [],   // terminal
 *       'cancelled'=> [],   // terminal
 *   ]
 *
 * The first key in the map is the initial state.
 */
final class WorkflowDefinition
{
    /**
     * Workflow name → (from-state → list<to-state>).
     * All states that appear as values must also appear as keys.
     * Terminal states map to an empty list.
     *
     * @var array<string, array<string, list<string>>>
     */
    private const DEFINITIONS = [
        'order' => [
            'draft'     => ['submitted'],
            'submitted' => ['approved', 'rejected', 'cancelled'],
            'approved'  => ['fulfilled', 'cancelled'],
            'rejected'  => [],
            'fulfilled' => [],
            'cancelled' => [],
        ],
        'content' => [
            'draft'     => ['submitted'],
            'submitted' => ['approved', 'rejected'],
            'approved'  => [],
            'rejected'  => ['draft'],   // can revise and resubmit
        ],
    ];

    /**
     * Check whether the given workflow name is registered.
     */
    public static function isValidWorkflow(string $workflow): bool
    {
        return isset(self::DEFINITIONS[$workflow]);
    }

    /**
     * Return the initial state of a workflow (first key in the map).
     *
     * @throws \InvalidArgumentException If the workflow is unknown.
     */
    public static function initialState(string $workflow): string
    {
        if (!self::isValidWorkflow($workflow)) {
            throw new \InvalidArgumentException("Unknown workflow: {$workflow}");
        }
        $first = array_key_first(self::DEFINITIONS[$workflow]);
        assert($first !== null);
        return $first;
    }

    /**
     * Return the list of valid next states from the current state.
     *
     * @return list<string>
     * @throws \InvalidArgumentException If the workflow or state is unknown.
     */
    public static function allowedNext(string $workflow, string $fromState): array
    {
        if (!self::isValidWorkflow($workflow)) {
            throw new \InvalidArgumentException("Unknown workflow: {$workflow}");
        }
        if (!array_key_exists($fromState, self::DEFINITIONS[$workflow])) {
            throw new \InvalidArgumentException("Unknown state '{$fromState}' in workflow '{$workflow}'");
        }
        return self::DEFINITIONS[$workflow][$fromState];
    }

    /**
     * Check whether a transition is valid.
     */
    public static function canTransition(string $workflow, string $fromState, string $toState): bool
    {
        try {
            return in_array($toState, self::allowedNext($workflow, $fromState), strict: true);
        } catch (\InvalidArgumentException) {
            return false;
        }
    }

    /**
     * Assert that a transition is valid; throw HttpTermination(409) if not.
     *
     * @throws \Nene\Xion\HttpTermination 409 Conflict when the transition is not allowed.
     */
    public static function assertTransition(string $workflow, string $fromState, string $toState): void
    {
        if (!self::canTransition($workflow, $fromState, $toState)) {
            throw new \Nene\Xion\HttpTermination(
                \Nene\Xion\JsonResponder::response(
                    ['Result' => false, 'Data' => [
                        'status'       => 'failure',
                        'errorCode'    => 'INVALID-TRANSITION',
                        'errorMessage' => "Transition from '{$fromState}' to '{$toState}' is not allowed.",
                    ]],
                    409
                )
            );
        }
    }

    /**
     * Return all defined states for a workflow.
     *
     * @return list<string>
     * @throws \InvalidArgumentException If the workflow is unknown.
     */
    public static function allStates(string $workflow): array
    {
        if (!self::isValidWorkflow($workflow)) {
            throw new \InvalidArgumentException("Unknown workflow: {$workflow}");
        }
        return array_keys(self::DEFINITIONS[$workflow]);
    }
}
