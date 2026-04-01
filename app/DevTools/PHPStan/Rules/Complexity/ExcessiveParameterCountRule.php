<?php

declare(strict_types=1);

namespace App\DevTools\PHPStan\Rules\Complexity;

use PhpParser\Node;
use PhpParser\Node\Stmt\ClassMethod;
use PHPStan\Analyser\Scope;
use PHPStan\Rules\IdentifierRuleError;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;

/**
 * Non-constructor methods must not accept more than 4 parameters.
 *
 * Many parameters signal a method is doing too much or needs a parameter
 * object. Constructors are excluded — dependency injection count is enforced
 * separately by the cognitive-complexity dependency_tree setting.
 *
 * @implements Rule<ClassMethod>
 */
final class ExcessiveParameterCountRule implements Rule
{
    private const int THRESHOLD = 4;

    public function getNodeType(): string
    {
        return ClassMethod::class;
    }

    /**
     * @return list<IdentifierRuleError>
     */
    public function processNode(Node $node, Scope $scope): array
    {
        if (! self::isInAppNamespace($scope) || $node->name->name === '__construct') {
            return [];
        }

        $paramCount = \count($node->params);

        if ($paramCount <= self::THRESHOLD) {
            return [];
        }

        return [self::buildError($node->name->name, $paramCount)];
    }

    private static function isInAppNamespace(Scope $scope): bool
    {
        $namespace = $scope->getNamespace();

        return $namespace !== null && \str_starts_with($namespace, 'App\\');
    }

    private static function buildError(string $methodName, int $paramCount): IdentifierRuleError
    {
        return RuleErrorBuilder::message(
            \sprintf(
                'Method %s() has %d parameters — exceeds the %d-parameter limit. Consider a parameter object or splitting the method.',
                $methodName,
                $paramCount,
                self::THRESHOLD,
            ),
        )
            ->identifier('alz.excessiveParameterCount')
            ->tip('Group related parameters into a value object or DTO. If this is a VO named constructor receiving its own fields, this is a valid suppression — add to the baseline with a reason.')
            ->build();
    }
}
