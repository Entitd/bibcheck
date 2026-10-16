<?php

return [
    'execution' => env('BIBCHECK_EXTERNAL_EXECUTION', 'queue'),
    'enabled' => env('BIBCHECK_EXTERNAL_ENABLED', true),
    'contact_email' => env('BIBCHECK_CONTACT_EMAIL'),
    'user_agent' => env('BIBCHECK_USER_AGENT', 'BibCheck/1.0'),
    'algorithm_version' => '2.0',
    'timeout' => 8,
    'max_requests' => 6,
    'max_entries' => 100,
    'max_active_runs' => 2,
    'sync_max_entries' => 3,
    'title_threshold' => 92,
    'candidate_gap' => 8,
    'cache' => ['identifier' => 604800, 'search' => 86400, 'not_found' => 21600],
    'providers' => [
        'crossref' => ['cache_ttl' => 86400, 'enabled' => true, 'url' => 'https://api.crossref.org', 'interval' => 1],
        'openlibrary' => ['enabled' => true, 'url' => 'https://openlibrary.org', 'interval' => 1],
        'datacite' => ['enabled' => true, 'url' => 'https://api.datacite.org', 'interval' => 1, 'window_limit' => 450],
        'dblp' => ['enabled' => false, 'url' => 'https://dblp.org', 'interval' => 1],
        'openalex' => ['enabled' => true, 'interval' => 1, 'daily_search_limit' => 900],
    ],
];
