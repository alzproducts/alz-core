<?php

declare(strict_types=1);

use NunoMaduro\PhpInsights\Domain\Insights\CyclomaticComplexityIsHigh;
use NunoMaduro\PhpInsights\Domain\Insights\ForbiddenDefineFunctions;
use NunoMaduro\PhpInsights\Domain\Insights\ForbiddenNormalClasses;
use NunoMaduro\PhpInsights\Domain\Insights\ForbiddenPrivateMethods;
use NunoMaduro\PhpInsights\Domain\Insights\ForbiddenTraits;
use NunoMaduro\PhpInsights\Domain\Sniffs\ForbiddenSetterSniff;
use PHP_CodeSniffer\Standards\Generic\Sniffs\Files\LineLengthSniff;
use PHP_CodeSniffer\Standards\Generic\Sniffs\Files\OneClassPerFileSniff;
use PHP_CodeSniffer\Standards\Generic\Sniffs\Formatting\SpaceAfterCastSniff;
use PHP_CodeSniffer\Standards\Generic\Sniffs\Formatting\SpaceAfterNotSniff;
use PHP_CodeSniffer\Standards\Generic\Sniffs\Functions\OpeningFunctionBraceBsdAllmanSniff;
use PHP_CodeSniffer\Standards\PEAR\Sniffs\Classes\ClassDeclarationSniff;
use PHP_CodeSniffer\Standards\PEAR\Sniffs\WhiteSpace\ScopeClosingBraceSniff;
use PHP_CodeSniffer\Standards\PSR1\Sniffs\Classes\ClassDeclarationSniff as Psr1ClassDeclarationSniff;
use PHP_CodeSniffer\Standards\PSR2\Sniffs\Methods\FunctionClosingBraceSniff;
use PhpCsFixer\Fixer\Basic\BracesFixer;
use PhpCsFixer\Fixer\ClassNotation\ClassDefinitionFixer;
use PhpCsFixer\Fixer\ClassNotation\OrderedClassElementsFixer;
use PhpCsFixer\Fixer\FunctionNotation\FunctionDeclarationFixer;
use SlevomatCodingStandard\Sniffs\Classes\ClassStructureSniff;
use SlevomatCodingStandard\Sniffs\Classes\ForbiddenPublicPropertySniff;
use SlevomatCodingStandard\Sniffs\Classes\SuperfluousAbstractClassNamingSniff;
use SlevomatCodingStandard\Sniffs\Classes\SuperfluousExceptionNamingSniff;
use SlevomatCodingStandard\Sniffs\Classes\SuperfluousInterfaceNamingSniff;
use SlevomatCodingStandard\Sniffs\Classes\SuperfluousTraitNamingSniff;
use SlevomatCodingStandard\Sniffs\Commenting\DocCommentSpacingSniff;
use SlevomatCodingStandard\Sniffs\Commenting\InlineDocCommentDeclarationSniff;
use SlevomatCodingStandard\Sniffs\Commenting\UselessFunctionDocCommentSniff;
use SlevomatCodingStandard\Sniffs\Functions\FunctionLengthSniff;
use SlevomatCodingStandard\Sniffs\Namespaces\AlphabeticallySortedUsesSniff;
use SlevomatCodingStandard\Sniffs\TypeHints\DeclareStrictTypesSniff;
use SlevomatCodingStandard\Sniffs\TypeHints\DisallowMixedTypeHintSniff;
use SlevomatCodingStandard\Sniffs\TypeHints\ParameterTypeHintSniff;
use SlevomatCodingStandard\Sniffs\TypeHints\PropertyTypeHintSniff;
use SlevomatCodingStandard\Sniffs\TypeHints\ReturnTypeHintSniff;
use SlevomatCodingStandard\Sniffs\TypeHints\UselessConstantTypeHintSniff;

