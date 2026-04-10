<?php

declare(strict_types=1);

use App\Presentation\Http\Middleware\HorizonBasicAuthMiddleware;
use Illuminate\Support\Str;

return [

    /*
    |--------------------------------------------------------------------------
    | Horizon Domain
    |--------------------------------------------------------------------------
    |
    | This is the subdomain where Horizon will be accessible from. If this
    | setting is null, Horizon will reside under the same domain as the
    | application. Otherwise, this value will serve as the subdomain.
    |
    */

    'domain' => env('HORIZON_DOMAIN'),

    /*
    |--------------------------------------------------------------------------
    | Horizon Authentication
    |--------------------------------------------------------------------------
    |
    | These credentials will be used to authenticate access to the Horizon
    | dashboard via HTTP Basic Authentication. This provides simple but
    | effective protection for the Horizon interface in production.
    |
    */

    'auth' => [
        'username' => env('HORIZON_USER'),
        'password' => env('HORIZON_PASSWORD'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Horizon Path
    |--------------------------------------------------------------------------
    |
    | This is the URI path where Horizon will be accessible from. Feel free
    | to change this path to anything you like. Note that the URI will not
    | affect the paths of its internal API that aren't exposed to users.
    |
    */

    'path' => env('HORIZON_PATH', 'horizon'),

    /*
    |--------------------------------------------------------------------------
    | Horizon Redis Connection
    |--------------------------------------------------------------------------
    |
    | This is the name of the Redis connection where Horizon will store the
    | meta information required for it to function. It includes the list
    | of supervisors, failed jobs, job metrics, and other information.
    |
    */

    'use' => 'default',

    /*
    |--------------------------------------------------------------------------
    | Horizon Redis Prefix
    |--------------------------------------------------------------------------
    |
    | This prefix will be used when storing all Horizon data in Redis. You
    | may modify the prefix when you are running multiple installations
    | of Horizon on the same server so that they don't have problems.
    |
    */

    'prefix' => env(
        'HORIZON_PREFIX',
        Str::slug((string) env('APP_NAME', 'laravel'), '_') . '_horizon:',
    ),

    /*
    |--------------------------------------------------------------------------
    | Horizon Route Middleware
    |--------------------------------------------------------------------------
    |
    | These middleware will get attached onto each Horizon route, giving you
    | the chance to add your own middleware to this list or change any of
    | the existing middleware. Or, you can simply stick with this list.
    |
    */

    'middleware' => ['web', HorizonBasicAuthMiddleware::class],

    /*
    |--------------------------------------------------------------------------
    | Queue Wait Time Thresholds
    |--------------------------------------------------------------------------
    |
    | This option allows you to configure when the LongWaitDetected event
    | will be fired. Every connection / queue combination may have its
    | own, unique threshold (in seconds) before this event is fired.
    |
    */

    'waits' => [
        'redis:high' => 30,
        'redis:default' => 60,
        'redis-long:low' => 120,
        'redis:bulk' => 120,
        'redis-xl:background' => 300,
    ],

    /*
    |--------------------------------------------------------------------------
    | Job Trimming Times
    |--------------------------------------------------------------------------
    |
    | Here you can configure for how long (in minutes) you desire Horizon to
    | persist the recent and failed jobs. Typically, recent jobs are kept
    | for one hour while all failed jobs are stored for an entire week.
    |
    */

    'trim' => [
        'recent' => 1440,
        'pending' => 1440,
        'completed' => 1440,
        'recent_failed' => 10080,
        'failed' => 10080,
        'monitored' => 10080,
    ],

    /*
    |--------------------------------------------------------------------------
    | Silenced Jobs
    |--------------------------------------------------------------------------
    |
    | Silencing a job will instruct Horizon to not place the job in the list
    | of completed jobs within the Horizon dashboard. This setting may be
    | used to fully remove any noisy jobs from the completed jobs list.
    |
    */

    'silenced' => [
        // App\Jobs\ExampleJob::class,
    ],

    'silenced_tags' => [
        // 'notifications',
    ],

    /*
    |--------------------------------------------------------------------------
    | Metrics
    |--------------------------------------------------------------------------
    |
    | Here you can configure how many snapshots should be kept to display in
    | the metrics graph. This will get used in combination with Horizon's
    | `horizon:snapshot` schedule to define how long to retain metrics.
    |
    */

    'metrics' => [
        'trim_snapshots' => [
            'job' => 24,
            'queue' => 24,
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Fast Termination
    |--------------------------------------------------------------------------
    |
    | When this option is enabled, Horizon's "terminate" command will not
    | wait on all of the workers to terminate unless the --wait option
    | is provided. Fast termination can shorten deployment delay by
    | allowing a new instance of Horizon to start while the last
    | instance will continue to terminate each of its workers.
    |
    */

    'fast_termination' => true,

    /*
    |--------------------------------------------------------------------------
    | Memory Limit (MB)
    |--------------------------------------------------------------------------
    |
    | This value describes the maximum amount of memory the Horizon master
    | supervisor may consume before it is terminated and restarted. For
    | configuring these limits on your workers, see the next section.
    |
    */

    'memory_limit' => 256,

    /*
    |--------------------------------------------------------------------------
    | Queue Worker Configuration
    |--------------------------------------------------------------------------
    |
    | Here you may define the queue worker settings used by your application
    | in all environments. These supervisors and settings handle all your
    | queued jobs and will be provisioned by Horizon during deployment.
    |
    */

    /*
    |--------------------------------------------------------------------------
    | Queue Priority Tiers
    |--------------------------------------------------------------------------
    |
    | high       - Time-sensitive, user-facing (webhooks, notifications)
    | default    - Normal priority (order sync, daily jobs)
    | low        - Bulk/background work (full customer sync, data migrations)
    | background - Ultra-long-running jobs (historical backfills, full PO syncs)
    |
    */

    'defaults' => [
        'supervisor-1' => [
            'connection' => 'redis',
            'queue' => ['high', 'default'],
            'balance' => 'auto',
            'autoScalingStrategy' => 'time',
            'maxProcesses' => 1,
            'maxTime' => 3600,
            'maxJobs' => 500,
            'memory' => 512,
            'tries' => 1,
            'timeout' => 60,
            'nice' => 0,
        ],
        'supervisor-low' => [
            'connection' => 'redis-long',
            'queue' => ['low'],
            'balance' => 'auto',
            'autoScalingStrategy' => 'time',
            'maxProcesses' => 1,
            'maxTime' => 3600,
            'maxJobs' => 500,
            'memory' => 512,
            'tries' => 1,
            'timeout' => 9300, // Must exceed longest job timeout (9000s) per Laravel timeout chain rule
            'nice' => 0,
        ],
        'supervisor-bulk' => [
            'connection' => 'redis',
            'queue' => ['bulk'],
            'balance' => 'auto',
            'autoScalingStrategy' => 'time',
            'maxProcesses' => 1,
            'maxTime' => 3600,
            'maxJobs' => 1000, // Higher than default — many small jobs, reduce worker restart overhead
            'memory' => 512,
            'tries' => 1,
            'timeout' => 60,
            'nice' => 0,
        ],
        'supervisor-background' => [
            'connection' => 'redis-xl',
            'queue' => ['background'],
            'balance' => 'auto',
            'autoScalingStrategy' => 'time',
            'maxProcesses' => 1,
            'maxTime' => 50400,   // 14h — worker lifecycle buffer above 12h job timeout
            'maxJobs' => 50,
            'memory' => 512,
            'tries' => 1,         // Safety fallback — background jobs run once; each is expensive
            'timeout' => 43500,   // Must exceed longest job timeout (43200s)
            'nice' => 0,
        ],
    ],

    'environments' => [
        'production' => [
            'supervisor-1' => [
                'maxProcesses' => 8,
                'balanceMaxShift' => 1,
                'balanceCooldown' => 3,
                'tries' => 3,
                'timeout' => 90,
                'maxTime' => 3600,
                'maxJobs' => 100,
            ],
            'supervisor-low' => [
                'minProcesses' => 1,
                'maxProcesses' => 6,
                'tries' => 3,
                'timeout' => 9300,  // Must exceed longest low-queue job timeout (9000s)
                'maxTime' => 10800, // 3h — worker lifecycle buffer
                'nice' => 10, // Lower CPU priority for bulk work — gives high/default queues preference
            ],
            'supervisor-background' => [
                'minProcesses' => 0,
                'maxProcesses' => 2,
                'timeout' => 43500,
                'maxTime' => 50400,
                'nice' => 10,
            ],
            'supervisor-bulk' => [
                'minProcesses' => 0,
                'maxProcesses' => 3,
                'tries' => 3,
                'timeout' => 90,
                'nice' => 10, // Lower CPU priority — don't compete with syncs
            ],
        ],

        'local' => [
            'supervisor-1' => [
                'maxProcesses' => 3,
            ],
            'supervisor-low' => [
                'maxProcesses' => 1,
            ],
            'supervisor-bulk' => [
                'maxProcesses' => 1,
            ],
            'supervisor-background' => [
                'maxProcesses' => 1,
            ],
        ],
    ],
];
