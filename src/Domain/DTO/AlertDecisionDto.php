<?php

declare(strict_types=1);

namespace App\Domain\DTO;

class AlertDecisionDto
{
    public function __construct(
        public readonly AlertRuleDto $rule,
        public readonly string $action, // 'trigger', 'resolve', 'none'
        public readonly bool $shouldNotify
    ) {}
}
