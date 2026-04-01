<?php

declare(strict_types=1);

namespace App\DevTools\PHPStan\Rules\Jobs;

use PhpParser\Node;
use PHPStan\Analyser\Scope;
use PHPStan\Node\InClassNode;
use PHPStan\Reflection\ClassReflection;
use PHPStan\Rules\IdentifierRuleError;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;

/**
 * Jobs must define $tries and $timeout properties.
 *
 * Without explicit $tries, Laravel retries indefinitely on failure.
 * Without explicit $timeout, jobs can hang forever consuming a worker.
 * Both must be set to ensure predictable failure behavior.
 *
 * @implements Rule<InClassNode>
 */
final class JobRequiredPropertiesRule implements Rule
{
    private const array REQUIRED_PROPERTIES = ['tries', 'timeout'];

    public function getNodeType(): string
    {
        return InClassNode::class;
    }

    /**
     * @return list<IdentifierRuleError>
     */
    public function processNode(Node $node, Scope $scope): array
    {
        $classReflection = $node->getClassReflection();

        if (! self::isConcreteJobClass($classReflection)) {
            return [];
        }

        return self::findMissingPropertyErrors($classReflection);
    }

    private static function isConcreteJobClass(ClassReflection $classReflection): bool
    {
        return \str_contains($classReflection->getName(), 'App\\Infrastructure\\Jobs\\')
            && ! $classReflection->isAbstract()
            && ! $classReflection->isEnum();
    }

    /**
     * @return list<IdentifierRuleError>
     */
    private static function findMissingPropertyErrors(ClassReflection $classReflection): array
    {
        $errors = [];

        foreach (self::REQUIRED_PROPERTIES as $property) {
            if (! $classReflection->hasNativeProperty($property)) {
                $errors[] = RuleErrorBuilder::message(
                    \sprintf('Job must define the $%s property.', $property),
                )
                    ->identifier('alz.jobRequiredProperty')
                    ->build();
            }
        }

        return $errors;
    }
}
