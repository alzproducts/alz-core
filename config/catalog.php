<?php

declare(strict_types=1);

return [
    /*
        |--------------------------------------------------------------------------
        | Products List Fragments
        |--------------------------------------------------------------------------
        |
        | The release id salts every stored list fragment's fingerprint, so a deploy
        | never serves a fragment rendered by older code. Only local and testing runs,
        | which have no commit SHA, fall back to a fixed id.
        |
        */
    'list_fragments' => [
        'release' => env(
            'RAILWAY_GIT_COMMIT_SHA',
            in_array(env('APP_ENV'), ['local', 'testing'], true) ? 'dev' : null,
        ),
    ],
];
