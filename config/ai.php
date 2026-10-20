<?php

return [

    /*
    |--------------------------------------------------------------------------
    | AI services (self-hosted Llama through Ollama)
    |--------------------------------------------------------------------------
    |
    | Off by default. When on, new projects, offers, reviews, profiles and portfolio items are screened in the background
    | (a job, never inside the user's request) and anything that looks like spam, fraud or abuse is filed as a report for
    | the moderators. Projects and profiles are also turned into embeddings (pgvector) to match projects with freelancers.
    | If Ollama is down nothing breaks: the job logs the error and the content stays as it is.
    |
    */

    'enabled' => (bool) env('AI_ENABLED', false),

    'url' => env('OLLAMA_URL', 'http://ollama:11434'),

    // Chat model used to screen content.
    'model' => env('OLLAMA_MODEL', 'llama3.2:3b'),

    // Embedding model for matching (768 dimensions for nomic-embed-text).
    'embedding_model' => env('OLLAMA_EMBEDDING_MODEL', 'nomic-embed-text'),

    'timeout' => (int) env('OLLAMA_TIMEOUT', 30),

    // A report is filed when the model is at least this sure (0-1).
    'flag_threshold' => (float) env('AI_FLAG_THRESHOLD', 0.7),

];
