<?php

declare(strict_types=1);

namespace Semitexa\LaravelAiVerify\Verify;

use Closure;
use Semitexa\LaravelAiVerify\Verify\Checks\Check;
use Throwable;

/** Runs a plan's targets sequentially, in plan order. A crashing check is "incomplete", never a pass. */
final class Executor
{
    /**
     * @param  array<string, Check>  $checks  check name => implementation
     */
    public function __construct(private readonly array $checks) {}

    /**
     * @param  (Closure(Target): void)|null  $onStart
     * @param  (Closure(Result): void)|null  $onResult
     * @return list<Result>
     */
    public function execute(Plan $plan, ?Closure $onStart = null, ?Closure $onResult = null): array
    {
        $results = [];

        foreach ($plan->targets() as $target) {
            $onStart?->__invoke($target);
            $started = hrtime(true);
            $check = $this->checks[$target->check] ?? null;

            try {
                $result = $check === null
                    ? Result::incomplete("No implementation for check '{$target->check}'")
                    : $check->run($target);
            } catch (Throwable $e) {
                $result = Result::incomplete($target->check.' crashed: '.$e->getMessage());
            }

            $result->bindTo($target);
            $result->durationMs = (int) ((hrtime(true) - $started) / 1_000_000);
            $results[] = $result;
            $onResult?->__invoke($result);
        }

        return $results;
    }
}