return [

    /*
    |--------------------------------------------------------------------------
    | Default Preset
    |--------------------------------------------------------------------------
    |
    | This option controls the default preset that will be used by PHP Insights
    | to make your code reliable, simple, and clean. However, you can always
    | adjust the `Metrics` and `Insights` below in this configuration file.
    |
    | Supported: "default", "laravel", "symfony", "magento2", "drupal", "wordpress"
    |
    */

    'preset' => 'laravel',

    /*
    |--------------------------------------------------------------------------
    | IDE
    |--------------------------------------------------------------------------
    |
    | This options allow to add hyperlinks in your terminal to quickly open
    | files in your favorite IDE while browsing your PhpInsights report.
    |
    | Supported: "textmate", "macvim", "emacs", "sublime", "phpstorm",
    | "atom", "vscode".
    |
    | If you have another IDE that is not in this list but which provide an
    | url-handler, you could fill this config with a pattern like this:
    |
    | myide://open?url=file://%f&line=%l
    |
    */

    'ide' => null,

    /*
    |--------------------------------------------------------------------------
    | Configuration
    |--------------------------------------------------------------------------
    |
    | Here you may adjust all the various `Insights` that will be used by PHP
    | Insights. You can either add, remove or configure `Insights`. Keep in
    | mind, that all added `Insights` must belong to a specific `Metric`.
    |
    */

    'exclude' => [
        'vendor',
        'bootstrap/cache',
        'storage',
        'node_modules',
        'examples',
        'database/migrations',
        '.rector-cache',
        'build',
        'coverage-report',
        'phparkitect.php',  // Config file with intentionally long descriptive strings
    ],

    'add' => [
        // No additional checks needed
    ],

    'remove' => [
        // Architecture - too strict for Laravel
        ForbiddenNormalClasses::class,
        ForbiddenDefineFunctions::class,
        ForbiddenTraits::class,

        // Naming - allow Interface/Exception/Trait/Abstract prefixes/suffixes (PHP standard convention)
        SuperfluousAbstractClassNamingSniff::class,
        SuperfluousInterfaceNamingSniff::class,
        SuperfluousExceptionNamingSniff::class,
        SuperfluousTraitNamingSniff::class,

        // Type hints - handled by PHPStan level 8
        DisallowMixedTypeHintSniff::class,
        ParameterTypeHintSniff::class,
        PropertyTypeHintSniff::class,
        ReturnTypeHintSniff::class,
        UselessConstantTypeHintSniff::class,    // PHPStan requires these for type inference

        // Doc comments - PHPStan conflicts (requires specific inline formats)
        InlineDocCommentDeclarationSniff::class,

        // Style - let Pint handle ALL style (PSR-12)
        AlphabeticallySortedUsesSniff::class,
        DeclareStrictTypesSniff::class,
        UselessFunctionDocCommentSniff::class,
        DocCommentSpacingSniff::class,
        SpaceAfterCastSniff::class,
        SpaceAfterNotSniff::class,

        // Brace style - delegate to Pint (PER preset)
        OpeningFunctionBraceBsdAllmanSniff::class,
        ClassDeclarationSniff::class,
        ClassStructureSniff::class,
        ScopeClosingBraceSniff::class,          // CodeSniffer: scope braces
        FunctionClosingBraceSniff::class,       // CodeSniffer: function braces

        // PHP-CS-Fixer style - delegate to Pint (PER preset)
        /** @phpstan-ignore classConstant.deprecatedClass */
        BracesFixer::class,                     // PHP-CS-Fixer: brace placement (deprecated)
        ClassDefinitionFixer::class,            // PHP-CS-Fixer: class definition style
        FunctionDeclarationFixer::class,        // PHP-CS-Fixer: function declaration style
        OrderedClassElementsFixer::class,       // PHP-CS-Fixer: class element ordering

        // Line length - delegate to Pint (PER preset)
        LineLengthSniff::class,                 // Let Pint handle line length
    ],

    'config' => [
        ForbiddenPrivateMethods::class => [
            'title' => 'The usage of private methods is not idiomatic in Laravel.',
        ],

        // Cyclomatic Complexity - realistic threshold for Laravel
        CyclomaticComplexityIsHigh::class => [
            'maxComplexity' => 10,
        ],

        // Function length - allow reasonable method sizes
        FunctionLengthSniff::class => [
            'maxLinesLength' => 50,
            'exclude' => [
                // Validation-heavy transformer with unavoidably verbose null checks
                'app/Infrastructure/GoogleAds/Transformers/GoogleAdsRowTransformer.php',
                // Job exception handling pattern requires multiple catch blocks
                'app/Presentation/Jobs',
            ],
        ],

        // Framework-required patterns (per-file exclusions)
        ForbiddenSetterSniff::class => [
            'exclude' => [
                'app/DevTools/GitHooks/AbstractProcessHook.php',
                'app/DevTools/GitHooks/AbstractPreCommitProcessHook.php',
            ],
        ],

        // Laravel Job classes require public $tries, $backoff, $timeout properties (queue contract)
        ForbiddenPublicPropertySniff::class => [
            'exclude' => [
                'app/Presentation/Jobs',
            ],
        ],

        PropertyTypeHintSniff::class => [
            'exclude' => [
                'app/Models/User.php',
            ],
        ],

        // Linnworks Query Objects co-locate Row DTOs in same file for cohesion
        OneClassPerFileSniff::class => [
            'exclude' => [
                'app/Infrastructure/Linnworks/Queries',
            ],
        ],
        Psr1ClassDeclarationSniff::class => [
            'exclude' => [
                'app/Infrastructure/Linnworks/Queries',
            ],
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Requirements
    |--------------------------------------------------------------------------
    |
    | Here you may define a level you want to reach per `Insights` category.
    | When a score is lower than the minimum level defined, then an error
    | code will be returned. This is optional and individually defined.
    |
    */

    'requirements' => [
        'min-quality' => 90.0,
        'min-complexity' => 85.0,
        'min-architecture' => 90.0,
        'min-style' => 95.0,
        'disable-security-check' => false,
    ],

    /*
    |--------------------------------------------------------------------------
    | Threads
    |--------------------------------------------------------------------------
    |
    | Here you may adjust how many threads (core) PHPInsights can use to perform
    | the analysis. This is optional, don't provide it and the tool will guess
    | the max core number available. It accepts null value or integer > 0.
    |
    */

    'threads' => null,

    /*
    |--------------------------------------------------------------------------
    | Timeout
    |--------------------------------------------------------------------------
    | Here you may adjust the timeout (in seconds) for PHPInsights to run before
    | a ProcessTimedOutException is thrown.
    | This accepts an int > 0. Default is 60 seconds, which is the default value
    | of Symfony's setTimeout function.
    |
    */

    'timeout' => 60,
];
